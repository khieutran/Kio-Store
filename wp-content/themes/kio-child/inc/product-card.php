<?php
/**
 * Thẻ sản phẩm: phần trình bày dùng chung ở mọi lưới (danh mục, trang chủ, flash sale, liên quan).
 *
 * Không copy template content-product.php của Woo: template đó chỉ gọi 4 hook, mỗi hook có nhiều
 * "mảnh" xếp theo priority. Chèn thêm mảnh vào đúng priority là đủ, và mọi thứ plugin gắn vào
 * thẻ (nhãn F1, thông số F3, trả góp F2, yêu thích, so sánh) vẫn chạy.
 *
 * Thứ tự in trong một thẻ:
 *   woocommerce_before_shop_loop_item_title
 *     4  mở khung ảnh + nhóm nhãn, in nhãn giảm giá   (file này)
 *     5  nhãn Mới / Hết hàng                          (kio-store, F1)
 *     6  đóng nhóm nhãn                               (file này)
 *     10 ảnh                                          (Woo)
 *     11 đóng khung ảnh                               (file này)
 *   woocommerce_after_shop_loop_item_title
 *     7  thông số                                     (kio-store, F3)
 *     9  bật hiển thị giá "Từ X"                      (file này)
 *     10 giá                                          (Woo)
 *     11 tắt hiển thị giá "Từ X"                      (file này)
 *     15 trả góp                                      (kio-store, F2)
 *     20 sao + đã bán                                 (file này)
 *     25 số ưu đãi                                    (file này)
 *
 * @package KioChild
 */

defined( 'ABSPATH' ) || exit;

/**
 * Gắn các mảnh của thẻ sản phẩm.
 *
 * Chạy ở init vì remove_action() chỉ gỡ được thứ đã đăng ký: Storefront đăng ký lúc nạp theme.
 *
 * @return void
 */
function kio_child_product_card_hooks(): void {
	// Storefront in nhãn giảm giá SAU tiêu đề, Woo in sao ngay dưới tiêu đề: thẻ này tự đặt lại.
	remove_action( 'woocommerce_after_shop_loop_item_title', 'woocommerce_show_product_loop_sale_flash', 6 );
	remove_action( 'woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_rating', 5 );

	add_action( 'woocommerce_before_shop_loop_item_title', 'kio_child_card_media_open', 4 );
	add_action( 'woocommerce_before_shop_loop_item_title', 'kio_child_card_badges_close', 6 );
	add_action( 'woocommerce_before_shop_loop_item_title', 'kio_child_card_media_close', 11 );

	add_action( 'woocommerce_after_shop_loop_item_title', 'kio_child_card_price_from_on', 9 );
	add_action( 'woocommerce_after_shop_loop_item_title', 'kio_child_card_price_from_off', 11 );
	add_action( 'woocommerce_after_shop_loop_item_title', 'kio_child_card_meta', 20 );
	add_action( 'woocommerce_after_shop_loop_item_title', 'kio_child_card_offers', 25 );
}
add_action( 'init', 'kio_child_product_card_hooks' );

/**
 * Mở khung ảnh và nhóm nhãn. Sản phẩm hết hàng chỉ giữ nhãn "Hết hàng" (F1 in ở priority 5).
 *
 * @return void
 */
function kio_child_card_media_open(): void {
	global $product;

	echo '<div class="kio-card-media"><div class="kio-card-badges">';
	if ( $product instanceof WC_Product && $product->is_in_stock() ) {
		woocommerce_show_product_loop_sale_flash();
	}
}

/**
 * Đóng nhóm nhãn.
 *
 * @return void
 */
function kio_child_card_badges_close(): void {
	echo '</div>';
}

/**
 * Đóng khung ảnh.
 *
 * @return void
 */
function kio_child_card_media_close(): void {
	echo '</div>';
}

/**
 * Bật filter giá "Từ X" ngay trước khi Woo in giá của thẻ.
 *
 * Bật rồi tắt quanh priority 10 thay vì gắn cố định: filter chỉ tác động lên giá trong thẻ,
 * còn giá ở đầu trang sản phẩm (cũng qua filter này) vẫn là khoảng giá đầy đủ.
 *
 * @return void
 */
