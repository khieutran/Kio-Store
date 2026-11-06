<?php
/**
 * Cam kết dưới nút mua và câu hỏi thường gặp trên trang sản phẩm.
 *
 * Nội dung lấy đúng theo các trang chính sách demo (data/pages/chinh-sach-*.html) và cài đặt của site.
 * Sửa chính sách thì sửa cả ở đây. Câu trả lời dùng <details>: mở đóng không cần JS.
 *
 * @package KioStore
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', 'kio_store_product_assurance_assets' );
add_action( 'woocommerce_single_product_summary', 'kio_store_render_assurance', 36 );
add_action( 'woocommerce_after_single_product_summary', 'kio_store_render_product_faq', 12 );

/**
 * Nạp CSS ở trang sản phẩm.
 *
 * @return void
 */
function kio_store_product_assurance_assets(): void {
	if ( is_product() ) {
		wp_enqueue_style( 'kio-store-assurance', KIO_STORE_URL . 'assets/css/product-assurance.css', array(), kio_store_asset_version( 'assets/css/product-assurance.css' ) );
	}
}

/**
 * Số tháng bảo hành theo chính sách: máy 12 tháng, đồng hồ, âm thanh, phụ kiện 6 tháng.
 *
 * @param WC_Product $product Sản phẩm.
 * @return int
 */
function kio_store_warranty_months( WC_Product $product ): int {
	return has_term( array( 'dien-thoai', 'may-tinh-bang', 'laptop' ), 'product_cat', $product->get_id() ) ? 12 : 6;
}

/**
 * ACTION woocommerce_single_product_summary: cam kết, ngay dưới khuyến mãi (35).
 *
 * @return void
 */
function kio_store_render_assurance(): void {
	global $product;

	if ( ! $product instanceof WC_Product ) {
		return;
	}

	$items = array(
		array( __( 'Hàng chính hãng', 'kio-store' ), '/chinh-sach-bao-hanh/' ),
		/* translators: %d: số tháng bảo hành. */
		array( sprintf( __( 'Bảo hành %d tháng', 'kio-store' ), kio_store_warranty_months( $product ) ), '/chinh-sach-bao-hanh/' ),
		array( __( 'Đổi trả trong 30 ngày', 'kio-store' ), '/chinh-sach-doi-tra/' ),
		array( __( 'Miễn phí giao hàng từ 300.000 ₫', 'kio-store' ), '/chinh-sach-giao-hang/' ),
	);

	echo '<ul class="kio-assurance">';
	foreach ( $items as $item ) {
		printf( '<li><a href="%1$s">%2$s</a></li>', esc_url( home_url( $item[1] ) ), esc_html( $item[0] ) );
	}
	echo '</ul>';
}

/**
 * Các câu hỏi của một sản phẩm.
 *
 * @param WC_Product $product Sản phẩm.
 * @return array<int,array{0:string,1:string}> Câu hỏi, câu trả lời.
 */
function kio_store_product_faq( WC_Product $product ): array {
	$faq = array(
		array(
			__( 'Sản phẩm được bảo hành bao lâu?', 'kio-store' ),
			/* translators: %d: số tháng bảo hành. */
			sprintf( __( 'Sản phẩm được bảo hành %d tháng. Khi đến bảo hành, quý khách mang theo phiếu mua hàng hoặc hoá đơn; sản phẩm cần còn nguyên tem, không va đập, không vào nước, chưa tự ý sửa chữa.', 'kio-store' ), kio_store_warranty_months( $product ) ),
		),
		array(
			__( 'Tôi có đổi trả được không?', 'kio-store' ),
			__( 'Có, trong vòng 30 ngày kể từ ngày nhận hàng, với sản phẩm còn nguyên hộp và chưa qua sử dụng; hoặc khi sản phẩm lỗi kỹ thuật do nhà sản xuất, giao sai mẫu, sai màu, sai dung lượng.', 'kio-store' ),
		),
		array(
			__( 'Bao lâu thì nhận được hàng?', 'kio-store' ),
			__( 'Nội thành TP. Hồ Chí Minh giao trong 2 giờ (giờ hành chính). Các tỉnh thành khác từ 2 đến 4 ngày làm việc.', 'kio-store' ),
		),
		array(
			__( 'Phí giao hàng thế nào?', 'kio-store' ),
			__( 'Đơn từ 300.000 ₫ được miễn phí giao hàng tiêu chuẩn. Đơn dưới 300.000 ₫ phí 30.000 ₫.', 'kio-store' ),
		),
		array(
			__( 'Có những cách thanh toán nào?', 'kio-store' ),
			__( 'Thanh toán khi nhận hàng (COD) hoặc chuyển khoản ngân hàng.', 'kio-store' ),
		),
	);

	return $faq;
}

/**
 * ACTION woocommerce_after_single_product_summary: câu hỏi thường gặp, sau khối tab (10).
 *
 * @return void
 */
function kio_store_render_product_faq(): void {
	global $product;

	if ( ! $product instanceof WC_Product ) {
		return;
	}
	?>
	<section class="kio-faq" aria-labelledby="kio-faq-title">
		<h2 class="kio-section-title" id="kio-faq-title"><?php esc_html_e( 'Câu hỏi thường gặp', 'kio-store' ); ?></h2>
		<?php foreach ( kio_store_product_faq( $product ) as $item ) : ?>
			<details>
				<summary><?php echo esc_html( $item[0] ); ?></summary>
				<p><?php echo esc_html( $item[1] ); ?></p>
			</details>
		<?php endforeach; ?>
		<p class="kio-faq__note"><?php esc_html_e( 'Nội dung minh hoạ cho website demo.', 'kio-store' ); ?></p>
	</section>
	<?php
}
