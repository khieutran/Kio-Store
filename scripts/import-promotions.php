<?php
/**
 * Nạp khuyến mãi mẫu từ data/promotions.json bằng CRUD API của WordPress.
 *
 * Chạy lặp lại được: tìm khuyến mãi theo meta `_kio_promotion_key` (trường "key"
 * trong JSON), có rồi thì cập nhật, chưa có thì tạo mới.
 *
 * Sản phẩm áp dụng ghi trong JSON bằng SKU, danh mục bằng slug → script đổi sang
 * ID (SKU → wc_get_product_id_by_sku, slug → term_id) rồi lưu meta, để không ghi
 * thẳng ID vào file (ID thay đổi theo từng môi trường).
 *
 * Chạy: docker compose run --rm wpcli eval-file /scripts/import-promotions.php
 */

$file       = $args[0] ?? '/data/promotions.json';
$promotions = json_decode( file_get_contents( $file ), true );
if ( ! is_array( $promotions ) ) {
	WP_CLI::error( "Không đọc được $file: " . json_last_error_msg() );
}

if ( ! post_type_exists( 'kio_promotion' ) ) {
	WP_CLI::error( 'Chưa đăng ký post type kio_promotion (plugin kio-store chưa kích hoạt?)' );
}

$report = array();

foreach ( $promotions as $data ) {
	$key = sanitize_title( $data['key'] ?? '' );
	if ( '' === $key ) {
		WP_CLI::warning( 'Bỏ qua khuyến mãi thiếu trường "key"' );
		continue;
	}

	$existing = get_posts(
		array(
			'post_type'      => 'kio_promotion',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_key'       => '_kio_promotion_key', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- bảng nhỏ, tìm theo key định danh.
			'meta_value'     => $key,                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- chỉ 1 lần cho mỗi khuyến mãi.
		)
	);
	$post_id = $existing ? $existing[0] : 0;
	$action  = $post_id ? 'cập nhật' : 'tạo mới';

	$product_ids = array();
	foreach ( (array) ( $data['products'] ?? array() ) as $sku ) {
		$id = wc_get_product_id_by_sku( (string) $sku );
		if ( $id ) {
			$product_ids[] = $id;
		}
	}

	$category_ids = array();
	foreach ( (array) ( $data['categories'] ?? array() ) as $slug ) {
		$term = get_term_by( 'slug', (string) $slug, 'product_cat' );
		if ( $term ) {
			$category_ids[] = (int) $term->term_id;
		}
	}

	$post_data = array(
		'post_type'    => 'kio_promotion',
		'post_status'  => 'publish',
		'post_title'   => $data['title'] ?? '',
		'post_content' => $data['content'] ?? '',
	);
	if ( $post_id ) {
		$post_data['ID'] = $post_id;
		wp_update_post( $post_data );
	} else {
		$post_id = wp_insert_post( $post_data );
	}

	if ( is_wp_error( $post_id ) || ! $post_id ) {
		WP_CLI::warning( "Không tạo được khuyến mãi $key" );
		continue;
	}

	update_post_meta( $post_id, '_kio_promotion_key', $key );
	update_post_meta( $post_id, '_kio_promotion_products', $product_ids );
	update_post_meta( $post_id, '_kio_promotion_categories', $category_ids );
	update_post_meta( $post_id, '_kio_promotion_start', $data['start'] ?? '' );
	update_post_meta( $post_id, '_kio_promotion_end', $data['end'] ?? '' );
	update_post_meta( $post_id, '_kio_promotion_order', (int) ( $data['order'] ?? 0 ) );

	$report[] = array(
		'ID'      => $post_id,
		'Key'     => $key,
		'Tiêu đề' => $data['title'] ?? '',
		'Kết quả' => $action,
	);
}

WP_CLI\Utils\format_items( 'table', $report, array( 'ID', 'Key', 'Tiêu đề', 'Kết quả' ) );
