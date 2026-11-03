<?php
/**
 * F9 — Coupon "Giảm X% tối đa Y đồng" + chặn cộng dồn mã giảm tiền.
 *
 * Phát hiện ở Phase 1: GIAM500K + KIO10 cộng dồn, đơn 23 triệu được giảm 2,8 triệu; Woo không có
 * "mức giảm tối đa" cho coupon phần trăm.
 *
 * 1) Ô "Giảm tối đa (₫)" trong màn hình sửa coupon (tab Chung), lưu meta _kio_max_discount.
 *
 * 2) Áp mức trần: khi X% của các sản phẩm được áp mã VƯỢT mức trần, coupon được tính như loại
 *    "giảm cố định cho giỏ hàng" (fixed_cart) với số tiền = mức trần (filter getter
 *    woocommerce_coupon_get_discount_type / _amount). Lý do không dùng filter
 *    woocommerce_coupon_get_discount_amount: filter đó chạy TỪNG dòng sản phẩm, và khi có nó Woo tắt
 *    bước bù phần làm tròn cuối (WC_Discounts::apply_coupon_percent, $adjust_final_discount) → tổng
 *    giảm lệch vài đồng so với mức trần. fixed_cart thì Woo tự chia cho các dòng chính xác tới từng
 *    đồng, vẫn chỉ áp cho sản phẩm hợp lệ (điều kiện danh mục/sản phẩm của coupon).
 *
 * 3) Mã "chỉ dùng riêng" (individual use, cài đặt có sẵn của Woo) vẫn cho đi kèm mã CHỈ miễn phí
 *    vận chuyển (không giảm tiền hàng, vd. FREESHIP): 2 filter woocommerce_apply_*individual_use_coupon.
 *
 * @package KioStore
 */

defined( 'ABSPATH' ) || exit;

add_action( 'woocommerce_coupon_options', 'kio_store_coupon_max_discount_field', 10, 2 );
add_action( 'woocommerce_coupon_options_save', 'kio_store_coupon_max_discount_save', 10, 2 );
add_filter( 'woocommerce_coupon_get_discount_type', 'kio_store_coupon_capped_type', 10, 2 );
add_filter( 'woocommerce_coupon_get_amount', 'kio_store_coupon_capped_amount', 10, 2 );
add_filter( 'woocommerce_apply_with_individual_use_coupon', 'kio_store_allow_freeship_with_individual', 10, 2 );
add_filter( 'woocommerce_apply_individual_use_coupon', 'kio_store_keep_freeship_with_individual', 10, 3 );

/**
 * ACTION woocommerce_coupon_options: in ô "Giảm tối đa" trong tab Chung của màn hình sửa coupon.
 *
 * Woo tự lo nonce + quyền cho cả meta box coupon (WC_Admin_Meta_Boxes) trước khi gọi hook lưu.
 *
 * @param int       $coupon_id ID coupon.
 * @param WC_Coupon $coupon    Coupon đang sửa.
 * @return void
 */
function kio_store_coupon_max_discount_field( int $coupon_id, WC_Coupon $coupon ): void {
	woocommerce_wp_text_input(
		array(
			'id'                => '_kio_max_discount',
			'label'             => __( 'Giảm tối đa (₫)', 'kio-store' ),
			'description'       => __( 'Chỉ áp dụng cho mã giảm theo phần trăm. Bỏ trống = không giới hạn.', 'kio-store' ),
			'desc_tip'          => true,
			'type'              => 'number',
			'value'             => $coupon->get_meta( '_kio_max_discount' ),
			'custom_attributes' => array(
				'min'  => '0',
				'step' => '1000',
			),
		)
	);
}

/**
 * ACTION woocommerce_coupon_options_save: lưu mức giảm tối đa.
 *
 * Woo gọi hook này SAU khi đã kiểm tra nonce + quyền của meta box và lưu coupon; ở đây chỉ làm sạch
 * dữ liệu (số nguyên không âm) rồi lưu meta.
 *
 * @param int       $post_id ID coupon.
 * @param WC_Coupon $coupon  Coupon vừa lưu.
 * @return void
 */
