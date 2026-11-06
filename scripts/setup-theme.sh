#!/usr/bin/env bash
# Cài theme cha Storefront (cố định phiên bản), kích hoạt child theme kio-child
# và xoá theme mặc định không dùng. Chạy lặp lại được.
#
# Cách dùng: ./scripts/setup-theme.sh

# shellcheck source=scripts/_lib.sh
source "$(dirname "$0")/_lib.sh"

# Phiên bản Storefront cố định (cùng lý do cố định WOO_VERSION trong setup-woocommerce.sh).
STORE_VERSION=4.6.2

# --- 1. Cài theme cha -----------------------------------------------------------
if ! wp theme is-installed storefront; then
  step "Tải Storefront $STORE_VERSION"
  wp theme install storefront --version="$STORE_VERSION"
fi

# --- 2. Kích hoạt child theme (kio-child khai báo Template: storefront) ----------
step "Kích hoạt child theme kio-child"
wp theme activate kio-child

# Chữ của Storefront (thanh đáy mobile...). Không có thì site hiện tiếng Anh.
wp language theme install storefront vi

# --- 3. Xoá theme mặc định không dùng -------------------------------------------
step "Xoá theme mặc định không dùng"
for t in twentytwentyfive twentytwentyfour twentytwentythree twentytwentytwo twentytwentyone twentytwenty; do
  wp theme delete "$t" >/dev/null 2>&1 || true
done

# Ghi rõ đang dùng theme nào (xác nhận kio-child hoạt động, không bị "broken").
active_theme=$(wp option get stylesheet)
echo "    Theme đang dùng: $active_theme (cha: $(wp option get template))"
