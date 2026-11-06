<?php
/**
 * F3 — "Thông số nổi bật" trên thẻ sản phẩm.
 *
 * In 2–3 dòng ngắn ngay dưới tên sản phẩm trong vòng lặp danh mục/trang chủ,
 * lấy từ các thuộc tính toàn cục đã có: pa_chip, pa_ram, pa_man-hinh, pa_pin.
 *
 * Thứ tự và nội dung theo danh mục, cấu hình bằng mảng kio_store_spec_map():
 *   - điện thoại / tablet / laptop: chip, RAM, màn hình.
 *   - đồng hồ thông minh: chip, màn hình (dữ liệu không có RAM).
 *   - âm thanh / phụ kiện: pin.
 * Thuộc tính nào không có giá trị thì bỏ qua, nên sản phẩm có thể in ít hơn 3 dòng.
 *
 * Dùng hook woocommerce_after_shop_loop_item_title (priority 7, sau tên, trước giá)
 * nên KHÔNG cần override template content-product.php.
 *
 * @package KioStore
 */

defined( 'ABSPATH' ) || exit;

add_action( 'woocommerce_after_shop_loop_item_title', 'kio_store_render_loop_specs', 7 );
add_action( 'woocommerce_single_product_summary', 'kio_store_render_single_specs', 22 );

/**
 * Bản đồ danh mục → danh sách thuộc tính (theo thứ tự ưu tiên hiển thị).
 *
 * @return array
 */
function kio_store_spec_map(): array {
	return array(
		'dien-thoai'         => array( 'chip', 'ram', 'man-hinh' ),
		'may-tinh-bang'      => array( 'chip', 'ram', 'man-hinh' ),
		'laptop'             => array( 'chip', 'ram', 'man-hinh' ),
		'dong-ho-thong-minh' => array( 'chip', 'man-hinh' ),
		'am-thanh'           => array( 'pin' ),
		'phu-kien'           => array( 'pin' ),
	);
}

/**
 * Nhãn tiếng Việt cho từng thuộc tính.
 *
 * @return array
 */
function kio_store_spec_labels(): array {
	return array(
		'chip'     => __( 'Chip', 'kio-store' ),
		'ram'      => __( 'RAM', 'kio-store' ),
		'man-hinh' => __( 'Màn hình', 'kio-store' ),
		'pin'      => __( 'Pin', 'kio-store' ),
	);
}

/**
 * Giá trị một thuộc tính toàn cục của sản phẩm.
 *
 * Không dùng $product->get_attribute(): hàm đó qua wc_get_product_terms() chạy một query riêng cho
 * từng thuộc tính của từng thẻ (đo được 30 query ở trang danh mục, 49 ở trang chủ). get_the_terms()
 * đọc cache term mà vòng lặp đã nạp gộp cho cả danh sách, nên không tốn thêm query.
 *
 * @param WC_Product $product Sản phẩm.
 * @param string     $key     Tên thuộc tính, không có tiền tố pa_.
 * @return string Các giá trị nối bằng dấu phẩy; rỗng nếu không có.
 */
function kio_store_product_attribute_text( WC_Product $product, string $key ): string {
	$terms = get_the_terms( $product->get_id(), 'pa_' . $key );
	if ( ! is_array( $terms ) ) {
		return '';
	}

	return trim( implode( ', ', wp_list_pluck( $terms, 'name' ) ) );
}

/**
 * Danh sách thông số (nhãn → giá trị) của một sản phẩm theo danh mục của nó.
 *
 * @param WC_Product $product Sản phẩm cần lấy thông số.
 * @return array Mỗi phần tử là array( nhãn, giá trị ).
 */
function kio_store_product_specs( WC_Product $product ): array {
	$map    = kio_store_spec_map();
	$labels = kio_store_spec_labels();

	$keys = array();
	foreach ( $product->get_category_ids() as $category_id ) {
		$term = get_term( $category_id );
		if ( $term && ! is_wp_error( $term ) && isset( $map[ $term->slug ] ) ) {
			$keys = $map[ $term->slug ];
			break;
		}
	}

	if ( empty( $keys ) ) {
		return array();
	}

	$specs = array();
	foreach ( $keys as $key ) {
		$value = kio_store_product_attribute_text( $product, $key );
		if ( '' !== $value ) {
			$specs[] = array( $labels[ $key ] ?? ucfirst( $key ), $value );
		}
	}

	return $specs;
}

/**
 * ACTION woocommerce_after_shop_loop_item_title: in thông số nổi bật trên thẻ.
 *
 * @return void
 */
function kio_store_render_loop_specs(): void {
	global $product;

	if ( ! $product instanceof WC_Product ) {
		return;
	}

	$specs = kio_store_product_specs( $product );
	if ( empty( $specs ) ) {
		return;
	}

	echo '<div class="kio-specs">';
	foreach ( $specs as $spec ) {
		printf(
			'<span class="kio-specs__item"><span class="kio-specs__label">%1$s</span>%2$s</span>',
			esc_html( $spec[0] ),
			esc_html( $spec[1] )
		);
	}
	echo '</div>';
}

/**
 * ACTION woocommerce_single_product_summary: thông số nổi bật trên trang sản phẩm.
 *
 * Priority 22: sau mô tả ngắn (20), trước nút mua (30).
 *
 * @return void
 */
function kio_store_render_single_specs(): void {
	global $product;

	if ( ! $product instanceof WC_Product ) {
		return;
	}

	// Đã có bảng thông số đầy đủ thì thôi: vùng mua chỉ giữ thứ cần để quyết định mua.
	if ( kio_store_detailed_specs( $product ) ) {
		return;
	}

	$specs = kio_store_product_specs( $product );
	if ( empty( $specs ) ) {
		return;
	}

	echo '<div class="kio-spec-grid">';
	foreach ( $specs as $spec ) {
		printf(
			'<div class="kio-spec-grid__item"><span class="kio-spec-grid__label">%1$s</span><span class="kio-spec-grid__value">%2$s</span></div>',
			esc_html( $spec[0] ),
			esc_html( $spec[1] )
		);
	}
	echo '</div>';
}
