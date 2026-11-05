#!/usr/bin/env python3
"""
Kiểm tra bảo mật: thử các đường tấn công thường gặp vào site local, nhìn từ người lạ.

Mỗi kiểm tra là một phép thử thật (gửi request, đọc phản hồi), không đọc cấu hình rồi tin nó đúng.
Nhóm "Không chặn nhầm" kiểm tra chiều ngược lại: những thứ khách cần vẫn phải dùng được.

Chạy khi site đang bật:
    python3 scripts/security-check.py               # in kết quả, thoát mã 1 nếu có mục không đạt
    python3 scripts/security-check.py --save <tên>  # lưu thêm docs/metrics/security-<tên>.json

Script đặt tạm vài file vào container rồi xoá ngay, chỉ dùng cho site local của mình.
Chỉ dùng thư viện chuẩn của Python.
"""

import argparse
import http.cookiejar
import json
import os
import re
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request
import uuid

SITE = "http://localhost:8080"
WP_ROOT = "/var/www/html"
BLOCKED = (403, 404)  # chặn hoặc không có: khách đều không lấy được
SECURITY_HEADERS = {
    "X-Content-Type-Options": "nosniff",
    "X-Frame-Options": "SAMEORIGIN",
    "Referrer-Policy": "strict-origin-when-cross-origin",
    "Permissions-Policy": None,  # chỉ cần có mặt, nội dung tuỳ tính năng của site
}

results = []


def check(name, ok, detail=""):
    """In kết quả một kiểm tra và ghi lại."""
    print(f"  {'✓' if ok else '✗'} {name}" + (f"  ({detail})" if detail else ""))
    results.append({"name": name, "ok": bool(ok), "detail": str(detail)})


def section(title):
    print(f"\n{title}")


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


def request(path, data=None, headers=None, method=None):
    """Gửi request, không đi theo chuyển hướng. Trả về (mã HTTP, header, nội dung)."""
    # Accept: */* để bỏ qua page cache: phép thử cần phản hồi thật của WordPress.
    all_headers = {"Accept": "*/*", "User-Agent": "kio-security-check/1.0", **(headers or {})}
    body = data.encode() if isinstance(data, str) else data
    req = urllib.request.Request(SITE + path, data=body, headers=all_headers, method=method)
    try:
        with urllib.request.build_opener(NoRedirect).open(req, timeout=30) as r:
            return r.status, r.headers, r.read().decode(errors="replace")
    except urllib.error.HTTPError as e:
        return e.code, e.headers, e.read().decode(errors="replace")
    except OSError as e:
        return 0, {}, str(e)


def run(*command):
    """Chạy lệnh, trả về stdout (rỗng nếu lỗi)."""
    done = subprocess.run(command, capture_output=True, text=True, timeout=120, check=False)
    return done.stdout


def in_container(command):
    return run("docker", "compose", "exec", "-T", "wordpress", "sh", "-c", command)


def with_temp_file(relative_path, content, path_on_site=None):
    """Đặt tạm một file vào site, gửi request tới nó, xoá file. Trả về (mã HTTP, nội dung)."""
    target = f"{WP_ROOT}/{relative_path}"
    in_container(f"printf '%s' '{content}' > {target}")
    try:
        status, _, body = request("/" + (path_on_site or relative_path))
    finally:
        in_container(f"rm -f {target}")
    return status, body


def check_code_execution():
    section("Chạy code trên server")
    status, body = with_temp_file("wp-content/uploads/kio-check.php", "<?php echo 6*7;")
    check("PHP không chạy trong uploads/", status in BLOCKED and "42" not in body, f"HTTP {status}")

    # Đuôi kép là cách lách thường gặp khi Apache chỉ khớp phần cuối tên file.
    status, body = with_temp_file("wp-content/uploads/kio-check.php.jpg", "<?php echo 6*7;")
    check("Đuôi kép .php.jpg không chạy", body.strip() != "42", f"HTTP {status}")


