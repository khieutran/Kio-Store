<?php
/**
 * Trang danh sách sản phẩm: chip lọc nhanh, nút sắp xếp, "Xem thêm".
 *
 * Mọi nút ở đây là link thật dùng đúng tham số lọc của Woo (filter_product_brand, min_price,
 * orderby…), nên chạy được cả khi tắt JS. assets/js/category-filter.js chỉ chặn cú bấm, tải chính
 * URL đó rồi thay nội dung: không có endpoint riêng, kết quả luôn khớp với trang thật.
 *
 * @package KioChild
 */

defined( 'ABSPATH' ) || exit;

const KIO_CHILD_PRODUCTS_PER_PAGE = 10; // Bội của 5 cột: không để lại hàng lẻ.

add_action( 'init', 'kio_child_archive_hooks' );
add_filter( 'loop_shop_per_page', 'kio_child_products_per_page', 20 );
add_action(
	'widgets_init',
	static function (): void {
		register_widget( 'Kio_Child_Price_Bands_Widget' );
	}
);

/**
 * Thay thanh sắp xếp của Storefront bằng thanh công cụ riêng.
 *
 * @return void
 */
function kio_child_archive_hooks(): void {
	foreach ( array( 'woocommerce_before_shop_loop', 'woocommerce_after_shop_loop' ) as $hook ) {
		remove_action( $hook, 'storefront_sorting_wrapper', 9 );
		remove_action( $hook, 'woocommerce_catalog_ordering', 10 );
		remove_action( $hook, 'woocommerce_result_count', 20 );
		remove_action( $hook, 'storefront_sorting_wrapper_close', 31 );
	}
	remove_action( 'woocommerce_before_shop_loop', 'storefront_woocommerce_pagination', 30 );

	// Trang danh mục vào thẳng bộ lọc và sản phẩm. Thẻ h1 vẫn còn, chỉ ẩn bằng CSS (assets/css/category.css).
	remove_action( 'woocommerce_archive_description', 'woocommerce_taxonomy_archive_description', 10 );
	remove_action( 'woocommerce_archive_description', 'woocommerce_product_archive_description', 10 );

	add_action( 'woocommerce_before_shop_loop', 'kio_child_archive_toolbar', 20 );
	add_action( 'woocommerce_after_shop_loop', 'kio_child_load_more', 25 );

	// Không có kết quả thì Woo bỏ qua hook đầu danh sách: vẫn phải in chip để khách bỏ lọc được.
	add_action( 'woocommerce_no_products_found', 'kio_child_quick_filters', 5 );
}

/**
 * FILTER loop_shop_per_page.
 *
 * @return int
 */
function kio_child_products_per_page(): int {
	return KIO_CHILD_PRODUCTS_PER_PAGE;
}

/**
 * URL của trang hiện tại (về trang 1) sau khi đổi tham số.
 *
 * @param array $set    Tham số cần đặt.
 * @param array $remove Tên tham số cần bỏ.
 * @return string
 */
function kio_child_archive_url( array $set = array(), array $remove = array() ): string {
	$url = remove_query_arg( array_merge( $remove, array( 'paged', 'product-page' ) ), get_pagenum_link( 1, false ) );

	return add_query_arg( array_map( 'rawurlencode', $set ), $url );
}

/**
 * Tên các tham số lọc đang có trên URL.
 *
 * @return string[]
 */
function kio_child_archive_filter_keys(): array {
	$keys = array();
	foreach ( array_keys( $_GET ) as $key ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- chỉ đọc tên tham số để dựng link.
		if ( preg_match( '/^(filter_|query_type_)/', (string) $key ) || in_array( $key, array( 'filtering', 'min_price', 'max_price' ), true ) ) {
			$keys[] = (string) $key;
		}
	}

	return $keys;
}

/**
 * Các khoảng giá chip lọc nhanh.
 *
 * @return array<int,array{label:string,min:int,max:int}> max = 0 nghĩa là không giới hạn trên.
 */
