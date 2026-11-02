<?php
/**
 * Vẽ ảnh đại diện (screenshot.png) cho child theme kio-child, 1200×900.
 * Ảnh tự vẽ bằng GD, không vướng bản quyền. Chạy lại khi muốn đổi ảnh:
 *   docker compose run --rm wpcli eval-file /scripts/make-screenshot.php
 */

require_once '/scripts/lib/product-image.php';

$w  = 1200;
$h  = 900;
$im = imagecreatetruecolor( $w, $h );

// Nền gradient xanh dương → xanh dương đậm.
for ( $x = 0; $x < $w; $x++ ) {
	imageline( $im, $x, 0, $x, $h, kio_color( $im, kio_mix( '#0d47a1', '#08347a', $x / $w ) ) );
}

// Khối nội dung trắng ở giữa, giả làm "trang chủ" thu nhỏ.
$box = [ 90, 160, 1110, 780 ];
kio_rrect( $im, $box[0], $box[1], $box[2], $box[3], 24, kio_color( $im, '#ffffff' ) );

// Header giả: thanh brand + các ô.
kio_rrect( $im, 90, 160, 1110, 240, 24, kio_color( $im, '#0d47a1' ) );
kio_text_at( $im, 'Kio Mobile', KIO_FONT_BOLD, 34, 300, 216, '#ffffff' );
kio_rrect( $im, 780, 182, 1080, 226, 10, kio_color( $im, '#f4f6f8' ) );

// Vài thẻ sản phẩm giả.
for ( $i = 0; $i < 4; $i++ ) {
	$x = 120 + $i * 240;
	kio_rrect( $im, $x, 300, $x + 210, 560, 14, kio_color( $im, '#f4f6f8' ) );
	kio_rrect( $im, $x + 20, 380, $x + 190, 500, 10, kio_color( $im, '#e5e7eb' ) );
	kio_rrect( $im, $x + 20, 520, $x + 150, 540, 8, kio_color( $im, '#d70018' ) );
}

// Chữ thương hiệu ở dưới.
kio_text_center( $im, 'Kio Child — Theme hiển thị cho Kio Mobile', KIO_FONT_BOLD, 30, 700, '#111827', $w - 200 );
kio_text_center( $im, 'KIO MOBILE · ẢNH DEMO', KIO_FONT_REGULAR, 16, 760, '#9ca3af', $w );

imagepng( $im, get_stylesheet_directory() . '/screenshot.png', 9 );
imagedestroy( $im );

echo "Đã tạo " . get_stylesheet_directory() . "/screenshot.png\n";
