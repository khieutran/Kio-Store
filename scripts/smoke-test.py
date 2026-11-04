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


def product_card(page_html, title):
    """Trả về đoạn HTML của thẻ <li class="product"> chứa tiêu đề sản phẩm đã cho."""
    i = page_html.find('woocommerce-loop-product__title">' + title)
    if i < 0:
        return ""
    start = page_html.rfind('<li class="', 0, i)
    end = page_html.find("</li>", i)
    return page_html[start:end] if start >= 0 and end >= 0 else ""


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


def check_labels():
    """Phase 4 · F1: nhãn tự động trên thẻ sản phẩm (giảm giá %, Mới, Hết hàng)."""
    _, cat = get(SITE + "/danh-muc-san-pham/dien-thoai/")

    flip = product_card(cat, "Samsung Galaxy Z Flip7")
    check("F1: Z Flip7 nhãn giảm giá đúng mức lớn nhất (Giảm đến 14%)",
          "Giảm đến 14%" in flip, flip[:120] if flip else "không tìm thấy thẻ sản phẩm")

    reno = product_card(cat, "OPPO Reno15 5G")
    check("F1: OPPO Reno15 (10 ngày) có nhãn 'Mới'", 'kio-badge--new">Mới</span>' in reno,
          reno[:120] if reno else "không tìm thấy thẻ sản phẩm")

    iphone = product_card(cat, "iPhone 16e")
    check("F1: iPhone 16e (260 ngày) không có nhãn 'Mới'", 'kio-badge--new"' not in iphone)
    check("F1: iPhone 16e (đang sale) vẫn có nhãn giảm giá", "kio-badge--sale" in iphone)


def check_installment():
    """Phase 4 · F2: dòng trả góp 0% tính từ giá, chỉ hiện khi ≥ giá tối thiểu."""
    _, s26 = get(SITE + "/san-pham/samsung-galaxy-s26/")
    i = s26.find('class="kio-installment"')
    block = s26[i:i + 200] if i >= 0 else ""
    check("F2: S26 hiện dòng trả góp", 'class="kio-installment"' in s26)
    # 22.990.000 ÷ 6 kỳ, làm tròn LÊN nghìn → 3.832.000
    check("F2: S26 trả góp đúng 3.832.000 ₫/tháng", "3.832.000" in block, block[:140])

    cheap = by_sku.get("ANK-SAC20W", {}).get("permalink")
    if cheap:
        _, page = get(cheap)
        check("F2: sản phẩm 390.000 (< 3.000.000) không hiện trả góp", "kio-installment" not in page)
    else:
        check("F2: sản phẩm dưới giá tối thiểu không hiện trả góp", False, "không lấy được link sản phẩm")


def check_specs():
    """Phase 4 · F3: thông số nổi bật trên thẻ sản phẩm (chip, RAM, màn hình, pin)."""
    _, phones = get(SITE + "/danh-muc-san-pham/dien-thoai/")
    s26 = product_card(phones, "Samsung Galaxy S26")
    check("F3: điện thoại S26 hiện 3 thông số (Chip, RAM, Màn hình)",
          all(x in s26 for x in ["Chip", "RAM", "Màn hình"]) and "Snapdragon 8 Elite Gen 5" in s26,
          s26[:140])

    _, acc = get(SITE + "/danh-muc-san-pham/phu-kien/")
    pb = product_card(acc, "Sạc dự phòng Anker 10.000mAh 22.5W")
    check("F3: sạc dự phòng hiện 'Pin' (10000 mAh)", "Pin" in pb and "10000 mAh" in pb)

    sac = product_card(acc, "Sạc nhanh Anker Nano 20W USB-C")
    check("F3: phụ kiện không có thông số thì không hiện khối", "kio-specs" not in sac)


def check_promotions():
    """Phase 4 · F4: khối khuyến mãi trên trang sản phẩm (Custom Post Type)."""
    iphone = by_sku.get("APL-IP16E", {}).get("permalink")
    if not iphone:
        check("F4: lấy link sản phẩm iPhone 16e", False, "không lấy được link")
        return
    _, page = get(iphone)
    check("F4: iPhone 16e hiện khối 'Khuyến mãi'", "kio-promotions" in page)
    check("F4: có khuyến mãi 'Tặng ốp lưng chính hãng'", "Tặng ốp lưng chính hãng" in page)
    # iPhone 16e khớp 4 khuyến mãi (ốp lưng + thu cũ đổi mới + giảm 500k + phiếu quà tặng)
    check("F4: khớp > 3 khuyến mãi nên có nút 'Xem thêm'", "kio-promotions__toggle" in page)
    check("F4: khuyến mãi hết hạn không hiện", "hết hạn (demo)" not in page)

    ipad = by_sku.get("APL-IPADAIR11", {}).get("permalink")
    if ipad:
        _, page = get(ipad)
        check("F4: iPad Air (đúng 3 khuyến mãi) không có nút 'Xem thêm'", "kio-promotions__toggle" not in page)


