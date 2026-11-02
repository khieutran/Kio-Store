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
import urllib.parse
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


def check_theme():
    _, home = get(SITE + "/")
    check("Theme kio-child: style.css được nạp", "wp-content/themes/kio-child/style.css" in home)
    for cls in ["kio-hero-grid", "kio-flash-sale", "kio-cats", "kio-brands", "kio-commit", "kio-footer"]:
        check(f"Trang chủ có khối {cls}", cls in home)
    check("Trang chủ có lưới sản phẩm", 'class="products' in home)
    check("Có link 'Bỏ qua đến nội dung' (skip link → #content)", 'class="skip-link screen-reader-text" href="#content"' in home)
    check("Mega menu: số thẻ <li> mở và đóng bằng nhau",
          home.count("kio-mega__item") > 0
          and len(re.findall(r"<li[\s>]", home[home.find("kio-mega__list"):home.find("</nav>")]))
          == home[home.find("kio-mega__list"):home.find("</nav>")].count("</li>"))

    # Trang Giỏ hàng/Thanh toán phải dùng shortcode (hook classic chạy ở Phase 4).
    # Cần một sản phẩm simple trong giỏ "classic" (server-side) thì form mới hiện ra.
    simple = next((p for p in by_sku.values() if p.get("type") == "simple"), None)
    if simple is None:
        check("Giỏ hàng dùng shortcode", False, "không tìm thấy sản phẩm simple")
        return
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(CookieJar()))
    try:
        opener.open(SITE + "/?add-to-cart=%d" % simple["id"], timeout=30).read()
    except (urllib.error.HTTPError, urllib.error.URLError, OSError):
        pass

    def get_with_cart(path):
        try:
            return opener.open(SITE + path, timeout=30).read().decode()
        except urllib.error.HTTPError as e:
            return e.read().decode(errors="replace")
        except (urllib.error.URLError, OSError):
            return ""

    # Thêm vào giỏ bằng AJAX (như nút "Thêm vào giỏ" ở trang danh mục): Woo trả về "fragments"
    # để thay HTML không cần tải lại trang. Header tự dựng phải có fragment cho số đếm giỏ hàng.
    try:
        body = opener.open(urllib.request.Request(
            SITE + "/?wc-ajax=add_to_cart", data=f"product_id={simple['id']}&quantity=1".encode(), method="POST",
        ), timeout=30).read()
        fragment = json.loads(body).get("fragments", {}).get("em.kio-cart-count", "")
    except (urllib.error.URLError, OSError, ValueError) as e:
        fragment = str(e)
    check("AJAX thêm vào giỏ cập nhật số đếm ở header (fragment em.kio-cart-count)",
          'class="kio-cart-count"' in fragment and 'data-count="2"' in fragment, fragment[:80])

    cart = get_with_cart("/gio-hang/")
    check("Giỏ hàng dùng shortcode [woocommerce_cart]", "woocommerce-cart-form" in cart)
    checkout = get_with_cart("/thanh-toan/")
    check("Thanh toán dùng shortcode [woocommerce_checkout]", 'name="checkout"' in checkout)


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


def product_titles(page_html):
    return re.findall(r'woocommerce-loop-product__title">([^<]+)', page_html)


