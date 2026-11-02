#!/usr/bin/env python3
"""
Smoke test: kiểm tra cửa hàng đang chạy hoạt động đúng, nhìn từ phía khách hàng.

Không đăng nhập, chỉ dùng HTTP như trình duyệt: mở trang, gọi Store API (API mà
trang Giỏ hàng/Thanh toán gọi), xem hộp thư Mailpit, đọc log lỗi PHP.

Chạy sau ./scripts/install.sh:
    python3 scripts/smoke-test.py

Thoát với mã 1 nếu có kiểm tra thất bại (CI dựa vào mã này để báo đỏ).
Chỉ dùng thư viện chuẩn của Python.
"""

import html
import json
import re
import subprocess
import sys
import time
import urllib.error
import urllib.request
import uuid
from http.cookiejar import CookieJar

SITE = "http://localhost:8080"
STORE_API = SITE + "/wp-json/wc/store/v1"
MAILPIT = "http://localhost:8025/api/v1"

EXPECTED_PRODUCTS = 33
VN_ADDRESS = {
    "first_name": "Khách", "last_name": "Thử", "address_1": "1 Lê Lợi",
    "city": "TP. Hồ Chí Minh", "country": "VN", "postcode": "",
}

failures = []


def check(name, ok, detail=""):
    """In kết quả một kiểm tra và ghi lại nếu thất bại."""
    print(f"  {'✓' if ok else '✗'} {name}" + (f"  ({detail})" if detail else ""))
    if not ok:
        failures.append(name)


def get(url):
    """GET, trả về (mã HTTP, nội dung). Không tự đi theo chuyển hướng.
    Không kết nối được (dịch vụ chưa chạy, timeout) → (0, lý do), không văng lỗi."""
    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, *args, **kwargs):
            return None
    try:
        with urllib.request.build_opener(NoRedirect).open(url, timeout=30) as r:
            return r.status, r.read().decode()
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode(errors="replace")
    except (urllib.error.URLError, OSError) as e:
        return 0, str(e)


class Cart:
    """Một phiên giỏ hàng của khách vãng lai (có cookie riêng), gọi qua Store API."""

    def __init__(self):
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(CookieJar()))
        with self.opener.open(STORE_API + "/cart", timeout=30) as r:
            self.nonce = r.headers["Nonce"]   # Store API yêu cầu nonce cho mọi thao tác ghi

    def call(self, path, data=None):
        req = urllib.request.Request(
            STORE_API + path,
            method="GET" if data is None else "POST",
            data=None if data is None else json.dumps(data).encode(),
            headers={"Content-Type": "application/json", "Nonce": self.nonce},
        )
        try:
            with self.opener.open(req, timeout=30) as r:
                return json.loads(r.read())
        except urllib.error.HTTPError as e:
            return json.loads(e.read())
        except (urllib.error.URLError, OSError) as e:
            return {"code": "connection_error", "message": str(e)}


def selected_shipping(cart):
    return [r["name"] for p in cart.get("shipping_rates", []) for r in p["shipping_rates"] if r["selected"]]


def section(title, fn):
    """Chạy một nhóm kiểm tra. Nhóm gặp lỗi bất ngờ thì ghi là thất bại và chạy tiếp nhóm sau,
    để một sự cố không che mất kết quả của các phần còn lại."""
    print(title)
    try:
        fn()
    except Exception as e:  # noqa: BLE001 - cố ý bắt mọi lỗi để báo cáo thay vì dừng
        check(f"{title}: lỗi bất ngờ", False, f"{type(e).__name__}: {e}"[:200])


# Dùng chung giữa các nhóm: sản phẩm lấy từ Store API, tra theo SKU
by_sku = {}


def check_pages():
    for path in ["/", "/cua-hang/", "/san-pham/samsung-galaxy-s26/", "/danh-muc-san-pham/dien-thoai/",
                 "/thuong-hieu/apple/", "/gio-hang/"]:
        status, _ = get(SITE + path)
        check(f"GET {path} → 200", status == 200, f"nhận {status}")
    status, shop = get(SITE + "/cua-hang/")
    check("Cửa hàng mở cho khách (không ở chế độ Sắp ra mắt)", status == 200 and "coming-soon-page" not in shop)


