<?php
/**
 * Nạp sản phẩm từ file JSON bằng WooCommerce CRUD API.
 *
 * Dùng WC_Product_Simple / WC_Product_Variable / WC_Product_Variation và ->save()
 * thay vì tự ghi wp_posts + wp_postmeta, vì save() còn làm thêm nhiều việc:
 * cập nhật bảng lookup (wp_wc_product_meta_lookup), gán term cho product_type,
 * product_visibility (hết hàng, nổi bật), xoá cache giá, chạy các hook mà plugin
 * khác lắng nghe.
 *
 * Chạy lặp lại được: tìm sản phẩm theo SKU, có rồi thì cập nhật, chưa có thì tạo.
 *
 * Chạy: docker compose run --rm wpcli eval-file /scripts/import-products.php [/data/products.json]
 */

require_once '/scripts/lib/product-image.php';

$file     = $args[0] ?? '/data/products.json';
$products = json_decode( file_get_contents( $file ), true );
if ( ! is_array( $products ) ) {
	WP_CLI::error( "Không đọc được $file: " . json_last_error_msg() );
}

/** Lấy term_id theo tên, chưa có thì tạo. */
function kio_term( string $taxonomy, string $name ): int {
	$slug = sanitize_title( $name );
	$term = get_term_by( 'slug', $slug, $taxonomy );
	if ( $term ) {
		return $term->term_id;
	}
	$result = wp_insert_term( $name, $taxonomy, [ 'slug' => $slug ] );
	if ( is_wp_error( $result ) ) {
		WP_CLI::error( "$taxonomy/$name: " . $result->get_error_message() );
	}
	return $result['term_id'];
}

/**
 * Tạo một thuộc tính gắn vào sản phẩm.
 * $variation = true: thuộc tính này dùng để tạo biến thể (Dung lượng, Màu sắc).
 * $variation = false: chỉ hiển thị trong bảng thông số (RAM, Màn hình).
 */
function kio_attribute( string $slug, array $values, int $position, bool $variation ): WC_Product_Attribute {
	$taxonomy  = wc_attribute_taxonomy_name( $slug );     // 'mau-sac' → 'pa_mau-sac'
	$attribute = new WC_Product_Attribute();
	$attribute->set_id( wc_attribute_taxonomy_id_by_name( $slug ) );
	$attribute->set_name( $taxonomy );
	$attribute->set_options( array_map( fn( $v ) => kio_term( $taxonomy, $v ), $values ) );
	$attribute->set_position( $position );
	$attribute->set_visible( true );
	$attribute->set_variation( $variation );
	return $attribute;
}

/** Mã màu của một giá trị Màu sắc (term meta kio_color_hex tạo ở Bước 3). */
function kio_color_hex( string $color_name, string $fallback = '#d8d8d8' ): string {
	$term = get_term_by( 'slug', sanitize_title( $color_name ), 'pa_mau-sac' );
	return ( $term ? get_term_meta( $term->term_id, 'kio_color_hex', true ) : '' ) ?: $fallback;
}

/** '+1 day' → đối tượng ngày theo múi giờ của site. */
function kio_date( string $expr, string $time ): string {
	return ( new DateTime( $expr, wp_timezone() ) )->format( "Y-m-d $time" );
}

$report = [];

