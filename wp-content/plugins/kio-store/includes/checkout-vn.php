<?php
/**
 * F8 — Form địa chỉ / thanh toán cho Việt Nam.
 *
 * Phát hiện ở Phase 1: với quốc gia VN, Woo để Họ + Tên 2 ô riêng, số điện thoại không bắt buộc,
 * không có danh sách tỉnh, ô "Thị trấn / Thành phố" phải gõ tay. Sửa:
 *   - Số điện thoại BẮT BUỘC: dùng cài đặt có sẵn của Woo (option woocommerce_checkout_phone_field,
 *     ghi trong scripts/woo-settings.php) — không code lại thứ Woo đã có.
 *   - Kiểm tra định dạng số điện thoại Việt Nam (code ở đây).
 *   - Tỉnh/Thành phố chọn từ danh sách 34 đơn vị sau sắp xếp 01/07/2025 (data/vn-provinces.json →
 *     filter woocommerce_states).
 *   - Gộp thành 1 ô "Họ và tên" (bỏ ô Họ): filter woocommerce_default_address_fields.
 *   - Ô "Thành phố" thành "Phường/Xã" (chính quyền 2 cấp), ẩn mã bưu chính: filter woocommerce_get_country_locale.
 *
 * Locale theo quốc gia nên áp dụng cho mọi form địa chỉ dùng VN: thanh toán, giao hàng, trang Tài
 * khoản. Chỉ chạy với trang thanh toán dạng shortcode (Phase 2); Checkout block dùng Store API.
 *
 * @package KioStore
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'woocommerce_states', 'kio_store_vn_states' );
add_filter( 'woocommerce_get_country_locale', 'kio_store_vn_locale' );
add_filter( 'woocommerce_default_address_fields', 'kio_store_single_name_field' );
add_action( 'woocommerce_after_checkout_validation', 'kio_store_validate_vn_phone', 10, 2 );
add_action( 'woocommerce_store_api_checkout_update_order_from_request', 'kio_store_validate_vn_phone_store_api' );

/**
 * Danh sách tỉnh/thành: mã → tên (đọc từ file dữ liệu đi kèm plugin, cache trong request).
 *
 * @return array<string,string>
 */
function kio_store_vn_provinces(): array {
	static $provinces = null;
	if ( null !== $provinces ) {
		return $provinces;
	}

	$provinces = array();
	$data      = wp_json_file_decode( KIO_STORE_DIR . 'data/vn-provinces.json', array( 'associative' => true ) );
	foreach ( (array) ( $data['provinces'] ?? array() ) as $province ) {
		$provinces[ $province['code'] ] = $province['name'];
	}

	return $provinces;
}

/**
 * FILTER woocommerce_states: thêm danh sách tỉnh/thành cho VN (Woo không có sẵn).
 *
 * Có danh sách thì Woo tự in ô chọn (select) thay cho ô gõ tay, và lưu MÃ tỉnh vào đơn hàng.
 *
 * @param array $states Mã quốc gia → (mã tỉnh → tên).
 * @return array
 */
function kio_store_vn_states( array $states ): array {
	$states['VN'] = kio_store_vn_provinces();

	return $states;
}

/**
 * FILTER woocommerce_get_country_locale: nhãn, thứ tự, bắt buộc/ẩn của từng ô địa chỉ khi quốc gia là VN.
 *
 * Woo dùng "locale" này cả phía PHP (kiểm tra khi đặt hàng) lẫn JS (đổi form khi khách đổi quốc gia).
 *
 * @param array $locale Mã quốc gia → cấu hình từng ô.
 * @return array
 */
function kio_store_vn_locale( array $locale ): array {
	$locale['VN'] = array_replace_recursive(
		$locale['VN'] ?? array(),
		array(
			'state'     => array(
				'label'    => __( 'Tỉnh/Thành phố', 'kio-store' ),
				'required' => true,
				'hidden'   => false,
				'priority' => 42,
			),
			// Chính quyền 2 cấp (tỉnh → xã/phường) từ 01/07/2025: không còn cấp huyện.
			'city'      => array(
				'label'    => __( 'Phường/Xã', 'kio-store' ),
				'priority' => 44,
			),
			'address_1' => array(
				'label'       => __( 'Số nhà, tên đường', 'kio-store' ),
				'placeholder' => __( 'Ví dụ: 123 Nguyễn Văn Linh', 'kio-store' ),
				'priority'    => 46,
			),
			'postcode'  => array(
				'required' => false,
				'hidden'   => true,
			),
		)
	);

	return $locale;
}

