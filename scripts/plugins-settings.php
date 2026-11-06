<?php
/**
 * Cấu hình các chức năng của Phase 3 (plugin + widget có sẵn của Woo). Chạy lặp lại được.
 *
 *   1. Bộ lọc sản phẩm: widget có sẵn của WooCommerce trong sidebar trang danh mục
 *   2. So sánh: YITH Compare
 *   3. Đánh giá có ảnh: Photo Reviews
 *   4. Yêu thích: WPC Smart Wishlist
 *   5. SEO: Rank Math
 *   6. Form liên hệ: Fluent Forms
 *
 * Mỗi plugin lưu cài đặt trong wp_options; tên option tra từ mã nguồn plugin
 * (file cài đặt / giá trị mặc định), không đoán.
 *
 * Chạy sau scripts/setup-plugins.php. Chạy: docker compose run --rm wpcli eval-file /scripts/plugins-settings.php
 */

wp_set_current_user( 1 );

/** Gộp mảng cài đặt mới vào option dạng mảng (giữ nguyên các khoá không nhắc tới). */
function kio_merge_option( string $name, array $values ): void {
	$current = get_option( $name, array() );
	update_option( $name, array_replace_recursive( is_array( $current ) ? $current : array(), $values ) );
}

/** Đổi tiêu đề + slug của trang do plugin tự tạo (plugin chưa có bản dịch tiếng Việt). */
function kio_rename_page( int $page_id, string $title, string $slug ): void {
	if ( $page_id && get_post( $page_id ) ) {
		wp_update_post( [ 'ID' => $page_id, 'post_title' => $title, 'post_name' => $slug ] );
		WP_CLI::log( "    Trang $page_id → $title (/$slug/)" );
	}
}

// --- 1. Bộ lọc: widget có sẵn của WooCommerce ---------------------------------------
// Widget "cổ điển" lưu ở 2 nơi:
//   - widget_<id_base>: mảng các bản (instance) của widget đó, khoá là số thứ tự
//   - sidebars_widgets: sidebar nào chứa những widget nào ("woocommerce_price_filter-1"...)
// Thay 5 widget mặc định của WordPress (tìm kiếm, bài viết mới…) trong sidebar-1.

$filter_widgets = [
	'woocommerce_brand_nav'           => [ 1 => [ 'title' => 'Thương hiệu', 'display_type' => 'list' ] ],
	'kio_price_bands'                 => [ 1 => [ 'title' => 'Khoảng giá' ] ],
	'woocommerce_layered_nav'         => [
		1 => [ 'title' => 'Dung lượng', 'attribute' => 'dung-luong', 'display_type' => 'list', 'query_type' => 'or' ],
		2 => [ 'title' => 'RAM', 'attribute' => 'ram', 'display_type' => 'list', 'query_type' => 'or' ],
		3 => [ 'title' => 'Màu sắc', 'attribute' => 'mau-sac', 'display_type' => 'list', 'query_type' => 'or' ],
	],
];
$sidebar = [];
foreach ( $filter_widgets as $id_base => $instances ) {
	update_option( "widget_$id_base", $instances + [ '_multiwidget' => 1 ] );
	foreach ( array_keys( $instances ) as $n ) {
		$sidebar[] = "$id_base-$n";
	}
}
$sidebars              = wp_get_sidebars_widgets();
$sidebars['sidebar-1'] = $sidebar;
wp_set_sidebars_widgets( $sidebars );
WP_CLI::log( '    Bộ lọc: ' . count( $sidebar ) . ' widget Woo trong sidebar-1' );

// --- 2. So sánh: YITH Compare ---------------------------------------------------------
update_option( 'yith_woocompare_is_button', 'button' );
update_option( 'yith_woocompare_button_text', 'So sánh' );
update_option( 'yith_woocompare_show_compare_button_in', 'both' );   // trang danh mục + trang sản phẩm
update_option( 'yith_woocompare_table_text', 'So sánh sản phẩm' );
update_option( 'yith_woocompare_fields_product_info_title', 'yes' );
update_option( 'yith_woocompare_fields_product_info_image', 'yes' );
update_option( 'yith_woocompare_fields_product_info_add_cart', 'yes' );
// Các dòng trong bảng so sánh: giá + thông số lấy từ thuộc tính toàn cục (Phase 1).
update_option(
	'yith_woocompare_fields',
	[
		'title'          => true,
		'image'          => true,
		'add_to_cart'    => true,
		'price'          => true,
		'pa_chip'        => true,
		'pa_ram'         => true,
		'pa_man-hinh'    => true,
		'pa_pin'         => true,
		'pa_dung-luong'  => true,
		'pa_mau-sac'     => true,
		'stock'          => true,
	]
);
kio_rename_page( (int) get_option( 'yith_woocompare_page_id' ), 'So sánh sản phẩm', 'so-sanh' );

