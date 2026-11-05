<?php
/**
 * F5 — Flash Sale: shortcode [kio_flash_sale], đồng hồ đếm ngược, cache, REST.
 *
 * Flash sale = sản phẩm/biến thể có giá sale HẸN GIỜ đang hiệu lực (Woo lưu ở meta
 * _sale_price_dates_from/_sale_price_dates_to; Xem Phase 1: Action Scheduler
 * wc_product_end_scheduled_sale tự tắt sale khi hết hạn). Đồng hồ đếm ngược tới thời
 * điểm kết thúc SỚM NHẤT trong các sản phẩm đang flash sale.
 *
 * Kết quả truy vấn lưu transient `kio_flash_sale` (xoá khi sản phẩm được lưu).
 *
 * Bước 1 (file này): shortcode [kio_flash_sale limit="8"] + JS đếm ngược.
 * Bước 2: block kio-store/flash-sale (block.json + render.php) dùng lại kio_store_flash_sale_render().
 * Bước 3: REST GET /wp-json/kio/v1/flash-sale (kio_store_flash_sale_rest).
 *
 * @package KioStore
 */

defined( 'ABSPATH' ) || exit;

add_shortcode( 'kio_flash_sale', 'kio_store_flash_sale_shortcode' );
// Xoá cache khi dữ liệu sale có thể đã đổi. Sale nằm ở BIẾN THỂ, và khi Action Scheduler bật/tắt
// giá sale hẹn giờ (wc_product_start/end_scheduled_sale) Woo lưu biến thể → hook *_variation,
// không phải woocommerce_update_product. Thiếu hook này thì cache giữ sản phẩm đã hết sale.
add_action( 'woocommerce_update_product', 'kio_store_flash_sale_invalidate' );
add_action( 'woocommerce_update_product_variation', 'kio_store_flash_sale_invalidate' );
add_action( 'woocommerce_new_product', 'kio_store_flash_sale_invalidate' );
add_action( 'woocommerce_delete_product', 'kio_store_flash_sale_invalidate' );
add_action( 'woocommerce_trash_product', 'kio_store_flash_sale_invalidate' );
add_action( 'rest_api_init', 'kio_store_register_flash_sale_rest' );
add_action( 'woocommerce_after_shop_loop_item_title', 'kio_store_render_flash_sale_progress', 18 );
add_action( 'init', 'kio_store_register_flash_sale_block' );

/**
 * Thời điểm kết thúc sale sớm nhất của một sản phẩm (theo giờ đang hiệu lực).
 *
 * Sản phẩm biến thể lưu sale ở từng biến thể, nên duyệt các biến thể đang sale và
 * lấy sale_to nhỏ nhất. Sản phẩm sale vĩnh viễn (không có sale_to) bị bỏ qua vì
 * không phải "flash sale hẹn giờ".
 *
 * @param WC_Product $product Sản phẩm cần kiểm tra.
 * @return int|null Unix timestamp kết thúc sớm nhất, null nếu không phải flash sale.
 */
function kio_store_product_sale_end( WC_Product $product ): ?int {
	if ( $product->is_type( 'variable' ) ) {
		$ends = array();
		foreach ( $product->get_children() as $child_id ) {
			$child = wc_get_product( $child_id );
			if ( $child && $child->is_on_sale() && $child->get_date_on_sale_to() ) {
				$ends[] = $child->get_date_on_sale_to()->getTimestamp();
			}
		}
		return empty( $ends ) ? null : min( $ends );
	}

	if ( $product->is_on_sale() && $product->get_date_on_sale_to() ) {
		return $product->get_date_on_sale_to()->getTimestamp();
	}

	return null;
}

/**
 * Dữ liệu flash sale: danh sách ID sản phẩm + thời điểm kết thúc sớm nhất (có cache).
 *
 * @return array{ids:int[],ends_at:int}
 */
