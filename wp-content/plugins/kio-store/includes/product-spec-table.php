<?php
/**
 * Bảng thông số kỹ thuật đầy đủ trên trang sản phẩm.
 *
 * Hai lớp: bảng rút gọn, bấm "Xem tất cả" thì bảng đầy đủ theo nhóm hiện ngay tại chỗ. Không dùng cửa sổ
 * nổi: khách đọc thông số để so sánh, cửa sổ che mất ảnh, giá và nút mua. Nội dung nằm sẵn trong HTML,
 * không tải AJAX: công cụ tìm kiếm đọc được, không thêm request, trang cache vẫn đủ dữ liệu.
 *
 * Lưu ở product meta _kio_specs (scripts/import-specs.php nạp từ data/specs.json), không tạo attribute:
 * chỉ dữ liệu dùng để lọc mới là attribute. Hàng chục attribute cho mỗi sản phẩm làm phình bảng term
 * mà không ai lọc theo "khẩu độ camera trước".
 *
 * @package KioStore
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', 'kio_store_spec_table_assets' );
add_action( 'woocommerce_after_single_product_summary', 'kio_store_render_spec_table', 6 );
add_filter( 'woocommerce_product_tabs', 'kio_store_remove_additional_info_tab', 98 );

/**
 * Thông số đầy đủ của một sản phẩm.
 *
 * @param WC_Product $product Sản phẩm.
 * @return array{source?:string,checked_at?:string,groups?:array} Rỗng nếu chưa có dữ liệu.
 */
function kio_store_detailed_specs( WC_Product $product ): array {
	$specs = $product->get_meta( '_kio_specs' );

	return is_array( $specs ) && ! empty( $specs['groups'] ) ? $specs : array();
}

/**
 * Sản phẩm của trang hiện tại nếu có thông số; null nếu không.
 *
 * @return WC_Product|null
 */
function kio_store_spec_table_product(): ?WC_Product {
	if ( ! is_product() ) {
		return null;
	}
	$product = wc_get_product( get_queried_object_id() );

	return $product && kio_store_detailed_specs( $product ) ? $product : null;
}

/**
 * Nạp CSS/JS, chỉ ở trang sản phẩm có thông số.
 *
 * @return void
 */
function kio_store_spec_table_assets(): void {
	if ( ! kio_store_spec_table_product() ) {
		return;
	}

	wp_enqueue_style( 'kio-store-spec-table', KIO_STORE_URL . 'assets/css/spec-table.css', array(), kio_store_asset_version( 'assets/css/spec-table.css' ) );
	wp_enqueue_script(
		'kio-store-spec-table',
		KIO_STORE_URL . 'assets/js/spec-table.js',
		array(),
		kio_store_asset_version( 'assets/js/spec-table.js' ),
		array(
			'in_footer' => true,
			'strategy'  => 'defer',
		)
	);
}

/**
 * FILTER woocommerce_product_tabs: có bảng thông số thì bỏ tab "Thông tin bổ sung" để không lặp.
 *
 * @param array $tabs Các tab của Woo.
 * @return array
 */
function kio_store_remove_additional_info_tab( array $tabs ): array {
	if ( kio_store_spec_table_product() ) {
		unset( $tabs['additional_information'] );
	}

	return $tabs;
}

/**
 * HTML các dòng của một bảng.
 *
 * @param array $rows Mỗi dòng có label, value.
 * @return string
 */
function kio_store_spec_rows_html( array $rows ): string {
	$html = '';
	foreach ( $rows as $row ) {
		$html .= sprintf( '<tr><th scope="row">%1$s</th><td>%2$s</td></tr>', esc_html( $row['label'] ), esc_html( $row['value'] ) );
	}

	return $html;
}

/**
 * ACTION woocommerce_after_single_product_summary: in bảng rút gọn và bảng đầy đủ.
 *
 * Priority 6: ngay sau khung ảnh và cột mua (5), trước khối tab (10).
 *
 * @return void
 */
function kio_store_render_spec_table(): void {
	global $product;

	if ( ! $product instanceof WC_Product ) {
		return;
	}
	$specs = kio_store_detailed_specs( $product );
	if ( ! $specs ) {
		return;
	}

	$main = array();
	foreach ( $specs['groups'] as $group ) {
		foreach ( $group['rows'] as $row ) {
			if ( ! empty( $row['main'] ) ) {
				// Tách khỏi nhóm thì "Độ phân giải" không rõ của màn hình hay camera nào: dùng nhãn riêng.
				$main[] = array(
					'label' => $row['short'] ?? $row['label'],
					'value' => $row['value'],
				);
			}
		}
	}
	$has_more = count( $main ) < array_sum( array_map( static fn( array $group ): int => count( $group['rows'] ), $specs['groups'] ) );
	?>
	<section class="kio-spec-table" id="thong-so" aria-labelledby="kio-spec-title">
		<h2 class="kio-section-title" id="kio-spec-title"><?php esc_html_e( 'Thông số kỹ thuật', 'kio-store' ); ?></h2>
		<table class="kio-spec-table__rows kio-spec-table__summary">
			<tbody><?php echo kio_store_spec_rows_html( $main ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- đã escape từng ô. ?></tbody>
		</table>
		<?php if ( $has_more ) : ?>
			<div class="kio-spec-table__full" id="kio-spec-full" hidden>
				<?php foreach ( $specs['groups'] as $group ) : ?>
					<div class="kio-spec-table__group">
						<h3><?php echo esc_html( $group['name'] ); ?></h3>
						<table class="kio-spec-table__rows">
							<tbody><?php echo kio_store_spec_rows_html( $group['rows'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- đã escape từng ô. ?></tbody>
						</table>
					</div>
				<?php endforeach; ?>
				<?php if ( ! empty( $specs['source'] ) ) : ?>
					<p class="kio-spec-table__source">
						<a href="<?php echo esc_url( $specs['source'] ); ?>" target="_blank" rel="noopener nofollow"><?php esc_html_e( 'Nguồn: trang chính thức của hãng', 'kio-store' ); ?></a>
					</p>
				<?php endif; ?>
			</div>
			<button type="button" class="kio-spec-table__more" aria-expanded="false" aria-controls="kio-spec-full" data-less="<?php esc_attr_e( 'Thu gọn', 'kio-store' ); ?>"><?php esc_html_e( 'Xem tất cả thông số', 'kio-store' ); ?></button>
		<?php endif; ?>
	</section>
	<?php
}