function kio_store_coupon_max_discount_save( int $post_id, WC_Coupon $coupon ): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce đã được WC_Admin_Meta_Boxes::save_meta_boxes kiểm tra.
	$max = isset( $_POST['_kio_max_discount'] ) ? absint( wp_unslash( $_POST['_kio_max_discount'] ) ) : 0;
	$coupon->update_meta_data( '_kio_max_discount', $max ? $max : '' );
	$coupon->save_meta_data();
}

/**
 * Mức trần đang áp dụng cho coupon trong giỏ hiện tại; 0 nếu không cần giới hạn.
 *
 * Chỉ tính khi: có giỏ hàng, coupon là loại phần trăm, có mức trần, và X% của các sản phẩm hợp lệ
 * vượt mức trần. Đọc loại/số tiền GỐC bằng context 'edit' (không qua filter của chính file này).
 *
 * @param WC_Coupon $coupon Coupon.
 * @return int
 */
function kio_store_coupon_active_cap( WC_Coupon $coupon ): int {
	static $computing = false;

	// is_valid_for_product() bên dưới lại gọi get_discount_type() → filter → hàm này: chặn đệ quy.
	if ( $computing || 'percent' !== $coupon->get_discount_type( 'edit' ) || ! function_exists( 'WC' ) || ! WC()->cart ) {
		return 0;
	}
	$max = absint( $coupon->get_meta( '_kio_max_discount' ) );
	if ( ! $max ) {
		return 0;
	}

	$computing = true;
	$eligible  = 0.0;
	foreach ( WC()->cart->get_cart() as $item ) {
		if ( ! empty( $item['data'] ) && $coupon->is_valid_for_product( $item['data'], $item ) ) {
			$eligible += (float) $item['line_subtotal'];
		}
	}
	$computing = false;

	return ( $eligible * (float) $coupon->get_amount( 'edit' ) / 100 ) > $max ? $max : 0;
}

/**
 * FILTER woocommerce_coupon_get_discount_type: vượt trần → tính như fixed_cart.
 *
 * @param string    $type   Loại coupon.
 * @param WC_Coupon $coupon Coupon.
 * @return string
 */
function kio_store_coupon_capped_type( $type, WC_Coupon $coupon ) {
	return kio_store_coupon_active_cap( $coupon ) ? 'fixed_cart' : $type;
}

/**
 * FILTER woocommerce_coupon_get_amount: vượt trần → số tiền = mức trần.
 *
 * @param string    $amount Số tiền / phần trăm.
 * @param WC_Coupon $coupon Coupon.
 * @return string
 */
function kio_store_coupon_capped_amount( $amount, WC_Coupon $coupon ) {
	$cap = kio_store_coupon_active_cap( $coupon );

	return $cap ? (string) $cap : $amount;
}

/**
 * Coupon chỉ miễn phí vận chuyển, không giảm tiền hàng (vd. FREESHIP)?
 *
 * @param WC_Coupon $coupon Coupon.
 * @return bool
 */
function kio_store_is_freeship_only( WC_Coupon $coupon ): bool {
	return $coupon->get_free_shipping() && 0.0 === (float) $coupon->get_amount( 'edit' );
}

/**
 * FILTER woocommerce_apply_with_individual_use_coupon: đã có mã "chỉ dùng riêng" trong giỏ, có cho áp
 * thêm mã mới không? Cho phép nếu mã mới chỉ miễn phí vận chuyển.
 *
 * @param bool      $apply      Mặc định false (từ chối).
 * @param WC_Coupon $the_coupon Mã đang áp thêm.
 * @return bool
 */
function kio_store_allow_freeship_with_individual( $apply, WC_Coupon $the_coupon ) {
	return $apply || kio_store_is_freeship_only( $the_coupon );
}

/**
 * FILTER woocommerce_apply_individual_use_coupon: khi áp mã "chỉ dùng riêng", Woo gỡ mọi mã khác trừ
 * các mã trả về ở đây → giữ lại mã chỉ miễn phí vận chuyển.
 *
 * @param array     $keep       Mã được giữ lại.
 * @param WC_Coupon $the_coupon Mã "chỉ dùng riêng" đang áp.
 * @param array     $applied    Các mã đang có trong giỏ.
 * @return array
 */
function kio_store_keep_freeship_with_individual( $keep, $the_coupon, $applied ) {
	foreach ( (array) $applied as $code ) {
		if ( kio_store_is_freeship_only( new WC_Coupon( $code ) ) ) {
			$keep[] = $code;
		}
	}

	return $keep;
}