def check_products():
    status, body = get(STORE_API + "/products?per_page=100")
    products = json.loads(body) if status == 200 else []
    check(f"Có {EXPECTED_PRODUCTS} sản phẩm", len(products) == EXPECTED_PRODUCTS, f"có {len(products)}")
    by_sku.update({p["sku"]: p for p in products})

    s26 = by_sku.get("SS-S26", {})
    check("Galaxy S26 là sản phẩm có biến thể", s26.get("type") == "variable")
    prices = s26.get("prices", {})
    check("Giá dạng VND, không số lẻ", prices.get("currency_code") == "VND" and prices.get("currency_minor_unit") == 0)

    # Dữ liệu biến thể nằm trong thuộc tính data-product_variations của form trên trang sản phẩm
    _, page = get(SITE + "/san-pham/ipad-air-11-inch-m3-wi-fi/")
    m = re.search(r'data-product_variations="([^"]*)"', page)
    variations = json.loads(html.unescape(m.group(1))) if m else []
    out = [v["sku"] for v in variations if not v["is_in_stock"]]
    check("iPad Air 256GB Xanh dương hiển thị hết hàng", out == ["APL-IPADAIR11-256GB-XANH-DUONG"], f"hết hàng: {out}")
    check("22.990.000 ₫ hiển thị trên trang S26", "22.990.000" in get(SITE + "/san-pham/samsung-galaxy-s26/")[1])


def check_cart():
    charger = by_sku.get("ANK-SAC20W", {}).get("id")
    cable = by_sku.get("APL-CAP-USBC1M", {}).get("id")

    cart = Cart()
    cart.call("/cart/add-item", {"id": charger, "quantity": 1})
    c = cart.call("/cart/update-customer", {"shipping_address": VN_ADDRESS})
    total = c.get("totals", {}).get("total_price")
    check("Giỏ 390.000: chọn sẵn Miễn phí giao hàng", selected_shipping(c) == ["Miễn phí giao hàng"], selected_shipping(c))
    check("Giỏ 390.000: tổng tiền 390.000", total == "390000", total)

    c = cart.call("/cart/apply-coupon", {"code": "GIAM500K"})
    check("GIAM500K bị từ chối với giỏ dưới 10 triệu", c.get("code") == "woocommerce_rest_cart_coupon_error", c.get("code"))

    # Mọi sản phẩm demo đều từ 390.000 trở lên (đã đủ freeship), nên chỉ kiểm tra mã áp dụng được
    cart = Cart()
    cart.call("/cart/add-item", {"id": cable, "quantity": 1})
    c = cart.call("/cart/apply-coupon", {"code": "FREESHIP"})
    check("FREESHIP áp dụng được", "code" not in c and any(x["code"] == "freeship" for x in c.get("coupons", [])),
          c.get("message", ""))

    c = cart.call("/cart/apply-coupon", {"code": "KIO10"})
    check("KIO10 bị từ chối với giỏ dưới 2 triệu", c.get("code") == "woocommerce_rest_cart_coupon_error", c.get("code"))


def check_mail():
    # Tự gửi một email có mã riêng qua wp_mail() rồi tìm đúng email đó trong Mailpit.
    # Không dựa vào email sinh ra lúc cài đặt: Mailpit lưu trong bộ nhớ, khởi động lại là mất.
    token = uuid.uuid4().hex[:12]
    sent = subprocess.run(
        ["docker", "compose", "run", "--rm", "-T", "wpcli", "eval",
         f"echo wp_mail('smoke@kio.local', 'Smoke test {token}', 'Kiểm tra gửi mail') ? 'ok' : 'fail';"],
        capture_output=True, text=True,
    ).stdout.strip()
    check("wp_mail() gửi thành công", sent.endswith("ok"), sent[-80:])
    found = 0
    for _ in range(10):   # chờ tối đa ~10 giây
        status, body = get(f"{MAILPIT}/search?query=subject:{token}")
        found = json.loads(body).get("messages_count", 0) if status == 200 else 0
        if found:
            break
        time.sleep(1)
    check("Mailpit nhận được email vừa gửi", found == 1, f"tìm thấy {found}")


def check_php_log():
    log = subprocess.run(
        ["docker", "compose", "exec", "-T", "wordpress", "sh", "-c", "cat wp-content/debug.log 2>/dev/null || true"],
        capture_output=True, text=True,
    ).stdout
    fatals = [line for line in log.splitlines() if "PHP Fatal" in line]
    warnings = [line for line in log.splitlines() if "PHP Warning" in line]
    check("Không có lỗi PHP Fatal", not fatals, fatals[0][:160] if fatals else "")
    if warnings:
        print(f"  ! {len(warnings)} PHP Warning (không làm hỏng test), dòng đầu: {warnings[0][:160]}")


def main():
    section("1. Trang web", check_pages)
    section("2. Sản phẩm (Store API)", check_products)
    section("3. Giỏ hàng, giao hàng, coupon", check_cart)
    section("4. Email (Mailpit)", check_mail)
    section("5. Log lỗi PHP", check_php_log)

    print()
    if failures:
        print(f"THẤT BẠI: {len(failures)} kiểm tra")
        sys.exit(1)
    print("Tất cả kiểm tra đều đạt.")


if __name__ == "__main__":
    main()
