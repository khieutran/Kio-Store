<?php
/**
 * Vẽ ảnh placeholder cho sản phẩm bằng PHP GD rồi đưa vào Media Library.
 *
 * Ảnh tự vẽ nên không vướng bản quyền, và có dòng "KIO MOBILE · ẢNH DEMO".
 * Mỗi ảnh có một "key" (băm từ thông số vẽ). Chạy lại script thì dùng lại
 * ảnh cũ thay vì upload thêm bản trùng.
 *
 * Dùng:
 *   $attachment_id = kio_product_image([
 *       'device' => 'phone',            // phone | tablet | laptop | watch | headphones | earbuds
 *                                       // speaker | charger | powerbank | cable | case | box
 *       'badge'  => '65W',              // chữ in trên củ sạc (mặc định 20W)
 *       'color'  => '#1f1f1f',          // màu thân máy
 *       'title'  => 'Samsung Galaxy S26',
 *       'label'  => 'Đen',              // dòng phụ (màu/phiên bản), có thể bỏ trống
 *       'brand'  => 'Samsung',
 *   ]);
 */

const KIO_IMAGE_VERSION = 'v1';   // đổi khi sửa cách vẽ để buộc tạo lại ảnh
const KIO_IMAGE_SIZE    = 800;    // kích thước ảnh cuối (px)
const KIO_IMAGE_SCALE   = 2;      // vẽ ở 2x rồi thu nhỏ để cạnh mượt hơn
const KIO_FONT_BOLD     = '/data/fonts/BeVietnamPro-Bold.ttf';
const KIO_FONT_REGULAR  = '/data/fonts/BeVietnamPro-Regular.ttf';

function kio_product_image( array $spec ): int {
	$spec = array_merge( [ 'device' => 'box', 'color' => '#d8d8d8', 'title' => '', 'label' => '', 'brand' => '' ], $spec );
	$key  = md5( KIO_IMAGE_VERSION . wp_json_encode( $spec ) );

	// Đã có ảnh với đúng thông số này → dùng lại
	$existing = get_posts( [
		'post_type'   => 'attachment',
		'post_status' => 'inherit',
		'meta_key'    => '_kio_image_key',
		'meta_value'  => $key,
		'fields'      => 'ids',
		'numberposts' => 1,
	] );
	if ( $existing ) {
		return (int) $existing[0];
	}

	$file = kio_render_product_image( $spec, sys_get_temp_dir() . "/kio-$key.png" );

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$name = sanitize_title( trim( $spec['title'] . ' ' . $spec['label'] ) ) . '.png';
	$id   = media_handle_sideload( [ 'name' => $name, 'tmp_name' => $file ], 0, trim( $spec['title'] . ' ' . $spec['label'] ) );
	if ( is_wp_error( $id ) ) {
		WP_CLI::error( 'Upload ảnh thất bại: ' . $id->get_error_message() );
	}

	update_post_meta( $id, '_kio_image_key', $key );
	update_post_meta( $id, '_wp_attachment_image_alt', trim( $spec['title'] . ' ' . $spec['label'] ) );
	return $id;
}

