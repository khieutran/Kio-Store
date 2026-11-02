<?php
/**
 * Cấu hình giao hàng và thanh toán.
 *
 * Giao hàng: 1 vùng "Việt Nam" gồm 2 phương thức
 *   - Phí cố định 30.000 ₫
 *   - Miễn phí: đơn từ 300.000 ₫ HOẶC có coupon miễn phí ship
 * Thanh toán: COD và chuyển khoản ngân hàng (BACS).
 *
 * Chạy lặp lại được. Chạy: docker compose run --rm wpcli eval-file /scripts/woo-shipping-payments.php
 */

// --- 1. Vùng giao hàng --------------------------------------------------------------
// Vùng lưu ở wp_woocommerce_shipping_zones, quốc gia của vùng ở ..._zone_locations,
// phương thức ở ..._zone_methods. Cài đặt của mỗi phương thức lại nằm trong wp_options
// với tên woocommerce_<method>_<instance_id>_settings.

$zone = null;
foreach ( WC_Shipping_Zones::get_zones() as $z ) {
	if ( 'Việt Nam' === $z['zone_name'] ) {
		$zone = new WC_Shipping_Zone( $z['id'] );
	}
}
if ( ! $zone ) {
	$zone = new WC_Shipping_Zone();
	$zone->set_zone_name( 'Việt Nam' );
	$zone->set_locations( [ [ 'code' => 'VN', 'type' => 'country' ] ] );
	$zone->save();
	WP_CLI::log( "    Tạo vùng giao hàng Việt Nam (ID {$zone->get_id()})" );
}

/** Thêm phương thức vào vùng nếu chưa có, rồi ghi cài đặt. */
function kio_shipping_method( WC_Shipping_Zone $zone, string $method_id, array $settings ): void {
	$instance = null;
	foreach ( $zone->get_shipping_methods() as $method ) {
		if ( $method->id === $method_id ) {
			$instance = $method;
		}
	}
	if ( ! $instance ) {
		$instance_id = $zone->add_shipping_method( $method_id );
		$instance    = WC_Shipping_Zones::get_shipping_method( $instance_id );
	}

	$option = $instance->get_instance_option_key();   // woocommerce_flat_rate_1_settings
	update_option( $option, array_merge( (array) get_option( $option, [] ), $settings ) );
	WP_CLI::log( sprintf( '    %-14s → %s', $method_id, $option ) );
}

kio_shipping_method( $zone, 'flat_rate', [
	'title'      => 'Giao hàng tiêu chuẩn',
	'tax_status' => 'none',
	'cost'       => '30000',
] );

kio_shipping_method( $zone, 'free_shipping', [
	'title'            => 'Miễn phí giao hàng',
	'requires'         => 'either',   // đủ giá trị tối thiểu HOẶC có coupon miễn phí ship
	'min_amount'       => '300000',
	'ignore_discounts' => 'no',       // tính 300.000 trên số tiền SAU khi trừ coupon
] );

// Thứ tự: Woo tự CHỌN SẴN phương thức đứng đầu trong số các phương thức khả dụng.
// Đưa "Miễn phí" lên đầu: đơn đủ điều kiện sẽ mặc định được miễn phí; đơn chưa đủ thì
// "Miễn phí" không xuất hiện nên "Phí cố định" là lựa chọn duy nhất.
// (Trong wp-admin, đây là thao tác kéo thả thứ tự trong vùng giao hàng.)
global $wpdb;
foreach ( [ 'free_shipping' => 1, 'flat_rate' => 2 ] as $method_id => $order ) {
	$wpdb->update(
		"{$wpdb->prefix}woocommerce_shipping_zone_methods",
		[ 'method_order' => $order ],
		[ 'zone_id' => $zone->get_id(), 'method_id' => $method_id ]
	);
}
WC_Cache_Helper::invalidate_cache_group( 'shipping_zones' );

// --- 2. Phương thức thanh toán -------------------------------------------------------
// Cài đặt của mỗi cổng thanh toán là một mảng trong wp_options: woocommerce_<id>_settings

function kio_gateway( string $id, array $settings ): void {
	$option = "woocommerce_{$id}_settings";
	update_option( $option, array_merge( (array) get_option( $option, [] ), $settings ) );
	WP_CLI::log( sprintf( '    %-14s %s', $id, 'yes' === $settings['enabled'] ? 'bật' : 'tắt' ) );
}

kio_gateway( 'cod', [
	'enabled'            => 'yes',
	'title'              => 'Thanh toán khi nhận hàng (COD)',
	'description'        => 'Kiểm tra hàng rồi thanh toán tiền mặt cho nhân viên giao hàng.',
	'instructions'       => 'Nhân viên Kio Mobile sẽ gọi xác nhận đơn trong vòng 30 phút (giờ hành chính).',
	'enable_for_virtual' => 'no',
] );

kio_gateway( 'bacs', [
	'enabled'      => 'yes',
	'title'        => 'Chuyển khoản ngân hàng',
	'description'  => 'Chuyển khoản theo thông tin bên dưới, nội dung ghi mã đơn hàng. Đơn được xử lý sau khi nhận tiền.',
	'instructions' => 'Vui lòng chuyển khoản trong 24 giờ, nội dung: mã đơn hàng của bạn.',
] );

// Tài khoản nhận tiền (dữ liệu demo), lưu ở option riêng
update_option( 'woocommerce_bacs_accounts', [ [
	'account_name'   => 'CONG TY TNHH KIO MOBILE (DEMO)',
	'account_number' => '0123456789',
	'bank_name'      => 'Ngân hàng Demo',
	'sort_code'      => '',
	'iban'           => '',
	'bic'            => '',
] ] );

kio_gateway( 'cheque', [ 'enabled' => 'no' ] );
