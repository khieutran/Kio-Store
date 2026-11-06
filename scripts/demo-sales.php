<?php
/**
 * Ghi cấu hình sale demo từ data/products.json vào option, đặt lịch gia hạn mỗi ngày.
 *
 * Chạy sau import-products.php. Chạy lặp lại được, chỉ đụng giá sale và ngày sale của sản phẩm trong
 * danh sách, không đè tên, giá gốc, tồn kho như import-products.php.
 *
 * Chạy: docker compose run --rm wpcli eval-file /scripts/demo-sales.php
 *
 * @package KioStore
 */

$kio_products = json_decode( file_get_contents( '/data/products.json' ), true );
if ( ! is_array( $kio_products ) ) {
	WP_CLI::error( 'Không đọc được /data/products.json' );
}

/**
 * Tìm biến thể theo giá trị thuộc tính, cách import-products.php lưu: pa_mau-sac => xanh-duong.
 *
 * @param WC_Product $parent     Sản phẩm cha.
 * @param array      $attributes Thuộc tính trong JSON.
 * @return int ID biến thể, 0 nếu không thấy.
 */
function kio_find_variation( WC_Product $parent, array $attributes ): int {
	$wanted = array();
	foreach ( $attributes as $slug => $value ) {
		$wanted[ wc_attribute_taxonomy_name( $slug ) ] = sanitize_title( $value );
	}

	foreach ( $parent->get_children() as $child_id ) {
		$child = wc_get_product( $child_id );
		if ( $child && ! array_diff_assoc( $wanted, $child->get_attributes() ) ) {
			return $child_id;
		}
	}

	return 0;
}

$kio_sales = array();
foreach ( $kio_products as $kio_data ) {
	$kio_id = wc_get_product_id_by_sku( $kio_data['sku'] );
	$kio_parent = $kio_id ? wc_get_product( $kio_id ) : null;
	if ( ! $kio_parent ) {
		continue;
	}

	foreach ( array_merge( array( $kio_data ), $kio_data['variations'] ?? array() ) as $kio_item ) {
		if ( ! isset( $kio_item['sale_to'] ) ) {
			continue;
		}

		$kio_target = isset( $kio_item['attributes'] ) ? kio_find_variation( $kio_parent, $kio_item['attributes'] ) : $kio_id;
		if ( $kio_target ) {
			$kio_sales[] = array(
				'id'         => $kio_target,
				'sale_price' => $kio_item['sale_price'],
				'from'       => $kio_item['sale_from'],
				'to'         => $kio_item['sale_to'],
			);
		}
	}
}

// Không autoload: chỉ việc nền mỗi ngày cần đọc.
update_option( 'kio_demo_sales', $kio_sales, false );
update_option( 'kio_demo_rolling_sale', 1, false );

$kio_rolled = kio_store_roll_demo_sales();

if ( ! as_has_scheduled_action( 'kio_store_roll_demo_sales' ) ) {
	as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, 'kio_store_roll_demo_sales', array(), 'kio-store' );
}

WP_CLI::log( sprintf( '    %d sale demo, đặt lại %d', count( $kio_sales ), $kio_rolled ) );
