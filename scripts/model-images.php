<?php
/**
 * Dùng ảnh chụp từ mô hình 3D (data/model-images/<SKU>-<slug màu>.png) làm ảnh sản phẩm và biến thể.
 *
 * Chạy sau import-products.php. Chạy lặp lại được: ảnh đã nạp thì dùng lại, không tạo bản trùng.
 * Chạy: docker compose run --rm wpcli eval-file /scripts/model-images.php
 *
 * @package KioStore
 */

require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

/**
 * Nạp một file vào thư viện Media.
 *
 * @param string $file Đường dẫn file.
 * @return int ID ảnh.
 */
function kio_model_attachment( string $file ): int {
	$hash  = md5_file( $file );
	$found = get_posts(
		array(
			'post_type'   => 'attachment',
			'post_status' => 'inherit',
			'numberposts' => 1,
			'fields'      => 'ids',
			'meta_key'    => '_kio_model_image', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- chạy một lần lúc cài.
			'meta_value'  => $hash, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- như trên.
		)
	);
	if ( $found ) {
		return (int) $found[0];
	}

	$tmp = wp_tempnam( basename( $file ) );
	copy( $file, $tmp );
	$id = media_handle_sideload(
		array(
			'name'     => basename( $file ),
			'tmp_name' => $tmp,
		),
		0
	);
	if ( is_wp_error( $id ) ) {
		WP_CLI::error( $id->get_error_message() );
	}
	update_post_meta( $id, '_kio_model_image', $hash );

	return (int) $id;
}

$kio_count = 0;
foreach ( json_decode( file_get_contents( '/data/products.json' ), true ) as $kio_data ) {
	$kio_files = glob( '/data/model-images/' . $kio_data['sku'] . '-*.png' );
	$kio_id    = wc_get_product_id_by_sku( $kio_data['sku'] );
	if ( ! $kio_files || ! $kio_id ) {
		continue;
	}

	$kio_images = array();
	foreach ( $kio_files as $kio_file ) {
		$kio_color                = substr( basename( $kio_file, '.png' ), strlen( $kio_data['sku'] ) + 1 );
		$kio_images[ $kio_color ] = kio_model_attachment( $kio_file );
	}

	$kio_product = wc_get_product( $kio_id );
	foreach ( $kio_product->get_children() as $kio_child ) {
		$kio_variation = wc_get_product( $kio_child );
		$kio_slug      = sanitize_title( $kio_variation->get_attribute( 'pa_mau-sac' ) );
		// Biến thể không chia theo màu (laptop chia theo RAM): dùng ảnh duy nhất của sản phẩm.
		$kio_variation->set_image_id( $kio_images[ $kio_slug ] ?? reset( $kio_images ) );
		$kio_variation->save();
	}

	$kio_product->set_image_id( reset( $kio_images ) );
	$kio_product->set_gallery_image_ids( array_slice( array_values( $kio_images ), 1 ) );
	$kio_product->save();
	++$kio_count;
}

WP_CLI::log( "    $kio_count sản phẩm dùng ảnh từ mô hình 3D" );
