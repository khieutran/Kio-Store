<?php
/**
 * Xem mô hình 3D trên trang sản phẩm: kéo xoay mọi hướng, đổi màu theo biến thể đang chọn.
 *
 * Mô hình tự dựng (tools/models), không phải ảnh sản phẩm thật. Ảnh chính của sản phẩm là ảnh chụp từ mô
 * hình đúng góc mở đầu, nên chuyển sang 3D không bị giật. Thư viện và file GLB tải sau khi trang tải xong.
 * Cần có file assets/models/<SKU>.glb, sản phẩm chưa có mô hình thì không hiện gì.
 *
 * @package KioStore
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', 'kio_store_product_3d_assets' );
add_action( 'woocommerce_after_single_product_summary', 'kio_store_render_product_3d', 7 );
add_action( 'woocommerce_single_product_summary', 'kio_store_render_3d_badge', 6 );


/**
 * Đường dẫn tương đối của mô hình trong thư mục plugin; rỗng nếu sản phẩm chưa có.
 *
 * @param WC_Product $product Sản phẩm.
 * @return string
 */
function kio_store_product_model_path( WC_Product $product ): string {
	$path = 'assets/models/' . sanitize_file_name( $product->get_sku() ) . '.glb';

	return file_exists( KIO_STORE_DIR . $path ) ? $path : '';
}

/**
 * Góc nhìn mở đầu, cũng là góc chụp ảnh sản phẩm để chuyển sang 3D không bị giật.
 *
 * Mặt lưng chếch nghiêng: nhìn thẳng mặt trước chỉ thấy màn hình, các màu giống hệt nhau. Laptop, đồng hồ
 * nhìn từ trước mới đẹp, vỏ vẫn thấy màu.
 *
 * @param WC_Product $product Sản phẩm.
 * @return string Giá trị camera-orbit của model-viewer.
 */
function kio_store_product_model_orbit( WC_Product $product ): string {
	return has_term( array( 'laptop', 'dong-ho-thong-minh' ), 'product_cat', $product->get_id() ) ? '30deg 78deg 130%' : '150deg 78deg 130%';
}

/**
 * Nạp JS, CSS ở trang sản phẩm có mô hình.
 *
 * @return void
 */
function kio_store_product_3d_assets(): void {
	if ( ! is_product() ) {
		return;
	}
	$product = wc_get_product( get_queried_object_id() );
	if ( ! $product || ! kio_store_product_model_path( $product ) ) {
		return;
	}

	wp_enqueue_style( 'kio-store-product-3d', KIO_STORE_URL . 'assets/css/product-3d.css', array(), kio_store_asset_version( 'assets/css/product-3d.css' ) );
	wp_enqueue_script(
		'kio-store-product-3d',
		KIO_STORE_URL . 'assets/js/product-3d.js',
		array( 'jquery' ), // nghe sự kiện found_variation của Woo.
		kio_store_asset_version( 'assets/js/product-3d.js' ),
		array(
			'in_footer' => true,
			'strategy'  => 'defer',
		)
	);
}

/**
 * Mã màu các biến thể của sản phẩm: slug màu → mã hex (term meta kio_color_hex).
 *
 * @param WC_Product $product Sản phẩm.
 * @return array<string,string>
 */
function kio_store_product_model_colors( WC_Product $product ): array {
	$colors = array();
	$terms  = get_the_terms( $product->get_id(), 'pa_mau-sac' );

	foreach ( is_array( $terms ) ? $terms : array() as $term ) {
		$hex = (string) get_term_meta( $term->term_id, 'kio_color_hex', true );
		if ( '' !== $hex ) {
			$colors[ $term->slug ] = $hex;
		}
	}

	return $colors;
}

/**
 * ACTION woocommerce_after_single_product_summary: in dữ liệu cho JS.
 *
 * In ở đây vì trong khung ảnh FlexSlider coi mọi phần tử con là một slide. JS chuyển nút vào khung ảnh.
 *
 * @return void
 */
function kio_store_render_product_3d(): void {
	global $product;

	if ( ! $product instanceof WC_Product ) {
		return;
	}
	$path = kio_store_product_model_path( $product );
	if ( '' === $path ) {
		return;
	}

	printf(
		'<div class="kio-model-data" hidden data-model="%1$s" data-viewer="%2$s" data-colors="%3$s" data-name="%4$s" data-orbit="%5$s" data-autoplay="%6$s"></div>',
		esc_url( add_query_arg( 'ver', kio_store_asset_version( $path ), KIO_STORE_URL . $path ) ),
		esc_url( add_query_arg( 'ver', kio_store_asset_version( 'assets/vendor/model-viewer.min.js' ), KIO_STORE_URL . 'assets/vendor/model-viewer.min.js' ) ),
		esc_attr( (string) wp_json_encode( kio_store_product_model_colors( $product ) ) ),
		esc_attr( $product->get_name() ),
		esc_attr( kio_store_product_model_orbit( $product ) ),
		esc_attr( apply_filters( 'kio_store_model_autoplay', true ) ? '1' : '0' )
	);
}

/**
 * ACTION woocommerce_single_product_summary: nhãn 360° dưới tên sản phẩm.
 *
 * @return void
 */
function kio_store_render_3d_badge(): void {
	global $product;

	if ( $product instanceof WC_Product && kio_store_product_model_path( $product ) ) {
		printf( '<span class="kio-badge kio-badge--360">%s</span>', esc_html__( 'Xem 360°', 'kio-store' ) );
	}
}