def check_plugins():
    """Phase 3: bộ lọc (widget Woo), so sánh, yêu thích, đánh giá có ảnh, SEO, form liên hệ."""
    cat = SITE + "/danh-muc-san-pham/dien-thoai/"
    _, page = get(cat)

    # Bộ lọc: widget có sẵn của Woo trong sidebar, lọc qua tham số URL
    check("Bộ lọc: sidebar có widget thương hiệu, khoảng giá, thuộc tính",
          all(w in page for w in ["woocommerce_brand_nav-1", "woocommerce_price_filter-1", "woocommerce_layered_nav-1"]))
    check("Bộ lọc: không còn widget mặc định của WordPress (tìm kiếm, bài viết…)",
          "wp-block-latest-posts" not in page and "wp-block-search" not in page)
    check("Bộ lọc: có nút 'Bộ lọc' cho mobile", 'class="kio-filter-toggle"' in page)
    n = len(product_titles(get(cat + "?filter_ram=8gb")[1]))
    check("Lọc RAM 8GB trong Điện thoại → 4 sản phẩm", n == 4, f"có {n}")
    m = re.search(r'filter_product_brand=(\d+)"[^>]*>Apple<', page)
    titles = product_titles(get(cat + f"?filtering=1&filter_product_brand={m.group(1)}")[1]) if m else []
    check("Lọc thương hiệu Apple → chỉ iPhone", len(titles) == 3 and all("iPhone" in t for t in titles), titles)
    n = len(product_titles(get(cat + "?min_price=0&max_price=10000000")[1]))
    check("Lọc giá dưới 10 triệu → 2 sản phẩm", n == 2, f"có {n}")

    # So sánh (YITH) + Yêu thích (WPC): nút trên thẻ sản phẩm, trang riêng
    check("So sánh: nút trên thẻ sản phẩm", 'class="compare button' in page)
    check("So sánh: trang /so-sanh/ → 200", get(SITE + "/so-sanh/")[0] == 200)
    check("Yêu thích: nút trái tim trên thẻ sản phẩm", "woosw-btn" in page)
    check("Yêu thích: trang /yeu-thich/ → 200", get(SITE + "/yeu-thich/")[0] == 200)

    # Đánh giá có ảnh (Photo Reviews) + SEO (Rank Math) trên trang sản phẩm
    _, product = get(SITE + "/san-pham/samsung-galaxy-s26/")
    check("Đánh giá có ảnh: Photo Reviews có trên trang sản phẩm", "woocommerce-photo-reviews" in product or "wcpr" in product)
    title = (re.search(r"<title>([^<]+)", product) or [None, ""])[1]
    check("SEO: title theo mẫu Rank Math (… | Kio Mobile)", title.endswith("| Kio Mobile"), title)
    check("SEO: có meta description", '<meta name="description"' in product)
    graph = []
    for block in re.findall(r'<script type="application/ld\+json"[^>]*>(.*?)</script>', product, re.S):
        data = json.loads(block)
        graph += data.get("@graph", [data])
    offers = next((g.get("offers") for g in graph if g.get("@type") == "Product"), None)
    check("SEO: schema Product có offers (giá)", bool(offers), str(offers)[:80])
    _, index = get(SITE + "/sitemap_index.xml")
    check("SEO: sitemap có sản phẩm + danh mục + thương hiệu",
          all(x in index for x in ["product-sitemap.xml", "product_cat-sitemap.xml", "product_brand-sitemap.xml"]))
    n = get(SITE + "/product-sitemap.xml")[1].count("<loc>")
    check(f"SEO: sitemap sản phẩm có ≥ {EXPECTED_PRODUCTS} URL", n >= EXPECTED_PRODUCTS, f"có {n}")

    # Form liên hệ (Fluent Forms): gửi như khách thật → email tới Mailpit
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(CookieJar()))
    contact = opener.open(SITE + "/lien-he/", timeout=30).read().decode()
    form = re.search(r'<form[^>]*data-form_id="(\d+)"[^>]*>(.*?)</form>', contact, re.S)
    check("Liên hệ: form có trên trang", bool(form))
    if not form:
        return
    hidden = dict(re.findall(r'<input[^>]*type="hidden"[^>]*name="([^"]+)"[^>]*value="([^"]*)"', form.group(2)))
    token = uuid.uuid4().hex[:12]
    fields = {**hidden, "names[first_name]": "Khách Smoke Test", "email": "khach@vidu.com",
              "subject": f"Smoke {token}", "message": "Kiểm tra form liên hệ."}
    ajax = re.search(r'"ajaxUrl":"([^"]+)"', contact).group(1).replace("\\/", "/")
    body = urllib.parse.urlencode({"action": "fluentform_submit", "form_id": form.group(1),
                                   "data": urllib.parse.urlencode(fields)}).encode()
    try:
        result = json.loads(opener.open(urllib.request.Request(ajax, data=body), timeout=30).read())
    except (urllib.error.URLError, OSError, ValueError) as e:
        result = {"error": str(e)}
    check("Liên hệ: gửi form thành công", result.get("success") is True, str(result)[:100])
    found = 0
    for _ in range(10):
        status, mail = get(f"{MAILPIT}/search?query=subject:{token}")
        found = json.loads(mail).get("messages_count", 0) if status == 200 else 0
        if found:
            break
        time.sleep(1)
    check("Liên hệ: chủ shop nhận email trong Mailpit", found == 1, f"tìm thấy {found}")


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
    section("3. Theme và giao diện", check_theme)
    section("4. Giỏ hàng, giao hàng, coupon", check_cart)
    section("5. Email (Mailpit)", check_mail)
    section("6. Plugin chức năng (Phase 3)", check_plugins)
    section("7. Log lỗi PHP", check_php_log)

    print()
    if failures:
        print(f"THẤT BẠI: {len(failures)} kiểm tra")
        sys.exit(1)
    print("Tất cả kiểm tra đều đạt.")


if __name__ == "__main__":
    main()
