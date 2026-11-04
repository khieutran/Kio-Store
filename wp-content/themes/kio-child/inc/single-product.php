<?php
/**
 * Trang sản phẩm: bố cục và chọn phiên bản bằng chip.
 *
 * Chip chỉ là cách điều khiển khác của ô chọn gốc: <select> của Woo vẫn nằm trong form (đã ẩn) và
 * vẫn là nguồn sự thật. Nhờ vậy giá, ảnh, tồn kho, nút mua, trả góp F2, thanh mua F7 vẫn chạy
 * bằng đúng các sự kiện của Woo, không phải viết lại.
 *
 * @package KioChild
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'kio_child_single_product_hooks' );
add_action( 'wp', 'kio_child_single_product_remove_sidebar' );
add_action( 'wp_head', 'kio_child_variation_chips_noscript' );
add_filter( 'body_class', 'kio_child_single_product_body_class' );
add_filter( 'storefront_related_products_args', 'kio_child_related_products_args' );
add_filter( 'woocommerce_dropdown_variation_attribute_options_args', 'kio_child_variation_select_args' );
add_filter( 'woocommerce_dropdown_variation_attribute_options_html', 'kio_child_variation_chips', 10, 2 );

/**
 * Gỡ 2 tính năng của Storefront: thẻ "sản phẩm trước/sau" bị cắt ở 2 mép màn hình, và thanh mua
 * dính trên cùng (đè lên header, trùng việc với thanh mua F7 và cột ảnh dính).
 *
 * @return void
 */
function kio_child_single_product_hooks(): void {
	remove_action( 'woocommerce_after_single_product_summary', 'storefront_single_product_pagination', 30 );
	remove_action( 'storefront_after_footer', 'storefront_sticky_single_add_to_cart', 999 );

	// Bọc nhãn + ảnh + cột mua: cột ảnh dính chỉ trong khung này, không trôi đè lên khối tab.
	add_action( 'woocommerce_before_single_product_summary', 'kio_child_product_top_open', 5 );
	add_action( 'woocommerce_after_single_product_summary', 'kio_child_product_top_close', 5 );
}

/**
 * Mở khung ảnh + cột mua.
 *
 * @return void
 */
function kio_child_product_top_open(): void {
	echo '<div class="kio-product-top">';
}

/**
 * Đóng khung ảnh + cột mua.
 *
 * @return void
 */
function kio_child_product_top_close(): void {
	echo '</div>';
}

/**
 * Trang dùng toàn bộ chiều rộng: sidebar chỉ chứa bộ lọc, mà bộ lọc không in gì ở các trang này.
 *
 * @return bool
 */
function kio_child_is_wide_page(): bool {
	return is_product() || is_cart() || is_checkout();
}

/**
 * Bỏ sidebar để cột phải không bị trống. Chạy ở hook wp vì is_product() chỉ có kết quả sau khi
 * WordPress phân tích URL.
 *
 * @return void
 */
function kio_child_single_product_remove_sidebar(): void {
	if ( kio_child_is_wide_page() ) {
		remove_action( 'woocommerce_sidebar', 'woocommerce_get_sidebar', 10 );
		remove_action( 'storefront_sidebar', 'storefront_get_sidebar', 10 );
	}
}

/**
 * FILTER body_class: dùng lớp toàn chiều rộng có sẵn của Storefront.
 *
 * @param string[] $classes Lớp của thẻ body.
 * @return string[]
 */
function kio_child_single_product_body_class( array $classes ): array {
	if ( kio_child_is_wide_page() ) {
		$classes   = array_diff( $classes, array( 'right-sidebar', 'left-sidebar' ) );
		$classes[] = 'storefront-full-width-content';
	}

	return $classes;
}

/**
 * FILTER storefront_related_products_args: trang đã rộng hết nên hiện đủ một hàng 5 thẻ.
 *
 * @param array $args Tham số sản phẩm liên quan.
 * @return array
 */
function kio_child_related_products_args( array $args ): array {
	$args['posts_per_page'] = 5;
	$args['columns']        = 5;

	return $args;
}

/**
 * FILTER woocommerce_dropdown_variation_attribute_options_args: gắn lớp để CSS ẩn ô chọn gốc.
 *
 * @param array $args Tham số ô chọn.
 * @return array
 */
