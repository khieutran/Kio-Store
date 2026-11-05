<?php
/**
 * Plugin Name:       Kio Store
 * Description:       Tính năng riêng của cửa hàng Kio Mobile (project học WordPress).
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * Author:            Kio Mobile
 * Text Domain:       kio-store
 *
 * Khối comment ở trên là "plugin header": WordPress đọc nó để biết đây là một plugin
 * và hiển thị trong Plugins → Installed Plugins. "Requires Plugins" (từ WP 6.5)
 * khiến plugin này không kích hoạt được nếu WooCommerce chưa bật.
 *
 * @package KioStore
 */

// Chặn truy cập trực tiếp file qua URL (chỉ cho chạy khi WordPress nạp plugin).
defined( 'ABSPATH' ) || exit;

define( 'KIO_STORE_DIR', plugin_dir_path( __FILE__ ) );
define( 'KIO_STORE_URL', plugin_dir_url( __FILE__ ) );

/**
 * Version của file CSS/JS theo thời gian sửa file.
 *
 * Trình duyệt giữ CSS/JS tới 1 năm (docker/apache/kio-performance.conf), nên URL phải đổi mỗi khi
 * file đổi. Số phiên bản cố định sẽ khiến khách chạy mãi bản cũ sau khi sửa code.
 *
 * @param string $path Đường dẫn file, tính từ thư mục plugin.
 * @return string
 */
function kio_store_asset_version( string $path ): string {
	return (string) filemtime( KIO_STORE_DIR . $path );
}

// Mỗi tính năng một file trong includes/.
require_once KIO_STORE_DIR . 'includes/local-mail.php';
require_once KIO_STORE_DIR . 'includes/dev-metrics.php';
require_once KIO_STORE_DIR . 'includes/product-labels.php';
require_once KIO_STORE_DIR . 'includes/installment.php';
require_once KIO_STORE_DIR . 'includes/product-specs.php';
require_once KIO_STORE_DIR . 'includes/promotion.php';
require_once KIO_STORE_DIR . 'includes/product-loop.php';
require_once KIO_STORE_DIR . 'includes/flash-sale.php';
require_once KIO_STORE_DIR . 'includes/product-row.php';
require_once KIO_STORE_DIR . 'includes/sticky-buy-bar.php';
require_once KIO_STORE_DIR . 'includes/checkout-vn.php';
require_once KIO_STORE_DIR . 'includes/coupon-max-discount.php';
require_once KIO_STORE_DIR . 'includes/video-reels.php';
require_once KIO_STORE_DIR . 'includes/ajax-add-to-cart.php';
require_once KIO_STORE_DIR . 'includes/performance.php';
require_once KIO_STORE_DIR . 'includes/page-cache.php';
