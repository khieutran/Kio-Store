#!/usr/bin/env bash
# Đánh giá một plugin ứng viên (Phase 3), tự động và lặp lại được:
#   1. Ghi trạng thái DB trước khi cài (số bảng, số option autoload)
#   2. Cài + kích hoạt đúng phiên bản, CHƯA cấu hình gì (đo "giá" mặc định của plugin)
#   3. Đo hiệu năng 3 trang (scripts/measure.py) và so với mốc "baseline"
#   4. Kiểm tra tương thích HPOS theo khai báo với WooCommerce
#   5. Gỡ hẳn plugin, xem còn để lại bảng/option không
#
# Cách dùng: ./scripts/eval-plugin.sh <slug> <phiên-bản> [--keep]
#   --keep: không gỡ plugin sau khi đo (khi đã chốt dùng plugin này)

# shellcheck source=scripts/_lib.sh
source "$(dirname "$0")/_lib.sh"

slug=${1:?Thiếu slug plugin}
version=${2:?Thiếu phiên bản}
keep=${3:-}

# Danh sách bảng, mỗi dòng một tên (không khung, không tiêu đề)
tables() {
  docker compose exec -T -e MYSQL_PWD="$DB_PASSWORD" db mysql -N -u"$DB_USER" "$DB_NAME" -e "SHOW TABLES"
}

# Danh sách tên option (bỏ transient: tự hết hạn, không phải rác lâu dài)
options() {
  docker compose exec -T -e MYSQL_PWD="$DB_PASSWORD" db mysql -N -u"$DB_USER" "$DB_NAME" \
    -e "SELECT option_name FROM wp_options WHERE option_name NOT LIKE '%transient%' ORDER BY option_name"
}

db_state() {
  sql "SELECT
         (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()) AS bang,
         (SELECT COUNT(*) FROM wp_options WHERE autoload IN ('yes','on','auto-on')) AS option_autoload,
         (SELECT ROUND(SUM(LENGTH(option_value))/1024,1) FROM wp_options WHERE autoload IN ('yes','on','auto-on')) AS autoload_kb,
         (SELECT COUNT(*) FROM wp_options) AS option_tong" | tail -n +4 | head -1
}

step "Trạng thái DB trước khi cài: $(db_state)"
before_tables=$(tables)
before_options=$(options)

step "Cài $slug $version"
wp plugin install "$slug" --version="$version" --activate --force 2>&1 | tail -1
step "Trạng thái DB sau khi cài:   $(db_state)"
new_tables=$(comm -13 <(sort <<<"$before_tables") <(tables | sort))
step "Bảng mới: ${new_tables:-(không có)}" | tr '\n' ' '; echo

step "Tương thích HPOS (khai báo với WooCommerce)"
wp eval "
  \$fc = wc_get_container()->get( \Automattic\WooCommerce\Internal\Features\FeaturesController::class );
  \$r  = \$fc->get_compatible_plugins_for_feature( 'custom_order_tables', true );
  \$found = false;
  foreach ( array( 'compatible', 'incompatible', 'uncertain' ) as \$k ) {
    foreach ( \$r[ \$k ] as \$file ) { if ( 0 === strpos( \$file, '$slug/' ) ) { echo '    ', \$k, PHP_EOL; \$found = true; } }
  }
  if ( ! \$found ) { echo '    không khai báo (plugin không dùng tới đơn hàng Woo hoặc không khai báo)', PHP_EOL; }"

step "Đo hiệu năng (chưa cấu hình)"
python3 -I scripts/measure.py --save "plugin-$slug" > /dev/null
python3 -I scripts/measure.py --compare baseline "plugin-$slug"

if [[ "$keep" != "--keep" ]]; then
  step "Gỡ plugin"
  wp plugin deactivate "$slug" --uninstall > /dev/null 2>&1 || wp plugin delete "$slug" > /dev/null
  wp plugin is-installed "$slug" && wp plugin delete "$slug" > /dev/null
  step "Trạng thái DB sau khi gỡ:    $(db_state)"
  left=$(comm -13 <(sort <<<"$before_tables") <(tables | sort))
  step "Bảng còn sót sau khi gỡ: ${left:-(không có)}" | tr '\n' ' '; echo
  left_opts=$(comm -13 <(sort <<<"$before_options") <(options | sort))
  step "Option còn sót sau khi gỡ: ${left_opts:-(không có)}" | tr '\n' ' '; echo
fi