/** Vẽ ảnh và lưu ra file PNG, trả về đường dẫn file. */
function kio_render_product_image( array $spec, string $path ): string {
	$s  = KIO_IMAGE_SCALE;
	$w  = KIO_IMAGE_SIZE * $s;
	$im = imagecreatetruecolor( $w, $w );
	imagealphablending( $im, true );

	// Nền xám rất nhạt, giống thẻ sản phẩm của các trang bán lẻ
	imagefill( $im, 0, 0, kio_color( $im, '#f3f4f6' ) );

	$cx = $w / 2;
	$cy = 330 * $s;   // tâm vùng vẽ thiết bị (phần trên ảnh, chừa chỗ cho chữ)

	switch ( $spec['device'] ) {
		case 'phone':
			kio_draw_screen_device( $im, $cx, $cy, 250 * $s, 500 * $s, 44 * $s, 12 * $s, $spec['color'], true );
			break;
		case 'tablet':
			kio_draw_screen_device( $im, $cx, $cy, 400 * $s, 520 * $s, 34 * $s, 22 * $s, $spec['color'], false );
			break;
		case 'charger':
			kio_draw_charger( $im, $cx, $cy, $s, $spec['color'], $spec['badge'] ?? '20W' );
			break;
		case 'headphones':
			kio_draw_headphones( $im, $cx, $cy, $s, $spec['color'] );
			break;
		case 'laptop':
			kio_draw_laptop( $im, $cx, $cy, $s, $spec['color'] );
			break;
		case 'watch':
			kio_draw_watch( $im, $cx, $cy, $s, $spec['color'] );
			break;
		case 'earbuds':
			kio_draw_earbuds( $im, $cx, $cy, $s, $spec['color'] );
			break;
		case 'speaker':
			kio_draw_speaker( $im, $cx, $cy, $s, $spec['color'] );
			break;
		case 'powerbank':
			kio_draw_powerbank( $im, $cx, $cy, $s, $spec['color'] );
			break;
		case 'cable':
			kio_draw_cable( $im, $cx, $cy, $s, $spec['color'] );
			break;
		case 'case':
			kio_draw_case( $im, $cx, $cy, $s, $spec['color'] );
			break;
		default:
			kio_rrect( $im, $cx - 180 * $s, $cy - 180 * $s, $cx + 180 * $s, $cy + 180 * $s, 30 * $s, kio_color( $im, kio_shade( $spec['color'], -0.25 ) ) );
			kio_rrect( $im, $cx - 172 * $s, $cy - 172 * $s, $cx + 172 * $s, $cy + 172 * $s, 26 * $s, kio_color( $im, $spec['color'] ) );
	}

	// Thương hiệu: góc trên trái
	if ( $spec['brand'] ) {
		imagettftext( $im, 20 * $s, 0, 48 * $s, 72 * $s, kio_color( $im, '#6b7280' ), KIO_FONT_BOLD, mb_strtoupper( $spec['brand'] ) );
	}

	// Tên sản phẩm và dòng phụ: căn giữa phía dưới
	kio_text_center( $im, $spec['title'], KIO_FONT_BOLD, 34 * $s, 680 * $s, '#111827', $w - 96 * $s );
	if ( $spec['label'] ) {
		kio_text_center( $im, $spec['label'], KIO_FONT_REGULAR, 24 * $s, 728 * $s, '#4b5563', $w - 96 * $s );
	}
	kio_text_center( $im, 'KIO MOBILE · ẢNH DEMO', KIO_FONT_REGULAR, 13 * $s, 778 * $s, '#9ca3af', $w );

	// Thu nhỏ 2x → kích thước thật
	$out = imagecreatetruecolor( KIO_IMAGE_SIZE, KIO_IMAGE_SIZE );
	imagecopyresampled( $out, $im, 0, 0, 0, 0, KIO_IMAGE_SIZE, KIO_IMAGE_SIZE, $w, $w );
	imagepng( $out, $path, 9 );
	imagedestroy( $im );
	imagedestroy( $out );
	return $path;
}

/** Điện thoại / máy tính bảng: thân máy, viền, màn hình có dải sáng. */
function kio_draw_screen_device( $im, $cx, $cy, $bw, $bh, $r, $bezel, string $hex, bool $island ): void {
	$x1 = $cx - $bw / 2; $y1 = $cy - $bh / 2;
	$x2 = $cx + $bw / 2; $y2 = $cy + $bh / 2;

	// Bóng đổ nhẹ
	kio_rrect( $im, $x1 + 4, $y1 + 16, $x2 - 4, $y2 + 16, $r, kio_color( $im, '#000000', 118 ) );
	// Viền thân máy (đậm hơn màu thân) và thân máy
	kio_rrect( $im, $x1, $y1, $x2, $y2, $r, kio_color( $im, kio_shade( $hex, -0.35 ) ) );
	kio_rrect( $im, $x1 + 5, $y1 + 5, $x2 - 5, $y2 - 5, $r - 4, kio_color( $im, $hex ) );

	// Màn hình: gradient xanh → đen, vẽ từng dòng ngang.
	// Ở phần bo góc, thu hẹp dòng theo phương trình đường tròn để không tràn ra viền.
	$sx1 = $x1 + $bezel; $sy1 = $y1 + $bezel;
	$sx2 = $x2 - $bezel; $sy2 = $y2 - $bezel;
	$sr  = max( 4, $r - $bezel );
	$h   = $sy2 - $sy1;
	for ( $i = 0; $i <= $h; $i++ ) {
		$dy    = max( $sr - $i, $i - ( $h - $sr ), 0 );          // khoảng cách vào vùng bo góc
		$inset = $dy > 0 ? $sr - sqrt( max( 0, $sr * $sr - $dy * $dy ) ) : 0;
		imageline( $im, (int) ( $sx1 + $inset ), (int) ( $sy1 + $i ), (int) ( $sx2 - $inset ), (int) ( $sy1 + $i ),
			kio_color( $im, kio_mix( '#1d4ed8', '#0b1220', $i / $h ) ) );
	}
	// Dải sáng chéo (nằm dưới phần bo góc)
	$top  = $sy1 + $sr;
	$band = [ $sx1, $top + $h * 0.12, $sx2, $top, $sx2, $top + $h * 0.22, $sx1, $top + $h * 0.34 ];
	imagefilledpolygon( $im, array_map( 'intval', $band ), kio_color( $im, '#ffffff', 112 ) );

	if ( $island ) {
		$iw = $bw * 0.28; $ih = $bezel * 2.2;
		kio_rrect( $im, $cx - $iw / 2, $sy1 + $bezel, $cx + $iw / 2, $sy1 + $bezel + $ih, $ih / 2, kio_color( $im, '#000000' ) );
	}
}