function kio_child_card_price_from_on(): void {
	add_filter( 'woocommerce_variable_price_html', 'kio_child_card_variable_price', 10, 2 );
}

/**
 * Tắt filter giá "Từ X".
 *
 * @return void
 */
function kio_child_card_price_from_off(): void {
	remove_filter( 'woocommerce_variable_price_html', 'kio_child_card_variable_price', 10 );
}

/**
 * FILTER woocommerce_variable_price_html: "14.990.000 ₫ – 17.990.000 ₫" thành "Từ 14.990.000 ₫".
 *
 * Giá gạch ngang là giá gốc của CHÍNH biến thể rẻ nhất, không phải giá gốc thấp nhất, để cặp
 * giá khách thấy là một mức giảm có thật.
 *
 * @param string     $price   HTML giá mặc định.
 * @param WC_Product $product Sản phẩm biến thể.
 * @return string HTML giá.
 */
function kio_child_card_variable_price( string $price, WC_Product $product ): string {
	if ( ! $product instanceof WC_Product_Variable ) {
		return $price;
	}

	$prices = $product->get_variation_prices( true ); // Woo đã sắp giá tăng dần và cache sẵn.
	if ( empty( $prices['price'] ) ) {
		return $price;
	}

	$cheapest_id = array_key_first( $prices['price'] );
	$now         = (float) $prices['price'][ $cheapest_id ];
	$regular     = (float) ( $prices['regular_price'][ $cheapest_id ] ?? $now );
	$highest     = (float) max( $prices['price'] );

	$html = sprintf(
		'<span class="kio-price-now">%s%s</span>',
		$highest > $now ? esc_html__( 'Từ', 'kio-child' ) . ' ' : '',
		wc_price( $now )
	);

	if ( $regular > $now ) {
		$html .= sprintf(
			'<del aria-hidden="true">%1$s</del><span class="screen-reader-text">%2$s %3$s</span>',
			wc_price( $regular ),
			esc_html__( 'Giá gốc:', 'kio-child' ),
			wp_strip_all_tags( wc_price( $regular ) )
		);
	}

	return $html;
}

/**
 * Dòng "★ 4,5 (12) · Đã bán 1.340". Chỉ in phần có số liệu thật, không có gì thì không in.
 *
 * @return void
 */
function kio_child_card_meta(): void {
	global $product;

	if ( ! $product instanceof WC_Product ) {
		return;
	}

	$parts  = array();
	$rating = (float) $product->get_average_rating();
	$count  = (int) $product->get_rating_count();
	$sold   = (int) $product->get_total_sales();

	if ( $rating > 0 ) {
		$parts[] = sprintf(
			'<span class="kio-card-meta__rating"><span aria-hidden="true">%1$s (%2$s)</span><span class="screen-reader-text">%3$s</span></span>',
			esc_html( number_format_i18n( $rating, 1 ) ),
			esc_html( number_format_i18n( $count ) ),
			/* translators: 1: điểm trung bình, 2: số đánh giá. */
			esc_html( sprintf( __( '%1$s sao, %2$s đánh giá', 'kio-child' ), number_format_i18n( $rating, 1 ), number_format_i18n( $count ) ) )
		);
	}

	if ( $sold > 0 ) {
		$parts[] = sprintf(
			'<span class="kio-card-meta__sold">%s</span>',
			/* translators: %s: số lượng đã bán. */
			esc_html( sprintf( __( 'Đã bán %s', 'kio-child' ), number_format_i18n( $sold ) ) )
		);
	}

	if ( $parts ) {
		echo '<div class="kio-card-meta">' . implode( '', $parts ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- từng phần đã escape ở trên.
	}
}

/**
 * Dòng "+2 ưu đãi" nếu sản phẩm có khuyến mãi F4 đang hiệu lực.
 *
 * @return void
 */
function kio_child_card_offers(): void {
	global $product;

	if ( ! $product instanceof WC_Product || ! function_exists( 'kio_store_product_promotions' ) ) {
		return;
	}

	$count = count( kio_store_product_promotions( $product ) );
	if ( $count > 0 ) {
		printf(
			'<div class="kio-card-offers">%s</div>',
			/* translators: %d: số khuyến mãi đang áp dụng. */
			esc_html( sprintf( __( '+%d ưu đãi', 'kio-child' ), $count ) )
		);
	}
}