def check_spoofed_ip():
    section("Giả địa chỉ IP")
    fake_ip = "203.0.113.77"
    marker = uuid.uuid4().hex
    request(f"/kio-check-{marker}", headers={"X-Forwarded-For": fake_ip})
    logs = run("docker", "compose", "logs", "--no-log-prefix", "--since", "2m", "wordpress")
    lines = [line for line in logs.splitlines() if marker in line]
    source = lines[0].split()[0] if lines else "không thấy trong log"
    check("Apache không tin X-Forwarded-For của khách", bool(lines) and source != fake_ip, f"log ghi {source}")


def check_exposed_files():
    section("File không được công khai")
    status, body = with_temp_file("wp-content/kio-check.log", "bi-mat")
    check("File .log không tải được", status in BLOCKED and "bi-mat" not in body, f"HTTP {status}")

    status, _, _ = request("/wp-content/debug.log")
    check("debug.log không tải được", status in BLOCKED, f"HTTP {status}")

    for path in ("readme.html", "license.txt", "wp-config-sample.php"):
        status, _, _ = request("/" + path)
        check(f"{path} bị chặn", status in BLOCKED, f"HTTP {status}")

    for path in (".env", "wp-config.php.bak", ".git/config", "wp-content/uploads/wc-logs/", "backups/"):
        status, _, _ = request("/" + path)
        check(f"{path} không truy cập được", status in BLOCKED, f"HTTP {status}")

    status, _, body = request("/wp-content/uploads/")
    check("Không liệt kê thư mục uploads/", "Index of" not in body, f"HTTP {status}")


def check_xmlrpc():
    section("XML-RPC")
    payload = '<?xml version="1.0"?><methodCall><methodName>system.listMethods</methodName></methodCall>'
    status, _, body = request("/xmlrpc.php", data=payload, headers={"Content-Type": "text/xml"})
    check("xmlrpc.php bị chặn", status in BLOCKED, f"HTTP {status}")
    check("Không dùng được system.multicall", "system.multicall" not in body)

    _, headers, _ = request("/")
    check("Không có header X-Pingback", not headers.get("X-Pingback"))


def first_product_path():
    """Đường dẫn một sản phẩm, lấy từ Store API."""
    _, _, body = request("/wp-json/wc/store/v1/products?per_page=1")
    return urllib.parse.urlparse(json.loads(body)[0]["permalink"]).path


def check_user_enumeration():
    section("Lộ tên người dùng")
    for path in ("/wp-json/wp/v2/users", "/wp-json/wp/v2/users/1", "/?rest_route=/wp/v2/users"):
        status, _, body = request(path)
        check(f"{path} không trả user", '"slug"' not in body, f"HTTP {status}")

    status, headers, _ = request("/?author=1")
    location = headers.get("Location") or ""
    check("?author=1 không dẫn tới tên user", "/author/" not in location, location or f"HTTP {status}")

    status, _, _ = request("/author/admin/")
    check("Không có trang tác giả", status != 200, f"HTTP {status}")

    home = urllib.parse.quote(SITE + "/", safe="")
    status, _, body = request(f"/wp-json/oembed/1.0/embed?url={home}")
    check("oEmbed không lộ tên tác giả", '"author_name":"admin"' not in body, f"HTTP {status}")

    # JSON-LD của plugin SEO nằm ngay trong HTML nên cũng có trong trang cache.
    for path in ("/", "/gioi-thieu/", first_product_path()):
        status, _, body = request(path)
        leaks = re.findall(r'/author/[^"/]+|"name":"admin"', body)
        check(f"HTML {path} không lộ user", status == 200 and not leaks, leaks[0] if leaks else f"HTTP {status}")

    for path in ("/author-sitemap.xml", "/wp-sitemap-users-1.xml"):
        status, _, body = request(path)
        check(f"{path} không liệt kê user", status != 200 or "/author/" not in body, f"HTTP {status}")


def clear_login_lockouts():
    """Mọi request local mang chung IP gateway Docker: không gỡ khoá thì anh cũng bị khoá theo."""
    code = (
        "foreach (array('lockouts', 'retries', 'retries_valid', 'logged') as $name) {"
        " delete_option('limit_login_' . $name); }"
        " wp_cache_flush();"
    )
    run("docker", "compose", "run", "--rm", "-T", "wpcli", "eval", code)


