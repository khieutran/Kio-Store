<?php
/**
 * F7 — Thanh mua hàng dính dưới màn hình (mobile, trang sản phẩm).
 *
 * Khi khách cuộn qua nút "Thêm vào giỏ" gốc, một thanh nhỏ hiện ở đáy màn hình: tên ngắn,
 * giá (đổi theo biến thể đang chọn), nút mua. Nút mua KHÔNG tự thêm vào giỏ mà bấm hộ nút gốc
 * của Woo → giữ nguyên mọi kiểm tra của Woo (chưa chọn biến thể, hết hàng, số lượng…).
 *
 * CSS + JS chỉ nạp trên trang sản phẩm (is_product), thanh chỉ hiện ở màn hình ≤ 768px.
 *
 * @package KioStore
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', 'kio_store_sticky_buy_bar_assets' );
add_action( 'wp_footer', 'kio_store_render_sticky_buy_bar' );

/**
 * Sản phẩm của trang hiện tại nếu là trang sản phẩm mua được; null nếu không.
 *
 * @return WC_Product|null
 */
function kio_store_sticky_buy_bar_product(): ?WC_Product {
	if ( ! is_product() ) {
		return null;
	}
	$product = wc_get_product( get_queried_object_id() );

	return ( $product && $product->is_purchasable() ) ? $product : null;
}

/**
 * Nạp CSS/JS của thanh mua hàng, chỉ trên trang sản phẩm.
 *
 * @return void
 */
function kio_store_sticky_buy_bar_assets(): void {
	if ( ! kio_store_sticky_buy_bar_product() ) {
		return;
	}

	wp_enqueue_style( 'kio-store-sticky-buy-bar', KIO_STORE_URL . 'assets/css/sticky-buy-bar.css', array(), KIO_STORE_VERSION );
	wp_enqueue_script(
		'kio-store-sticky-buy-bar',
		KIO_STORE_URL . 'assets/js/sticky-buy-bar.js',
		array( 'jquery' ), // nghe sự kiện found_variation / reset_data của Woo (jQuery).
		KIO_STORE_VERSION,
		array(
			'in_footer' => true,
			'strategy'  => 'defer',
		)
	);
}

/**
 * ACTION wp_footer: in thanh mua hàng (ẩn sẵn, JS quyết định khi nào hiện).
 *
 * @return void
 */
function kio_store_render_sticky_buy_bar(): void {
	$product = kio_store_sticky_buy_bar_product();
	if ( ! $product ) {
		return;
	}

	$label = $product->is_type( 'variable' ) ? __( 'Mua ngay', 'kio-store' ) : $product->single_add_to_cart_text();
	?>
	<div class="kio-buy-bar" hidden>
		<div class="kio-buy-bar__info">
			<span class="kio-buy-bar__name"><?php echo esc_html( $product->get_name() ); ?></span>
			<span class="kio-buy-bar__price"><?php echo wp_kses_post( $product->get_price_html() ); ?></span>
		</div>
		<button type="button" class="kio-buy-bar__button"><?php echo esc_html( $label ); ?></button>
	</div>
	<?php
}