// --- 3. Đánh giá có ảnh: Photo Reviews ------------------------------------------------
kio_merge_option(
	'_wcpr_nkt_setting',
	[
		'enable' => 'on',
		'photo'  => [
			'enable'                 => 'on',
			'maxfiles'               => 3,
			'overall_rating'         => 'on', // ô điểm trung bình phía trên danh sách đánh giá.
			'rating_count'           => 'on', // thanh tỉ lệ từng mức sao.
			'rating_count_bar_color' => '#0d47a1',
			'verified'               => 'default',
			'verified_text'          => 'Đã mua hàng',
		],
		// Mặc định plugin TỰ GỬI COUPON cho khách viết đánh giá → tắt (shop không có chương trình này).
		'coupon' => [ 'enable' => 'off' ],
	]
);
WP_CLI::log( '    Photo Reviews: bật ảnh (tối đa 3), tắt coupon tự động' );

// --- 4. Yêu thích: WPC Smart Wishlist -------------------------------------------------
// Plugin không có bản dịch tiếng Việt → đổi chữ qua phần "localization" trong cài đặt của nó.
kio_merge_option(
	'woosw_localization',
	[
		'button'          => 'Yêu thích',
		'button_added'    => 'Đã thích',
		'add_wishlist'    => 'Thêm vào yêu thích',
		'added_message'   => 'Đã thêm {name} vào danh sách yêu thích.',
		'already_message' => '{name} đã có trong danh sách yêu thích.',
		'empty_message'   => 'Chưa có sản phẩm nào trong danh sách yêu thích.',
		'empty_button'    => 'Xoá hết',
		'continue'        => 'Tiếp tục mua sắm',
		'delete'          => 'Xoá',
		'popup_heading'   => 'Yêu thích',
		'open_page'       => 'Xem danh sách yêu thích',
		'menu_label'      => 'Yêu thích',
		'removed_message' => 'Đã bỏ khỏi danh sách yêu thích.',
		'error_message'   => 'Có lỗi xảy ra, vui lòng thử lại!',
		'login_message'   => 'Vui lòng đăng nhập để dùng danh sách yêu thích!',
		'empty_notice'    => 'Danh sách yêu thích đang trống.',
	]
);
// Danh sách yêu thích ngắn, thanh cuộn của trình duyệt là đủ: bớt thư viện perfect-scrollbar ở mọi trang.
kio_merge_option( 'woosw_settings', [ 'perfect_scrollbar' => 'no' ] );
$wishlist_settings = get_option( 'woosw_settings', array() );
kio_rename_page( (int) ( $wishlist_settings['page_id'] ?? get_page_by_path( 'wishlist' )->ID ?? 0 ), 'Sản phẩm yêu thích', 'yeu-thich' );

// --- 5. SEO: Rank Math -----------------------------------------------------------------
// Chỉ giữ 3 module cần dùng. Bỏ: analytics (đòi kết nối Google), link-counter (tạo 2 bảng,
// quét link mỗi lần lưu bài), content-ai, instant-indexing, ai-visibility, seo-analysis…
update_option( 'rank_math_modules', [ 'sitemap', 'rich-snippet', 'woocommerce' ] );
// Rank Math TẮT toàn bộ SEO ngoài trang web (title, meta, schema, sitemap) cho tới khi admin
// đăng ký tài khoản Rank Math hoặc bấm "Bỏ qua" trong trình hướng dẫn: kiểm tra ở
// Helper::is_invalid_registration(). Ba option dưới đây = thao tác "Bỏ qua" + "Hoàn tất" trong wizard.
update_option( 'rank_math_registration_skip', true );
update_option( 'rank_math_wizard_completed', true );
update_option( 'rank_math_is_configured', true, false );
delete_option( 'rank_math_notifications' );                 // cảnh báo "No Index" là cố ý (site demo)
kio_merge_option(
	'rank-math-options-titles',
	[
		'title_separator'      => '|',
		'homepage_title'       => '%sitename% %sep% Điện thoại, máy tính bảng, laptop chính hãng',
		'homepage_description' => 'Kio Mobile (website demo): điện thoại, máy tính bảng, laptop, đồng hồ, phụ kiện chính hãng. Trả góp 0%, giao nhanh 2h, đổi trả 30 ngày.',
	]
);
// Sitemap: Rank Math mặc định TẮT danh mục và thương hiệu sản phẩm, trong khi đây là
// các trang quan trọng nhất của shop sau trang sản phẩm.
kio_merge_option(
	'rank-math-options-sitemap',
	[
		'tax_product_cat_sitemap'   => 'on',
		'tax_product_brand_sitemap' => 'on',
	]
);
// Rank Math lưu đệm trang tổng sitemap: đổi cài đặt xong phải xoá cache thì mới thấy mục mới.
if ( class_exists( '\\RankMath\\Sitemap\\Cache' ) ) {
	\RankMath\Sitemap\Cache::invalidate_storage();
}
delete_transient( '_rank_math_activation_redirect' );   // không tự chuyển admin sang trình hướng dẫn
WP_CLI::log( '    Rank Math: module sitemap + rich-snippet + woocommerce; sitemap có danh mục + thương hiệu' );