/** Củ sạc: khối bo góc, 2 chân cắm, cổng USB-C. */
function kio_draw_charger( $im, $cx, $cy, $s, string $hex, string $badge ): void {
	$half = 150 * $s;
	foreach ( [ -55, 55 ] as $dx ) {   // chân cắm
		kio_rrect( $im, $cx + ( $dx - 14 ) * $s, $cy - $half - 90 * $s, $cx + ( $dx + 14 ) * $s, $cy - $half + 10 * $s, 6 * $s, kio_color( $im, '#9ca3af' ) );
	}
	kio_rrect( $im, $cx - $half + 4, $cy - $half + 16, $cx + $half - 4, $cy + $half + 16, 46 * $s, kio_color( $im, '#000000', 118 ) );
	kio_rrect( $im, $cx - $half, $cy - $half, $cx + $half, $cy + $half, 46 * $s, kio_color( $im, kio_shade( $hex, -0.2 ) ) );
	kio_rrect( $im, $cx - $half + 6, $cy - $half + 6, $cx + $half - 6, $cy + $half - 6, 42 * $s, kio_color( $im, $hex ) );
	kio_rrect( $im, $cx - 50 * $s, $cy + 40 * $s, $cx + 50 * $s, $cy + 72 * $s, 16 * $s, kio_color( $im, '#374151' ) ); // cổng USB-C
	kio_text_at( $im, $badge, KIO_FONT_BOLD, 22 * $s, $cx, $cy - 10 * $s, kio_contrast( $hex ) );
}

/** Tai nghe chụp tai: vòm đầu + 2 chụp tai. */
function kio_draw_headphones( $im, $cx, $cy, $s, string $hex ): void {
	$bg    = kio_color( $im, '#f3f4f6' );
	$body  = kio_color( $im, $hex );
	$dark  = kio_color( $im, kio_shade( $hex, 0.25 ) );
	$outer = 400 * $s; $inner = 330 * $s;

	// Vòm đầu: vẽ vòng tròn dày, rồi che nửa dưới bằng màu nền
	imagefilledellipse( $im, (int) $cx, (int) $cy, (int) $outer, (int) $outer, $body );
	imagefilledellipse( $im, (int) $cx, (int) $cy, (int) $inner, (int) $inner, $bg );
	imagefilledrectangle( $im, (int) ( $cx - $outer ), (int) $cy, (int) ( $cx + $outer ), (int) ( $cy + $outer ), $bg );

	foreach ( [ -1, 1 ] as $side ) {   // chụp tai
		$ex = $cx + $side * 175 * $s;
		kio_rrect( $im, $ex - 30 * $s, $cy - 20 * $s, $ex + 30 * $s, $cy + 40 * $s, 10 * $s, $dark );
		kio_rrect( $im, $ex - 62 * $s, $cy + 20 * $s, $ex + 62 * $s, $cy + 210 * $s, 56 * $s, $body );
		kio_rrect( $im, $ex - 44 * $s, $cy + 40 * $s, $ex + 44 * $s, $cy + 190 * $s, 40 * $s, $dark );
	}
}