def check_flash_sale():
    """Phase 4 · F5: khối flash sale (block) trên trang chủ + đồng hồ đếm ngược."""
    _, home = get(SITE + "/")

    m = re.search(r'data-ends-at="(\d+)"', home)
    check("F5: khối flash sale render đồng hồ đếm ngược", bool(m), "" if m else "không có data-ends-at")
    check("F5: đồng hồ đếm ngược trỏ về tương lai", bool(m) and int(m.group(1)) > int(time.time()),
          m.group(1) if m else "")
    check("F5: hết placeholder 'Đồng hồ đếm ngược — Phase 4'", "Đồng hồ đếm ngược — Phase 4" not in home)

    i = home.find('class="kio-flash-sale"')
    end = home.find("kio-section-title", i) if i >= 0 else -1
    region = home[i:end] if i >= 0 and end > 0 else ""
    titles = product_titles(region)
    check("F5: flash sale có 6 sản phẩm", len(titles) == 6, f"có {len(titles)}")


def wp_eval(code):
    """Chạy một đoạn PHP trong WordPress qua WP-CLI, trả về chuỗi in ra."""
    return subprocess.run(["docker", "compose", "run", "--rm", "-T", "wpcli", "eval", code],
                          capture_output=True, text=True).stdout.strip()


def check_phase4_review():
    """Hồi quy cho các lỗi tìm thấy khi review Phase 4 (F1, F2, F4, F5)."""
    # F5: REST API trả danh sách flash sale, khớp số thẻ trên trang chủ
    status, body = get(SITE + "/wp-json/kio/v1/flash-sale")
    data = json.loads(body) if status == 200 else {}
    check("F5: REST /wp-json/kio/v1/flash-sale có 6 sản phẩm và thời điểm kết thúc trong tương lai",
          data.get("count") == 6 and data.get("ends_at", 0) > time.time(), f"{status} count={data.get('count')}")
    skus = [p["sku"] for p in data.get("products", [])]
    check("F5: sale hẹn giờ CHƯA tới (iPad Air Xanh dương) không làm sai % giảm iPad",
          next((p["discount_percent"] for p in data.get("products", []) if p["sku"] == "APL-IPADAIR11"), None) == 12, skus)

    out = wp_eval(
        # F5: lưu biến thể (việc Woo làm khi bật/tắt sale hẹn giờ) phải xoá cache flash sale
        "kio_store_flash_sale_data(); wc_get_product(wc_get_product_id_by_sku('XM-RN15PRO-128GB-DEN'))->save();"
        "echo false === get_transient('kio_flash_sale') ? 'cache:ok ' : 'cache:con ';"
        # F1: tính % giảm của sản phẩm biến thể không được nạp từng biến thể (dùng dữ liệu giá có cache)
        "global $wpdb; wp_cache_flush(); $p = wc_get_product(wc_get_product_id_by_sku('APL-IP17PM'));"
        "$p->get_variation_prices(true); $q = $wpdb->num_queries; kio_store_discount_percent($p);"
        "echo 'query:', $wpdb->num_queries - $q, ' ';"
        # F2: Quản lý cửa hàng phải LƯU được cài đặt trả góp (options.php)
        "echo get_role('shop_manager')->has_cap(apply_filters('option_page_capability_kio_installment', 'manage_options')) ? 'sm:ok ' : 'sm:khong ';"
        # F4: khuyến mãi thiếu meta thứ tự vẫn phải hiện
        "$id = wp_insert_post(array('post_type'=>'kio_promotion','post_status'=>'publish','post_title'=>'smoke-no-order','post_content'=>'x'));"
        "$t = wp_list_pluck(kio_store_product_promotions(wc_get_product(wc_get_product_id_by_sku('SS-S26'))), 'post_title');"
        "wp_delete_post($id, true); echo in_array('smoke-no-order', $t, true) ? 'promo:ok' : 'promo:mat';"
    )
    check("F5: lưu biến thể → xoá cache flash sale", "cache:ok" in out, out)
    m = re.search(r"query:(\d+)", out)
    check("F1: tính % giảm sản phẩm biến thể không tốn query (dùng giá có cache)", bool(m) and int(m.group(1)) == 0, out)
    check("F2: Quản lý cửa hàng lưu được cài đặt trả góp", "sm:ok" in out, out)
    check("F4: khuyến mãi thiếu meta thứ tự vẫn hiển thị", "promo:ok" in out, out)


def check_rows_and_buy_bar():
    """Phase 4 · F6 (dải sản phẩm trang chủ bằng code) + F7 (thanh mua hàng mobile)."""
    _, home = get(SITE + "/")
    rows = re.findall(r'<section class="kio-strip kio-product-row" data-category="([^"]+)"[^>]*>(.*?)</section>', home, re.S)
    check("F6: trang chủ có 4 dải [kio_product_row]", [r[0] for r in rows] == ["dien-thoai", "laptop", "am-thanh", "phu-kien"],
          [r[0] for r in rows])
    laptop = dict(rows).get("laptop", "")
    chips = re.findall(r'kio-strip__chip" href="([^"]+)" data-brand="[1-9]\d*">([^<]+)', laptop)
    check("F6: chip thương hiệu dải Laptop tự sinh (Apple, ASUS, Lenovo)",
          sorted(n for _, n in chips) == ["ASUS", "Apple", "Lenovo"], [n for _, n in chips])
    apple = next((html.unescape(u) for u, n in chips if n == "Apple"), "")
    titles = re.findall(r'woocommerce-loop-product__title">([^<]+)', get(apple)[1]) if apple else []
    check("F6: bấm chip Apple ở dải Laptop → chỉ MacBook", bool(titles) and all("MacBook" in t for t in titles), titles)
    out = wp_eval("$v = (int) get_option('kio_product_rows_version', 1); wc_get_product(wc_get_product_id_by_sku('SS-S26'))->save();"
                  "echo (int) get_option('kio_product_rows_version', 1) > $v ? 'ok' : 'khong';")
    check("F6: lưu sản phẩm → tăng phiên bản cache các dải", out.endswith("ok"), out)

    _, product = get(SITE + "/san-pham/samsung-galaxy-s26/")
    check("F7: trang sản phẩm có thanh mua hàng + JS", 'class="kio-buy-bar"' in product and "sticky-buy-bar.js" in product)
    check("F7: trang chủ không nạp JS/CSS thanh mua hàng", "sticky-buy-bar" not in home)