// --- 6. Form liên hệ: Fluent Forms ----------------------------------------------------
// Dùng lại form mẫu "Contact Form Demo" của plugin, dịch sang tiếng Việt, thêm thông báo
// email cho chủ shop (form mẫu chỉ lưu vào DB, không gửi mail). Xoá form mẫu không dùng.
$forms_table = wpFluent()->table( 'fluentform_forms' );
$form        = wpFluent()->table( 'fluentform_forms' )->where( 'title', 'Liên hệ Kio Mobile' )->first()
	?: wpFluent()->table( 'fluentform_forms' )->where( 'title', 'Contact Form Demo' )->first();

if ( ! $form ) {
	WP_CLI::error( 'Không tìm thấy form mẫu của Fluent Forms để dùng lại.' );
}

$fields = json_decode( $form->form_fields, true );
foreach ( $fields['fields'] as &$field ) {
	$name = $field['attributes']['name'] ?? '';
	if ( 'names' === $name ) {
		// Người Việt quen nhập họ tên trong 1 ô: chỉ hiện ô first_name, đổi nhãn.
		$field['settings']['admin_field_label']                    = 'Họ và tên';
		$field['fields']['first_name']['settings']['label']        = 'Họ và tên';
		$field['fields']['first_name']['attributes']['placeholder'] = 'Nguyễn Văn A';
		$field['fields']['first_name']['settings']['validation_rules']['required'] = [ 'value' => true, 'message' => 'Vui lòng nhập họ tên' ];
		$field['fields']['middle_name']['settings']['visible']     = false;
		$field['fields']['last_name']['settings']['visible']       = false;
	} elseif ( 'email' === $name ) {
		$field['settings']['label']                                = 'Email';
		$field['attributes']['placeholder']                        = 'email@vidu.com';
		$field['settings']['validation_rules']['required']['message'] = 'Vui lòng nhập email';
		$field['settings']['validation_rules']['email']['message']    = 'Email không hợp lệ';
	} elseif ( 'subject' === $name ) {
		$field['settings']['label']         = 'Tiêu đề';
		$field['attributes']['placeholder'] = 'Ví dụ: Hỏi về bảo hành iPhone';
	} elseif ( 'message' === $name ) {
		$field['settings']['label']         = 'Nội dung';
		$field['attributes']['placeholder'] = 'Anh/chị cần Kio Mobile hỗ trợ gì?';
		$field['settings']['validation_rules']['required'] = [ 'value' => true, 'message' => 'Vui lòng nhập nội dung' ];
	}
}
unset( $field );
$fields['submitButton']['settings']['button_ui']['text'] = 'Gửi liên hệ';

wpFluent()->table( 'fluentform_forms' )->where( 'id', $form->id )->update(
	[ 'title' => 'Liên hệ Kio Mobile', 'form_fields' => wp_json_encode( $fields, JSON_UNESCAPED_UNICODE ) ]
);

