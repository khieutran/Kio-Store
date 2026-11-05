#!/usr/bin/env python3
"""
Đo Lighthouse (công cụ của Google, cùng lõi với PageSpeed Insights) ở chế độ mobile.

Mỗi trang đo nhiều lần, lấy trung vị. Giỏ hàng và thanh toán đo với hồ sơ Chrome có sẵn sản phẩm.
Cần Node và Chrome trên máy (đường dẫn khác thì đặt biến CHROME_PATH). Lighthouse cố định phiên bản để kết quả so sánh được với nhau.

Dùng:
  python3 scripts/lighthouse.py --save phase5-start     # lưu docs/metrics/lighthouse-<tên>.json
  python3 scripts/lighthouse.py --compare a b

Chỉ số:
  score  điểm Performance (0-100)
  fcp    lúc chữ/ảnh đầu tiên hiện
  lcp    lúc phần tử lớn nhất hiện xong (khách cảm nhận trang đã tải)
  cls    độ giật layout
  tbt    thời gian luồng chính bị chặn, thay cho INP khi đo trong phòng thí nghiệm

Số Lighthouse giả lập mạng và CPU chậm, dùng để so sánh tương đối, không phải tốc độ thật.
"""

import argparse
import json
import os
import shutil
import statistics
import subprocess
import sys
import tempfile
import urllib.request
from pathlib import Path

LIGHTHOUSE = "lighthouse@13.6.0"
CHROME = os.environ.get("CHROME_PATH", "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome")
SITE = "http://localhost:8080"
PAGES = {
    "Trang chủ": "/",
    "Danh mục Điện thoại": "/danh-muc-san-pham/dien-thoai/",
    "Sản phẩm Galaxy S26": "/san-pham/samsung-galaxy-s26/",
    "Giỏ hàng": "/gio-hang/",
    "Thanh toán": "/thanh-toan/",
}
CART_PAGES = {"/gio-hang/", "/thanh-toan/"}
RUNS = 3
SAVE_DIR = Path(__file__).resolve().parent.parent / "docs" / "metrics"
FIELDS = ["score", "fcp_ms", "lcp_ms", "cls", "tbt_ms", "kb", "requests"]


def cart_profile(folder):
    """Hồ sơ Chrome có sẵn giỏ hàng, Lighthouse chạy trên hồ sơ đó.

    Không gửi cookie bằng --extra-headers: phiên Woo không giữ được, AJAX của trang thanh toán báo
    "phiên hết hạn" rồi xoá form, kết quả đo là trang lỗi chứ không phải trang thật.
    """
    with urllib.request.urlopen(SITE + "/wp-json/wc/store/v1/products?type=simple&stock_status=instock&per_page=1", timeout=60) as r:
        product = json.load(r)[0]["id"]
    subprocess.run([CHROME, "--headless=new", "--no-first-run", f"--user-data-dir={folder}", "--virtual-time-budget=8000",
                    "--dump-dom", f"{SITE}/?add-to-cart={product}"], check=True, capture_output=True, timeout=90)
    # Giữ cookie giỏ hàng nhưng xoá cache file: đo như khách mở trang lần đầu.
    for cache in ("Cache", "Code Cache"):
        shutil.rmtree(Path(folder) / "Default" / cache, ignore_errors=True)


def run_once(url, cart):
    with tempfile.TemporaryDirectory() as folder:
        out = Path(folder) / "report.json"
        flags = "--headless=new --no-first-run"
        command = ["npx", "--yes", LIGHTHOUSE, url, "--quiet", "--only-categories=performance", "--output=json", f"--output-path={out}"]
        if cart:
            profile = Path(folder) / "profile"
            cart_profile(profile)
            flags += f" --user-data-dir={profile}"
            command.append("--disable-storage-reset")
        command.append("--chrome-flags=" + flags)
        subprocess.run(command, check=True, capture_output=True, timeout=240)
        report = json.loads(out.read_text())

    audits = report["audits"]
    return {
        "score": round(report["categories"]["performance"]["score"] * 100),
        "fcp_ms": round(audits["first-contentful-paint"]["numericValue"]),
        "lcp_ms": round(audits["largest-contentful-paint"]["numericValue"]),
        "cls": round(audits["cumulative-layout-shift"]["numericValue"], 3),
        "tbt_ms": round(audits["total-blocking-time"]["numericValue"]),
        "kb": round(audits["total-byte-weight"]["numericValue"] / 1024),
        "requests": len(audits["network-requests"]["details"]["items"]),
    }


def measure(path):
    runs = [run_once(SITE + path, path in CART_PAGES) for _ in range(RUNS)]
    return {k: statistics.median(r[k] for r in runs) for k in FIELDS}


def table(results):
    rows = ["| Trang | " + " | ".join(FIELDS) + " |", "|" + "---|" * (len(FIELDS) + 1)]
    for page, r in results.items():
        rows.append(f"| {page} | " + " | ".join(f"{r[k]:g}" for k in FIELDS) + " |")
    return "\n".join(rows)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--save", metavar="TÊN")
    ap.add_argument("--compare", nargs=2, metavar=("TRƯỚC", "SAU"))
    args = ap.parse_args()

    if args.compare:
        a, b = (json.loads((SAVE_DIR / f"lighthouse-{n}.json").read_text()) for n in args.compare)
        for page in a:
            print(page, {k: (a[page][k], b[page][k]) for k in FIELDS if a[page][k] != b[page][k]})
        return

    results = {page: measure(path) for page, path in PAGES.items()}
    print(table(results))
    if args.save:
        (SAVE_DIR / f"lighthouse-{args.save}.json").write_text(json.dumps(results, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    sys.exit(main())
