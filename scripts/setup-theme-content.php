<?php
/**
 * Thiết lập nội dung theme cho Kio Mobile. Chạy lặp lại được.
 *
 * Việc trong file này:
 *   1. Vẽ banner (hero + 2 banner nhỏ) bằng GD, đưa vào Media Library.
 *   2. Tạo/cập nhật các trang từ data/pages/*.html (block markup).
 *   3. Đặt "Trang chủ" làm trang tĩnh (show_on_front = page).
 *   4. Chuyển Giỏ hàng/Thanh toán sang shortcode (để hook PHP classic chạy ở Phase 4).
 *   5. Tạo menu: mega menu danh mục + 2 menu footer, gán vị trí menu.
 *   6. Ghi một số cài đặt Customizer (theme mod) và option.
 *
 * Chạy: docker compose run --rm wpcli eval-file /scripts/setup-theme-content.php
 */

require_once '/scripts/lib/banner-image.php';

// wp_insert_post chạy kses cho nội dung nếu user hiện tại không có quyền
// unfiltered_html. Block markup chứa <svg>/class riêng nên phải chạy dưới quyền admin.
wp_set_current_user( 1 );

// --- 1. Banner --------------------------------------------------------------------

$banner_ids = [
	'HERO_1'   => kio_banner_image( [ 'title' => 'Điện thoại giảm đến 20%', 'subtitle' => 'Thu cũ đổi mới — trả góp 0%', 'badge' => 'GIẢM SỐC' ] ),
	'HERO_2'   => kio_banner_image( [ 'from' => '#312e81', 'to' => '#1e1b4b', 'title' => 'Laptop văn phòng giá tốt', 'subtitle' => 'Trả góp 0% lãi suất' ] ),
	'HERO_3'   => kio_banner_image( [ 'from' => '#b45309', 'to' => '#7c2d12', 'title' => 'Phụ kiện chính hãng', 'subtitle' => 'Sạc, cáp, ốp lưng, tai nghe' ] ),
	'BANNER_A' => kio_banner_image( [ 'width' => 600, 'height' => 236, 'from' => '#0d9488', 'to' => '#134e4a', 'title' => 'Thu cũ đổi mới', 'subtitle' => 'Lên đời điện thoại' ] ),
	'BANNER_B' => kio_banner_image( [ 'width' => 600, 'height' => 236, 'from' => '#be123c', 'to' => '#881337', 'title' => 'Tai nghe giảm sốc', 'subtitle' => 'Giảm đến 40%' ] ),
];

$banner_urls = [];
foreach ( $banner_ids as $key => $id ) {
	$banner_urls[ '{{' . $key . '}}' ] = wp_get_attachment_image_url( $id, 'full' );
}
WP_CLI::log( sprintf( '    %d banner đã vẽ xong', count( $banner_ids ) ) );

// --- 2. Trang nội dung ------------------------------------------------------------

/**
 * Tạo hoặc cập nhật trang theo slug.
 *
 * @param string $slug     Slug trang.
 * @param string $title    Tiêu đề.
 * @param string $content  Nội dung (block markup).
 * @param string $template Tên file template (rỗng = mặc định).
 * @return int ID trang.
 */
function kio_upsert_page( string $slug, string $title, string $content, string $template = '' ): int {
	$existing = get_page_by_path( $slug );
	$args     = [
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => $title,
		'post_name'    => $slug,
		'post_content' => $content,
	];

	if ( $existing ) {
		$args['ID'] = $existing->ID;
		$id         = wp_update_post( $args );
		$action     = 'cập nhật';
	} else {
		$id     = wp_insert_post( $args );
		$action = 'tạo mới';
	}

	if ( $template ) {
		update_post_meta( $id, '_wp_page_template', $template );
	}

	WP_CLI::log( sprintf( '    %-26s %s (ID %d)', $slug, $action, $id ) );
	return (int) $id;
}

$pages = [
	'trang-chu'             => [ 'title' => 'Trang chủ', 'template' => 'template-fullwidth.php' ],
	'chinh-sach-bao-hanh'   => [ 'title' => 'Chính sách bảo hành' ],
	'chinh-sach-doi-tra'    => [ 'title' => 'Chính sách đổi trả' ],
	'chinh-sach-giao-hang'  => [ 'title' => 'Chính sách giao hàng' ],
	'huong-dan-mua-hang'    => [ 'title' => 'Hướng dẫn mua hàng' ],
	'lien-he'               => [ 'title' => 'Liên hệ' ],
	'gioi-thieu'            => [ 'title' => 'Giới thiệu' ],
	'tra-cuu-don-hang'      => [ 'title' => 'Tra cứu đơn hàng' ],
];

