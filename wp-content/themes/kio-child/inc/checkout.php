<?php
/**
 * Giỏ hàng và thanh toán: thanh bước, cột tóm tắt dính, bớt chữ thừa.
 *
 * Trang vẫn dùng shortcode cổ điển của Woo. Mọi thứ gắn qua hook và filter có sẵn, không override
 * template: khi Woo cập nhật template thì không phải đối chiếu lại.
 *
 * @package KioChild
 */

defined( 'ABSPATH' ) || exit;

add_action( 'woocommerce_before_cart', 'kio_child_steps_cart', 5 );
add_action( 'woocommerce_before_checkout_form', 'kio_child_steps_checkout', 5 );
add_action( 'woocommerce_before_thankyou', 'kio_child_steps_done', 5 );
add_action( 'woocommerce_checkout_before_order_review_heading', 'kio_child_order_side_open' );
add_action( 'woocommerce_checkout_after_order_review', 'kio_child_order_side_close' );
add_filter( 'storefront_sticky_order_review', '__return_false' );
add_filter( 'woocommerce_shipping_package_name', 'kio_child_shipping_package_name' );
add_filter( 'woocommerce_shipping_estimate_html', '__return_empty_string' );
add_filter( 'woocommerce_checkout_login_message', 'kio_child_checkout_login_message' );
add_filter( 'woocommerce_add_success', 'kio_child_hide_cart_updated_notice' );
add_filter( 'woocommerce_checkout_coupon_message', 'kio_child_checkout_coupon_message' );

/**
 * In thanh bước.
 *
 * @param int $current Bước hiện tại, tính từ 1.
 * @return void
 */
function kio_child_steps( int $current ): void {
	$steps = array(
		1 => array( __( 'Giỏ hàng', 'kio-child' ), wc_get_cart_url() ),
		2 => array( __( 'Thông tin', 'kio-child' ), '' ),
		3 => array( __( 'Hoàn tất', 'kio-child' ), '' ),
	);

	echo '<ol class="kio-steps" aria-label="' . esc_attr__( 'Các bước đặt hàng', 'kio-child' ) . '">';
	foreach ( $steps as $number => $step ) {
		$state = $number < $current ? ' is-done' : ( $number === $current ? ' is-current' : '' );
		$label = sprintf( '<span class="kio-steps__number">%d</span><span class="kio-steps__label">%s</span>', $number, esc_html( $step[0] ) );

		// Chỉ bước đã qua và có trang riêng mới là link; đã đặt hàng xong thì không quay lại giỏ nữa.
		if ( $number < $current && '' !== $step[1] && 3 !== $current ) {
			$label = sprintf( '<a href="%s">%s</a>', esc_url( $step[1] ), $label );
		}

		printf(
			'<li class="kio-steps__item%1$s"%2$s>%3$s</li>',
			esc_attr( $state ),
			$number === $current ? ' aria-current="step"' : '',
			$label // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- từng phần đã escape ở trên.
		);
	}
	echo '</ol>';
}

/**
 * Bước 1.
 *
 * @return void
 */
function kio_child_steps_cart(): void {
	kio_child_steps( 1 );
}

/**
 * Bước 2.
 *
 * @return void
 */
function kio_child_steps_checkout(): void {
	kio_child_steps( 2 );
}

/**
 * Bước 3.
 *
 * @return void
 */
function kio_child_steps_done(): void {
	kio_child_steps( 3 );
}

/**
 * Bọc tiêu đề + bảng đơn hàng: trong template chúng là 2 phần tử rời, muốn dính cùng nhau phải có cha chung.
 *
 * @return void
 */
function kio_child_order_side_open(): void {
	echo '<div class="kio-order-side">';
}

/**
 * Đóng khung tóm tắt đơn.
 *
 * @return void
 */
function kio_child_order_side_close(): void {
	echo '</div>';
}

/**
 * FILTER woocommerce_shipping_package_name: chữ "Shipment" chưa có bản dịch.
 *
 * @return string
 */
function kio_child_shipping_package_name(): string {
	return __( 'Giao hàng', 'kio-child' );
}

/**
 * FILTER woocommerce_add_success: bỏ thông báo "Giỏ hàng đã được cập nhật".
 *
 * Giỏ tự cập nhật khi đổi số lượng, nên có thông báo là Woo lại cuộn trang tới nó sau mỗi lần bấm.
 * Trả chuỗi rỗng thì wc_add_notice() không thêm gì. Các thông báo khác giữ nguyên.
 *
 * @param string $message Nội dung thông báo.
 * @return string
 */
function kio_child_hide_cart_updated_notice( string $message ): string {
	return __( 'Cart updated.', 'woocommerce' ) === $message ? '' : $message; // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- so với đúng chuỗi của Woo.
}

/**
 * FILTER woocommerce_checkout_login_message.
 *
 * @return string
 */
function kio_child_checkout_login_message(): string {
	return __( 'Đã có tài khoản?', 'kio-child' );
}

/**
 * FILTER woocommerce_checkout_coupon_message: giữ class showcoupon và aria để JS của Woo mở được form.
 *
 * @return string
 */
function kio_child_checkout_coupon_message(): string {
	return sprintf(
		'%1$s <a href="#" role="button" aria-label="%2$s" aria-controls="woocommerce-checkout-form-coupon" aria-expanded="false" class="showcoupon">%3$s</a>',
		esc_html__( 'Có mã giảm giá?', 'kio-child' ),
		esc_attr__( 'Nhập mã giảm giá của bạn', 'kio-child' ),
		esc_html__( 'Nhập mã', 'kio-child' )
	);
}