def check_checkout_and_coupons():
    """Phase 4 · F8 (form thanh toán VN) + F9 (coupon giảm tối đa, chặn cộng dồn)."""
    # F8: đặt hàng thật qua trang thanh toán (shortcode) như khách
    charger = by_sku.get("APL-CAP-USBC1M", {}).get("id")

    def place_order(phone):
        op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(CookieJar()))
        op.open(f"{SITE}/?add-to-cart={charger}", timeout=30).read()
        page = op.open(SITE + "/thanh-toan/", timeout=30).read().decode()
        nonce = re.search(r'name="woocommerce-process-checkout-nonce" value="([^"]+)"', page)
        form = {"billing_first_name": "Khách Smoke Test", "billing_country": "VN", "billing_state": "HA-NOI",
                "billing_city": "Phường Ba Đình", "billing_address_1": "1 Hoàng Hoa Thám", "billing_phone": phone,
                "billing_email": "khach@vidu.com", "payment_method": "cod",
                "woocommerce-process-checkout-nonce": nonce.group(1) if nonce else "", "_wp_http_referer": "/thanh-toan/"}
        res = json.loads(op.open(urllib.request.Request(SITE + "/?wc-ajax=checkout",
                                                        data=urllib.parse.urlencode(form).encode()), timeout=60).read())
        return page, res

    page, res = place_order("")
    states = re.search(r'<select name="billing_state".*?</select>', page, re.S)
    check("F8: ô Tỉnh/Thành phố có đúng 34 lựa chọn",
          bool(states) and len(re.findall(r'<option value="[A-Z]', states.group(0))) == 34)
    check("F8: một ô 'Họ và tên', không còn ô 'Họ' (billing_last_name)",
          'id="billing_first_name"' in page and 'id="billing_last_name"' not in page)
    check("F8: thiếu số điện thoại → không đặt được", res.get("result") == "failure" and "Số điện thoại" in res.get("messages", ""))
    _, res = place_order("0123 456")
    check("F8: số điện thoại sai định dạng → báo lỗi tiếng Việt",
          res.get("result") == "failure" and "không hợp lệ" in html.unescape(res.get("messages", "")))
    _, res = place_order("090 123 4567")
    m = re.search(r"order-received/(\d+)", res.get("redirect", ""))
    check("F8: số điện thoại đúng → đặt hàng thành công", res.get("result") == "success" and bool(m), res.get("result"))
    if m:
        out = wp_eval(f"$o = wc_get_order({m.group(1)}); echo $o->get_billing_state(), '|', $o->get_billing_city();"
                      f"$o->update_status('cancelled'); $o->delete(true);")   # huỷ để trả lại tồn kho rồi xoá đơn thử
        check("F8: đơn lưu mã tỉnh + phường/xã", out.startswith("HA-NOI|Phường Ba Đình"), out)

    # F9: coupon qua Store API
    s26 = by_sku.get("SS-S26", {})
    variation = next((v["id"] for v in s26.get("variations", [])
                      if {a["value"] for a in v["attributes"]} == {"256gb", "den"}), None)
    cart = Cart()
    cart.call("/cart/add-item", {"id": variation, "quantity": 1})
    cart.call("/cart/add-item", {"id": by_sku.get("ANK-SAC20W", {}).get("id"), "quantity": 1})
    c = cart.call("/cart/apply-coupon", {"code": "KIO10"})
    discount = c.get("totals", {}).get("total_discount")
    check("F9: KIO10 trên giỏ 23.380.000 giảm đúng mức trần 500.000", discount == "500000", discount)
    c = cart.call("/cart/apply-coupon", {"code": "GIAM500K"})
    check("F9: không dùng chung GIAM500K với KIO10", c.get("code") == "woocommerce_rest_cart_coupon_error", c.get("code"))
    c = cart.call("/cart/apply-coupon", {"code": "FREESHIP"})
    check("F9: FREESHIP (chỉ miễn phí ship) vẫn dùng chung được với KIO10",
          sorted(x["code"] for x in c.get("coupons", [])) == ["freeship", "kio10"], c.get("message", ""))