/** Laptop: màn hình (dùng lại hàm vẽ màn hình) + đế bàn phím. */
function kio_draw_laptop( $im, $cx, $cy, $s, string $hex ): void {
	kio_draw_screen_device( $im, $cx, $cy - 40 * $s, 540 * $s, 350 * $s, 22 * $s, 16 * $s, $hex, false );
	$y = $cy + 140 * $s;
	kio_rrect( $im, $cx - 330 * $s, $y, $cx + 330 * $s, $y + 34 * $s, 16 * $s, kio_color( $im, kio_shade( $hex, -0.3 ) ) );
	kio_rrect( $im, $cx - 326 * $s, $y, $cx + 326 * $s, $y + 26 * $s, 13 * $s, kio_color( $im, $hex ) );
	kio_rrect( $im, $cx - 60 * $s, $y, $cx + 60 * $s, $y + 10 * $s, 5 * $s, kio_color( $im, kio_shade( $hex, -0.2 ) ) ); // khe mở nắp
}

/** Đồng hồ: dây đeo + mặt đồng hồ bo tròn. */
function kio_draw_watch( $im, $cx, $cy, $s, string $hex ): void {
	kio_rrect( $im, $cx - 82 * $s, $cy - 240 * $s, $cx + 82 * $s, $cy + 240 * $s, 40 * $s, kio_color( $im, kio_shade( $hex, -0.15 ) ) );
	kio_draw_screen_device( $im, $cx, $cy, 250 * $s, 300 * $s, 70 * $s, 20 * $s, $hex, false );
	kio_rrect( $im, $cx + 125 * $s, $cy - 50 * $s, $cx + 145 * $s, $cy + 10 * $s, 8 * $s, kio_color( $im, kio_shade( $hex, -0.3 ) ) ); // núm xoay
}

/** Tai nghe nhét tai: hộp sạc + đèn báo. */
function kio_draw_earbuds( $im, $cx, $cy, $s, string $hex ): void {
	$w = 170 * $s; $h = 130 * $s;
	kio_rrect( $im, $cx - $w + 4, $cy - $h + 16, $cx + $w - 4, $cy + $h + 16, 100 * $s, kio_color( $im, '#000000', 118 ) );
	kio_rrect( $im, $cx - $w, $cy - $h, $cx + $w, $cy + $h, 100 * $s, kio_color( $im, kio_shade( $hex, -0.15 ) ) );
	kio_rrect( $im, $cx - $w + 6, $cy - $h + 6, $cx + $w - 6, $cy + $h - 6, 94 * $s, kio_color( $im, $hex ) );
	imagefilledrectangle( $im, (int) ( $cx - $w + 10 ), (int) ( $cy - 40 * $s ), (int) ( $cx + $w - 10 ), (int) ( $cy - 37 * $s ), kio_color( $im, kio_shade( $hex, -0.2 ) ) ); // đường nắp
	imagefilledellipse( $im, (int) $cx, (int) ( $cy + 10 * $s ), (int) ( 16 * $s ), (int) ( 16 * $s ), kio_color( $im, '#22c55e' ) ); // đèn
}

/** Loa Bluetooth dạng trụ nằm ngang, có lưới loa. */
function kio_draw_speaker( $im, $cx, $cy, $s, string $hex ): void {
	$w = 260 * $s; $h = 110 * $s;
	kio_rrect( $im, $cx - $w + 4, $cy - $h + 16, $cx + $w - 4, $cy + $h + 16, $h, kio_color( $im, '#000000', 118 ) );
	kio_rrect( $im, $cx - $w, $cy - $h, $cx + $w, $cy + $h, $h, kio_color( $im, kio_shade( $hex, -0.3 ) ) );
	kio_rrect( $im, $cx - $w + 40 * $s, $cy - $h, $cx + $w - 40 * $s, $cy + $h, 60 * $s, kio_color( $im, $hex ) );
	$dot = kio_color( $im, kio_shade( $hex, -0.25 ) );
	for ( $x = -180; $x <= 180; $x += 24 ) {
		for ( $y = -80; $y <= 80; $y += 24 ) {
			imagefilledellipse( $im, (int) ( $cx + $x * $s ), (int) ( $cy + $y * $s ), (int) ( 9 * $s ), (int) ( 9 * $s ), $dot );
		}
	}
}

