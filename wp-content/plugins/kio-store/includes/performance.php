<?php
/**
 * Phase 5: gỡ CSS/JS plugin nạp ở nơi không dùng tới.
 *
 * Plugin thường nạp file ở mọi trang cho chắc. CSS là tài nguyên chặn hiển thị, nên mỗi file thừa làm
 * chữ hiện chậm đi. Số đo từng mục ở docs/phase5/performance.md.
 *
 * Không gỡ jquery-migrate: YITH Compare còn dùng .success() đã bỏ ở jQuery 3, gỡ là lỗi JS.
 *
 * @package KioStore
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', 'kio_store_trim_compare_assets', 100 );
add_action( 'wp_enqueue_scripts', 'kio_store_scripts_to_footer', 999 );
add_filter( 'image_editor_output_format', 'kio_store_webp_output' );
add_filter( 'wp_get_attachment_image_attributes', 'kio_store_card_image_sizes', 10, 3 );

/**
 * Chuyển mọi script xuống cuối trang.
 *
 * Script trong <head> chặn hiển thị: trình duyệt phải tải và chạy xong mới vẽ tiếp. Chỉ dời riêng
 * jQuery không được vì script Woo ở <head> phụ thuộc nó sẽ kéo nó lên lại. Thứ tự giữa các script
 * vẫn theo khai báo phụ thuộc.
 *
 * @return void
 */
function kio_store_scripts_to_footer(): void {
	$scripts = wp_scripts();
	foreach ( array_keys( $scripts->registered ) as $handle ) {
		$scripts->add_data( $handle, 'group', 1 );
	}
}

/**
 * FILTER image_editor_output_format: các bản ảnh thu nhỏ tạo ra ở dạng WebP, nhẹ hơn PNG nhiều.
 *
 * Ảnh gốc giữ nguyên. Máy chủ không hỗ trợ WebP thì WordPress tự giữ định dạng cũ.
 *
 * @param array $formats Định dạng nguồn => định dạng đích.
 * @return array
 */
function kio_store_webp_output( array $formats ): array {
	$formats['image/png']  = 'image/webp';
	$formats['image/jpeg'] = 'image/webp';

	return $formats;
}

/**
 * FILTER wp_get_attachment_image_attributes: báo đúng bề rộng thật của ảnh trên thẻ sản phẩm.
 *
 * Mặc định sizes là 324px nên điện thoại màn hình mật độ cao tải bản 768px cho khung khoảng 170px.
 *
 * @param array        $attr       Thuộc tính thẻ img.
 * @param WP_Post      $attachment Ảnh.
 * @param string|int[] $size       Kích thước yêu cầu.
 * @return array
 */
function kio_store_card_image_sizes( array $attr, $attachment, $size ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- chữ ký filter của WordPress.
	if ( 'woocommerce_thumbnail' === $size ) {
		$attr['sizes'] = '(max-width: 767px) 44vw, (max-width: 1023px) 30vw, 230px';
	}

	return $attr;
}

/**
 * Bảng so sánh chỉ có ở trang So sánh: CSS và thư viện bảng không cần ở trang khác.
 *
 * JS chính của YITH vẫn giữ vì nút "So sánh" trên thẻ cần nó, chỉ bỏ các thư viện nó khai báo phụ thuộc.
 *
 * @return void
 */
function kio_store_trim_compare_assets(): void {
	if ( is_page( (int) get_option( 'yith_woocompare_page_id' ) ) ) {
		return;
	}

	wp_dequeue_style( 'yith_woocompare_page' );
	wp_dequeue_style( 'jquery-fixedheadertable-style' );

	$main = wp_scripts()->query( 'yith-woocompare-main' );
	if ( $main ) {
		$main->deps = array_values( array_diff( $main->deps, array( 'jquery-fixedheadertable', 'jquery-fixedcolumns', 'jquery-imagesloaded' ) ) );
	}
}
