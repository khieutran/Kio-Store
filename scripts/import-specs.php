<?php
/**
 * Nạp thông số đầy đủ từ data/specs.json vào product meta _kio_specs.
 *
 * Chạy sau import-products.php. Chạy lặp lại được, chỉ ghi meta này.
 * Chạy: docker compose run --rm wpcli eval-file /scripts/import-specs.php
 *
 * @package KioStore
 */

$kio_specs = json_decode( file_get_contents( '/data/specs.json' ), true );
if ( ! is_array( $kio_specs ) ) {
	WP_CLI::error( 'Không đọc được /data/specs.json: ' . json_last_error_msg() );
}

$kio_count = 0;
foreach ( $kio_specs as $kio_sku => $kio_data ) {
	// Thông số không có nguồn thì không nạp: không để số liệu đoán lọt lên site.
	if ( empty( $kio_data['source'] ) || empty( $kio_data['checked_at'] ) ) {
		WP_CLI::error( "$kio_sku thiếu source hoặc checked_at" );
	}

	$kio_product = wc_get_product( wc_get_product_id_by_sku( $kio_sku ) );
	if ( ! $kio_product ) {
		WP_CLI::error( "Không có sản phẩm SKU $kio_sku" );
	}

	$kio_product->update_meta_data( '_kio_specs', $kio_data );
	$kio_product->save();
	++$kio_count;
}

WP_CLI::log( "    $kio_count sản phẩm có thông số đầy đủ" );