def check_login():
    section("Đăng nhập")
    clear_login_lockouts()
    try:
        run_login_checks()
    finally:
        clear_login_lockouts()


def run_login_checks():
    def login_error(username):
        data = urllib.parse.urlencode({"log": username, "pwd": uuid.uuid4().hex})
        _, _, body = request("/wp-login.php", data=data)
        match = re.search(r'id="login_error".*?</div>', body, re.S)
        text = re.sub(r"<[^>]+>", "", match.group(0) if match else "")
        return text.replace(username, "").strip()

    real = login_error("admin")
    fake = login_error("kio-khong-co-user-nay")
    check("Thông báo sai không tiết lộ user có tồn tại", bool(real) and real == fake, real[:60])
    clear_login_lockouts()

    def woo_login_error(username):
        _, headers, body = request("/tai-khoan/")
        match = re.search(r'name="woocommerce-login-nonce" value="([^"]+)"', body)
        cookies = "; ".join(c.split(";")[0] for c in headers.get_all("Set-Cookie") or [])
        data = urllib.parse.urlencode({
            "username": username, "password": uuid.uuid4().hex, "login": "1",
            "woocommerce-login-nonce": match.group(1) if match else "",
            "_wp_http_referer": "/tai-khoan/",
        })
        _, _, body = request("/tai-khoan/", data=data, headers={"Cookie": cookies})
        match = re.search(r'woocommerce-error.*?</ul>', body, re.S)
        return re.sub(r"<[^>]+>", "", match.group(0) if match else "").replace(username, "").strip()

    real = woo_login_error("admin")
    fake = woo_login_error("kio-khong-co-user-nay")
    check("Form tài khoản của Woo cũng không tiết lộ user", bool(real) and real == fake, real[:60])
    clear_login_lockouts()

    # Phải nằm sau kiểm tra thông báo: khi bị khoá thì trang không còn thông báo sai mật khẩu nữa.
    status, body = 0, ""
    for _ in range(8):
        data = urllib.parse.urlencode({"log": "kio-khong-co-user-nay", "pwd": uuid.uuid4().hex})
        status, _, body = request("/wp-login.php", data=data)
    check("Đăng nhập sai 8 lần liên tiếp bị chặn", "quá nhiều lần" in body, f"HTTP {status}")

    # Bị khoá rồi thì đổi IP khai báo trong header cũng không thoát được.
    data = urllib.parse.urlencode({"log": "kio-khong-co-user-nay", "pwd": uuid.uuid4().hex})
    _, _, body = request("/wp-login.php", data=data, headers={"X-Forwarded-For": "198.51.100.9"})
    check("Đổi X-Forwarded-For không thoát được khoá", "quá nhiều lần" in body)


def check_headers():
    section("Header")
    _, headers, _ = request("/")
    # Lần 1 có thể tạo cache, lần 2 mới là phản hồi lấy từ cache.
    request("/", headers={"Accept": "text/html"})
    _, cached, _ = request("/", headers={"Accept": "text/html"})
    check("Lấy được trang từ page cache để kiểm tra", bool(cached.get("X-Cache-Handler")))

    for name, expected in SECURITY_HEADERS.items():
        for label, response in (("trang thường", headers), ("trang cache", cached)):
            value = response.get(name)
            ok = bool(value) if expected is None else value == expected
            check(f"{name} có trên {label}", ok, value or "thiếu")

    server = headers.get("Server") or ""
    check("Server không lộ phiên bản", not re.search(r"\d", server), server)
    check("Không có X-Powered-By", not headers.get("X-Powered-By"), headers.get("X-Powered-By") or "")

    _, _, body = request("/")
    generators = re.findall(r'<meta name="generator" content="(?:WordPress|WooCommerce)[^"]*"', body)
    check("Không có thẻ generator WordPress, Woo", not generators, generators[0] if generators else "")
    check("Không có link RSD trỏ tới xmlrpc.php", 'rel="EditURI"' not in body)

    _, _, feed = request("/feed/")
    check("Feed không lộ phiên bản", "wordpress.org/?v=" not in feed)


