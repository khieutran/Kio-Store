<?php
/**
 * Tạo khung dữ liệu sản phẩm từ data/taxonomies.json:
 *   - Danh mục       → taxonomy product_cat
 *   - Thương hiệu    → taxonomy product_brand
 *   - Thuộc tính     → định nghĩa trong bảng wp_woocommerce_attribute_taxonomies,
 *                      giá trị là term của taxonomy pa_<slug>
 *
 * Chạy lặp lại được: có rồi thì cập nhật tên/mô tả/thứ tự, chưa có thì tạo.
 * Chạy: docker compose run --rm wpcli eval-file /scripts/woo-taxonomies.php
 */

$data = json_decode( file_get_contents( '/data/taxonomies.json' ), true );
if ( ! $data ) {
	WP_CLI::error( 'Không đọc được /data/taxonomies.json' );
}

/**
 * Tạo hoặc cập nhật một term, trả về term_id.
 * Thứ tự hiển thị lưu ở term meta "order" (Woo dùng meta này để sắp xếp
 * danh mục và giá trị thuộc tính khi chọn "Thứ tự tuỳ chỉnh").
 */
function kio_upsert_term( string $taxonomy, string $name, string $slug, array $args = [], ?int $order = null ): int {
	$existing = get_term_by( 'slug', $slug, $taxonomy );

	if ( $existing ) {
		wp_update_term( $existing->term_id, $taxonomy, array_merge( [ 'name' => $name ], $args ) );
		$term_id = $existing->term_id;
		$action  = 'cập nhật';
	} else {
		$result = wp_insert_term( $name, $taxonomy, array_merge( [ 'slug' => $slug ], $args ) );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( "$taxonomy/$slug: " . $result->get_error_message() );
		}
		$term_id = $result['term_id'];
		$action  = 'tạo mới';
	}

	if ( null !== $order ) {
		update_term_meta( $term_id, 'order', $order );
	}

	// sprintf đếm byte, chữ có dấu (ắ, ệ…) chiếm 2–3 byte nên tự căn cột theo số ký tự
	WP_CLI::log( sprintf( '    %-14s %s%s %s', $taxonomy, $name, str_repeat( ' ', max( 1, 24 - mb_strlen( $name ) ) ), $action ) );
	return $term_id;
}

// --- 1. Danh mục mặc định: đổi "Chưa phân loại" thành "Khác" ---------------------
// Sản phẩm không gán danh mục nào sẽ tự rơi vào danh mục này (ID lưu ở option default_product_cat).
$default_id = (int) get_option( 'default_product_cat' );
wp_update_term( $default_id, 'product_cat', $data['default_category'] );
update_term_meta( $default_id, 'order', 99 ); // luôn đứng cuối
WP_CLI::log( "    product_cat    Danh mục mặc định (ID $default_id) → {$data['default_category']['name']}" );

// --- 2. Danh mục --------------------------------------------------------------------
foreach ( $data['categories'] as $i => $cat ) {
	kio_upsert_term( 'product_cat', $cat['name'], $cat['slug'], [ 'description' => $cat['description'] ], $i );
}

// --- 3. Thương hiệu -----------------------------------------------------------------
foreach ( $data['brands'] as $i => $brand ) {
	kio_upsert_term( 'product_brand', $brand['name'], $brand['slug'], [], $i );
}

// --- 4. Thuộc tính toàn cục ---------------------------------------------------------
foreach ( $data['attributes'] as $attr ) {
	$attr_args = [
		'name'         => $attr['name'],
		'slug'         => $attr['slug'],   // Woo tự thêm tiền tố: taxonomy = pa_<slug>
		'type'         => 'select',
		'order_by'     => 'menu_order',    // sắp theo term meta "order", không theo ABC
		'has_archives' => false,           // không tạo trang /dung-luong/256gb/
	];

	$attr_id = wc_attribute_taxonomy_id_by_name( $attr['slug'] );
	if ( $attr_id ) {
		wc_update_attribute( $attr_id, $attr_args );
		WP_CLI::log( "    attribute      {$attr['name']} (pa_{$attr['slug']}) cập nhật" );
	} else {
		$attr_id = wc_create_attribute( $attr_args );
		if ( is_wp_error( $attr_id ) ) {
			WP_CLI::error( $attr_id->get_error_message() );
		}
		WP_CLI::log( "    attribute      {$attr['name']} (pa_{$attr['slug']}) tạo mới, ID $attr_id" );
	}

	// Woo chỉ đăng ký taxonomy pa_* lúc WordPress khởi động (hook init).
	// Thuộc tính vừa tạo trong request này chưa có taxonomy, nên phải tự đăng ký tạm
	// thì mới thêm term được.
	$taxonomy = wc_attribute_taxonomy_name( $attr['slug'] );
	if ( ! taxonomy_exists( $taxonomy ) ) {
		register_taxonomy( $taxonomy, [ 'product' ], [ 'hierarchical' => false ] );
	}

	foreach ( $attr['terms'] as $i => $term ) {
		$term    = is_string( $term ) ? [ 'name' => $term ] : $term;
		$term_id = kio_upsert_term( $taxonomy, $term['name'], sanitize_title( $term['name'] ), [], $i );

		// Mã màu: term meta tự đặt tên, dùng ở Phase 2/4 để vẽ ô chọn màu (swatch)
		if ( isset( $term['hex'] ) ) {
			update_term_meta( $term_id, 'kio_color_hex', $term['hex'] );
		}
	}
}

// Xoá cache danh sách thuộc tính (Woo lưu trong transient)
delete_transient( 'wc_attribute_taxonomies' );

WP_CLI::success( sprintf(
	'%d danh mục, %d thương hiệu, %d thuộc tính.',
	count( $data['categories'] ) + 1,
	count( $data['brands'] ),
	count( $data['attributes'] )
) );
