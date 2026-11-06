<?php
/**
 * Đổi tên màu của site đã có dữ liệu cho khớp data/products.json, không đụng giá, tồn kho.
 *
 * Bản cài mới không cần: import-products.php dùng thẳng màu trong JSON. Đổi thuộc tính của sản phẩm, của
 * từng biến thể và vẽ lại ảnh mang tên màu cũ.
 * Chạy: docker compose run --rm wpcli eval-file /scripts/rename-colors.php '<json {sku: {cũ: mới}}>'
 *
 * @package KioStore
 */

require_once '/scripts/lib/product-image.php';

$kio_map = json_decode( $args[0], true );
if ( ! is_array( $kio_map ) ) {
	WP_CLI::error( 'Thiếu danh sách màu' );
}

$kio_taxonomy = 'pa_mau-sac';
$kio_data_all = array_column( json_decode( file_get_contents( '/data/products.json' ), true ), null, 'sku' );

foreach ( $kio_map as $kio_sku => $kio_colors ) {
	$kio_product = wc_get_product( wc_get_product_id_by_sku( $kio_sku ) );
	$kio_data    = $kio_data_all[ $kio_sku ];

	// slug cũ → term mới.
	$kio_terms = array();
	foreach ( $kio_colors as $kio_old => $kio_new ) {
		$kio_term = get_term_by( 'name', $kio_new, $kio_taxonomy );
		if ( ! $kio_term ) {
			WP_CLI::error( "Chưa có màu $kio_new, chạy woo-taxonomies.php trước" );
		}
		$kio_terms[ sanitize_title( $kio_old ) ] = $kio_term;
	}

	$kio_attributes = $kio_product->get_attributes();
	$kio_current    = $kio_attributes[ $kio_taxonomy ];
	$kio_options    = array();
	foreach ( $kio_current->get_options() as $kio_option ) {
		$kio_slug      = get_term( $kio_option )->slug;
		$kio_options[] = isset( $kio_terms[ $kio_slug ] ) ? $kio_terms[ $kio_slug ]->term_id : $kio_option;
	}

	// Tạo object mới: sửa thẳng object Woo đang giữ thì lúc lưu nó thấy không đổi và bỏ qua.
	$kio_attribute = new WC_Product_Attribute();
	$kio_attribute->set_id( $kio_current->get_id() );
	$kio_attribute->set_name( $kio_taxonomy );
	$kio_attribute->set_options( $kio_options );
	$kio_attribute->set_position( $kio_current->get_position() );
	$kio_attribute->set_visible( $kio_current->get_visible() );
	$kio_attribute->set_variation( $kio_current->get_variation() );
	$kio_attributes[ $kio_taxonomy ] = $kio_attribute;
	$kio_product->set_attributes( $kio_attributes );

	$kio_defaults = $kio_product->get_default_attributes();
	if ( isset( $kio_defaults[ $kio_taxonomy ], $kio_terms[ $kio_defaults[ $kio_taxonomy ] ] ) ) {
		$kio_defaults[ $kio_taxonomy ] = $kio_terms[ $kio_defaults[ $kio_taxonomy ] ]->slug;
		$kio_product->set_default_attributes( $kio_defaults );
	}
	$kio_product->save();

	$kio_first = 0;
	foreach ( $kio_product->get_children() as $kio_child ) {
		$kio_variation = wc_get_product( $kio_child );
		$kio_selected  = $kio_variation->get_attributes();
		$kio_slug      = $kio_selected[ $kio_taxonomy ] ?? '';
		if ( ! isset( $kio_terms[ $kio_slug ] ) ) {
			continue;
		}

		$kio_term                      = $kio_terms[ $kio_slug ];
		$kio_selected[ $kio_taxonomy ] = $kio_term->slug;
		$kio_variation->set_attributes( $kio_selected );
		$kio_image = kio_product_image(
			array_filter(
				array(
					'device' => $kio_data['image']['device'],
					'color'  => (string) get_term_meta( $kio_term->term_id, 'kio_color_hex', true ),
					'title'  => $kio_data['name'],
					'label'  => $kio_term->name,
					'brand'  => $kio_data['brand'],
					'badge'  => $kio_data['image']['badge'] ?? null,
				)
			)
		);
		$kio_variation->set_image_id( $kio_image );
		$kio_variation->save();
		$kio_first = $kio_first ? $kio_first : $kio_image;
	}

	if ( $kio_first ) {
		$kio_product = wc_get_product( $kio_product->get_id() );
		$kio_product->set_image_id( $kio_first );
		$kio_product->save();
	}
	WP_CLI::log( "    $kio_sku" );
}
