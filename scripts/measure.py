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
  python3 scripts/measure.py --noise a a2 a3           # mức nhiễu giữa các lần đo CÙNG code

Giỏ hàng và thanh toán đo với phiên có sẵn một sản phẩm (2 trang này không bao giờ page cache).
Mặc định đo trang không qua page cache (gửi Accept: */*, Cache Enabler bỏ qua). Thêm --cached để đo
đúng như khách chưa có giỏ hàng.
Thay đổi nhỏ hơn mức nhiễu thì không được coi là cải thiện.

Chỉ dùng thư viện chuẩn của Python.
"""

import argparse
import http.cookiejar
import json
import os
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
    "Giỏ hàng": "/gio-hang/",
    "Thanh toán": "/thanh-toan/",
}
CART_PAGES = {"/gio-hang/", "/thanh-toan/"}
RUNS = 5
META = "_meta"
CACHED = False
SAVE_DIR = Path(__file__).resolve().parent.parent / "docs" / "metrics"
FIELDS = [
    ("queries", "Query DB", ""), ("server_ms", "Tạo trang", "ms"), ("ttfb_ms", "TTFB", "ms"),
    ("html_kb", "HTML", "KB"), ("css_n", "CSS", "file"), ("css_kb", "CSS", "KB"),
    ("js_n", "JS", "file"), ("js_kb", "JS", "KB"), ("img_n", "Ảnh", "thẻ"),
]


def cart_opener():
    """Phiên riêng có sẵn một sản phẩm còn hàng trong giỏ."""
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    with urllib.request.urlopen(SITE + "/wp-json/wc/store/v1/products?type=simple&stock_status=instock&per_page=1", timeout=60) as r:
        product = json.load(r)[0]["id"]
    opener.open(f"{SITE}/?add-to-cart={product}", timeout=60).read()
    return opener


def fetch(url, opener=None):
    # Cache Enabler phục vụ khi Accept có text/html HOẶC không có Accept, nên phải gửi rõ */*.
    request = urllib.request.Request(url, headers={"Accept": "text/html" if CACHED else "*/*"})
    start = time.perf_counter()
    with (opener.open(request, timeout=60) if opener else urllib.request.urlopen(request, timeout=60)) as r:
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
    opener = cart_opener() if path in CART_PAGES else None
    for i in range(RUNS + 1):
        html, ttfb = fetch(SITE + path, opener)
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
        if page != META:
            rows.append(f"| {page} | " + " | ".join(f"{r[k]:g}" for k, *_ in FIELDS) + " |")
    return "\n".join(rows)


def noise(runs, names):
    """Chênh lớn nhất giữa các lần đo cùng code, tính theo % của trung vị."""
    rows = ["### Mức nhiễu: " + ", ".join(names), "", "| Trang | Thấp nhất (ms) | Cao nhất (ms) | Trung vị (ms) | Nhiễu |", "|---|---|---|---|---|"]
    for page in (p for p in runs[0] if p != META):
        values = [r[page]["server_ms"] for r in runs if page in r]
        mid = statistics.median(values)
        rows.append(f"| {page} | {min(values):g} | {max(values):g} | {mid:g} | ±{(max(values) - min(values)) / 2 / mid * 100:.0f}% |")
    return "\n".join(rows)


def compare(a, b, name_a, name_b):
    rows = [f"### {name_a} → {name_b}", "", "| Trang | Chỉ số | " + name_a + " | " + name_b + " | Chênh |", "|---|---|---|---|---|"]
    for page in (p for p in a if p != META):
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
    ap.add_argument("--noise", nargs="+", metavar="TÊN")
    ap.add_argument("--cached", action="store_true")
    args = ap.parse_args()

    global CACHED
    CACHED = args.cached

    if args.compare:
        a, b = (json.loads((SAVE_DIR / f"{n}.json").read_text()) for n in args.compare)
        print(compare(a, b, *args.compare))
        return

    if args.noise:
        print(noise([json.loads((SAVE_DIR / f"{n}.json").read_text()) for n in args.noise], args.noise))
        return

    results = {page: measure_page(path) for page, path in PAGES.items()}
    results[META] = {"load": round(os.getloadavg()[0], 2), "at": time.strftime("%Y-%m-%d %H:%M")}
    print(table(results, args.save or "Kết quả đo"))
    if args.save:
        SAVE_DIR.mkdir(parents=True, exist_ok=True)
        (SAVE_DIR / f"{args.save}.json").write_text(json.dumps(results, ensure_ascii=False, indent=2))
        print(f"\nĐã lưu {SAVE_DIR / (args.save + '.json')}")


if __name__ == "__main__":
    sys.exit(main())