def check_product_card():
    """Phase 4.5: thẻ sản phẩm (khung ảnh, nhãn gộp, giá "Từ X", đã bán, số ưu đãi)."""
    _, cat = get(SITE + "/danh-muc-san-pham/dien-thoai/")
    flip = product_card(cat, "Samsung Galaxy Z Flip7")

    media = flip[flip.find('class="kio-card-media"'):flip.find("woocommerce-loop-product__title")]
    check("Thẻ: nhãn giảm giá nằm trong khung ảnh", "kio-badge--sale" in media, flip[:160])
    check("Thẻ: chỉ một nhãn giảm giá", flip.count("kio-badge--sale") == 1, str(flip.count("kio-badge--sale")))
    check("Thẻ: giá biến thể hiện 'Từ X' thay cho khoảng giá",
          'kio-price-now">Từ ' in flip and "Khoảng giá" not in flip)
    check("Thẻ: giá gạch ngang đi cùng giá rẻ nhất", "<del" in flip)
    check("Thẻ: nhãn trả góp tách riêng", 'kio-installment__tag">Trả góp 0%</span>' in flip)
    check("Thẻ: có 'Đã bán'", "Đã bán " in flip)
    check("Thẻ: có số ưu đãi", re.search(r'kio-card-offers">\+\d+ ưu đãi', flip) is not None)

    _, single = get(SITE + "/san-pham/samsung-galaxy-z-flip7/")
    summary = single[single.find('class="summary'):]
    check("Trang sản phẩm: giá đầu trang vẫn là khoảng giá", "Khoảng giá" in summary[:4000])

    out = wp_eval(
        "$GLOBALS['product'] = wc_get_product(wc_get_product_id_by_sku('JBL-T520BT'));"
        "ob_start(); kio_child_card_media_open(); kio_store_render_loop_badges(); kio_child_card_badges_close();"
        "echo ob_get_clean();"
    )
    check("Thẻ: hết hàng chỉ có nhãn 'Hết hàng'", "kio-badge--out" in out and "kio-badge--sale" not in out, out[:160])

    # Đếm số lần WP_Query chạy, không đếm query SQL: WordPress tự nhớ kết quả của query giống hệt
    # nhau trong request nên số query SQL luôn là 0, dù hàm có gọi lại get_posts() mỗi thẻ hay không.
    runs = wp_eval(
        "$p = wc_get_product(wc_get_product_id_by_sku('APL-IP16E'));"
        "kio_store_product_promotions($p); $n = 0;"
        "add_action('pre_get_posts', function () use (&$n) { $n++; });"
        "for ($i = 0; $i < 30; $i++) { kio_store_product_promotions($p); }"
        "echo 'runs=' . $n;"
    )
    check("Thẻ: danh sách khuyến mãi chỉ lấy một lần mỗi request", runs.endswith("runs=0"), runs[-80:])


def check_single_product():
    """Phase 4.5: trang sản phẩm (chip phiên bản, bố cục). Hành vi JS của chip test bằng trình duyệt."""
    _, page = get(SITE + "/san-pham/samsung-galaxy-s26/")
    form = page[page.find("<form class=\"variations_form"):page.find("</form>", page.find("variations_form"))]

    check("Chip: select gốc của Woo vẫn nằm trong form", 'name="attribute_pa_dung-luong"' in form and "kio-variation-select" in form)
    check("Chip: có radio cho mọi giá trị (2 dung lượng + 2 màu)", form.count('class="kio-chip"') == 4, str(form.count('class="kio-chip"')))
    check("Chip: lựa chọn mặc định đã được đánh dấu", form.count("checked='checked'") == 2, str(form.count("checked='checked'")))
    check("Chip: màu có ô tròn lấy từ kio_color_hex", re.search(r'kio-chip__swatch" style="background:#[0-9a-fA-F]{3,6}"', form) is not None)
    check("Chip: dung lượng có chỗ cho giá, màu thì không", form.count("kio-chip__price") == 2, str(form.count("kio-chip__price")))
    check("Chip: tắt JS thì hiện lại select", "<noscript><style>.kio-chips{display:none}" in page)
    check("Chip: JS nạp ở sản phẩm biến thể, có defer",
          re.search(r'<script[^>]*variation-chips\.js[^>]*defer|<script[^>]*defer[^>]*variation-chips\.js', page) is not None)

    check("Bố cục: có khung ảnh + cột mua", 'class="kio-product-top"' in page)
    check("Bố cục: khung đóng trước khối tab",
          page.find('class="kio-product-top"') < page.find('class="summary') < page.find("woocommerce-tabs wc-tabs-wrapper"))
    check("Bố cục: không còn sidebar", 'id="secondary"' not in page)
    check("Bố cục: bỏ thẻ sản phẩm trước/sau", 'class="storefront-product-pagination"' not in page)
    check("Bố cục: bỏ thanh mua dính của Storefront", 'class="storefront-sticky-add-to-cart"' not in page)
    check("F3: bảng thông số trên trang sản phẩm", 'kio-spec-grid__value">Snapdragon' in page)

    _, simple = get(SITE + "/san-pham/airpods-pro-3/")
    check("Sản phẩm đơn: không có chip, không nạp JS chip", "kio-chips" not in simple.replace(".kio-chips{", "") and "variation-chips.js" not in simple)

    _, home = get(SITE + "/")
    check("CSS trang sản phẩm chỉ nạp ở trang sản phẩm", "single-product.css" not in home and "single-product.css" in page)