function kio_store_flash_sale_data(): array {
	$data = get_transient( 'kio_flash_sale' );
	if ( false !== $data && is_array( $data ) ) {
		return $data;
	}

	$products = wc_get_products(
		array(
			'status' => 'publish',
			'limit'  => -1,
		)
	);

	$ids      = array();
	$ends     = array();
	$progress = array();
	foreach ( $products as $product ) {
		$end = kio_store_product_sale_end( $product );
		if ( null !== $end ) {
			$ids[]                          = $product->get_id();
			$ends[]                         = $end;
			$progress[ $product->get_id() ] = kio_store_sold_percent( $product );
		}
	}

	$data = array(
		'ids'      => $ids,
		'ends_at'  => empty( $ends ) ? 0 : min( $ends ),
		'progress' => $progress,
	);

	// Hết hạn sau 1 giờ, hoặc ĐÚNG lúc flash sale sớm nhất kết thúc nếu sớm hơn: lỡ Action
	// Scheduler chạy trễ thì cache vẫn không giữ sản phẩm đã quá giờ.
	$ttl = HOUR_IN_SECONDS;
	if ( $data['ends_at'] > 0 ) {
		$ttl = max( 1, min( $ttl, $data['ends_at'] - time() ) );
	}
	set_transient( 'kio_flash_sale', $data, $ttl );
	kio_store_schedule_flash_sale_cache_clear( (int) $data['ends_at'] );

	return $data;
}

/**
 * Phần trăm đã bán: đã bán / (đã bán + còn trong kho). Sản phẩm biến thể cộng tồn của từng biến thể.
 *
 * Tính trong lúc dựng cache nên việc nạp từng biến thể chỉ tốn lúc cache hết hạn.
 *
 * @param WC_Product $product Sản phẩm.
 * @return int|null Phần trăm 0–100; null nếu không biết tồn kho (không theo dõi kho, chưa bán).
 */
function kio_store_sold_percent( WC_Product $product ): ?int {
	$items = $product->is_type( 'variable' ) ? array_filter( array_map( 'wc_get_product', $product->get_children() ) ) : array( $product );
	$stock = 0;
	foreach ( $items as $item ) {
		if ( ! $item->managing_stock() ) {
			return null;
		}
		$stock += max( 0, (int) $item->get_stock_quantity() );
	}

	$sold = (int) $product->get_total_sales();

	return $sold + $stock > 0 ? (int) round( $sold / ( $sold + $stock ) * 100 ) : null;
}

/**
 * ACTION woocommerce_after_shop_loop_item_title: thanh "Đã bán x%", chỉ trong khối Flash Sale.
 *
 * Tên vòng lặp do kio_store_product_loop_html() đặt, nên cùng một hook mà các lưới khác không in.
 * Thanh vẽ bằng transform: scaleX (đọc --kio-sold) thay vì đổi width.
 *
 * @return void
 */
function kio_store_render_flash_sale_progress(): void {
	global $product;

	if ( 'kio_flash_sale' !== wc_get_loop_prop( 'name' ) || ! $product instanceof WC_Product ) {
		return;
	}

	$percent = kio_store_flash_sale_data()['progress'][ $product->get_id() ] ?? null;
	if ( null === $percent ) {
		return;
	}

	printf(
		'<div class="kio-sold-bar"><span class="kio-sold-bar__text">%1$s</span><span class="kio-sold-bar__track" aria-hidden="true"><span class="kio-sold-bar__fill" style="--kio-sold:%2$s"></span></span></div>',
		/* translators: %d: phần trăm đã bán. */
		esc_html( sprintf( __( 'Đã bán %d%%', 'kio-store' ), $percent ) ),
		esc_attr( (string) ( $percent / 100 ) )
	);
}

/**
 * Xoá cache flash sale (gọi khi có sản phẩm được lưu).
 *
 * @return void
 */
function kio_store_flash_sale_invalidate(): void {
	delete_transient( 'kio_flash_sale' );
}

/**
 * Render khối flash sale (đồng hồ + lưới sản phẩm), dùng chung cho shortcode và block.
 *
 * @param array $atts Thuộc tính: limit (số sản phẩm, mặc định 8).
 * @return string HTML; rỗng nếu không có sản phẩm flash sale.
 */
