#!/usr/bin/env bash
# Sao lưu database, uploads, wp-config.php. Chạy bên trong service "backup" (docker-compose.yml).
#
#   docker compose exec backup bash /scripts/backup.sh            # sao lưu ngay
#   docker compose exec backup bash /scripts/backup.sh --if-due   # chỉ chạy nếu bản gần nhất đã cũ (service tự gọi mỗi giờ)
#   docker compose exec backup bash /scripts/backup.sh --health   # thoát 1 nếu lần trước lỗi hoặc đã lâu không có bản mới
#
# Không có plugin, theme: phiên bản cố định trong repo, install.sh cài lại được. Không có cache, Redis:
# tự sinh lại. Không có .env: giữ riêng, bản sao lưu không tự khôi phục được mật khẩu DB.
#
# Thư mục backups/ chứa dữ liệu khách và mã băm mật khẩu: nằm ngoài thư mục web, không vào git, chỉ
# chủ sở hữu đọc được (umask 077). Mật khẩu DB đi qua biến MYSQL_PWD, không nằm trên dòng lệnh.

# -E: trap ERR chạy cả khi lỗi nằm trong hàm. Thiếu nó thì sao lưu hỏng mà không báo gì.
set -Eeuo pipefail
umask 077

backup_dir=/backups
keep=${BACKUP_KEEP:-7}
every_hours=${BACKUP_EVERY_HOURS:-24}
failure_file=$backup_dir/LAST_FAILURE

latest() {
  find "$backup_dir" -mindepth 1 -maxdepth 1 -type d -name '20*' | sort | tail -n 1
}

# Bản gần nhất cũ hơn số giờ cho trước? Chưa có bản nào thì coi như cũ.
older_than() {
  local newest
  newest=$(latest)
  [[ -z "$newest" ]] || [[ -n "$(find "$newest" -maxdepth 0 -mmin +$(($1 * 60)))" ]]
}

health() {
  if [[ -f "$failure_file" ]]; then
    cat "$failure_file"
    exit 1
  fi
  # Bản cài mới chưa có bản sao lưu nào: chưa tới lượt, không phải lỗi.
  if [[ -n "$(latest)" ]] && older_than $((every_hours * 2)); then
    echo "Không có bản sao lưu mới trong $((every_hours * 2)) giờ"
    exit 1
  fi
}

sql_count() {
  mysql -h "$DB_HOST" -u"$DB_USER" -N "$DB_NAME" -e "$1"
}

verify() {
  local dir=$1 tables
  gzip -t "$dir/database.sql.gz"
  # mysqldump chết giữa chừng thì không có dòng này: file vẫn giải nén được nhưng thiếu bảng.
  # Không dùng grep -q sau dấu |: grep thoát sớm làm zcat chết vì SIGPIPE, pipefail coi là lỗi.
  [[ "$(zcat "$dir/database.sql.gz" | tail -n 1)" == *'Dump completed'* ]]
  tables=$(zcat "$dir/database.sql.gz" | grep -c '^CREATE TABLE')
  [[ "$tables" -gt 0 ]]
  [[ "$(zcat "$dir/database.sql.gz" | grep -c '^CREATE TABLE .wp_wc_orders. (')" -eq 1 ]]
  tar -tzf "$dir/uploads.tar.gz" > /dev/null
  [[ -s "$dir/wp-config.php" ]]
}

backup() {
  local stamp tmp final
  stamp=$(date -u +%Y%m%d-%H%M%S)
  tmp=$backup_dir/.tmp-$stamp
  final=$backup_dir/$stamp

  rm -rf "$backup_dir"/.tmp-*
  mkdir -p "$tmp"

  # --single-transaction: chụp DB tại một thời điểm mà không khoá bảng, khách vẫn mua hàng được.
  # --no-tablespaces: user của WordPress không có quyền PROCESS.
  mysqldump -h "$DB_HOST" -u"$DB_USER" --single-transaction --no-tablespaces --hex-blob \
    --default-character-set=utf8mb4 "$DB_NAME" | gzip > "$tmp/database.sql.gz"

  # wc-logs là log của Woo, có thể chứa dữ liệu khách, không cần để khôi phục.
  tar -C /wp -czf "$tmp/uploads.tar.gz" --exclude='wp-content/uploads/wc-logs' wp-content/uploads

  # Có salt: thiếu file này thì mọi phiên đăng nhập mất hiệu lực sau khi khôi phục.
  cp /wp/wp-config.php "$tmp/wp-config.php"

  verify "$tmp"

  # Số liệu để đối chiếu sau khi khôi phục.
  {
    echo "created_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    echo "products=$(sql_count "SELECT COUNT(*) FROM wp_posts WHERE post_type = 'product' AND post_status = 'publish'")"
    echo "orders=$(sql_count "SELECT COUNT(*) FROM wp_wc_orders WHERE type = 'shop_order'")"
    echo "users=$(sql_count 'SELECT COUNT(*) FROM wp_users')"
    echo "reviews=$(sql_count "SELECT COUNT(*) FROM wp_comments WHERE comment_type = 'review'")"
    echo "upload_files=$(tar -tzf "$tmp/uploads.tar.gz" | grep -vc '/$')"
  } > "$tmp/manifest.txt"
  (cd "$tmp" && sha256sum database.sql.gz uploads.tar.gz wp-config.php manifest.txt > SHA256SUMS)

  # Chỉ đổi tên khi mọi thứ đã kiểm tra xong: thư mục có tên ngày tháng luôn là bản dùng được.
  mv "$tmp" "$final"
  rm -f "$failure_file"

  # Dọn bản cũ sau khi đã có bản mới, không dọn trước.
  find "$backup_dir" -mindepth 1 -maxdepth 1 -type d -name '20*' | sort -r | tail -n +$((keep + 1)) | xargs -r rm -rf

  echo "Đã sao lưu $stamp ($(du -sh "$final" | cut -f1))"
}

on_error() {
  rm -rf "$backup_dir"/.tmp-*
  echo "Sao lưu lỗi lúc $(date -u +%Y-%m-%dT%H:%M:%SZ)" | tee "$failure_file" >&2
}

case "${1:-}" in
  --health) health ;;
  --if-due)
    if older_than "$every_hours"; then
      trap on_error ERR
      backup
    fi
    ;;
  *)
    trap on_error ERR
    backup
    ;;
esac