/**
 * FILTER woocommerce_default_address_fields: một ô "Họ và tên" thay cho 2 ô Tên + Họ.
 *
 * Không dùng cờ 'hidden' trong locale như các ô khác: Woo chỉ áp cờ đó bằng JS (address-i18n.js) và
 * chỉ cho 5 ô địa chỉ (address_1, address_2, state, postcode, city) → ô "Họ" vẫn hiện (thấy được khi
 * chụp màn hình, kiểm tra phía PHP thì báo là "ẩn"). Shop chỉ bán trong Việt Nam (cài đặt Phase 1)
 * nên bỏ hẳn ô last_name; tên khách lưu trọn trong first_name.
 *
 * @param array $fields Các ô địa chỉ mặc định.
 * @return array
 */
function kio_store_single_name_field( array $fields ): array {
	unset( $fields['last_name'] );
	$fields['first_name']['label'] = __( 'Họ và tên', 'kio-store' );
	$fields['first_name']['class'] = array( 'form-row-wide' );

	return $fields;
}

/**
 * Số điện thoại Việt Nam hợp lệ?
 *
 * Chấp nhận dấu cách, chấm, gạch nối, ngoặc (khách hay gõ "090 123 4567"). Sau khi bỏ các ký tự đó:
 * di động 0 + (3|5|7|8|9) + 8 số, hoặc cố định 0 + 2 + 9 số; tiền tố 0 có thể viết +84 / 84.
 *
 * @param string $phone Số khách nhập.
 * @return bool
 */
function kio_store_is_vn_phone( string $phone ): bool {
	$digits = preg_replace( '/[\s.\-()]/', '', $phone );

	return (bool) preg_match( '/^(?:\+?84|0)(?:[35789]\d{8}|2\d{9})$/', (string) $digits );
}

/**
 * ACTION woocommerce_after_checkout_validation: báo lỗi nếu số điện thoại sai định dạng.
 *
 * Woo đã tự kiểm tra "bắt buộc" (theo option) trước hook này; ở đây chỉ kiểm tra định dạng khi khách
 * có nhập. Thêm lỗi vào $errors thì Woo hiện thông báo và không tạo đơn.
 *
 * @param array    $data   Dữ liệu form thanh toán đã làm sạch.
 * @param WP_Error $errors Danh sách lỗi.
 * @return void
 */
function kio_store_validate_vn_phone( array $data, WP_Error $errors ): void {
	$phone = (string) ( $data['billing_phone'] ?? '' );
	if ( 'VN' !== ( $data['billing_country'] ?? '' ) || '' === $phone ) {
		return;
	}

	if ( ! kio_store_is_vn_phone( $phone ) ) {
		$errors->add(
			'billing_phone_validation',
			__( 'Số điện thoại không hợp lệ. Vui lòng nhập số Việt Nam, ví dụ 0901234567.', 'kio-store' ),
			array( 'id' => 'billing_phone' )
		);
	}
}

/**
 * ACTION woocommerce_store_api_checkout_update_order_from_request: cùng quy tắc cho Store API.
 *
 * Trang thanh toán của site là shortcode, nhưng endpoint /wc/store/v1/checkout vẫn công khai và không
 * đi qua woocommerce_after_checkout_validation. Kiểm tra chỉ ở một đường thì đường kia tạo được đơn sai.
 *
 * @param WC_Order $order Đơn hàng đang tạo.
 * @return void
 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException Số điện thoại sai định dạng.
 */
function kio_store_validate_vn_phone_store_api( WC_Order $order ): void {
	$phone = $order->get_billing_phone();
	if ( 'VN' !== $order->get_billing_country() || '' === $phone || kio_store_is_vn_phone( $phone ) ) {
		return;
	}

	throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
		'kio_invalid_phone',
		esc_html__( 'Số điện thoại không hợp lệ. Vui lòng nhập số Việt Nam, ví dụ 0901234567.', 'kio-store' ),
		400
	);
}