function kio_store_flash_sale_render( array $atts ): string {
	$limit = isset( $atts['limit'] ) ? absint( $atts['limit'] ) : 8;
	$data  = kio_store_flash_sale_data();

	if ( empty( $data['ids'] ) ) {
		return '';
	}

	$ids  = array_slice( $data['ids'], 0, $limit );
	$ends = (int) $data['ends_at'];

	// Enqueue JS đếm ngược ngay khi khối được render (shortcode/block nằm trong
	// the_content, chạy trước wp_footer nên script nạp ở footer là kịp).
	wp_enqueue_script(
		'kio-store-flash-sale',
		KIO_STORE_URL . 'assets/js/flash-sale.js',
		array(),
		kio_store_asset_version( 'assets/js/flash-sale.js' ),
		array(
			'in_footer' => true,
			'strategy'  => 'defer',
		)
	);
	wp_enqueue_script( 'kio-store-carousel' );

	ob_start();
	?>
	<div class="kio-flash-sale">
		<div class="kio-flash-sale__head">
			<h2 class="kio-flash-sale__title"><?php esc_html_e( 'Flash Sale', 'kio-store' ); ?></h2>
			<?php if ( $ends > 0 ) : ?>
				<div class="kio-flash-sale__timer" data-ends-at="<?php echo esc_attr( (string) $ends ); ?>">
					<span class="kio-flash-sale__timer-label"><?php esc_html_e( 'Kết thúc trong', 'kio-store' ); ?></span>
					<span class="kio-flash-sale__countdown">
						<span class="kio-flash-sale__unit" data-unit="days">0</span><span class="kio-flash-sale__sep"><?php esc_html_e( 'ngày', 'kio-store' ); ?></span>
						<span class="kio-flash-sale__unit" data-unit="hours">00</span><span class="kio-flash-sale__sep">:</span>
						<span class="kio-flash-sale__unit" data-unit="minutes">00</span><span class="kio-flash-sale__sep">:</span>
						<span class="kio-flash-sale__unit" data-unit="seconds">00</span>
					</span>
				</div>
			<?php endif; ?>
		</div>

		<div data-kio-carousel data-label="<?php esc_attr_e( 'Sản phẩm Flash Sale', 'kio-store' ); ?>">
			<?php echo kio_store_product_loop_html( $ids, 'kio_flash_sale', 5 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML vòng lặp Woo, đã escape trong template. ?>
		</div>
	</div>
	<?php

	return ob_get_clean();
}

/**
 * Shortcode [kio_flash_sale limit="8"].
 *
 * @param array|string $atts Thuộc tính shortcode.
 * @return string HTML.
 */
function kio_store_flash_sale_shortcode( $atts ): string {
	$atts = shortcode_atts(
		array(
			'limit' => 8,
		),
		$atts,
		'kio_flash_sale'
	);

	return kio_store_flash_sale_render( $atts );
}

/**
 * Đăng ký block kio-store/flash-sale từ blocks/flash-sale/block.json.
 *
 * File block.json khai báo "render": "file:./render.php" nên WordPress tự gọi render.php khi in
 * khối; render.php dùng lại đúng hàm kio_store_flash_sale_render() của shortcode → một nguồn HTML
 * duy nhất. Phần trong trình soạn thảo (editor.js) chỉ hiển thị bản xem trước do server render.
 *
 * @return void
 */
function kio_store_register_flash_sale_block(): void {
	register_block_type( KIO_STORE_DIR . 'blocks/flash-sale' );
}

/**
 * Đăng ký route REST GET /wp-json/kio/v1/flash-sale.
 *
 * Route công khai, chỉ đọc: trả đúng những gì khách đã thấy trên trang chủ (tên, giá, ảnh,
 * link sản phẩm đang flash sale), không có dữ liệu riêng tư → permission_callback là
 * __return_true một cách có chủ đích. Route ghi dữ liệu thì KHÔNG bao giờ được làm vậy.
 *
 * @return void
 */
function kio_store_register_flash_sale_rest(): void {
	register_rest_route(
		'kio/v1',
		'/flash-sale',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'kio_store_flash_sale_rest',
			'permission_callback' => '__return_true',
		)
	);
}

/**
 * Callback REST: danh sách sản phẩm đang flash sale + thời điểm kết thúc.
 *
 * @return WP_REST_Response
 */
function kio_store_flash_sale_rest(): WP_REST_Response {
	$data     = kio_store_flash_sale_data();
	$products = array();

	foreach ( $data['ids'] as $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			continue;
		}
		$image      = wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_thumbnail' );
		$products[] = array(
			'id'               => $product->get_id(),
			'name'             => $product->get_name(),
			'sku'              => $product->get_sku(),
			'permalink'        => get_permalink( $product->get_id() ),
			'price'            => (float) $product->get_price(),
			'discount_percent' => function_exists( 'kio_store_discount_percent' ) ? kio_store_discount_percent( $product ) : null,
			'sale_ends_at'     => kio_store_product_sale_end( $product ),
			'image'            => $image ? $image : null,
		);
	}

	return new WP_REST_Response(
		array(
			'ends_at'     => (int) $data['ends_at'],
			'ends_at_iso' => $data['ends_at'] ? wp_date( 'c', (int) $data['ends_at'] ) : null,
			'count'       => count( $products ),
			'products'    => $products,
		)
	);
}
