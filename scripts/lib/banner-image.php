<?php
/**
 * Vẽ banner (hero slider, banner nhỏ) bằng PHP GD rồi đưa vào Media Library.
 * Dùng lại các hàm vẽ cơ bản (kio_color, kio_text_center, font) của product-image.php.
 *
 * Dùng:
 *   $attachment_id = kio_banner_image([
 *       'width'    => 1200,                 // px
 *       'height'   => 480,
 *       'from'     => '#0d47a1',            // màu gradient (sáng → tối)
 *       'to'       => '#08347a',
 *       'title'    => 'Điện thoại giảm đến 20%',
 *       'subtitle' => 'Thu cũ đổi mới — trả góp 0%',
 *       'badge'    => 'FLASH SALE',
 *   ]);
 */

require_once __DIR__ . '/product-image.php';

// Đổi khi sửa cách vẽ banner để buộc tạo lại ảnh (tách khỏi version của ảnh sản phẩm).
const KIO_BANNER_VERSION = 'v2';

/**
 * Vẽ banner và upload, trả về attachment ID. Chạy lặp lại được (key = thông số vẽ).
 *
 * @param array $spec Thông số banner.
 * @return int Attachment ID.
 */
function kio_banner_image( array $spec ): int {
	$spec = array_merge(
		[
			'width'    => 1200,
			'height'   => 480,
			'from'     => '#0d47a1',
			'to'       => '#08347a',
			'title'    => '',
			'subtitle' => '',
			'badge'    => '',
		],
		$spec
	);

	$key = md5( KIO_BANNER_VERSION . wp_json_encode( $spec ) );

	$existing = get_posts(
		[
			'post_type'   => 'attachment',
			'post_status' => 'inherit',
			'meta_key'    => '_kio_image_key',
			'meta_value'  => $key,
			'fields'      => 'ids',
			'numberposts' => 1,
		]
	);
	if ( $existing ) {
		return (int) $existing[0];
	}

	$file = kio_render_banner( $spec, sys_get_temp_dir() . "/kio-$key.webp" );

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$name = sanitize_title( $spec['title'] ? $spec['title'] : 'banner' ) . '.webp';
	$id   = media_handle_sideload(
		[ 'name' => $name, 'tmp_name' => $file ],
		0,
		trim( $spec['title'] . ' ' . $spec['subtitle'] )
	);
	if ( is_wp_error( $id ) ) {
		WP_CLI::error( 'Upload banner thất bại: ' . $id->get_error_message() );
	}

	update_post_meta( $id, '_kio_image_key', $key );
	update_post_meta( $id, '_wp_attachment_image_alt', trim( $spec['title'] . ' ' . $spec['subtitle'] ) );
	return $id;
}

/**
 * Vẽ banner ra file PNG, trả về đường dẫn.
 *
 * @param array  $spec Thông số.
 * @param string $path Đường dẫn file đích.
 * @return string Đường dẫn file.
 */
function kio_render_banner( array $spec, string $path ): string {
	$w  = (int) $spec['width'];
	$h  = (int) $spec['height'];
	$im = imagecreatetruecolor( $w, $h );

	// Nền gradient theo chiều ngang: trộn from → to.
	for ( $x = 0; $x < $w; $x++ ) {
		imageline( $im, $x, 0, $x, $h, kio_color( $im, kio_mix( $spec['from'], $spec['to'], $x / max( 1, $w - 1 ) ) ) );
	}

	$scale = $w / 1200; // cỡ chữ tương đối theo chiều rộng banner.

	// Badge (nếu có): ô cam ở góc trên trái.
	if ( $spec['badge'] ) {
		$fs     = 26 * $scale;
		$box    = imagettfbbox( $fs, 0, KIO_FONT_BOLD, $spec['badge'] );
		$bw     = $box[2] - $box[0];
		$bx     = 60 * $scale;
		$by     = 60 * $scale;
		kio_rrect( $im, $bx - 18, $by - 34, $bx + $bw + 18, $by + 14, 20, kio_color( $im, '#ff6a00' ) );
		imagettftext( $im, $fs, 0, (int) $bx, (int) $by, kio_color( $im, '#ffffff' ), KIO_FONT_BOLD, $spec['badge'] );
	}

	// Tiêu đề + phụ đề, căn trái phía dưới.
	$title_fs = 58 * $scale;
	$ty       = (int) ( $h * 0.55 );
	imagettftext( $im, $title_fs, 0, (int) ( 60 * $scale ), $ty, kio_color( $im, '#ffffff' ), KIO_FONT_BOLD, $spec['title'] );

	if ( $spec['subtitle'] ) {
		imagettftext( $im, 28 * $scale, 0, (int) ( 60 * $scale ), (int) ( $ty + 48 * $scale ), kio_color( $im, '#dbe7ff' ), KIO_FONT_REGULAR, $spec['subtitle'] );
	}

	// Nhãn demo, góc dưới trái (chừa góc dưới phải cho nút điều hướng slider).
	imagettftext( $im, 16 * $scale, 0, (int) ( 60 * $scale ), (int) ( $h - 30 * $scale ), kio_color( $im, '#a9c1ea' ), KIO_FONT_REGULAR, 'KIO MOBILE · ẢNH DEMO' );

	imagewebp( $im, $path, 85 ); // banner là ảnh LCP của trang chủ: WebP nhẹ hơn PNG nhiều.
	imagedestroy( $im );
	return $path;
}