def check_home_and_reels():
    """Phase 4.5: trang chủ (băng chuyền, thanh đã bán, tải có điều kiện) và video reels."""
    _, home = get(SITE + "/")

    flash = home[home.find('class="kio-flash-sale"'):home.find("kio-product-row")]
    check("Trang chủ: Flash Sale là băng chuyền", "data-kio-carousel" in flash)
    percents = re.findall(r'kio-sold-bar__text">Đã bán (\d+)%', flash)
    check("Trang chủ: thanh đã bán có số 0-100", bool(percents) and all(0 <= int(p) <= 100 for p in percents), str(percents))
    row = home[home.find("kio-product-row"):]
    check("Trang chủ: thanh đã bán chỉ có trong Flash Sale", "kio-sold-bar" not in row)
    check("Trang chủ: nạp home.js + carousel.js có defer",
          all(re.search(r'<script[^>]*' + name + r'[^>]*defer|<script[^>]*defer[^>]*' + name, home) for name in ("home\\.js", "carousel\\.js")))

    _, category = get(SITE + "/danh-muc-san-pham/dien-thoai/")
    check("home.css/home.js chỉ nạp ở trang chủ", "themes/kio-child/assets/js/home.js" not in category and "assets/css/home.css" not in category)

    check("Reels: chưa có video thì không in khối, không nạp JS", 'class="kio-reels"' not in home and "video-reels.js" not in home)

    parse = wp_eval(
        "foreach (['https://www.youtube.com/watch?v=aqz-KE-bpKQ&t=3', 'https://youtu.be/aqz-KE-bpKQ?si=x',"
        "'https://www.youtube.com/shorts/aqz-KE-bpKQ', 'https://m.youtube.com/watch?v=aqz-KE-bpKQ',"
        "'https://www.youtube-nocookie.com/embed/aqz-KE-bpKQ', 'https://www.youtube.com/live/aqz-KE-bpKQ'] as $u)"
        "{ echo kio_store_youtube_id($u) === 'aqz-KE-bpKQ' ? 'y' : 'n'; }"
        "foreach (['https://vimeo.com/aqz-KE-bpKQ', 'https://evil.com/watch?v=aqz-KE-bpKQ', 'https://youtube.com.evil.com/watch?v=aqz-KE-bpKQ',"
        "'https://youtu.be/short', 'javascript:alert(1)', 'https://youtu.be/aqz-KE-bpK\"'] as $u)"
        "{ echo '' === kio_store_youtube_id($u) ? 'y' : 'n'; }"
    )
    check("Reels: nhận đúng link YouTube, từ chối link khác", parse.endswith("y" * 12), parse[-20:])

    render = wp_eval(
        "$p = wp_insert_post(['post_type'=>'kio_video','post_status'=>'publish','post_title'=>'Kiểm thử']);"
        "update_post_meta($p, '_kio_video_id', 'aqz-KE-bpKQ');"
        "$bad = wp_insert_post(['post_type'=>'kio_video','post_status'=>'publish','post_title'=>'Link sai']);"
        "echo do_shortcode('[kio_video_reels]'); wp_delete_post($p, true); wp_delete_post($bad, true);"
    )
    check("Reels: có video thì in thẻ link YouTube", 'data-video="aqz-KE-bpKQ"' in render and "youtube.com/watch?v=aqz-KE-bpKQ" in render, render[:120])
    check("Reels: không tạo iframe sẵn", "<iframe" not in render)
    check("Reels: có cửa sổ xem <dialog>", "<dialog" in render)
    check("Reels: video thiếu ID bị bỏ qua", render.count('class="kio-reels__card"') == 1, str(render.count('class="kio-reels__card"')))


