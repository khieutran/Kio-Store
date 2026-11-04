<?php
/**
 * Nút mua trên trang sản phẩm: thêm vào giỏ không tải lại trang.
 *
 * Không tự viết logic thêm vào giỏ. Woo gắn WC_Form_Handler::add_to_cart_action vào hook wp_loaded,
 * chạy với MỌI request có tham số add-to-cart, nên JS chỉ việc gửi nguyên form tới wc-ajax. Tới lúc
 * endpoint này chạy (template_redirect, sau wp_loaded), Woo đã kiểm tra biến thể, tồn kho, số lượng
 * và ghi kết quả vào thông báo; endpoint chỉ đọc thông báo rồi trả về cho JS.
 *
 * @package KioStore
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', 'kio_store_ajax_add_to_cart_assets' );
add_action( 'wc_ajax_kio_add_to_cart', 'kio_store_ajax_add_to_cart' );

/**
 * Nạp JS ở trang sản phẩm.
 *
 * Cửa hàng bật "chuyển tới giỏ hàng sau khi thêm" thì không can thiệp: đó là cài đặt chủ shop chọn.
 *
 * @return void
 */
function kio_store_ajax_add_to_cart_assets(): void {
	if ( ! is_product() || 'yes' === get_option( 'woocommerce_cart_redirect_after_add' ) ) {
		return;
	}

	wp_enqueue_script(
		'kio-store-ajax-add-to-cart',
		KIO_STORE_URL . 'assets/js/ajax-add-to-cart.js',
		array( 'jquery' ),
		KIO_STORE_VERSION,
		array(
			'in_footer' => true,
			'strategy'  => 'defer',
		)
	);
	wp_localize_script( 'kio-store-ajax-add-to-cart', 'kioAddToCart', array( 'endpoint' => WC_AJAX::get_endpoint( 'kio_add_to_cart' ) ) );
}

/**
 * ACTION wc_ajax_kio_add_to_cart: trả kết quả của lần thêm vào giỏ Woo vừa xử lý.
 *
 * Phải xoá thông báo: nếu không, dòng "đã thêm vào giỏ" sẽ hiện lại ở trang khách mở tiếp theo.
 *
 * @return void
 */
function kio_store_ajax_add_to_cart(): void {
	if ( ! isset( $_REQUEST['add-to-cart'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- chỉ kiểm tra có tham số; form thêm vào giỏ của Woo không dùng nonce.
		wp_send_json_error( array( 'messages' => array() ), 400 );
	}

	$errors = array_map(
		static function ( array $notice ): string {
			// Bỏ cả link "Xem giỏ hàng" Woo kèm trong thông báo, không chỉ bỏ thẻ: chữ của link đứng
			// một mình ở cuối câu trông như lỗi.
			return trim( wp_strip_all_tags( (string) preg_replace( '#<a\b[^>]*>.*?</a>#s', '', $notice['notice'] ) ) );
		},
		wc_get_notices( 'error' )
	);
	wc_clear_notices();

	if ( $errors ) {
		wp_send_json_error( array( 'messages' => array_values( $errors ) ) );
	}

	WC_AJAX::get_refreshed_fragments();
}