/** Ghi một dòng meta của form (Fluent Forms lưu cài đặt form trong bảng riêng). */
function kio_form_meta( int $form_id, string $key, $value ): void {
	$table  = wpFluent()->table( 'fluentform_form_meta' );
	$exists = wpFluent()->table( 'fluentform_form_meta' )->where( 'form_id', $form_id )->where( 'meta_key', $key )->first();
	$json   = wp_json_encode( $value, JSON_UNESCAPED_UNICODE );
	if ( $exists ) {
		wpFluent()->table( 'fluentform_form_meta' )->where( 'id', $exists->id )->update( [ 'value' => $json ] );
	} else {
		$table->insert( [ 'form_id' => $form_id, 'meta_key' => $key, 'value' => $json ] );
	}
}

$settings_row = wpFluent()->table( 'fluentform_form_meta' )->where( 'form_id', $form->id )->where( 'meta_key', 'formSettings' )->first();
$settings     = $settings_row ? json_decode( $settings_row->value, true ) : [];
$settings['confirmation']['messageToShow'] = 'Cảm ơn anh/chị đã liên hệ. Kio Mobile sẽ phản hồi trong giờ làm việc.';
kio_form_meta( (int) $form->id, 'formSettings', $settings );

// Email thông báo cho chủ shop mỗi khi có người gửi form ({wp.admin_email}, {all_data}: biến của Fluent Forms).
kio_form_meta(
	(int) $form->id,
	'notifications',
	[
		'name'         => 'Thông báo cho chủ shop',
		'sendTo'       => [ 'type' => 'email', 'email' => '{wp.admin_email}', 'field' => 'email', 'routing' => [] ],
		'fromName'     => 'Kio Mobile',
		'fromEmail'    => '',
		'replyTo'      => '{inputs.email}',
		'bcc'          => '',
		'subject'      => 'Liên hệ mới: {inputs.subject}',
		'message'      => '<p>{all_data}</p>',
		'conditionals' => [ 'status' => false, 'type' => 'all', 'conditions' => [] ],
		'enabled'      => true,
		'email_template' => '',
	]
);

wpFluent()->table( 'fluentform_forms' )->where( 'title', 'Subscription Form' )->delete();
update_option( 'kio_contact_form_id', (int) $form->id );
WP_CLI::log( "    Fluent Forms: form #{$form->id} \"Liên hệ Kio Mobile\" + email thông báo; đã xoá form mẫu không dùng" );

// --- 7. Page cache: Cache Enabler (Phase 5) ---------------------------------------------
// Chỉ cache cho khách chưa có dữ liệu riêng. Số giỏ hàng, tim, so sánh in sẵn trong HTML, nên khách
// có các cookie dưới đây đi thẳng vào PHP. kio_personal do main.js đặt khi thả tim, vì woosw_key của
// plugin yêu thích có ở mọi khách. Trang có query string (lọc, sắp xếp) mặc định không cache.
// Hết hạn 6 giờ: ngắn hơn nonce (12–24 giờ), form trong trang cache không bị hết hạn.
update_option(
	'cache_enabler',
	array_merge(
		(array) get_option( 'cache_enabler', [] ),
		[
			'cache_expires'                      => 1,
			'cache_expiry_time'                  => 6,
			'clear_site_cache_on_saved_post'     => 1,
			'clear_site_cache_on_saved_comment'  => 1,
			'clear_site_cache_on_saved_term'     => 1,
			'clear_site_cache_on_changed_plugin' => 1,
			'compress_cache'                     => 1,
			'excluded_page_paths'                => '/^\/(gio-hang|thanh-toan|tai-khoan|tra-cuu-don-hang|lien-he|so-sanh|yeu-thich)(\/|$)/',
			'excluded_cookies'                   => '/^(wp-postpass|wordpress_logged_in|comment_author|woocommerce_items_in_cart|wp_woocommerce_session|YITH_WooCompare_Products_List|kio_personal)/',
		]
	)
);
Cache_Enabler::update_backend();
WP_CLI::log( '    Cache Enabler: cache 6 giờ cho khách chưa có dữ liệu riêng' );

// --- 8. Object cache: Redis (Phase 5) ---------------------------------------------------
// Tạo drop-in wp-content/object-cache.php. Từ đây dữ liệu nằm cả ở Redis: script nào sửa thẳng DB
// phải gọi wp_cache_flush(), nếu không site vẫn đọc giá trị cũ.
WP_CLI::runcommand( 'redis enable', [ 'return' => true, 'exit_error' => false ] );
WP_CLI::log( '    Redis Object Cache: đã bật drop-in' );

flush_rewrite_rules();   // slug trang đổi, sitemap của Rank Math
WP_CLI::success( 'Đã cấu hình 8 chức năng Phase 3 và Phase 5.' );
