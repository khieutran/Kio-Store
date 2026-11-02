<?php
/**
 * Cấu hình WooCommerce cho cửa hàng Việt Nam.
 *
 * Mọi cài đặt trong WooCommerce → Cài đặt đều là một dòng trong wp_options.
 * File này chạy bên trong WordPress (wp eval-file), nên gọi thẳng được
 * get_option / update_option, không cần mở một container cho từng option.
 *
 * Chạy: docker compose run --rm wpcli eval-file /scripts/woo-settings.php
 */

$settings = [
	// --- Địa chỉ cửa hàng ---
	'woocommerce_default_country'   => 'VN',
	'woocommerce_store_address'     => '123 Nguyễn Văn Linh',
	'woocommerce_store_address_2'   => 'Phường Tân Phong',
	'woocommerce_store_city'        => 'TP. Hồ Chí Minh',
	'woocommerce_store_postcode'    => '700000',

	// --- Chỉ bán và giao hàng trong Việt Nam ---
	'woocommerce_allowed_countries'          => 'specific',
	'woocommerce_specific_allowed_countries' => [ 'VN' ],
	'woocommerce_ship_to_countries'          => '',     // rỗng = giống danh sách bán hàng
	'woocommerce_default_customer_address'   => 'base', // địa chỉ khách mặc định = quốc gia cửa hàng, không dò IP

	// --- Tiền tệ: 32.990.000 ₫ ---
	'woocommerce_currency'           => 'VND',
	'woocommerce_currency_pos'       => 'right_space',
	'woocommerce_price_thousand_sep' => '.',
	'woocommerce_price_decimal_sep'  => ',',
	'woocommerce_price_num_decimals' => '0',

	// --- Thuế: tắt, giá niêm yết đã gồm VAT ---
	'woocommerce_calc_taxes' => 'no',

	// --- Tồn kho ---
	'woocommerce_manage_stock'            => 'yes',
	'woocommerce_notify_low_stock_amount' => '3',  // còn <= 3 thì báo sắp hết
	'woocommerce_notify_no_stock_amount'  => '0',
	'woocommerce_hide_out_of_stock_items' => 'no', // vẫn hiện sản phẩm hết hàng (làm mờ ở Phase 2)
	'woocommerce_hold_stock_minutes'      => '30', // giữ hàng 30 phút cho đơn chưa thanh toán

	// --- Tài khoản và thanh toán ---
	'woocommerce_enable_guest_checkout'                 => 'yes', // mua không cần đăng ký
	'woocommerce_enable_checkout_login_reminder'        => 'yes', // nhắc đăng nhập nếu đã có tài khoản
	'woocommerce_enable_signup_and_login_from_checkout' => 'yes', // tạo tài khoản ngay lúc thanh toán

	// --- Đánh giá sản phẩm ---
	'woocommerce_enable_reviews'                      => 'yes',
	'woocommerce_enable_review_rating'                => 'yes',
	'woocommerce_review_rating_required'              => 'yes',
	'woocommerce_review_rating_verification_label'    => 'yes', // nhãn "đã mua hàng"
	'woocommerce_review_rating_verification_required' => 'yes', // chỉ người đã mua mới được đánh giá

	// --- Email gửi khách ---
	'woocommerce_email_from_name'    => 'Kio Mobile',
	'woocommerce_email_from_address' => 'no-reply@kio.local',

	// --- Bỏ chế độ Sắp ra mắt, trình hướng dẫn, quảng cáo ---
	'woocommerce_coming_soon'                  => 'no',
	'woocommerce_allow_tracking'               => 'no',
	'woocommerce_show_marketplace_suggestions' => 'no',
	'woocommerce_onboarding_profile'           => [ 'skipped' => true ],
];

$changed = 0;
foreach ( $settings as $name => $value ) {
	$current = get_option( $name );
	if ( $current === $value ) {
		continue;
	}
	update_option( $name, $value );
	$changed++;
	printf(
		"    %-50s %s → %s\n",
		$name,
		'' === $current || false === $current ? '<trống>' : wp_json_encode( $current, JSON_UNESCAPED_UNICODE ),
		wp_json_encode( $value, JSON_UNESCAPED_UNICODE )
	);
}

printf( "    %d/%d cài đặt thay đổi. Giá mẫu: %s\n",
	$changed,
	count( $settings ),
	html_entity_decode( wp_strip_all_tags( wc_price( 32990000 ) ) )
);
