#!/usr/bin/env bash
# Cài và cấu hình WooCommerce cho Kio Mobile. Chạy lặp lại được.
#
# Cách dùng: ./scripts/setup-woocommerce.sh

source "$(dirname "$0")/_lib.sh"

# --- 1. Cài WooCommerce -------------------------------------------------------
if ! wp plugin is-installed woocommerce; then
  step "Tải WooCommerce"
  wp plugin install woocommerce
fi

# Cài bản dịch TRƯỚC khi kích hoạt: lúc kích hoạt lần đầu, Woo tạo các trang
# Cửa hàng/Giỏ hàng/Thanh toán/Tài khoản với tiêu đề và slug theo ngôn ngữ đang dùng.
step "Cài bản dịch tiếng Việt cho WooCommerce"
wp language plugin install woocommerce vi

if ! wp plugin is-active woocommerce; then
  step "Kích hoạt WooCommerce"
  wp plugin activate woocommerce
fi

# HPOS (High-Performance Order Storage): lưu đơn hàng vào bảng riêng wp_wc_orders
# thay vì wp_posts. Woo chỉ tự bật khi cài qua wp-admin, cài bằng CLI phải bật tay.
step "Bật HPOS"
wp wc hpos enable >/dev/null 2>&1 || true
# Lấy kết quả vào biến rồi mới cắt dòng đầu. Dùng "| head -1" sẽ đóng pipe sớm,
# gây lỗi "broken pipe" và làm script dừng do set -o pipefail.
hpos_status=$(wp wc hpos status)
echo "    ${hpos_status%%$'\n'*}"

# Plugin tự viết. Kích hoạt sau Woo (vì "Requires Plugins: woocommerce") và TRƯỚC khi
# bật cổng thanh toán ở mục 4: Woo gửi email cảnh báo khi bật cổng thanh toán, cần
# plugin này để email đi vào Mailpit thay vì báo lỗi sendmail.
step "Kích hoạt plugin kio-store"
wp plugin activate kio-store

# --- 2. Cấu hình cửa hàng cho Việt Nam ----------------------------------------
# Toàn bộ cài đặt nằm trong woo-settings.php, chạy một lần trong một container
# (nhanh hơn nhiều so với gọi "wp option update" cho từng option).
step "Cấu hình cửa hàng cho Việt Nam"
wp eval-file /scripts/woo-settings.php

# --- 3. Danh mục, thương hiệu, thuộc tính --------------------------------------
# Dữ liệu ở data/taxonomies.json, logic ở woo-taxonomies.php
step "Danh mục, thương hiệu, thuộc tính"
wp eval-file /scripts/woo-taxonomies.php

# --- 4. Giao hàng, thanh toán, coupon --------------------------------------------
step "Giao hàng và thanh toán"
wp eval-file /scripts/woo-shipping-payments.php
step "Coupon (data/coupons.json)"
wp eval-file /scripts/woo-coupons.php