/** Sạc dự phòng: khối chữ nhật đứng + 4 đèn báo pin + cổng. */
function kio_draw_powerbank( $im, $cx, $cy, $s, string $hex ): void {
	$w = 130 * $s; $h = 220 * $s;
	kio_rrect( $im, $cx - $w + 4, $cy - $h + 16, $cx + $w - 4, $cy + $h + 16, 40 * $s, kio_color( $im, '#000000', 118 ) );
	kio_rrect( $im, $cx - $w, $cy - $h, $cx + $w, $cy + $h, 40 * $s, kio_color( $im, kio_shade( $hex, -0.3 ) ) );
	kio_rrect( $im, $cx - $w + 6, $cy - $h + 6, $cx + $w - 6, $cy + $h - 6, 36 * $s, kio_color( $im, $hex ) );
	for ( $i = 0; $i < 4; $i++ ) {
		imagefilledellipse( $im, (int) ( $cx + ( $i - 1.5 ) * 30 * $s ), (int) ( $cy + 120 * $s ), (int) ( 14 * $s ), (int) ( 14 * $s ), kio_color( $im, $i < 3 ? '#22c55e' : '#4b5563' ) );
	}
	kio_rrect( $im, $cx - 40 * $s, $cy - $h + 20 * $s, $cx + 40 * $s, $cy - $h + 42 * $s, 11 * $s, kio_color( $im, '#374151' ) );
}

/** Cáp sạc cuộn tròn, 2 đầu cắm. */
function kio_draw_cable( $im, $cx, $cy, $s, string $hex ): void {
	// Vẽ 2 lớp: viền đậm dày bên dưới, màu cáp mỏng hơn bên trên → cáp trắng vẫn nổi trên nền sáng
	foreach ( [ [ 22, kio_shade( $hex, -0.35 ) ], [ 14, $hex ] ] as [ $thick, $color ] ) {
		imagesetthickness( $im, (int) ( $thick * $s ) );
		$line = kio_color( $im, $color );
		foreach ( [ 360, 300, 240 ] as $d ) {
			imagearc( $im, (int) $cx, (int) ( $cy + 20 * $s ), (int) ( $d * $s ), (int) ( $d * 0.8 * $s ), 0, 360, $line );
		}
		imageline( $im, (int) ( $cx + 180 * $s ), (int) ( $cy + 20 * $s ), (int) ( $cx + 230 * $s ), (int) ( $cy - 150 * $s ), $line );
		imageline( $im, (int) ( $cx - 180 * $s ), (int) ( $cy + 20 * $s ), (int) ( $cx - 230 * $s ), (int) ( $cy - 150 * $s ), $line );
	}
	imagesetthickness( $im, 1 );
	foreach ( [ -1, 1 ] as $side ) {   // đầu cắm USB-C
		$x = $cx + $side * 230 * $s;
		kio_rrect( $im, $x - 30 * $s, $cy - 250 * $s, $x + 30 * $s, $cy - 140 * $s, 14 * $s, kio_color( $im, kio_shade( $hex, -0.2 ) ) );
		kio_rrect( $im, $x - 20 * $s, $cy - 290 * $s, $x + 20 * $s, $cy - 245 * $s, 8 * $s, kio_color( $im, '#9ca3af' ) );
	}
}

/** Ốp lưng: mặt lưng điện thoại, cụm camera, vòng MagSafe. */
function kio_draw_case( $im, $cx, $cy, $s, string $hex ): void {
	$w = 130 * $s; $h = 255 * $s;
	kio_rrect( $im, $cx - $w + 4, $cy - $h + 16, $cx + $w - 4, $cy + $h + 16, 48 * $s, kio_color( $im, '#000000', 118 ) );
	kio_rrect( $im, $cx - $w, $cy - $h, $cx + $w, $cy + $h, 48 * $s, kio_color( $im, kio_shade( $hex, -0.25 ) ) );
	kio_rrect( $im, $cx - $w + 10, $cy - $h + 10, $cx + $w - 10, $cy + $h - 10, 44 * $s, kio_color( $im, $hex ) );
	// Cụm camera
	$x1 = $cx - $w + 22 * $s; $y1 = $cy - $h + 22 * $s;
	kio_rrect( $im, $x1, $y1, $x1 + 120 * $s, $y1 + 120 * $s, 30 * $s, kio_color( $im, kio_shade( $hex, -0.3 ) ) );
	foreach ( [ [ 35, 35 ], [ 85, 35 ], [ 35, 85 ] ] as [ $dx, $dy ] ) {
		imagefilledellipse( $im, (int) ( $x1 + $dx * $s ), (int) ( $y1 + $dy * $s ), (int) ( 40 * $s ), (int) ( 40 * $s ), kio_color( $im, '#111827' ) );
	}
	// Vòng MagSafe
	imagesetthickness( $im, (int) ( 6 * $s ) );
	imagearc( $im, (int) $cx, (int) ( $cy + 30 * $s ), (int) ( 150 * $s ), (int) ( 150 * $s ), 0, 360, kio_color( $im, kio_shade( $hex, -0.15 ) ) );
	imagesetthickness( $im, 1 );
}

