<?php
/**
 * F6 — Dải sản phẩm trang chủ bằng code + cache.
 *
 * Shortcode:
 *   [kio_product_row category="dien-thoai" limit="5" orderby="popularity" title="Điện thoại"]
 *
 * In một dải gồm: tiêu đề + "Xem tất cả" (link danh mục), các chip thương hiệu, lưới sản phẩm.
 *   - Chip thương hiệu TỰ SINH từ sản phẩm trong danh mục (không viết cứng), dẫn tới trang
 *     danh mục đã lọc theo thương hiệu (bộ lọc widget của Phase 3: filter_product_brand=<ID>).
 *     Muốn chọn tay: brands="apple,samsung".
 *   - Danh sách ID sản phẩm + thương hiệu lưu transient. Khoá cache gồm "số phiên bản"
 *     (option kio_product_rows_version): sản phẩm nào được lưu thì tăng số phiên bản, mọi
 *     transient cũ tự hết hiệu lực mà không phải tìm và xoá từng cái.
 *
 * So với shortcode [products] có sẵn của Woo (Phase 2 dùng): kết quả đo ở docs/phase4/phase-4.md.
 *
 * @package KioStore
 */

defined( 'ABSPATH' ) || exit;

add_shortcode( 'kio_product_row', 'kio_store_product_row_shortcode' );
add_action( 'woocommerce_update_product', 'kio_store_product_rows_bump_version' );
add_action( 'woocommerce_update_product_variation', 'kio_store_product_rows_bump_version' );
add_action( 'woocommerce_new_product', 'kio_store_product_rows_bump_version' );
add_action( 'woocommerce_delete_product', 'kio_store_product_rows_bump_version' );
add_action( 'woocommerce_trash_product', 'kio_store_product_rows_bump_version' );

/**
 * Tăng số phiên bản cache của các dải sản phẩm (gọi khi sản phẩm thay đổi).
 *
 * Không autoload: chỉ trang có dải sản phẩm mới cần đọc option này.
 *
 * @return void
 */
function kio_store_product_rows_bump_version(): void {
	update_option( 'kio_product_rows_version', (int) get_option( 'kio_product_rows_version', 1 ) + 1, false );
}

/**
 * Dữ liệu của một dải: ID sản phẩm + thương hiệu cho chip (có cache).
 *
 * @param array $args category, limit, orderby, brands (chuỗi slug, rỗng = tự sinh).
 * @return array{ids:int[],brands:array<int,array{id:int,name:string}>}
 */
function kio_store_product_row_data( array $args ): array {
	$version = (int) get_option( 'kio_product_rows_version', 1 );
	$key     = 'kio_row_' . md5( wp_json_encode( $args ) . '|' . $version );
	$cached  = get_transient( $key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	// Sắp xếp: popularity = bán chạy (meta total_sales), date = mới nhất, price = giá thấp → cao.
	$orderby = array(
		'popularity' => array(
			'orderby'  => 'meta_value_num',
			'meta_key' => 'total_sales', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- kết quả được cache trong transient.
			'order'    => 'DESC',
		),
		'date'       => array(
			'orderby' => 'date',
			'order'   => 'DESC',
		),
		'price'      => array(
			'orderby' => 'price',
			'order'   => 'ASC',
		),
	);

	$query = array_merge(
		array(
			'status'     => 'publish',
			'limit'      => $args['limit'],
			'category'   => array( $args['category'] ),
			'visibility' => 'catalog',
			'return'     => 'ids',
		),
		$orderby[ $args['orderby'] ] ?? $orderby['popularity']
	);
	$ids   = array_map( 'intval', wc_get_products( $query ) );

	// Thương hiệu: chọn tay theo slug, hoặc tự lấy từ MỌI sản phẩm của danh mục (không chỉ
	// sản phẩm đang hiện), sắp theo số sản phẩm giảm dần.
	$brands = array();
	if ( '' !== $args['brands'] ) {
		foreach ( array_map( 'trim', explode( ',', $args['brands'] ) ) as $slug ) {
			$term = get_term_by( 'slug', $slug, 'product_brand' );
			if ( $term ) {
				$brands[] = array(
					'id'   => (int) $term->term_id,
					'name' => $term->name,
				);
			}
		}
	} else {
		$all_ids = wc_get_products(
			array(
				'status'   => 'publish',
				'limit'    => -1,
				'category' => array( $args['category'] ),
				'return'   => 'ids',
			)
		);
		$counts  = array();
		foreach ( wp_get_object_terms( $all_ids, 'product_brand', array( 'fields' => 'all_with_object_id' ) ) as $term ) {
			$counts[ $term->term_id ] = array(
				'id'    => (int) $term->term_id,
				'name'  => $term->name,
				'count' => ( $counts[ $term->term_id ]['count'] ?? 0 ) + 1,
			);
		}
		usort(
			$counts,
			static function ( array $a, array $b ): int {
				return array( $b['count'], $a['name'] ) <=> array( $a['count'], $b['name'] );
			}
		);
		foreach ( $counts as $brand ) {
			$brands[] = array(
				'id'   => $brand['id'],
				'name' => $brand['name'],
			);
		}
	}

	$data = array(
		'ids'    => $ids,
		'brands' => $brands,
	);
	set_transient( $key, $data, DAY_IN_SECONDS );

	return $data;
}

/**
 * Shortcode [kio_product_row].
 *
 * @param array|string $atts Thuộc tính shortcode.
 * @return string HTML.
 */
function kio_store_product_row_shortcode( $atts ): string {
	$atts = shortcode_atts(
		array(
			'category' => '',
			'limit'    => 5,
			'orderby'  => 'popularity',
			'title'    => '',
			'brands'   => '',
			'columns'  => 5,
		),
		$atts,
		'kio_product_row'
	);

	$category = get_term_by( 'slug', sanitize_title( $atts['category'] ), 'product_cat' );
	if ( ! $category ) {
		return '';
	}

	$data = kio_store_product_row_data(
		array(
			'category' => $category->slug,
			'limit'    => max( 1, min( 20, absint( $atts['limit'] ) ) ),
			'orderby'  => sanitize_key( $atts['orderby'] ),
			'brands'   => sanitize_text_field( $atts['brands'] ),
		)
	);
	if ( empty( $data['ids'] ) ) {
		return '';
	}

	$category_url = get_term_link( $category );
	$title        = '' !== $atts['title'] ? $atts['title'] : $category->name;

	ob_start();
	?>
	<section class="kio-strip kio-product-row" data-category="<?php echo esc_attr( $category->slug ); ?>">
		<div class="kio-strip__head">
			<h2 class="kio-section-title"><?php echo esc_html( $title ); ?></h2>
			<a class="kio-strip__more" href="<?php echo esc_url( $category_url ); ?>"><?php esc_html_e( 'Xem tất cả', 'kio-store' ); ?></a>
		</div>
		<?php if ( ! empty( $data['brands'] ) ) : ?>
			<div class="kio-strip__chips">
				<?php
				foreach ( $data['brands'] as $brand ) :
					$brand_url = add_query_arg(
						array(
							'filtering'            => 1,
							'filter_product_brand' => $brand['id'],
						),
						$category_url
					);
					?>
					<a class="kio-strip__chip" href="<?php echo esc_url( $brand_url ); ?>"><?php echo esc_html( $brand['name'] ); ?></a>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
		<?php echo kio_store_product_loop_html( $data['ids'], 'kio_product_row', max( 1, min( 6, absint( $atts['columns'] ) ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML vòng lặp Woo, đã escape trong template. ?>
	</section>
	<?php

	return (string) ob_get_clean();
}
