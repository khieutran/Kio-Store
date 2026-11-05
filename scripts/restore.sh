#!/usr/bin/env bash
# Khôi phục site từ một bản trong backups/. ĐÈ database và uploads hiện tại.
#
#   ./scripts/restore.sh backups/20261105-080814        # hỏi xác nhận
#   ./scripts/restore.sh backups/20261105-080814 -y     # không hỏi
#
# Máy mới: cp .env (bản tự giữ), chạy ./scripts/install.sh để có đủ plugin, rồi chạy script này.
# Xong việc so số liệu với manifest.txt, lệch thì thoát mã 1.

backup_dir=$(cd "${1:?Thiếu đường dẫn bản sao lưu}" && pwd)
answer=${2:-}

# shellcheck source=scripts/_lib.sh
source "$(dirname "$0")/_lib.sh"
set -E
trap 'echo "Khôi phục DỞ DANG: site có thể đang tắt hoặc thiếu dữ liệu. Sửa lỗi rồi chạy lại." >&2' ERR

check_sums() {
  cd "$backup_dir"
  if command -v sha256sum > /dev/null; then
    sha256sum --quiet -c SHA256SUMS
  else
    shasum -a 256 --quiet -c SHA256SUMS
  fi
  cd - > /dev/null
}

count() {
  docker compose exec -T -e MYSQL_PWD="$DB_PASSWORD" db mysql -N -u"$DB_USER" "$DB_NAME" -e "$1"
}

expected() {
  grep "^$1=" "$backup_dir/manifest.txt" | cut -d= -f2
}

compare() {
  local actual
  actual=$2
  if [[ "$actual" == "$(expected "$1")" ]]; then
    printf '    %-14s %s\n' "$1" "$actual"
  else
    printf '    %-14s %s, bản sao lưu ghi %s\n' "$1" "$actual" "$(expected "$1")" >&2
    mismatch=1
  fi
}

step "Kiểm tra bản sao lưu"
check_sums
cat "$backup_dir/manifest.txt"

if [[ "$answer" != "-y" ]]; then
  read -r -p "Đè database và uploads của project \"${COMPOSE_PROJECT_NAME:-kio-store}\" bằng bản này? [y/N] " reply
  [[ "$reply" =~ ^[Yy]$ ]] || { echo "Đã huỷ."; exit 1; }
fi

started=$SECONDS

# Dừng để không có đơn hàng mới ghi vào lúc đang thay dữ liệu. db và redis vẫn chạy.
step "Dừng site"
docker compose stop wordpress cron backup

# Xoá hẳn database: dump chỉ DROP các bảng nó có, bảng mới tạo sau này sẽ còn sót lại.
step "Khôi phục database"
docker compose exec -T -e MYSQL_PWD="$DB_ROOT_PASSWORD" db mysql -uroot \
  -e "DROP DATABASE \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
gzip -dc "$backup_dir/database.sql.gz" |
  docker compose exec -T -e MYSQL_PWD="$DB_ROOT_PASSWORD" db mysql --default-character-set=utf8mb4 -uroot "$DB_NAME"

step "Khôi phục uploads, wp-config.php"
docker compose --progress quiet run --rm -T --no-deps --entrypoint sh wordpress -c \
  'find wp-content/uploads -mindepth 1 -delete; tar -xzf - -C /var/www/html; chown -R www-data:www-data wp-content/uploads' \
  < "$backup_dir/uploads.tar.gz"
docker compose --progress quiet run --rm -T --no-deps --entrypoint sh wordpress -c \
  'cat > wp-config.php; chown www-data:www-data wp-config.php' \
  < "$backup_dir/wp-config.php"

# Cache còn dữ liệu trước khi khôi phục: Redis giữ option, sản phẩm cũ; page cache giữ HTML cũ.
step "Xoá cache"
docker compose exec -T redis redis-cli FLUSHALL > /dev/null
docker compose --progress quiet run --rm -T --no-deps --entrypoint sh wordpress -c \
  'rm -rf wp-content/cache/cache-enabler'

step "Bật site"
docker compose up -d --wait wordpress cron backup

step "Đối chiếu với manifest.txt"
mismatch=0
compare products "$(count "SELECT COUNT(*) FROM wp_posts WHERE post_type = 'product' AND post_status = 'publish'")"
compare orders "$(count "SELECT COUNT(*) FROM wp_wc_orders WHERE type = 'shop_order'")"
compare users "$(count 'SELECT COUNT(*) FROM wp_users')"
compare reviews "$(count "SELECT COUNT(*) FROM wp_comments WHERE comment_type = 'review'")"
compare upload_files "$(docker compose exec -T wordpress sh -c "find wp-content/uploads -type f -not -path 'wp-content/uploads/wc-logs/*' | wc -l" | tr -d ' ')"

echo "Khôi phục trong $((SECONDS - started)) giây."
exit "$mismatch"
