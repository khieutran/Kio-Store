<?php
/**
 * F1 — Nhãn tự động trên thẻ sản phẩm và trang sản phẩm.
 *
 * Ba loại nhãn:
 *   - Nhãn giảm giá: thay chữ "Giảm giá!" mặc định của Woo thành "-x%" (sản phẩm đơn)
 *     hoặc "Giảm đến x%" (sản phẩm biến thể, lấy mức giảm lớn nhất giữa các biến thể).
 *   - Nhãn "Mới": sản phẩm tạo trong vòng KIO_STORE_NEW_DAYS ngày.
 *   - Nhãn "Hết hàng": sản phẩm đã hết kho.
 *
 * Hook dùng:
 *   - FILTER woocommerce_sale_flash: đổi chữ nhãn giảm giá ở mọi nơi Woo in ra
 *     (cả vòng lặp danh mục lẫn trang chi tiết sản phẩm).
 *   - ACTION woocommerce_before_shop_loop_item_title: in nhãn "Mới"/"Hết hàng" đè lên
 *     ảnh thẻ sản phẩm (chỉ ở vòng lặp, đúng như tài liệu F1).
 *
 * @package KioStore
 */

defined( 'ABSPATH' ) || exit;

// Số ngày một sản phẩm còn được coi là "Mới". Đặt một chỗ để không hardcode rải rác.
const KIO_STORE_NEW_DAYS = 30;

add_filter( 'woocommerce_sale_flash', 'kio_store_sale_flash_text', 10, 3 );
add_action( 'woocommerce_before_shop_loop_item_title', 'kio_store_render_loop_badges', 5 );

/**
 * Phần trăm giảm giá của sản phẩm; biến thể lấy mức lớn nhất.
 *
 * Sản phẩm biến thể không lưu giá ở chính nó mà ở từng biến thể. Dùng
 * get_variation_prices(): Woo đã tính sẵn giá của mọi biến thể đang hiển thị và lưu
 * cache (transient), nên KHÔNG phải nạp từng WC_Product_Variation (đo: 19 query → 0 query
 * cho sản phẩm 6 biến thể). Biến thể chưa tới giờ sale (sale hẹn giờ) có sale_price bằng
 * regular_price trong dữ liệu này nên tự bị loại.
 *
 * @param WC_Product $product Sản phẩm cần tính.
 * @return int|null Phần trăm đã làm tròn, null nếu sản phẩm không giảm giá.
 */
function kio_store_discount_percent( WC_Product $product ): ?int {
	$pairs = array();

	if ( $product instanceof WC_Product_Variable ) {
		$prices = $product->get_variation_prices( true );
		foreach ( $prices['regular_price'] as $variation_id => $regular ) {
			$pairs[] = array( (float) $regular, (float) ( $prices['sale_price'][ $variation_id ] ?? $regular ) );
		}
	} elseif ( $product->is_on_sale() && $product->get_regular_price() ) {
		$pairs[] = array( (float) $product->get_regular_price(), (float) $product->get_sale_price() );
	}

	$max = 0;
	foreach ( $pairs as $pair ) {
		list( $regular, $sale ) = $pair;
		if ( $regular > 0 && $sale >= 0 && $sale < $regular ) {
			$max = max( $max, (int) round( ( $regular - $sale ) / $regular * 100 ) );
		}
	}

	return $max > 0 ? $max : null;
}

/**
 * Sản phẩm có còn được coi là "Mới" không.
 *
 * "Mới" tính theo ngày tạo (date_created). Dữ liệu demo đặt date_created lùi về quá khứ
 * theo trường days_old trong data/products.json (xem scripts/import-products.php).
 *
 * @param WC_Product $product Sản phẩm cần kiểm tra.
 * @return bool
 */
function kio_store_is_new( WC_Product $product ): bool {
	$created = $product->get_date_created();
	if ( ! $created ) {
		return false;
	}

	$age = time() - $created->getTimestamp();

	return $age >= 0 && $age < KIO_STORE_NEW_DAYS * DAY_IN_SECONDS;
}

/**
 * FILTER woocommerce_sale_flash: đổi chữ "Giảm giá!" thành "-x%" / "Giảm đến x%".
 *
 * Tham số $post không ép kiểu WP_Post: plugin/theme khác có thể gọi filter này với giá trị
 * khác (null, ID) và ép kiểu sẽ gây lỗi fatal cho cả trang.
 *
 * @param string     $html    HTML nhãn mặc định.
 * @param mixed      $post    Bài viết (sản phẩm), không dùng tới.
 * @param WC_Product $product Sản phẩm đang in nhãn.
 * @return string HTML nhãn mới.
 */
function kio_store_sale_flash_text( string $html, $post, WC_Product $product ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassBeforeLastUsed,Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- chữ ký filter của Woo.
	$percent = kio_store_discount_percent( $product );
	if ( null === $percent ) {
		return $html;
	}

	$label = $product->is_type( 'variable' )
		/* translators: %d: phần trăm giảm giá lớn nhất. */
		? sprintf( __( 'Giảm đến %d%%', 'kio-store' ), $percent )
		: sprintf( '-%d%%', $percent );

	return '<span class="onsale kio-badge kio-badge--sale">' . esc_html( $label ) . '</span>';
}

/**
 * ACTION woocommerce_before_shop_loop_item_title: in nhãn "Mới"/"Hết hàng" trên ảnh thẻ.
 *
 * Chạy ở priority 5 (trước ảnh thu nhỏ ở priority 10 của Woo) để nhãn nằm trước <img> trong
 * DOM; CSS sẽ đặt vị trí tuyệt đối đè lên góc trên bên trái của ảnh. Khi sản phẩm hết hàng
 * chỉ in nhãn "Hết hàng" (không in giảm giá/"Mới" nữa để tránh chồng nhãn).
 *
 * @return void
 */
function kio_store_render_loop_badges(): void {
	global $product;

	if ( ! $product instanceof WC_Product ) {
		return;
	}

	$badges = array();

	if ( ! $product->is_in_stock() ) {
		$badges[] = array( 'out', __( 'Hết hàng', 'kio-store' ) );
	} elseif ( kio_store_is_new( $product ) ) {
		$badges[] = array( 'new', __( 'Mới', 'kio-store' ) );
	}

	if ( empty( $badges ) ) {
		return;
	}

	echo '<div class="kio-badges">';
	foreach ( $badges as $badge ) {
		printf(
			'<span class="kio-badge kio-badge--%s">%s</span>',
			esc_attr( $badge[0] ),
			esc_html( $badge[1] )
		);
	}
	echo '</div>';
}