$page_ids = [];
foreach ( $pages as $slug => $meta ) {
	$file = "/data/pages/$slug.html";
	if ( ! is_file( $file ) ) {
		WP_CLI::warning( "Thiếu $file" );
		continue;
	}
	$content = file_get_contents( $file );

	// Trang chủ: thay placeholder ảnh banner bằng URL thật.
	if ( 'trang-chu' === $slug ) {
		$content = strtr( $content, $banner_urls );
	}
	// Trang Liên hệ: ID form Fluent Forms do scripts/plugins-settings.php tạo (chạy trước script này).
	$content = strtr( $content, [ '{{CONTACT_FORM_ID}}' => (string) get_option( 'kio_contact_form_id', 0 ) ] );

	$page_ids[ $slug ] = kio_upsert_page( $slug, $meta['title'], $content, $meta['template'] ?? '' );
}

// --- 3. Trang tĩnh (front page) ---------------------------------------------------

update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $page_ids['trang-chu'] );
WP_CLI::log( '    show_on_front=page, page_on_front=' . $page_ids['trang-chu'] );

// SEO trang chủ (Rank Math). Trang chủ là trang tĩnh nên Rank Math lấy tiêu đề/mô tả từ meta
// riêng của trang này, không dùng cài đặt "homepage" chung. Chỉ ghi khi Rank Math đang bật.
if ( defined( 'RANK_MATH_VERSION' ) ) {
	update_post_meta( $page_ids['trang-chu'], 'rank_math_title', '%sitename% %sep% Điện thoại, máy tính bảng, laptop chính hãng' );
	update_post_meta( $page_ids['trang-chu'], 'rank_math_description', 'Kio Mobile (website demo): điện thoại, máy tính bảng, laptop, đồng hồ, phụ kiện chính hãng. Trả góp 0%, giao nhanh 2h, đổi trả 30 ngày.' );
}

// Option riêng để header lấy link trang "Tra cứu đơn hàng".
update_option( 'kio_tracking_page_id', $page_ids['tra-cuu-don-hang'] );

// --- 4. Giỏ hàng / Thanh toán về shortcode ---------------------------------------

$cart_id     = (int) get_option( 'woocommerce_cart_page_id' );
$checkout_id = (int) get_option( 'woocommerce_checkout_page_id' );
wp_update_post( [ 'ID' => $cart_id, 'post_content' => '[woocommerce_cart]' ] );
wp_update_post( [ 'ID' => $checkout_id, 'post_content' => '[woocommerce_checkout]' ] );
WP_CLI::log( "    Giỏ hàng (ID $cart_id) → [woocommerce_cart], Thanh toán (ID $checkout_id) → [woocommerce_checkout]" );

// --- 5. Menu ----------------------------------------------------------------------

/**
 * Lấy (hoặc tạo) menu theo tên, xoá hết item cũ để dựng lại sạch.
 *
 * @param string $name Tên menu.
 * @return int Menu ID.
 */
function kio_reset_menu( string $name ): int {
	$menu = wp_get_nav_menu_object( $name );
	if ( ! $menu ) {
		$menu_id = wp_create_nav_menu( $name );
	} else {
		$menu_id = $menu->term_id;
		foreach ( wp_get_nav_menu_items( $menu_id ) ?: [] as $item ) {
			wp_delete_post( $item->ID, true );
		}
	}
	return (int) $menu_id;
}

/**
 * Thêm một item vào menu.
 *
 * @param int    $menu_id   Menu ID.
 * @param string $type      'taxonomy' | 'post_type'.
 * @param string $object    Taxonomy hoặc post type.
 * @param int    $object_id ID term/post.
 * @param int    $parent_id ID item cha (0 = gốc).
 * @return int Item ID.
 */
function kio_add_menu_item( int $menu_id, string $type, string $object, int $object_id, int $parent_id = 0 ): int {
	$title = 'taxonomy' === $type
		? get_term( $object_id, $object )->name
		: get_the_title( $object_id );

	return (int) wp_update_nav_menu_item(
		$menu_id,
		0,
		[
			'menu-item-title'     => $title,
			'menu-item-object'    => $object,
			'menu-item-object-id' => $object_id,
			'menu-item-type'      => $type,
			'menu-item-status'    => 'publish',
			'menu-item-parent-id' => $parent_id,
		]
	);
}