def check_ports():
    section("Cổng Docker")
    for service, port in (("wordpress", 80), ("phpmyadmin", 80), ("mailpit", 8025)):
        bound = run("docker", "compose", "port", service, str(port)).strip()
        check(f"{service} chỉ nghe trên máy này", bound.startswith("127.0.0.1:"), bound or "không mở")
    for service, port in (("db", 3306), ("redis", 6379)):
        bound = run("docker", "compose", "port", service, str(port)).strip()
        check(f"{service} không mở ra ngoài", not bound or bound.endswith(":0"), bound or "không mở")


def check_wordpress_settings():
    section("Cấu hình WordPress")
    code = (
        "echo wp_json_encode(array("
        "'file_edit' => defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT,"
        "'app_passwords' => wp_is_application_passwords_available(),"
        "'comments' => get_option('default_comment_status'),"
        "'pings' => get_option('default_ping_status'),"
        "'register' => (int) get_option('users_can_register'),"
        "'two_factor' => class_exists('Two_Factor_Core') ? array_keys(Two_Factor_Core::get_providers()) : array(),"
        "'admin_2fa' => class_exists('Two_Factor_Core') && Two_Factor_Core::is_user_using_two_factor(1),"
        "));"
    )
    output = run("docker", "compose", "run", "--rm", "-T", "wpcli", "eval", code)
    match = re.search(r"\{.*\}", output)
    settings = json.loads(match.group(0)) if match else {}
    check("Đọc được cấu hình", bool(settings))
    check("Tắt sửa file trong admin", settings.get("file_edit") is True)
    check("Tắt Application Password", settings.get("app_passwords") is False)
    check("Bài mới không mở bình luận", settings.get("comments") == "closed", settings.get("comments"))
    check("Bài mới không nhận ping", settings.get("pings") == "closed", settings.get("pings"))
    check("Không cho tự đăng ký user WordPress", settings.get("register") == 0)
    check("2FA chỉ cho app tạo mã và mã dự phòng",
          sorted(settings.get("two_factor", [])) == ["Two_Factor_Backup_Codes", "Two_Factor_Totp"],
          ", ".join(settings.get("two_factor", [])))
    # Không tính đạt/không đạt: bản cài mới (CI) chưa có ai quét mã, việc này làm tay trên từng máy.
    print(f"  {'✓' if settings.get('admin_2fa') else '!'} admin "
          f"{'đã' if settings.get('admin_2fa') else 'CHƯA'} bật 2FA (không tính điểm)")


class Browser:
    """Phiên đăng nhập như trình duyệt: có cookie, đi theo chuyển hướng."""

    def __init__(self):
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar())
        )

    def open(self, path, data=None, headers=None, method=None):
        """Trả về (mã HTTP, URL cuối, nội dung)."""
        body = data.encode() if isinstance(data, str) else data
        req = urllib.request.Request(
            SITE + path, data=body, headers={"Accept": "*/*", **(headers or {})}, method=method
        )
        try:
            with self.opener.open(req, timeout=30) as r:
                return r.status, r.geturl(), r.read().decode(errors="replace")
        except urllib.error.HTTPError as e:
            return e.code, path, e.read().decode(errors="replace")

    def login(self, username, password):
        # Mở trang trước để có test cookie, nếu không WordPress báo lỗi dù đúng mật khẩu.
        self.open("/wp-login.php")
        data = urllib.parse.urlencode({
            "log": username, "pwd": password, "wp-submit": "1", "testcookie": "1",
            "redirect_to": SITE + "/wp-admin/",
        })
        return self.open("/wp-login.php", data=data)


# Trang admin → (Shop Manager vào được, Editor vào được).
ROLE_PAGES = {
    "plugins.php": (False, False),
    "options-general.php": (False, False),
    "options-general.php?page=wpvulnerability-options": (False, False),
    "admin.php?page=limit-login-attempts": (False, False),
    "admin.php?page=fluent_forms": (False, False),
    "users.php": (True, False),
    "edit.php?post_type=product": (True, False),
    "admin.php?page=wc-orders": (True, False),
    "edit.php?post_type=shop_coupon": (True, False),
    "admin.php?page=wc-settings": (True, False),
    "edit.php?post_type=kio_promotion": (True, False),
    "edit.php?post_type=kio_video": (True, False),
    "edit.php?post_type=page": (True, True),
    "post-new.php?post_type=page": (True, True),
    "post-new.php?post_type=kio_promotion": (True, False),
}

