<?php
/**
 * Hàm dùng chung: in một lưới sản phẩm theo danh sách ID, đúng markup vòng lặp của WooCommerce.
 *
 * Dùng template content-product.php của Woo (qua theme) nên mọi hook của thẻ sản phẩm vẫn chạy:
 * nhãn (F1), trả góp (F2), thông số (F3), nút yêu thích/so sánh (Phase 3)… Đây cũng là cách
 * WC_Shortcode_Products của Woo tự in lưới.
 *
 * Dùng bởi: F5 Flash Sale, F6 dải sản phẩm trang chủ.
 *
 * @package KioStore
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', 'kio_store_register_carousel' );

/**
 * Đăng ký JS băng chuyền. Chỉ đăng ký, chưa nạp: khối nào cần thì khai báo phụ thuộc hoặc enqueue.
 *
 * @return void
 */
function kio_store_register_carousel(): void {
	wp_register_script(
		'kio-store-carousel',
		KIO_STORE_URL . 'assets/js/carousel.js',
		array(),
		kio_store_asset_version( 'assets/js/carousel.js' ),
		array(
			'in_footer' => true,
			'strategy'  => 'defer',
		)
	);
}

/**
 * Trả về HTML lưới sản phẩm.
 *
 * @param int[]  $ids     ID sản phẩm theo đúng thứ tự cần hiển thị.
 * @param string $name    Tên vòng lặp (wc_get_loop_prop('name')), để plugin/CSS phân biệt.
 * @param int    $columns Số cột.
 * @return string HTML.
 */
function kio_store_product_loop_html( array $ids, string $name, int $columns = 5 ): string {
	if ( empty( $ids ) ) {
		return '';
	}

	// Nạp TRƯỚC dữ liệu bài viết + meta + term của cả danh sách bằng vài query gộp. Không có bước
	// này thì mỗi thẻ sản phẩm lại query riêng post/meta/term (lỗi "N+1 query"): đo được dải 5
	// sản phẩm tốn 66 query so với 47 của shortcode [products] (Woo cũng gọi đúng hàm này).
	_prime_post_caches( $ids, true, true );

	ob_start();

	wc_setup_loop(
		array(
			'columns'      => $columns,
			'name'         => $name,
			'is_shortcode' => true,
		)
	);
	woocommerce_product_loop_start();

	foreach ( $ids as $product_id ) {
		$GLOBALS['post'] = get_post( $product_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- theo đúng pattern của WC_Shortcode_Products.
		setup_postdata( $GLOBALS['post'] );
		wc_get_template_part( 'content', 'product' );
	}
	wp_reset_postdata();

	woocommerce_product_loop_end();
	wc_reset_loop();

	return (string) ob_get_clean();
}