// --- Hàm vẽ cơ bản ---------------------------------------------------------------

/** Hình chữ nhật bo góc: 2 hình chữ nhật chéo nhau + 4 hình tròn ở góc. */
function kio_rrect( $im, $x1, $y1, $x2, $y2, $r, $color ): void {
	[ $x1, $y1, $x2, $y2, $r ] = array_map( 'intval', [ $x1, $y1, $x2, $y2, $r ] );
	imagefilledrectangle( $im, $x1 + $r, $y1, $x2 - $r, $y2, $color );
	imagefilledrectangle( $im, $x1, $y1 + $r, $x2, $y2 - $r, $color );
	foreach ( [ [ $x1 + $r, $y1 + $r ], [ $x2 - $r, $y1 + $r ], [ $x1 + $r, $y2 - $r ], [ $x2 - $r, $y2 - $r ] ] as [ $x, $y ] ) {
		imagefilledellipse( $im, $x, $y, 2 * $r, 2 * $r, $color );
	}
}

/** Viết chữ căn giữa, tự giảm cỡ chữ nếu quá dài. */
function kio_text_center( $im, string $text, string $font, float $size, int $baseline, string $hex, int $max_width ): void {
	do {
		$box   = imagettfbbox( $size, 0, $font, $text );
		$width = $box[2] - $box[0];
		$size -= 2;
	} while ( $width > $max_width && $size > 10 );
	imagettftext( $im, $size + 2, 0, (int) ( ( imagesx( $im ) - $width ) / 2 ), $baseline, kio_color( $im, $hex ), $font, $text );
}

/** Viết chữ căn giữa quanh điểm (x, baseline). */
function kio_text_at( $im, string $text, string $font, float $size, $x, $baseline, string $hex ): void {
	$box = imagettfbbox( $size, 0, $font, $text );
	imagettftext( $im, $size, 0, (int) ( $x - ( $box[2] - $box[0] ) / 2 ), (int) $baseline, kio_color( $im, $hex ), $font, $text );
}

/** '#rrggbb' → màu GD. $alpha: 0 = đặc, 127 = trong suốt. */
function kio_color( $im, string $hex, int $alpha = 0 ): int {
	[ $r, $g, $b ] = sscanf( ltrim( $hex, '#' ), '%02x%02x%02x' );
	return imagecolorallocatealpha( $im, $r, $g, $b, $alpha );
}

/** Màu chữ đọc được trên nền $hex: nền tối → chữ sáng, nền sáng → chữ tối. */
function kio_contrast( string $hex ): string {
	[ $r, $g, $b ] = sscanf( ltrim( $hex, '#' ), '%02x%02x%02x' );
	$luminance     = ( 0.299 * $r + 0.587 * $g + 0.114 * $b ) / 255;
	return $luminance < 0.5 ? kio_shade( $hex, 0.6 ) : kio_shade( $hex, -0.45 );
}

/** Làm sáng (amount > 0) hoặc tối (amount < 0) một màu. */
function kio_shade( string $hex, float $amount ): string {
	return kio_mix( $hex, $amount > 0 ? '#ffffff' : '#000000', abs( $amount ) );
}

/** Trộn 2 màu, t = 0 → màu a, t = 1 → màu b. */
function kio_mix( string $a, string $b, float $t ): string {
	$ca = sscanf( ltrim( $a, '#' ), '%02x%02x%02x' );
	$cb = sscanf( ltrim( $b, '#' ), '%02x%02x%02x' );
	return vsprintf( '#%02x%02x%02x', array_map( fn( $x, $y ) => (int) round( $x + ( $y - $x ) * $t ), $ca, $cb ) );
}
