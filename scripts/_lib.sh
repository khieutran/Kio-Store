# Hàm dùng chung cho các script. Không chạy trực tiếp, dùng: source "$(dirname "$0")/_lib.sh"

set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

# Nạp biến từ .env (DB_*, WP_*)
set -a; source .env; set +a

# Gọi WP-CLI trong container.
#   --progress quiet: ẩn log "Container ... Creating"
#   --rm: xoá container sau khi chạy xong;  -T: không cần terminal tương tác
wp() { docker compose --progress quiet run --rm -T wpcli "$@"; }

step() { echo "==> $*"; }

# Chạy SQL bằng mysql client trong container db.
# Không dùng "wp db query": WP-CLI dùng client MariaDB, không hỗ trợ kiểu xác thực
# caching_sha2_password mặc định của MySQL 8.4.
sql() {
  docker compose exec -T -e MYSQL_PWD="$DB_PASSWORD" db \
    mysql --default-character-set=utf8mb4 -u"$DB_USER" "$DB_NAME" --table -e "$1"
}