ROLE_SETUP = """
$password = '%s';
$ids = array();
foreach (array('shop_manager', 'editor', 'customer', 'contributor') as $role) {
    $ids[$role] = wp_insert_user(array(
        'user_login' => 'kio-check-' . $role, 'user_pass' => $password,
        'user_email' => 'kio-check-' . $role . '@example.test', 'role' => $role,
    ));
}
echo wp_json_encode(array(
    'ids' => $ids,
    'shop_edit_admin' => user_can($ids['shop_manager'], 'edit_user', 1),
    'shop_edit_customer' => user_can($ids['shop_manager'], 'edit_user', $ids['customer']),
    'shop_promote' => user_can($ids['shop_manager'], 'promote_users'),
    'editor_html' => user_can($ids['editor'], 'unfiltered_html'),
    'contributor_promotion' => user_can($ids['contributor'], get_post_type_object('kio_promotion')->cap->edit_posts),
));
"""

ROLE_CLEANUP = """
require_once ABSPATH . 'wp-admin/includes/user.php';
$role = get_user_by('login', 'kio-check-customer');
echo wp_json_encode(array('customer_roles' => $role ? array_values($role->roles) : array()));
foreach (get_users(array('search' => 'kio-check-*', 'search_columns' => array('user_login'))) as $user) {
    wp_delete_user($user->ID);
}
"""


def check_roles():
    section("Phân quyền (đăng nhập bằng user tạm)")
    password = uuid.uuid4().hex
    output = run("docker", "compose", "run", "--rm", "-T", "wpcli", "eval", ROLE_SETUP % password)
    match = re.search(r"\{.*\}", output)
    facts = json.loads(match.group(0)) if match else {}
    check("Tạo được user tạm", bool(facts))
    try:
        if facts:
            run_role_checks(password, facts)
    finally:
        output = run("docker", "compose", "run", "--rm", "-T", "wpcli", "eval", ROLE_CLEANUP)
    match = re.search(r"\{.*\}", output)
    roles = json.loads(match.group(0)).get("customer_roles") if match else None
    check("Sau khi thử leo quyền, khách vẫn là customer", roles == ["customer"], roles)


def run_role_checks(password, facts):
    check("Shop Manager không sửa được admin", not facts["shop_edit_admin"])
    check("Shop Manager sửa được khách hàng", facts["shop_edit_customer"])
    check("Shop Manager không đổi được vai trò", not facts["shop_promote"])
    check("Editor không chèn được script", not facts["editor_html"])
    check("Contributor không sửa được khuyến mãi", not facts["contributor_promotion"])

    shop, editor = Browser(), Browser()
    shop.login("kio-check-shop_manager", password)
    editor.login("kio-check-editor", password)

    for page, allowed in ROLE_PAGES.items():
        for browser, label, expected in ((shop, "Shop Manager", allowed[0]), (editor, "Editor", allowed[1])):
            status, url, _ = browser.open("/wp-admin/" + page)
            entered = status == 200 and "/wp-admin/" in url
            word = "vào được" if expected else "không vào được"
            check(f"{label} {word} {page}", entered == expected, f"HTTP {status}")

    # Thử thật: Editor lưu <script> vào trang. Trang này sẽ chạy trong trình duyệt của admin khi xem.
    _, _, nonce = editor.open("/wp-admin/admin-ajax.php?action=rest-nonce")
    rest = {"X-WP-Nonce": nonce.strip(), "Content-Type": "application/json"}
    status, _, body = editor.open(
        "/wp-json/wp/v2/pages?context=edit", headers=rest,
        data=json.dumps({"title": "kio-check", "status": "draft", "content": "<script>alert(1)</script>ok"}),
    )
    page = json.loads(body) if status == 201 else {}
    check("Editor tạo được trang", status == 201, f"HTTP {status}")
    check("<script> của Editor bị lược bỏ", "<script" not in page.get("content", {}).get("raw", "<script"))
    if page:
        editor.open(f"/wp-json/wp/v2/pages/{page['id']}?force=true", headers=rest, method="DELETE")

    customer = Browser()
    customer.login("kio-check-customer", password)
    _, url, _ = customer.open("/wp-admin/")
    check("Khách hàng không vào được wp-admin", "/wp-admin/" not in url, url.replace(SITE, ""))

    # Thử leo quyền thật: Shop Manager tự nâng khách hàng lên admin qua REST.
    _, _, nonce = shop.open("/wp-admin/admin-ajax.php?action=rest-nonce")
    status, _, _ = shop.open(
        f"/wp-json/wp/v2/users/{facts['ids']['customer']}",
        data=json.dumps({"roles": ["administrator"]}),
        headers={"X-WP-Nonce": nonce.strip(), "Content-Type": "application/json"},
    )
    check("REST từ chối Shop Manager nâng user lên admin", status in (401, 403), f"HTTP {status}")

    # Người đăng nhập không được làm bẩn page cache của khách.
    shop.open("/", headers={"Accept": "text/html"})
    _, headers, body = request("/", headers={"Accept": "text/html"})
    check("Trang cache của khách không có thanh admin", "wpadminbar" not in body)


