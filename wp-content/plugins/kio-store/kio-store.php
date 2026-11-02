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
 */

// Chặn truy cập trực tiếp file qua URL (chỉ cho chạy khi WordPress nạp plugin)
defined( 'ABSPATH' ) || exit;

define( 'KIO_STORE_VERSION', '0.1.0' );
define( 'KIO_STORE_DIR', plugin_dir_path( __FILE__ ) );

// Mỗi tính năng một file trong includes/
require_once KIO_STORE_DIR . 'includes/local-mail.php';