function kio_child_variation_select_args( array $args ): array {
	$args['class'] = trim( ( $args['class'] ?? '' ) . ' kio-variation-select' );

	return $args;
}

/**
 * Các lựa chọn của một thuộc tính theo đúng thứ tự trong ô chọn gốc.
 *
 * @param WC_Product $product   Sản phẩm biến thể.
 * @param string     $attribute Tên thuộc tính.
 * @param array      $options   Giá trị Woo cho phép.
 * @return array<int,array{value:string,label:string,hex:string}>
 */
function kio_child_variation_choices( WC_Product $product, string $attribute, array $options ): array {
	$choices = array();

	if ( ! taxonomy_exists( $attribute ) ) {
		foreach ( $options as $option ) {
			$choices[] = array(
				'value' => (string) $option,
				'label' => (string) $option,
				'hex'   => '',
			);
		}

		return $choices;
	}

	foreach ( wc_get_product_terms( $product->get_id(), $attribute, array( 'fields' => 'all' ) ) as $term ) {
		if ( in_array( $term->slug, $options, true ) ) {
			$choices[] = array(
				'value' => $term->slug,
				'label' => $term->name,
				'hex'   => (string) sanitize_hex_color( (string) get_term_meta( $term->term_id, 'kio_color_hex', true ) ),
			);
		}
	}

	return $choices;
}

/**
 * FILTER woocommerce_dropdown_variation_attribute_options_html: in chip ngay sau ô chọn gốc.
 *
 * In từ PHP chứ không để JS dựng: chip có sẵn trong HTML đầu tiên nên trang không giật khi JS chạy.
 * Dùng radio thật: trình duyệt lo sẵn phím mũi tên, focus và cách đọc của trình đọc màn hình.
 * Giá từng lựa chọn và trạng thái hết hàng phụ thuộc lựa chọn còn lại nên JS điền sau.
 *
 * @param string $html HTML ô chọn gốc.
 * @param array  $args Tham số ô chọn.
 * @return string
 */
function kio_child_variation_chips( string $html, array $args ): string {
	$product   = $args['product'] ?? null;
	$attribute = (string) ( $args['attribute'] ?? '' );
	$options   = (array) ( $args['options'] ?? array() );

	if ( ! $product instanceof WC_Product || '' === $attribute || empty( $options ) ) {
		return $html;
	}

	$choices = kio_child_variation_choices( $product, $attribute, $options );
	if ( empty( $choices ) ) {
		return $html;
	}

	$name     = ! empty( $args['name'] ) ? (string) $args['name'] : 'attribute_' . sanitize_title( $attribute );
	$selected = (string) ( $args['selected'] ?? '' );
	$is_color = count( array_filter( array_column( $choices, 'hex' ) ) ) > 0;

	$chips = '';
	foreach ( $choices as $choice ) {
		$swatch = '' !== $choice['hex']
			? sprintf( '<span class="kio-chip__swatch" style="background:%s" aria-hidden="true"></span>', esc_attr( $choice['hex'] ) )
			: '';

		$chips .= sprintf(
			'<label class="kio-chip"><input type="radio" name="kio_chip_%1$s" value="%2$s"%3$s>%4$s<span class="kio-chip__text"><span class="kio-chip__label">%5$s</span>%6$s</span><span class="kio-chip__state screen-reader-text"></span></label>',
			esc_attr( $name ),
			esc_attr( $choice['value'] ),
			checked( $selected, $choice['value'], false ),
			$swatch,
			esc_html( $choice['label'] ),
			$is_color ? '' : '<span class="kio-chip__price"></span>'
		);
	}

	return $html . sprintf(
		'<div class="kio-chips%1$s" role="radiogroup" aria-label="%2$s" data-attribute="%3$s">%4$s</div>',
		$is_color ? ' kio-chips--color' : '',
		esc_attr( wc_attribute_label( $attribute, $product ) ),
		esc_attr( $name ),
		$chips
	);
}

/**
 * Tắt JS thì chip không hoạt động: hiện lại ô chọn gốc.
 *
 * @return void
 */
function kio_child_variation_chips_noscript(): void {
	if ( is_product() ) {
		echo '<noscript><style>.kio-chips{display:none}.kio-variation-select{display:block!important}</style></noscript>' . "\n";
	}
}