def check_row_tabs():
    """Phase 4.5: chip thương hiệu đổi sản phẩm tại chỗ qua wc-ajax kio_product_row."""
    _, home = get(SITE + "/")
    row = home[home.find('data-category="dien-thoai"'):home.find('data-category="laptop"')]

    check("Tab: có chip 'Tất cả' đang chọn", 'data-brand="0" aria-current="true"' in row, row[:160])
    match = re.search(r'filter_product_brand=(\d+)"[^>]*data-brand="(\d+)"[^>]*>Samsung<', html.unescape(row))
    check("Tab: chip vẫn là link thật tới trang đã lọc", match is not None and match.group(1) == match.group(2))
    check("Tab: nạp JS có defer", re.search(r'<script[^>]*product-row\.js[^>]*defer|<script[^>]*defer[^>]*product-row\.js', home) is not None)
    if not match:
        return

    url = SITE + "/?wc-ajax=kio_product_row&category=dien-thoai&limit=5&orderby=popularity&columns=5&brand="
    status, body = get(url + match.group(2))
    data = json.loads(body) if status == 200 else {}
    cards = (data.get("data") or {}).get("html", "")
    titles = product_titles(cards)
    check("Tab: chỉ trả sản phẩm của hãng đã chọn", bool(titles) and all("Samsung" in t for t in titles), str(titles))

    # Thẻ tải sau phải đủ như thẻ in cùng trang: đây là lý do dùng wc-ajax thay cho REST.
    for name, needle in (("nhãn/khung ảnh", "kio-card-media"), ("giá 'Từ'", 'kio-price-now">Từ '), ("trả góp", "kio-installment"),
                         ("thông số", "kio-specs"), ("ưu đãi", "kio-card-offers"), ("nút tim", "woosw-btn"), ("nút so sánh", "compare button")):
        check("Tab: thẻ tải sau có " + name, needle in cards)

    status, _ = get(SITE + "/?wc-ajax=kio_product_row&category=khong-co&brand=1")
    check("Tab: danh mục sai trả 404", status == 404, str(status))
    status, _ = get(SITE + "/?wc-ajax=kio_product_row&category=dien-thoai&brand=999999")
    check("Tab: thương hiệu không có thật trả 404", status == 404, str(status))
    junk = "rac" + uuid.uuid4().hex[:8]
    before = wp_eval("global $wpdb; echo $wpdb->get_var(\"SELECT COUNT(*) FROM $wpdb->options WHERE option_name LIKE '\\_transient\\_kio\\_row\\_%'\");")
    get(url.replace("orderby=popularity", "orderby=" + junk) + match.group(2))
    after = wp_eval("global $wpdb; echo $wpdb->get_var(\"SELECT COUNT(*) FROM $wpdb->options WHERE option_name LIKE '\\_transient\\_kio\\_row\\_%'\");")
    check("Tab: orderby rác không tạo thêm cache", before == after and before != "", before + " → " + after)

    _, few = get(url.replace("limit=5", "limit=2") + match.group(2))
    check("Tab: tham số limit có tác dụng", len(product_titles(json.loads(few)["data"]["html"])) == 2)


def check_header():
    """Phase 4.5: dữ liệu cho gợi ý tìm kiếm và phần header ở server. Hành vi JS test bằng trình duyệt."""
    _, home = get(SITE + "/")
    check("Header: nạp JS gợi ý có defer", re.search(r'<script[^>]*search-suggest\.js[^>]*defer|<script[^>]*defer[^>]*search-suggest\.js', home) is not None)
    check("Header: truyền địa chỉ Store API cho JS", re.search(r'kioSearch\s*=\s*\{[^}]*wc[\\/]+store[\\/]+v1[\\/]+products', home) is not None)
    check("Header: form tìm kiếm gốc vẫn còn", 'name="s"' in home and 'name="post_type" value="product"' in home)

    fields = "&per_page=5&orderby=popularity&order=desc&_fields=id,name,permalink,images,prices"
    status, body = get(SITE + "/wp-json/wc/store/v1/products?search=iphone" + fields)
    items = json.loads(body) if status == 200 else []
    check("Gợi ý: Store API trả kết quả", bool(items), str(status))
    check("Gợi ý: điện thoại đứng trước phụ kiện", bool(items) and items[0]["name"].startswith("iPhone"), items[0]["name"] if items else "")
    check("Gợi ý: đủ trường JS cần", bool(items) and all(k in items[0] for k in ("name", "permalink", "images", "prices")))
    check("Gợi ý: có giá khoảng để hiện 'Từ X'", bool(items) and items[0]["prices"].get("price_range") is not None)
    status, body = get(SITE + "/wp-json/wc/store/v1/products?search=khongcosanphamnay" + fields)
    check("Gợi ý: không có kết quả trả danh sách rỗng", status == 200 and json.loads(body) == [])


def check_ajax_add_to_cart():
    """Phase 4.5: nút mua trang sản phẩm gửi form tới wc-ajax, Woo vẫn là bên kiểm tra."""
    import http.cookiejar

    _, product = get(SITE + "/san-pham/samsung-galaxy-s26/")
    check("Nút mua: nạp JS có defer", re.search(r'<script[^>]*ajax-add-to-cart\.js[^>]*defer|<script[^>]*defer[^>]*ajax-add-to-cart\.js', product) is not None)
    _, home = get(SITE + "/")
    check("Nút mua: chỉ nạp ở trang sản phẩm", "ajax-add-to-cart.js" not in home)

    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    url = SITE + "/?wc-ajax=kio_add_to_cart"

    def post(fields):
        request = urllib.request.Request(url, urllib.parse.urlencode(fields).encode())
        try:
            with opener.open(request, timeout=30) as response:
                return response.status, json.loads(response.read().decode())
        except urllib.error.HTTPError as error:
            return error.code, json.loads(error.read().decode())

    product_id = wp_eval("echo wc_get_product_id_by_sku('SS-S26');").strip().splitlines()[-1]
    variation_id = wp_eval("echo wc_get_product_id_by_sku('SS-S26-256GB-DEN');").strip().splitlines()[-1]
    base = {"add-to-cart": product_id, "product_id": product_id, "quantity": 1}

    _, missing = post(base)
    check("Nút mua: thiếu phiên bản thì Woo báo lỗi, không thêm", missing.get("success") is False and bool(missing["data"]["messages"]), str(missing)[:120])

    status, _ = post({"quantity": 1})
    check("Nút mua: thiếu add-to-cart trả 400", status == 400, str(status))

    _, added = post({**base, "variation_id": variation_id, "attribute_pa_dung-luong": "256gb", "attribute_pa_mau-sac": "den"})
    count = (added.get("fragments") or {}).get("em.kio-cart-count", "")
    check("Nút mua: thêm đúng biến thể, trả số giỏ mới", 'data-count="1"' in count, count or str(added)[:120])

    stock = wp_eval("echo wc_get_product(wc_get_product_id_by_sku('SS-S26-256GB-DEN'))->get_stock_quantity();").strip().splitlines()[-1]
    _, over = post({**base, "variation_id": variation_id, "attribute_pa_dung-luong": "256gb", "attribute_pa_mau-sac": "den", "quantity": int(stock) + 1})
    messages = (over.get("data") or {}).get("messages") or [""]
    check("Nút mua: vượt tồn kho thì Woo báo lỗi", over.get("success") is False and bool(messages[0]), str(over)[:120])
    check("Nút mua: thông báo lỗi không dính chữ của link", "Xem giỏ hàng" not in messages[0] and "<" not in messages[0], messages[0])

    with opener.open(SITE + "/gio-hang/", timeout=30) as response:
        cart = response.read().decode()
    check("Nút mua: giỏ có đúng sản phẩm", "Samsung Galaxy S26" in cart and "cart_item" in cart)
    check("Nút mua: không để lại thông báo cũ ở trang sau", "woocommerce-message" not in cart)


