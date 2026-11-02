#!/usr/bin/env python3
"""
Đo hiệu năng một số trang, từ góc nhìn khách chưa đăng nhập.

Mỗi trang đo nhiều lần, lấy trung vị (bỏ lần đầu để cache PHP/DB "nóng" lên):
  - queries / server ms / memory: từ dòng <!-- kio-metrics ... --> do plugin kio-store in ra (local)
  - TTFB, tổng thời gian tải HTML (đo phía client)
  - dung lượng HTML; số file + dung lượng CSS, JS (chỉ file cùng site); số ảnh

Dùng:
  python3 scripts/measure.py                          # in bảng
  python3 scripts/measure.py --save baseline           # lưu docs/metrics/baseline.json
  python3 scripts/measure.py --compare baseline after  # so sánh 2 lần đo đã lưu

Chỉ dùng thư viện chuẩn của Python.
"""

import argparse
import json
import re
import statistics
import sys
import time
import urllib.request
from pathlib import Path

SITE = "http://localhost:8080"
PAGES = {
    "Trang chủ": "/",
    "Danh mục Điện thoại": "/danh-muc-san-pham/dien-thoai/",
    "Sản phẩm Galaxy S26": "/san-pham/samsung-galaxy-s26/",
}
RUNS = 5
SAVE_DIR = Path(__file__).resolve().parent.parent / "docs" / "metrics"
FIELDS = [
    ("queries", "Query DB", ""), ("server_ms", "Tạo trang", "ms"), ("ttfb_ms", "TTFB", "ms"),
    ("html_kb", "HTML", "KB"), ("css_n", "CSS", "file"), ("css_kb", "CSS", "KB"),
    ("js_n", "JS", "file"), ("js_kb", "JS", "KB"), ("img_n", "Ảnh", "thẻ"),
]


def fetch(url):
    start = time.perf_counter()
    with urllib.request.urlopen(url, timeout=60) as r:
        first = r.read(1)
        ttfb = time.perf_counter() - start
        body = first + r.read()
    return body.decode(errors="replace"), ttfb * 1000


def asset_size(url, cache):
    if url not in cache:
        try:
            with urllib.request.urlopen(url, timeout=60) as r:
                cache[url] = len(r.read())
        except OSError:
            cache[url] = 0
    return cache[url]


def measure_page(path):
    samples, cache = [], {}
    for i in range(RUNS + 1):
        html, ttfb = fetch(SITE + path)
        if i == 0:
            continue  # lần đầu: làm nóng cache
        m = re.search(r"kio-metrics queries=(\d+) time=(\d+)ms memory=(\d+)MB", html)
        css = set(re.findall(r"<link[^>]+rel=['\"]stylesheet['\"][^>]+href=['\"]([^'\"]+)", html))
        js = set(re.findall(r"<script[^>]+src=['\"]([^'\"]+)", html))
        css = {u for u in css if u.startswith(SITE)}
        js = {u for u in js if u.startswith(SITE)}
        samples.append({
            "queries": int(m.group(1)) if m else 0,
            "server_ms": int(m.group(2)) if m else 0,
            "ttfb_ms": round(ttfb),
            "html_kb": round(len(html.encode()) / 1024, 1),
            "css_n": len(css), "css_kb": round(sum(asset_size(u, cache) for u in css) / 1024, 1),
            "js_n": len(js), "js_kb": round(sum(asset_size(u, cache) for u in js) / 1024, 1),
            "img_n": len(re.findall(r"<img\s", html)),
        })
    return {k: statistics.median(s[k] for s in samples) for k, *_ in FIELDS}


def table(results, title):
    head = "| Trang | " + " | ".join(f"{n} ({u})" if u else n for _, n, u in FIELDS) + " |"
    rows = [f"### {title}", "", head, "|" + "---|" * (len(FIELDS) + 1)]
    for page, r in results.items():
        rows.append(f"| {page} | " + " | ".join(f"{r[k]:g}" for k, *_ in FIELDS) + " |")
    return "\n".join(rows)


def compare(a, b, name_a, name_b):
    rows = [f"### {name_a} → {name_b}", "", "| Trang | Chỉ số | " + name_a + " | " + name_b + " | Chênh |", "|---|---|---|---|---|"]
    for page in a:
        for k, n, u in FIELDS:
            va, vb = a[page][k], b.get(page, {}).get(k, 0)
            if va != vb:
                diff = vb - va
                pct = f" ({diff / va * 100:+.0f}%)" if va else ""
                rows.append(f"| {page} | {n} {u} | {va:g} | {vb:g} | {diff:+g}{pct} |")
    return "\n".join(rows)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--save", metavar="TÊN")
    ap.add_argument("--compare", nargs=2, metavar=("TRƯỚC", "SAU"))
    args = ap.parse_args()

    if args.compare:
        a, b = (json.loads((SAVE_DIR / f"{n}.json").read_text()) for n in args.compare)
        print(compare(a, b, *args.compare))
        return

    results = {page: measure_page(path) for page, path in PAGES.items()}
    print(table(results, args.save or "Kết quả đo"))
    if args.save:
        SAVE_DIR.mkdir(parents=True, exist_ok=True)
        (SAVE_DIR / f"{args.save}.json").write_text(json.dumps(results, ensure_ascii=False, indent=2))
        print(f"\nĐã lưu {SAVE_DIR / (args.save + '.json')}")


if __name__ == "__main__":
    sys.exit(main())
