#!/usr/bin/env bash
# Cài đặt và cấu hình WordPress bằng WP-CLI.
# Chạy lại nhiều lần được: bước nào đã làm rồi thì bỏ qua.
#
# Cách dùng: ./scripts/install.sh

# shellcheck source=scripts/_lib.sh
source "$(dirname "$0")/_lib.sh"

echo "==> Khởi động các container"
docker compose up -d --wait

echo "==> Chờ WordPress chép xong file core vào volume"
until docker compose exec -T wordpress test -f /var/www/html/wp-config.php; do sleep 2; done

# --- 1. Cài core ------------------------------------------------------------
if wp core is-installed 2>/dev/null; then
  echo "==> WordPress đã được cài, bỏ qua bước cài core"
else
  echo "==> Cài WordPress"
  wp core install \
    --url="$WP_URL" \
    --title="$WP_TITLE" \
    --admin_user="$WP_ADMIN_USER" \
    --admin_password="$WP_ADMIN_PASSWORD" \
    --admin_email="$WP_ADMIN_EMAIL" \
    --skip-email

  # Chỉ xoá nội dung mẫu ngay sau khi cài mới, để không đụng vào dữ liệu thật
  echo "==> Xoá nội dung mẫu"
  # Bài "Hello world!" (ID 1), "Sample Page" (ID 2), "Privacy Policy" nháp (ID 3)
  for id in 1 2 3; do
    wp post delete "$id" --force >/dev/null 2>&1 || true
  done
  wp comment delete 1 --force >/dev/null 2>&1 || true
fi

# --- 2. Ngôn ngữ, thời gian ---------------------------------------------------
echo "==> Cài tiếng Việt"
wp language core install vi --activate

echo "==> Múi giờ, định dạng ngày giờ"
wp option update timezone_string "Asia/Ho_Chi_Minh"
wp option update date_format "d/m/Y"
wp option update time_format "H:i"
wp option update start_of_week 1        # 1 = thứ Hai

# --- 3. Permalink -------------------------------------------------------------
echo "==> Permalink dạng /ten-bai-viet/"
# File .htaccess chứa rewrite rule đã có sẵn trong image wordpress:*-apache,
# nên chỉ cần lưu cấu trúc và làm mới rewrite rule trong database.
wp rewrite structure '/%postname%/'
wp rewrite flush

# --- 4. Plugin mặc định --------------------------------------------------------
echo "==> Gỡ plugin mặc định không dùng"
wp plugin delete hello akismet >/dev/null 2>&1 || true

# --- 5. Site demo: yêu cầu công cụ tìm kiếm không index ----------------------
wp option update blog_public 0

# --- 6. WooCommerce ------------------------------------------------------------
./scripts/setup-woocommerce.sh

# --- 7. Theme (Storefront + child theme kio-child) -----------------------------
./scripts/setup-theme.sh

# --- 8. Sản phẩm demo (data/products.json) -------------------------------------
# Lần đầu ~2 phút vì phải vẽ ảnh; chạy lại chỉ cập nhật, ảnh được dùng lại.
step "Nạp sản phẩm demo"
wp eval-file /scripts/import-products.php | tail -n +2 | wc -l | xargs printf '    %s sản phẩm\n'

# --- 8b. Khuyến mãi mẫu (Phase 4 · F4, data/promotions.json) --------------------
step "Thông số đầy đủ"
wp eval-file /scripts/import-specs.php

step "Ảnh chụp từ mô hình 3D"
wp eval-file /scripts/model-images.php

step "Sale demo tự gia hạn"
wp eval-file /scripts/demo-sales.php

step "Nạp khuyến mãi mẫu"
wp eval-file /scripts/import-promotions.php | tail -n +2 | wc -l | xargs printf '    %s khuyến mãi\n'

# --- 9. Plugin chức năng (data/plugins.json) + cấu hình ------------------------
# Chạy TRƯỚC bước nội dung theme: trang Liên hệ cần ID form do bước này tạo.
step "Plugin chức năng"
wp eval-file /scripts/setup-plugins.php
wp eval-file /scripts/plugins-settings.php

# --- 10. Nội dung theme: trang chủ, menu, trang chính sách, Customizer ---------
step "Nội dung theme (trang chủ, menu, footer, Customizer)"
wp eval-file /scripts/setup-theme-content.php

step "Cấu hình bảo mật"
wp eval-file /scripts/security-settings.php

echo
echo "Xong."
echo "  Cửa hàng:   $WP_URL/cua-hang/"
echo "  wp-admin:   $WP_URL/wp-admin (user: $WP_ADMIN_USER, mật khẩu trong .env)"
echo "  Mailpit:    http://localhost:8025"
echo "  phpMyAdmin: http://localhost:8081"