def check_archive():
    """Phase 4.5: chip lọc nhanh, nút sắp xếp, "Xem thêm". Hành vi không tải lại trang test bằng trình duyệt."""
    cat = SITE + "/danh-muc-san-pham/dien-thoai/"
    _, page = get(cat)

    check("Danh mục: có chip lọc nhanh", 'class="kio-quick-filters"' in page)
    check("Danh mục: bỏ ô chọn sắp xếp cũ", 'class="orderby"' not in page)
    check("Danh mục: có đủ 4 nút sắp xếp", page.count('kio-sort__item kio-archive-link') == 4, str(page.count('kio-sort__item kio-archive-link')))
    check("Danh mục: 'Nổi bật' đang chọn", re.search(r'aria-current="true">Nổi bật<', page) is not None)
    check("Danh mục: chỉ hiện khoảng giá có sản phẩm", "Dưới 5 triệu" not in page and "5 - 10 triệu" in page)
    check("Danh mục: nút bộ lọc mobile vẫn còn", "kio-filter-toggle" in page)

    chips = re.findall(r'class="kio-filter-chip kio-archive-link" href="([^"]+)"[^>]*>([^<]+)<', page)
    href = next((html.unescape(u) for u, name in chips if name == "Samsung"), "")
    check("Chip: Samsung dùng đúng tham số của Woo", "filter_product_brand=" in href and "filtering=1" in href, href)
    _, samsung = get(href)
    titles = product_titles(samsung)
    check("Chip: lọc đúng hãng", bool(titles) and all("Samsung" in t for t in titles), str(titles))
    active = re.search(r'kio-filter-chip kio-archive-link is-active" href="([^"]+)"[^>]*>Samsung', samsung)
    check("Chip: đang chọn có link bỏ lọc", active is not None and "filter_product_brand" not in html.unescape(active.group(1)))
    check("Chip: có 'Xoá lọc'", "kio-filter-clear" in samsung)

    second = next((html.unescape(u) for u, name in re.findall(r'class="kio-filter-chip kio-archive-link" href="([^"]+)"[^>]*>([^<]+)<', samsung) if name == "Xiaomi"), "")
    check("Chip: chọn thêm hãng thứ hai", bool(re.search(r"filter_product_brand=\d+(,|%2C)\d+", second)), second)
    _, two = get(second)
    both = product_titles(two)
    check("Chip: hai hãng ra cả hai", any("Samsung" in t for t in both) and any("Xiaomi" in t for t in both), str(both))

    band = next((html.unescape(u) for u, name in chips if name == "5 - 10 triệu"), "")
    _, mid = get(band)
    prices = re.findall(r'kio-filter-chip kio-archive-link is-active"[^>]*>5 - 10 triệu', mid)
    check("Chip giá: lọc được và đánh dấu đang chọn", bool(prices) and bool(product_titles(mid)))

    _, cheap = get(cat + "?orderby=price")
    first = product_titles(cheap)[:1]
    check("Sắp xếp: giá thấp trước", first == ["Xiaomi Redmi Note 15 Pro 5G"], str(first))
    check("Sắp xếp: nút đang chọn đúng", re.search(r'aria-current="true">Giá thấp<', cheap) is not None)

    _, empty = get(cat + "?min_price=1&max_price=2")
    check("Không có kết quả: vẫn còn link bỏ lọc", "woocommerce-no-products-found" in empty and "kio-filter-clear" in empty)

    _, shop = get(SITE + "/cua-hang/")
    check("Cửa hàng: 10 sản phẩm mỗi trang", len(product_titles(shop)) == 10, str(len(product_titles(shop))))
    more = re.search(r'kio-load-more__button" href="([^"]+)" rel="next" data-total="(\d+)">Xem thêm (\d+) sản phẩm', shop)
    check("Xem thêm: là link thật, số còn lại đúng", more is not None and int(more.group(3)) == int(more.group(2)) - 10, more.group(0)[:100] if more else "")
    check("Xem thêm: phân trang gốc vẫn in cho SEO", "woocommerce-pagination" in shop)
    if more:
        status, nxt = get(html.unescape(more.group(1)))
        check("Xem thêm: trang kế tiếp có sản phẩm khác", status == 200 and not set(product_titles(nxt)) & set(product_titles(shop)))

    _, home = get(SITE + "/")
    check("JS/CSS danh mục không nạp ở trang chủ", "category-filter.js" not in home and "css/category.css" not in home)