def check_custom_code():
    section("Code tự viết")
    payload = urllib.parse.quote('"><svg/onload=kio1><script>kio2</script>')
    for path in (
        f"/?s={payload}&post_type=product",
        f"/cua-hang/?orderby={payload}",
        f"/cua-hang/?filter_product_brand={payload}&filtering=1",
        f"/cua-hang/?filter_{payload}=1&query_type_{payload}=or",
        f"/?wc-ajax=kio_product_row&category={payload}&orderby={payload}",
    ):
        _, _, body = request(path)
        check(f"Không phản chiếu XSS: {path[:28]}", "<svg/onload=kio1" not in body and "<script>kio2" not in body)

    # Tham số dạng mảng làm hàm chỉ nhận chuỗi chết với TypeError: khách gây được HTTP 500 và đầy log.
    status, _, _ = request("/?wc-ajax=kio_product_row&category[]=a&brand[]=1&limit[]=1&orderby[]=x")
    check("kio_product_row không chết với tham số mảng", status < 500, f"HTTP {status}")

    root = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    exposed = []
    for folder in ("wp-content/plugins/kio-store", "wp-content/themes/kio-child"):
        for parent, _, names in os.walk(os.path.join(root, folder)):
            for name in names:
                if name.endswith(".php"):
                    path = os.path.relpath(os.path.join(parent, name), root)
                    status, _, body = request("/" + path)
                    if body.strip() or status >= 500:
                        exposed.append(path)
    check("Gọi thẳng file PHP không in gì", not exposed, exposed[0] if exposed else "")


class StoreCart:
    """Giỏ hàng qua Store API, như JS của trang thanh toán."""

    def __init__(self):
        self.browser = Browser()
        self.headers = {"Content-Type": "application/json"}
        self.call("/cart")

    def call(self, path, body=None):
        req = urllib.request.Request(
            SITE + "/wp-json/wc/store/v1" + path,
            data=json.dumps(body).encode() if body is not None else None,
            headers={"Accept": "*/*", **self.headers},
        )
        try:
            with self.browser.opener.open(req, timeout=30) as r:
                status, headers, text = r.status, r.headers, r.read().decode()
        except urllib.error.HTTPError as e:
            status, headers, text = e.code, e.headers, e.read().decode()
        for name in ("Nonce", "Cart-Token"):
            if headers.get(name):
                self.headers[name] = headers[name]
        return status, json.loads(text)


ORDER_CLEANUP = """
foreach (wc_get_orders(array('billing_email' => 'kio-check@example.test', 'limit' => -1, 'status' => 'any')) as $order) {
    $order->delete(true);
}
"""


def delete_order(order_id):
    """Huỷ trước khi xoá để Woo trả lại tồn kho."""
    if order_id:
        run("docker", "compose", "run", "--rm", "-T", "wpcli", "eval",
            f"$o = wc_get_order({order_id}); $o->update_status('cancelled'); $o->delete(true);")