foreach ( $products as $data ) {
	$is_variable = 'variable' === $data['type'];

	// --- 1. Tìm theo SKU hoặc tạo mới -------------------------------------------
	$id      = wc_get_product_id_by_sku( $data['sku'] );
	$product = $id ? wc_get_product( $id ) : ( $is_variable ? new WC_Product_Variable() : new WC_Product_Simple() );
	$action  = $id ? 'cập nhật' : 'tạo mới';

	// --- 2. Thông tin chung -----------------------------------------------------
	$product->set_name( $data['name'] );
	$product->set_slug( sanitize_title( $data['name'] ) );
	$product->set_status( 'publish' );
	$product->set_sku( $data['sku'] );
	$product->set_short_description( $data['short_description'] ?? '' );
	$product->set_description( $data['description'] ?? '' );
	$product->set_featured( ! empty( $data['featured'] ) );
	$product->set_category_ids( [ get_term_by( 'slug', $data['category'], 'product_cat' )->term_id ] );

	// Ngày tạo lùi về quá khứ: để nhãn "Mới" (Phase 4) chỉ hiện ở sản phẩm dưới 30 ngày
	if ( isset( $data['days_old'] ) ) {
		$product->set_date_created( time() - $data['days_old'] * DAY_IN_SECONDS );
	}
	// Số lượng đã bán (meta total_sales): dùng cho "Đã bán X" và sắp xếp theo bán chạy.
	// Bình thường Woo tự tăng con số này mỗi khi có đơn hàng.
	if ( isset( $data['sold'] ) ) {
		$product->set_total_sales( $data['sold'] );
	}

	// --- 3. Thuộc tính ----------------------------------------------------------
	$attributes = [];
	$position   = 0;

	if ( $is_variable ) {
		// Gom các giá trị xuất hiện trong biến thể, giữ thứ tự: Dung lượng [256GB, 512GB], Màu [Đen, Xanh dương]
		$variation_values = [];
		foreach ( $data['variations'] as $v ) {
			foreach ( $v['attributes'] as $slug => $value ) {
				$variation_values[ $slug ][ $value ] = true;
			}
		}
		foreach ( $variation_values as $slug => $values ) {
			$attributes[] = kio_attribute( $slug, array_keys( $values ), $position++, true );
		}
	}
	foreach ( $data['specs'] ?? [] as $slug => $value ) {
		$attributes[] = kio_attribute( $slug, [ $value ], $position++, false );
	}
	$product->set_attributes( $attributes );

	// --- 4. Giá, kho, ảnh cho sản phẩm simple -----------------------------------
	if ( ! $is_variable ) {
		$product->set_regular_price( (string) $data['regular_price'] );
		$product->set_sale_price( isset( $data['sale_price'] ) ? (string) $data['sale_price'] : '' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $data['stock'] );
		$product->set_image_id( kio_product_image( array_filter( [
			'device' => $data['image']['device'],
			'color'  => $data['image']['color'] ?? '#d8d8d8',
			'title'  => $data['name'],
			'brand'  => $data['brand'],
			'badge'  => $data['image']['badge'] ?? null,
		] ) ) );
	}

	$product_id = $product->save();

	// Thương hiệu: Woo chưa có setter riêng trong WC_Product, gán term trực tiếp
	wp_set_object_terms( $product_id, kio_term( 'product_brand', $data['brand'] ), 'product_brand' );

	// --- 5. Biến thể ------------------------------------------------------------
	$variation_count = 0;
	if ( $is_variable ) {
		$color_images = [];   // mỗi màu một ảnh, dùng chung cho mọi dung lượng

		foreach ( $data['variations'] as $v ) {
			// SKU biến thể = SKU cha + giá trị: SS-S26-256GB-XANH-DUONG
			$sku = $data['sku'] . '-' . strtoupper( implode( '-', array_map( 'sanitize_title', $v['attributes'] ) ) );

			$variation_id = wc_get_product_id_by_sku( $sku );
			$variation    = $variation_id ? wc_get_product( $variation_id ) : new WC_Product_Variation();
			$variation->set_parent_id( $product_id );
			$variation->set_sku( $sku );

			// Biến thể lưu GIÁ TRỊ đã chọn: ['pa_dung-luong' => '256gb', 'pa_mau-sac' => 'den']
			$selected = [];
			foreach ( $v['attributes'] as $slug => $value ) {
				$selected[ wc_attribute_taxonomy_name( $slug ) ] = sanitize_title( $value );
			}
			$variation->set_attributes( $selected );

			$variation->set_regular_price( (string) $v['regular_price'] );
			$variation->set_sale_price( isset( $v['sale_price'] ) ? (string) $v['sale_price'] : '' );
			// Giá sale hẹn giờ: Woo tự bật/tắt bằng việc chạy nền "woocommerce_scheduled_sales"
			$variation->set_date_on_sale_from( isset( $v['sale_from'] ) ? kio_date( $v['sale_from'], '00:00:00' ) : null );
			$variation->set_date_on_sale_to( isset( $v['sale_to'] ) ? kio_date( $v['sale_to'], '23:59:59' ) : null );
			$variation->set_manage_stock( true );
			$variation->set_stock_quantity( $v['stock'] );

			// Màu của ảnh: lấy từ biến thể; laptop biến thể theo RAM/SSD thì lấy màu ở specs
			$color = $v['attributes']['mau-sac'] ?? ( $data['specs']['mau-sac'] ?? '' );
			if ( ! isset( $color_images[ $color ] ) ) {
				$color_images[ $color ] = kio_product_image( array_filter( [
					'device' => $data['image']['device'],
					'color'  => kio_color_hex( $color, $data['image']['color'] ?? '#d8d8d8' ),
					'title'  => $data['name'],
					'label'  => isset( $v['attributes']['mau-sac'] ) ? $color : null,
					'brand'  => $data['brand'],
					'badge'  => $data['image']['badge'] ?? null,
				] ) );
			}
			$variation->set_image_id( $color_images[ $color ] );
			$variation->save();
			$variation_count++;
		}

		// Ảnh đại diện = ảnh màu đầu tiên, thư viện ảnh = các màu còn lại.
		// Biến thể mặc định khi mở trang = biến thể đầu tiên trong JSON.
		$product = wc_get_product( $product_id );
		$product->set_image_id( reset( $color_images ) );
		$product->set_gallery_image_ids( array_values( array_slice( $color_images, 1 ) ) );
		$product->set_default_attributes( array_map( 'sanitize_title', array_combine(
			array_map( 'wc_attribute_taxonomy_name', array_keys( $data['variations'][0]['attributes'] ) ),
			$data['variations'][0]['attributes']
		) ) );
		$product->save();

		// Tính lại giá thấp nhất/cao nhất và tình trạng kho của sản phẩm cha từ các biến thể
		WC_Product_Variable::sync( $product_id );
	}

	$product = wc_get_product( $product_id );
	$money   = fn( $v ) => number_format( (float) $v, 0, ',', '.' );
	if ( $is_variable ) {
		$min   = $product->get_variation_price( 'min' );
		$max   = $product->get_variation_price( 'max' );
		$price = $min === $max ? $money( $min ) : $money( $min ) . ' – ' . $money( $max );
	} else {
		$price = $product->is_on_sale()
			? $money( $product->get_sale_price() ) . ' (gốc ' . $money( $product->get_regular_price() ) . ')'
			: $money( $product->get_regular_price() );
	}
	$report[] = [
		'ID'        => $product_id,
		'SKU'       => $data['sku'],
		'Tên'       => $data['name'],
		'Loại'      => $product->get_type(),
		'Giá'       => $price,
		'Kho'       => $product->get_stock_status(),
		'Biến thể'  => $variation_count,
		'Kết quả'   => $action,
	];
}

WP_CLI\Utils\format_items( 'table', $report, array_keys( $report[0] ) );
