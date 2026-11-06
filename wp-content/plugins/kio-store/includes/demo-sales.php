<?php
/**
 * Sale xoay vòng cho dữ liệu demo: flash sale không biến mất sau vài ngày.
 *
 * Dữ liệu demo có ngày sale tương đối (data/products.json). Hết hạn thì Woo bỏ ngày sale và trả về giá gốc,
 * nên việc nền này giữ một bản cấu hình trong option kio_demo_sales và đặt lại trước khi sale kết thúc.
 * Không đọc data/: trên server không có thư mục đó.
 *
 * Chỉ chạy khi bật option kio_demo_rolling_sale, và chỉ đụng các sản phẩm trong danh sách: sale thật do
 * chủ shop đặt không bị ảnh hưởng. Danh sách do scripts/demo-sales.php ghi.
 *
 * @package KioStore
 */

defined( 'ABSPATH' ) || exit;

add_action( 'kio_store_roll_demo_sales', 'kio_store_roll_demo_sales' );

/**
 * Sale này có cần đặt lại không?
 *
 * Sale đang chạy: gia hạn khi còn dưới 1 ngày, để không có lúc nào khối flash sale bị trống.
 * Sale hẹn giờ (bắt đầu trong tương lai): lùi lại khi sắp bắt đầu, để luôn còn một sale "chưa tới".
 *
 * @param WC_Product $product Sản phẩm hoặc biến thể.
 * @param array      $sale    Cấu hình: from, to là biểu thức ngày tương đối.
 * @return bool
 */
function kio_store_demo_sale_is_due( WC_Product $product, array $sale ): bool {
	$is_scheduled = strtotime( $sale['from'] ) > time();
	$date         = $is_scheduled ? $product->get_date_on_sale_from() : $product->get_date_on_sale_to();

	return ! $date || $date->getTimestamp() - time() < DAY_IN_SECONDS;
}

/**
 * ACTION kio_store_roll_demo_sales: đặt lại các sale demo tới hạn.
 *
 * Lưu bằng CRUD API: hook lưu sản phẩm xoá luôn cache flash sale và page cache.
 *
 * @return int Số sản phẩm đã đặt lại.
 */
function kio_store_roll_demo_sales(): int {
	if ( ! get_option( 'kio_demo_rolling_sale' ) ) {
		return 0;
	}

	$rolled = 0;
	foreach ( (array) get_option( 'kio_demo_sales', array() ) as $sale ) {
		$product = wc_get_product( $sale['id'] );
		if ( ! $product || ! kio_store_demo_sale_is_due( $product, $sale ) ) {
			continue;
		}

		$timezone = wp_timezone();
		$product->set_sale_price( (string) $sale['sale_price'] );
		$product->set_date_on_sale_from( ( new DateTime( $sale['from'], $timezone ) )->format( 'Y-m-d 00:00:00' ) );
		$product->set_date_on_sale_to( ( new DateTime( $sale['to'], $timezone ) )->format( 'Y-m-d 23:59:59' ) );
		$product->save();
		++$rolled;
	}

	return $rolled;
}