function kio_child_price_bands(): array {
	$million = 1000000;
	$bands   = array(
		array( __( 'Dưới 1 triệu', 'kio-child' ), 0, 1 ),
		array( __( '1 - 3 triệu', 'kio-child' ), 1, 3 ),
		array( __( '3 - 5 triệu', 'kio-child' ), 3, 5 ),
		array( __( '5 - 7 triệu', 'kio-child' ), 5, 7 ),
		array( __( '7 - 10 triệu', 'kio-child' ), 7, 10 ),
		array( __( '10 - 20 triệu', 'kio-child' ), 10, 20 ),
		array( __( 'Trên 20 triệu', 'kio-child' ), 20, 0 ),
	);

	return array_map(
		static function ( array $band ) use ( $million ): array {
			return array(
				'label' => $band[0],
				'min'   => $band[1] * $million,
				'max'   => $band[2] * $million,
			);
		},
		$bands
	);
}

/**
 * Các nấc giá dùng được ở trang hiện tại: chỉ nấc có sản phẩm, kèm link và trạng thái đang chọn.
 *
 * Dùng chung cho chip lọc nhanh và widget ở sidebar, để 2 nơi luôn khớp nhau.
 *
 * @return array<int,array{label:string,url:string,active:bool}>
 */
function kio_child_price_band_links(): array {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- chỉ đọc bộ lọc đang áp dụng để dựng link.
	$min = isset( $_GET['min_price'] ) ? absint( $_GET['min_price'] ) : null;
	$max = isset( $_GET['max_price'] ) ? absint( $_GET['max_price'] ) : null;
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	$available = kio_child_available_bands( is_product_category() ? get_queried_object()->slug : '' );
	$links     = array();

	foreach ( kio_child_price_bands() as $index => $band ) {
		$active = null !== $min && $min === $band['min'] && ( 0 === $band['max'] ? null === $max : $max === $band['max'] );

		if ( ! $active && ! in_array( $index, $available, true ) ) {
			continue;
		}

		$set = array( 'min_price' => (string) $band['min'] );
		if ( $band['max'] > 0 ) {
			$set['max_price'] = (string) $band['max'];
		}

		$links[] = array(
			'label'  => $band['label'],
			'url'    => $active ? kio_child_archive_url( array(), array( 'min_price', 'max_price' ) ) : kio_child_archive_url( $set, array( 'max_price' ) ),
			'active' => $active,
		);
	}

	return $links;
}

/**
 * Vị trí các khoảng giá có sản phẩm trong danh mục, để không hiện chip bấm vào ra trang rỗng.
 *
 * Giá lấy theo giá thấp nhất của sản phẩm. Lưu transient, khoá gồm số phiên bản của F6 nên tự hết
 * hiệu lực khi có sản phẩm được lưu.
 *
 * @param string $category Slug danh mục, rỗng = cả cửa hàng.
 * @return int[]
 */