def check_store_api():
    section("Store API")
    _, products = StoreCart().call("/products?per_page=20&type=simple&stock_status=instock")
    product = next(p for p in products if p["is_purchasable"])
    real_price = product["prices"]["price"]

    cart = StoreCart()
    status, _ = cart.call("/cart/add-item", {"id": product["id"], "quantity": -5})
    check("Không thêm được số lượng âm", status == 400, f"HTTP {status}")
    _, body = cart.call("/cart/add-item", {"id": product["id"], "quantity": 1, "price": 1, "prices": {"price": "1"}})
    total = body.get("totals", {}).get("total_price")
    check("Không tự đặt được giá", total == real_price, f"{total} / {real_price}")

    address = {
        "first_name": "Kio Check", "last_name": "", "company": "", "address_1": "1 Le Loi", "address_2": "",
        "city": "Ninh Kieu", "state": "CAN-THO", "postcode": "", "country": "VN", "phone": "12345",
    }
    status, body = cart.call("/checkout", {
        "billing_address": {**address, "email": "kio-check@example.test"},
        "shipping_address": address,
        "payment_method": "cod",
    })
    check("Store API từ chối số điện thoại sai định dạng", status == 400, f"HTTP {status}")
    delete_order(body.get("order_id"))

    # Chiều ngược lại: chặn số sai mà chặn nhầm số đúng thì khách không mua được hàng.
    address["phone"] = "0901234567"
    status, body = cart.call("/checkout", {
        "billing_address": {**address, "email": "kio-check@example.test"},
        "shipping_address": address,
        "payment_method": "cod",
    })
    check("Store API vẫn nhận số điện thoại đúng", status == 200, f"HTTP {status}")
    delete_order(body.get("order_id"))

    # Lần checkout bị từ chối để lại đơn nháp, Woo dùng lại nó ở lần sau; xoá nốt nếu còn.
    run("docker", "compose", "run", "--rm", "-T", "wpcli", "eval", ORDER_CLEANUP)


def check_not_overblocked():
    section("Không chặn nhầm")
    for name, path in (
        ("Trang chủ", "/"),
        ("Trang đăng nhập", "/wp-login.php"),
        ("REST API", "/wp-json/"),
        ("Store API của Woo", "/wp-json/wc/store/v1/products?per_page=1"),
        ("REST flash sale", "/wp-json/kio/v1/flash-sale"),
        ("Sitemap sản phẩm", "/product-sitemap.xml"),
    ):
        status, _, _ = request(path)
        check(f"{name} vẫn dùng được", status == 200, f"HTTP {status}")

    _, _, body = request(first_product_path())
    check("Sản phẩm vẫn có mục đánh giá", 'id="reviews"' in body)

    status, body = with_temp_file("wp-content/uploads/kio-check.txt", "binh-thuong")
    check("File thường trong uploads/ vẫn tải được", status == 200 and "binh-thuong" in body, f"HTTP {status}")


def main():
    parser = argparse.ArgumentParser(description="Kiểm tra bảo mật site local")
    parser.add_argument("--save", metavar="TÊN", help="lưu kết quả vào docs/metrics/security-<tên>.json")
    args = parser.parse_args()

    status, _, _ = request("/")
    if status != 200:
        sys.exit(f"Site không phản hồi (HTTP {status}). Bật site trước khi kiểm tra.")

    check_code_execution()
    check_spoofed_ip()
    check_exposed_files()
    check_xmlrpc()
    check_user_enumeration()
    check_login()
    check_headers()
    check_ports()
    check_wordpress_settings()
    check_roles()
    check_custom_code()
    check_store_api()
    check_not_overblocked()

    failed = [r["name"] for r in results if not r["ok"]]
    print(f"\n{len(results) - len(failed)}/{len(results)} đạt.")

    if args.save:
        root = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
        path = os.path.join(root, "docs", "metrics", f"security-{args.save}.json")
        with open(path, "w", encoding="utf-8") as f:
            json.dump(results, f, ensure_ascii=False, indent=1)
        print(f"Đã lưu {path}")

    sys.exit(1 if failed else 0)


if __name__ == "__main__":
    main()