def check_cart_checkout_ui():
    """Phase 4.5: thanh bước, thông báo gọn, tóm tắt đơn. Hành vi JS test bằng trình duyệt."""
    import http.cookiejar

    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    product_id = wp_eval("echo wc_get_product_id_by_sku('APL-AIRPODS-PRO3') ?: wc_get_products(['limit'=>1,'type'=>'simple','stock_status'=>'instock','return'=>'ids'])[0];").strip().splitlines()[-1]
    opener.open(SITE + "/?add-to-cart=" + product_id, timeout=30).read()

    cart = opener.open(SITE + "/gio-hang/", timeout=30).read().decode()
    check("Giỏ: có sản phẩm để kiểm tra", "cart_item" in cart)
    check("Giỏ: thanh bước ở bước 1", re.search(r'kio-steps__item is-current" aria-current="step"><span class="kio-steps__number">1<', cart) is not None)
    check("Giỏ: không còn chữ Shipment", "Shipment" not in cart)
    check("Giỏ: không còn ô tính phí vận chuyển", "shipping-calculator-button" not in cart)
    check("Giỏ: nút cập nhật gốc vẫn còn cho khi tắt JS", 'name="update_cart"' in cart)
    check("Giỏ: dùng hết chiều rộng", 'id="secondary"' not in cart and "storefront-full-width-content" in cart)
    check("Giỏ: nạp cart.js có defer", re.search(r'<script[^>]*kio-child/assets/js/cart\.js[^>]*defer|<script[^>]*defer[^>]*kio-child/assets/js/cart\.js', cart) is not None)

    checkout = opener.open(SITE + "/thanh-toan/", timeout=30).read().decode()
    check("Thanh toán: thanh bước ở bước 2, bước 1 là link", 'kio-steps__item is-done"><a href=' in checkout and 'aria-current="step"><span class="kio-steps__number">2<' in checkout)
    check("Thanh toán: giữ class để Woo mở form", 'class="showlogin"' in checkout and 'class="showcoupon"' in checkout)
    check("Thanh toán: câu chữ gọn", "Có mã giảm giá?" in checkout and "Đã có tài khoản?" in checkout)
    side = checkout.find('class="kio-order-side"')
    check("Thanh toán: tiêu đề và bảng đơn cùng khung dính", 0 < side < checkout.find('id="order_review_heading"') < checkout.find('id="order_review"'))

    check("Thanh toán: nạp JS gom lỗi có defer", re.search(r'<script[^>]*kio-child/assets/js/checkout\.js[^>]*defer|<script[^>]*defer[^>]*kio-child/assets/js/checkout\.js', checkout) is not None)

    notice = wp_eval("echo apply_filters('woocommerce_add_success', __('Cart updated.', 'woocommerce')) === '' ? 'an' : 'con';"
                     "echo '|' . apply_filters('woocommerce_add_success', 'Thông báo khác');")
    check("Giỏ: chỉ bỏ đúng thông báo 'đã cập nhật'", notice.endswith("an|Thông báo khác"), notice[-40:])

    _, home = get(SITE + "/")
    check("CSS giỏ hàng không nạp ở trang chủ", "css/checkout.css" not in home)


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
    section("7. Nhãn sản phẩm (Phase 4 · F1)", check_labels)
    section("8. Trả góp (Phase 4 · F2)", check_installment)
    section("9. Thông số nổi bật (Phase 4 · F3)", check_specs)
    section("10. Khuyến mãi (Phase 4 · F4)", check_promotions)
    section("11. Flash sale (Phase 4 · F5)", check_flash_sale)
    section("12. Hồi quy sau review Phase 4", check_phase4_review)
    section("13. Dải sản phẩm + thanh mua hàng (Phase 4 · F6, F7)", check_rows_and_buy_bar)
    section("14. Thanh toán VN + coupon tối đa (Phase 4 · F8, F9)", check_checkout_and_coupons)
    section("15. Thẻ sản phẩm (Phase 4.5)", check_product_card)
    section("16. Trang sản phẩm (Phase 4.5)", check_single_product)
    section("17. Trang chủ + video reels (Phase 4.5)", check_home_and_reels)
    section("18. Chip thương hiệu dạng tab (Phase 4.5)", check_row_tabs)
    section("19. Header + gợi ý tìm kiếm (Phase 4.5)", check_header)
    section("20. Nút mua không tải lại trang (Phase 4.5)", check_ajax_add_to_cart)
    section("21. Trang danh mục (Phase 4.5)", check_archive)
    section("22. Giỏ hàng + thanh toán (Phase 4.5)", check_cart_checkout_ui)
    section("23. Log lỗi PHP", check_php_log)

    print()
    if failures:
        print(f"THẤT BẠI: {len(failures)} kiểm tra")
        sys.exit(1)
    print("Tất cả kiểm tra đều đạt.")


if __name__ == "__main__":
    main()
