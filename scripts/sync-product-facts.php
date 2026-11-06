<?php
/**
 * Cập nhật tên, mô tả và thuộc tính thông số từ data/products.json cho site đã có dữ liệu.
 *
 * Khác import-products.php: không đụng giá, tồn kho, ảnh, biến thể. Chỉ thuộc tính không tạo biến thể.
 * Chạy: docker compose run --rm wpcli eval-file /scripts/sync-product-facts.php
 *
 * @package KioStore
 */

$kio_products = json_decode( file_get_contents( '/data/products.json' ), true );
if ( ! is_array( $kio_products ) ) {
	WP_CLI::error( 'Không đọc được /data/products.json' );
}

$kio_spec_slugs = array( 'ram', 'man-hinh', 'chip', 'pin' );
$kio_count      = 0;

foreach ( $kio_products as $kio_data ) {
	$kio_product = wc_get_product( wc_get_product_id_by_sku( $kio_data['sku'] ) );
	if ( ! $kio_product ) {
		continue;
	}

	$kio_product->set_name( $kio_data['name'] );
	$kio_product->set_short_description( $kio_data['short_description'] );
	$kio_product->set_description( $kio_data['description'] );

	$kio_attributes = $kio_product->get_attributes();
	foreach ( $kio_spec_slugs as $kio_slug ) {
		$kio_taxonomy = wc_attribute_taxonomy_name( $kio_slug );
		$kio_current  = $kio_attributes[ $kio_taxonomy ] ?? null;
		if ( $kio_current && $kio_current->get_variation() ) {
			continue;
		}

		$kio_value = $kio_data['specs'][ $kio_slug ] ?? null;
		if ( null === $kio_value ) {
			unset( $kio_attributes[ $kio_taxonomy ] );
			continue;
		}

		$kio_term = get_term_by( 'name', $kio_value, $kio_taxonomy );
		if ( ! $kio_term ) {
			$kio_new  = wp_insert_term( $kio_value, $kio_taxonomy );
			$kio_term = get_term( $kio_new['term_id'], $kio_taxonomy );
		}

		// Luôn tạo object mới: sửa thẳng object Woo đang giữ thì lúc lưu nó so sánh thấy không đổi và bỏ qua.
		$kio_attribute = new WC_Product_Attribute();
		$kio_attribute->set_position( $kio_current ? $kio_current->get_position() : count( $kio_attributes ) );
		$kio_attribute->set_id( wc_attribute_taxonomy_id_by_name( $kio_slug ) );
		$kio_attribute->set_name( $kio_taxonomy );
		$kio_attribute->set_options( array( $kio_term->term_id ) );
		$kio_attribute->set_visible( true );
		$kio_attribute->set_variation( false );
		$kio_attributes[ $kio_taxonomy ] = $kio_attribute;
	}

	$kio_product->set_attributes( $kio_attributes );
	$kio_product->save();
	++$kio_count;
}

WP_CLI::log( "    Đã cập nhật $kio_count sản phẩm" );
