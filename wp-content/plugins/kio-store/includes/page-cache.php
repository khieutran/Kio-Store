<?php
/**
 * Phase 5: xoá page cache đúng lúc.
 *
 * Cache Enabler tự xoá khi lưu bài viết. Đoạn này bổ sung những việc đổi giá và tồn kho mà nó không
 * thấy: lưu biến thể, tồn kho đổi sau đơn hàng, và lúc Flash Sale hết hạn (giá đổi theo thời gian,
 * không có ai bấm lưu). Thanh toán luôn tính giá từ server, cache cũ chỉ làm trang hiện giá cũ.
 *
 * Cấu hình của plugin nằm ở scripts/plugins-settings.php.
 *
 * @package KioStore
 */

defined( 'ABSPATH' ) || exit;

add_action( 'woocommerce_update_product', 'kio_store_clear_page_cache' );
add_action( 'woocommerce_update_product_variation', 'kio_store_clear_page_cache' );
add_action( 'woocommerce_product_set_stock', 'kio_store_clear_page_cache' );
add_action( 'woocommerce_variation_set_stock', 'kio_store_clear_page_cache' );
add_action( 'kio_store_flash_sale_ended', 'kio_store_clear_page_cache' );

/**
 * Xoá cache cả site. Danh mục nhỏ và một sản phẩm hiện ở nhiều trang nên không xoá từng trang.
 *
 * @return void
 */
function kio_store_clear_page_cache(): void {
	do_action( 'cache_enabler_clear_site_cache' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook của Cache Enabler.
}

/**
 * Hẹn xoá cache đúng lúc Flash Sale kết thúc.
 *
 * @param int $ends_at Thời điểm kết thúc (Unix timestamp).
 * @return void
 */
function kio_store_schedule_flash_sale_cache_clear( int $ends_at ): void {
	if ( $ends_at > time() && ! wp_next_scheduled( 'kio_store_flash_sale_ended', array( $ends_at ) ) ) {
		wp_schedule_single_event( $ends_at, 'kio_store_flash_sale_ended', array( $ends_at ) );
	}
}