/**
 * Gán menu vào một vị trí (location).
 *
 * @param string $location Tên location.
 * @param int    $menu_id  Menu ID.
 */
function kio_assign_menu( string $location, int $menu_id ): void {
	$locations            = get_theme_mod( 'nav_menu_locations', [] );
	$locations[ $location ] = $menu_id;
	set_theme_mod( 'nav_menu_locations', $locations );
}

// Brand tương ứng với mỗi danh mục (để mega menu có cột thương hiệu con).
$category_brands = [
	'dien-thoai'         => [ 'apple', 'samsung', 'xiaomi', 'oppo' ],
	'may-tinh-bang'      => [ 'apple', 'samsung', 'xiaomi' ],
	'laptop'             => [ 'apple', 'asus', 'lenovo' ],
	'dong-ho-thong-minh' => [ 'apple', 'samsung' ],
	'am-thanh'           => [ 'apple', 'sony', 'jbl' ],
	'phu-kien'           => [ 'anker', 'baseus', 'apple', 'samsung' ],
];

$cat_menu_id = kio_reset_menu( 'Danh mục sản phẩm' );
foreach ( $category_brands as $cat_slug => $brand_slugs ) {
	$cat = get_term_by( 'slug', $cat_slug, 'product_cat' );
	if ( ! $cat ) {
		continue;
	}
	$cat_item = kio_add_menu_item( $cat_menu_id, 'taxonomy', 'product_cat', $cat->term_id );
	foreach ( $brand_slugs as $brand_slug ) {
		$brand = get_term_by( 'slug', $brand_slug, 'product_brand' );
		if ( $brand ) {
			kio_add_menu_item( $cat_menu_id, 'taxonomy', 'product_brand', $brand->term_id, $cat_item );
		}
	}
}
kio_assign_menu( 'kio-categories', $cat_menu_id );
WP_CLI::log( "    Menu mega: Danh mục sản phẩm ($cat_menu_id)" );

$policy_menu_id = kio_reset_menu( 'Footer: Chính sách' );
foreach ( [ 'chinh-sach-bao-hanh', 'chinh-sach-doi-tra', 'chinh-sach-giao-hang' ] as $slug ) {
	kio_add_menu_item( $policy_menu_id, 'post_type', 'page', $page_ids[ $slug ] );
}
kio_assign_menu( 'kio-footer-policy', $policy_menu_id );

$support_menu_id = kio_reset_menu( 'Footer: Hỗ trợ' );
foreach ( [ 'huong-dan-mua-hang', 'lien-he', 'tra-cuu-don-hang' ] as $slug ) {
	kio_add_menu_item( $support_menu_id, 'post_type', 'page', $page_ids[ $slug ] );
}
kio_assign_menu( 'kio-footer-support', $support_menu_id );
WP_CLI::log( "    Menu footer: Chính sách ($policy_menu_id), Hỗ trợ ($support_menu_id)" );

// --- 6. Customizer (theme mod) + option ------------------------------------------

set_theme_mod( 'kio_hotline', '1800 6868' );

// Màu chủ đạo: ghi vào theme mod của Storefront (Customizer) để nút/link khớp token.
$storefront_colors = [
	'storefront_button_background_color'     => '#0d47a1',
	'storefront_button_text_color'           => '#ffffff',
	'storefront_button_alt_background_color' => '#08347a',
	'storefront_button_alt_text_color'       => '#ffffff',
	'storefront_link_color'                  => '#0d47a1',
	'storefront_heading_color'               => '#1f2937',
	'storefront_text_color'                  => '#1f2937',
	'storefront_header_background_color'     => '#ffffff',
	'storefront_footer_background_color'     => '#10243f',
];
foreach ( $storefront_colors as $mod => $value ) {
	set_theme_mod( $mod, $value );
}

update_option( 'blogdescription', 'Cửa hàng điện thoại, máy tính bảng và đồ điện tử (demo)' );

WP_CLI::success( sprintf(
	'Xong: %d trang, %d menu, trang chủ tĩnh, giỏ/thanh toán dùng shortcode.',
	count( $page_ids ),
	3
) );