function kio_child_available_bands( string $category ): array {
	$key    = 'kio_bands_' . md5( $category . '|' . (int) get_option( 'kio_product_rows_version', 1 ) );
	$cached = get_transient( $key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$args = array(
		'status' => 'publish',
		'limit'  => -1,
	);
	if ( '' !== $category ) {
		$args['category'] = array( $category );
	}

	$found = array();
	foreach ( wc_get_products( $args ) as $product ) {
		$price = (float) $product->get_price();
		foreach ( kio_child_price_bands() as $index => $band ) {
			if ( $price >= $band['min'] && ( 0 === $band['max'] || $price < $band['max'] ) ) {
				$found[ $index ] = $index;
			}
		}
	}

	$found = array_values( $found );
	set_transient( $key, $found, DAY_IN_SECONDS );

	return $found;
}

/**
 * Các mốc của thanh kéo giá: từ nấc thấp nhất đến nấc cao nhất có sản phẩm trong danh mục.
 *
 * @return int[] Giá từng mốc. Mốc cuối bằng 0 nghĩa là không giới hạn trên.
 */
function kio_child_price_stops(): array {
	$available = kio_child_available_bands( is_product_category() ? get_queried_object()->slug : '' );
	if ( ! $available ) {
		return array();
	}

	$bands = array_slice( kio_child_price_bands(), min( $available ), max( $available ) - min( $available ) + 1 );
	$stops = array_column( $bands, 'min' );

	$stops[] = end( $bands )['max'];

	return $stops;
}

/**
 * Tên ngắn của một khoảng giá, cho chip khi khoảng đó không trùng nấc nào.
 *
 * @param int|null $min Giá thấp nhất.
 * @param int|null $max Giá cao nhất; null = không giới hạn.
 * @return string
 */
function kio_child_price_range_label( ?int $min, ?int $max ): string {
	$million = static fn( int $price ): string => number_format_i18n( $price / 1000000, 0 === $price % 1000000 ? 0 : 1 );

	if ( ! $max ) {
		/* translators: %s: giá, đơn vị triệu. */
		return sprintf( __( 'Từ %s triệu', 'kio-child' ), $million( (int) $min ) );
	}

	/* translators: 1: giá thấp, 2: giá cao, đơn vị triệu. */
	return sprintf( __( '%1$s - %2$s triệu', 'kio-child' ), $million( (int) $min ), $million( $max ) );
}

/**
 * Thương hiệu cho chip lọc nhanh.
 *
 * @return array<int,array{id:int,name:string}>
 */
function kio_child_archive_brands(): array {
	if ( is_tax( 'product_brand' ) ) {
		return array();
	}

	if ( is_product_category() && function_exists( 'kio_store_product_row_data' ) ) {
		return kio_store_product_row_data(
			array(
				'category' => get_queried_object()->slug,
				'limit'    => 1,
				'orderby'  => 'popularity',
				'brands'   => '',
				'brand'    => 0,
			)
		)['brands'];
	}

	$terms = get_terms( array( 'taxonomy' => 'product_brand' ) );

	return is_array( $terms ) ? array_map(
		static function ( WP_Term $term ): array {
			return array(
				'id'   => (int) $term->term_id,
				'name' => $term->name,
			);
		},
		$terms
	) : array();
}

/**
 * In một chip. Chip đang chọn có dấu × và link của nó là link bỏ lọc.
 *
 * @param string $label  Nhãn.
 * @param string $url    Đích khi bấm.
 * @param bool   $active Đang chọn.
 * @return string HTML.
 */
function kio_child_filter_chip( string $label, string $url, bool $active ): string {
	return sprintf(
		'<a class="kio-filter-chip kio-archive-link%1$s" href="%2$s" rel="nofollow"%3$s>%4$s%5$s</a>',
		$active ? ' is-active' : '',
		esc_url( $url ),
		$active ? ' aria-current="true"' : '',
		esc_html( $label ),
		/* translators: Chữ đọc thêm cho trình đọc màn hình ở chip đang chọn. */
		$active ? '<span class="kio-filter-chip__x" aria-hidden="true">&times;</span><span class="screen-reader-text">' . esc_html__( '(bỏ lọc)', 'kio-child' ) . '</span>' : ''
	);
}

/**
 * Hàng chip lọc nhanh: thương hiệu, khoảng giá, các bộ lọc khác đang bật ở sidebar.
 *
 * @return void
 */
function kio_child_quick_filters(): void {
	if ( is_search() ) {
		return;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- chỉ đọc bộ lọc đang áp dụng để dựng link.
	$chosen_brands = isset( $_GET['filter_product_brand'] ) ? array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_GET['filter_product_brand'] ) ) ) ) ) : array();
	$min           = isset( $_GET['min_price'] ) ? absint( $_GET['min_price'] ) : null;
	$max           = isset( $_GET['max_price'] ) ? absint( $_GET['max_price'] ) : null;
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	$chips = '';

	foreach ( kio_child_archive_brands() as $brand ) {
		$active = in_array( $brand['id'], $chosen_brands, true );
		$next   = $active ? array_diff( $chosen_brands, array( $brand['id'] ) ) : array_merge( $chosen_brands, array( $brand['id'] ) );
		$url    = empty( $next )
			? kio_child_archive_url( array(), array( 'filter_product_brand', 'filtering' ) )
			: add_query_arg( 'filter_product_brand', implode( ',', $next ), kio_child_archive_url( array( 'filtering' => '1' ) ) );
		$chips .= kio_child_filter_chip( $brand['name'], $url, $active );
	}

	$is_preset = false;
	foreach ( kio_child_price_band_links() as $band ) {
		$is_preset = $is_preset || $band['active'];
		$chips    .= kio_child_filter_chip( $band['label'], $band['url'], $band['active'] );
	}

	// Khoảng kéo qua nhiều nấc: vẫn hiện chip để khách bỏ lọc được.
	if ( ! $is_preset && ( null !== $min || null !== $max ) ) {
		$chips .= kio_child_filter_chip( kio_child_price_range_label( $min, $max ), kio_child_archive_url( array(), array( 'min_price', 'max_price' ) ), true );
	}

	// Bộ lọc bật từ sidebar (dung lượng, RAM, màu): hiện ở đây để bỏ được mà không phải mở sidebar.
	foreach ( WC_Query::get_layered_nav_chosen_attributes() as $taxonomy => $data ) {
		$name = 'filter_' . wc_attribute_taxonomy_slug( $taxonomy );
		$type = 'query_type_' . wc_attribute_taxonomy_slug( $taxonomy );
		foreach ( $data['terms'] as $slug ) {
			$term = get_term_by( 'slug', $slug, $taxonomy );
			if ( ! $term ) {
				continue;
			}
			$rest   = array_diff( $data['terms'], array( $slug ) );
			$url    = empty( $rest ) ? kio_child_archive_url( array(), array( $name, $type ) ) : add_query_arg( $name, implode( ',', $rest ), kio_child_archive_url() );
			$chips .= kio_child_filter_chip( $term->name, $url, true );
		}
	}

	$keys = kio_child_archive_filter_keys();
	if ( $keys ) {
		$chips .= sprintf(
			'<a class="kio-filter-clear kio-archive-link" href="%s" rel="nofollow">%s</a>',
			esc_url( kio_child_archive_url( array(), $keys ) ),
			esc_html__( 'Xoá lọc', 'kio-child' )
		);
	}

	if ( '' !== $chips ) {
		printf( '<div class="kio-quick-filters" role="group" aria-label="%s">%s</div>', esc_attr__( 'Lọc nhanh', 'kio-child' ), $chips ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- từng chip đã escape.
	}
}

