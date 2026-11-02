#!/usr/bin/env bash
# Phase 0: tạo nội dung mẫu để tìm hiểu WordPress lưu dữ liệu thế nào.
# Tạo: 1 chuyên mục, 1 bài viết (có thẻ, ảnh đại diện, 2 revision), 1 trang, 1 menu.
#
# Cách dùng: ./scripts/phase0-sample-content.sh

# shellcheck source=scripts/_lib.sh
source "$(dirname "$0")/_lib.sh"

if [[ -n "$(wp post list --post_type=page --name=gioi-thieu --field=ID)" ]]; then
  echo "Nội dung mẫu đã có, bỏ qua."; exit 0
fi

echo "==> Chuyên mục"
CAT_ID=$(wp term create category "Tin công nghệ" --slug=tin-cong-nghe --porcelain)

echo "==> Bài viết (Post)"
POST_ID=$(wp post create \
  --post_type=post --post_status=publish \
  --post_title="Chào mừng đến với Kio Mobile" \
  --post_name=chao-mung-kio-mobile \
  --post_content="<!-- wp:paragraph --><p>Bản nháp đầu tiên.</p><!-- /wp:paragraph -->" \
  --post_category="$CAT_ID" \
  --tags_input="khai-truong" \
  --porcelain)

echo "==> Ảnh đại diện (tạo ảnh PNG bằng PHP GD rồi import vào Media Library)"
# Mỗi lệnh wp chạy trong một container mới (--rm), nên file tạm phải nằm trong
# volume dùng chung /var/www/html, không dùng /tmp.
TMP_IMG=/var/www/html/wp-content/kio-cover.png
# Nháy đơn là cố ý: $im là biến PHP, bash không được thay giá trị ($TMP_IMG thì chèn riêng).
# shellcheck disable=SC2016
wp eval '
  $im = imagecreatetruecolor(1200, 630);
  imagefill($im, 0, 0, imagecolorallocate($im, 13, 71, 161));
  imagestring($im, 5, 520, 300, "Kio Mobile", imagecolorallocate($im, 255, 255, 255));
  imagepng($im, "'"$TMP_IMG"'");
'
wp media import "$TMP_IMG" --post_id="$POST_ID" --title="Ảnh bìa Kio Mobile" --featured_image >/dev/null
wp eval "unlink('$TMP_IMG');"

echo "==> Sửa bài 2 lần để sinh revision"
wp post update "$POST_ID" --post_content="<!-- wp:paragraph --><p>Bản sửa lần 1.</p><!-- /wp:paragraph -->" >/dev/null
wp post update "$POST_ID" --post_content="<!-- wp:paragraph --><p>Kio Mobile chính thức khai trương. Bản sửa lần 2.</p><!-- /wp:paragraph -->" >/dev/null

echo "==> Trang (Page)"
PAGE_ID=$(wp post create \
  --post_type=page --post_status=publish \
  --post_title="Giới thiệu" --post_name=gioi-thieu \
  --post_content="<!-- wp:paragraph --><p>Kio Mobile là cửa hàng demo bán điện thoại và đồ điện tử.</p><!-- /wp:paragraph -->" \
  --porcelain)

echo "==> Menu (classic menu: lưu thành các post kiểu nav_menu_item)"
wp menu create "Menu chính" >/dev/null
wp menu item add-post menu-chinh "$PAGE_ID" >/dev/null
wp menu item add-term menu-chinh category "$CAT_ID" >/dev/null

echo
echo "Bài viết: $(wp post get "$POST_ID" --field=url)"
echo "Trang:    $(wp post get "$PAGE_ID" --field=url)"