/**
 * Các nút sắp xếp.
 *
 * @return void
 */
function kio_child_sort_buttons(): void {
	$options = array(
		''           => __( 'Nổi bật', 'kio-child' ),
		'price'      => __( 'Giá thấp', 'kio-child' ),
		'price-desc' => __( 'Giá cao', 'kio-child' ),
		'popularity' => __( 'Bán chạy', 'kio-child' ),
	);
	$current = isset( $_GET['orderby'] ) ? sanitize_text_field( wp_unslash( $_GET['orderby'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- chỉ đọc để đánh dấu nút.

	echo '<div class="kio-sort" role="group" aria-label="' . esc_attr__( 'Sắp xếp', 'kio-child' ) . '">';
	foreach ( $options as $value => $label ) {
		printf(
			'<a class="kio-sort__item kio-archive-link" href="%1$s" rel="nofollow"%2$s>%3$s</a>',
			esc_url( '' === $value ? kio_child_archive_url( array(), array( 'orderby' ) ) : kio_child_archive_url( array( 'orderby' => $value ) ) ),
			$current === $value ? ' aria-current="true"' : '',
			esc_html( $label )
		);
	}
	echo '</div>';
}

/**
 * ACTION woocommerce_before_shop_loop: chip lọc nhanh + hàng sắp xếp.
 *
 * @return void
 */
function kio_child_archive_toolbar(): void {
	echo '<div class="kio-archive-bar" tabindex="-1">';
	kio_child_quick_filters();
	echo '<div class="kio-archive-bar__row">';
	kio_child_sort_buttons();
	woocommerce_result_count();
	kio_child_filter_toggle();
	echo '</div></div>';
}

/**
 * ACTION woocommerce_after_shop_loop: nút "Xem thêm".
 *
 * Là link tới trang kế tiếp: tắt JS vẫn dùng được, phân trang gốc của Woo vẫn in ở dưới cho công cụ
 * tìm kiếm. Có JS thì CSS ẩn phân trang và category-filter.js nối thêm sản phẩm.
 *
 * @return void
 */
function kio_child_load_more(): void {
	$current = max( 1, (int) wc_get_loop_prop( 'current_page' ) );
	$pages   = (int) wc_get_loop_prop( 'total_pages' );
	$total   = (int) wc_get_loop_prop( 'total' );

	if ( $current >= $pages ) {
		return;
	}

	$remaining = $total - $current * (int) wc_get_loop_prop( 'per_page' );

	printf(
		'<div class="kio-load-more"><a class="kio-load-more__button" href="%1$s" rel="next" data-total="%2$d">%3$s</a></div>',
		esc_url( get_pagenum_link( $current + 1, false ) ),
		esc_attr( (string) $total ),
		/* translators: %s: số sản phẩm chưa hiện. */
		esc_html( sprintf( __( 'Xem thêm %s sản phẩm', 'kio-child' ), number_format_i18n( $remaining ) ) )
	);
}
