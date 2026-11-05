<?php
/**
 * Thu hẹp bề mặt tấn công trong WordPress.
 *
 * Phần chặn được ở Apache nằm trong docker/apache/kio-security.conf. File này chỉ giữ những việc phải
 * biết ngữ cảnh WordPress (ai đang đăng nhập, route REST nào, loại lỗi đăng nhập nào).
 * Kiểm chứng bằng scripts/security-check.py.
 *
 * Không gỡ ?ver= khỏi CSS/JS dù nó lộ phiên bản: cache trình duyệt 1 năm dựa vào đó (Phase 5).
 * Ẩn phiên bản chỉ là lớp phụ, việc thật là cập nhật.
 *
 * @package KioStore
 */

defined( 'ABSPATH' ) || exit;

// xmlrpc.php đã bị Apache chặn. Filter này giữ cho WordPress cũng từ chối nếu Apache cấu hình thiếu
// (nó chỉ tắt các method cần đăng nhập, không tắt pingback nên không thay được Apache).
add_filter( 'xmlrpc_enabled', '__return_false' );
remove_action( 'wp_head', 'rsd_link' );

// Application Password đăng nhập REST bằng mật khẩu riêng, không qua form nên có thể đi vòng 2FA.
add_filter( 'wp_is_application_passwords_available', '__return_false' );

// Gỡ cả thẻ generator của Woo và dòng <generator> trong feed.
add_filter( 'the_generator', '__return_empty_string' );

add_filter( 'rest_endpoints', 'kio_store_hide_user_endpoints' );
add_action( 'template_redirect', 'kio_store_disable_author_archives', 1 );
add_filter( 'oembed_response_data', 'kio_store_hide_oembed_author' );
add_filter( 'rank_math/json_ld', 'kio_store_hide_schema_author', 999 );
add_filter( 'authenticate', 'kio_store_generic_login_error', 999 );
add_filter( 'shake_error_codes', 'kio_store_shake_login_error' );
add_filter( 'gettext_limit-login-attempts-reloaded', 'kio_store_translate_login_limit', 10, 2 );
add_filter( 'ngettext_limit-login-attempts-reloaded', 'kio_store_translate_login_limit_plural', 10, 2 );

/**
 * Capability của post type thuộc về cửa hàng (khuyến mãi, video): ai quản lý cửa hàng thì sửa được.
 *
 * Mặc định post type dùng quyền của bài viết, nên Editor, thậm chí Contributor cũng tạo được khuyến mãi
 * hiện trên trang sản phẩm. Trỏ mọi quyền về manage_woocommerce (Admin, Shop Manager) thì không phải ghi
 * thêm capability vào wp_user_roles. Cần map_meta_cap => true để edit_post, delete_post vẫn hoạt động.
 *
 * @return array<string,string>
 */
function kio_store_shop_post_type_caps(): array {
	return array_fill_keys(
		array(
			'create_posts',
			'edit_posts',
			'edit_others_posts',
			'edit_private_posts',
			'edit_published_posts',
			'publish_posts',
			'read_private_posts',
			'delete_posts',
			'delete_others_posts',
			'delete_private_posts',
			'delete_published_posts',
		),
		'manage_woocommerce'
	);
}

/**
 * FILTER rest_endpoints: khách không thấy route user, nên không dò được tên đăng nhập.
 *
 * Người đã đăng nhập vẫn cần: trình soạn thảo block gọi route này để chọn tác giả.
 * Dạng /?rest_route=/wp/v2/users cũng đi qua filter này, nên không chỉ chặn được một kiểu URL.
 *
 * @param array $endpoints Các route REST.
 * @return array
 */
function kio_store_hide_user_endpoints( array $endpoints ): array {
	if ( is_user_logged_in() ) {
		return $endpoints;
	}

	unset( $endpoints['/wp/v2/users'], $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );

	return $endpoints;
}

/**
 * ACTION template_redirect: site không có trang tác giả, trả 404.
 *
 * Priority 1: phải chạy trước redirect_canonical (priority 10), hàm đó sẽ chuyển ?author=1 sang
 * /author/<tên>/ và làm lộ tên.
 *
 * @return void
 */
function kio_store_disable_author_archives(): void {
	if ( ! is_author() ) {
		return;
	}

	global $wp_query;
	$wp_query->set_404();
	status_header( 404 );
	nocache_headers();
}

/**
 * FILTER oembed_response_data: bỏ tên tác giả khỏi dữ liệu nhúng trang.
 *
 * @param array $data Dữ liệu oEmbed.
 * @return array
 */
function kio_store_hide_oembed_author( array $data ): array {
	unset( $data['author_name'], $data['author_url'] );

	return $data;
}

/**
 * FILTER rank_math/json_ld: bỏ khối Person và trường author khỏi dữ liệu có cấu trúc.
 *
 * Rank Math in tác giả của trang (tên, link /author/...) vào HTML, kể cả HTML trong page cache. Link
 * đó cũng đã thành 404. Người bán hàng trên schema là Organization, không phải tài khoản admin.
 *
 * @param array $data Các khối JSON-LD.
 * @return array
 */
function kio_store_hide_schema_author( array $data ): array {
	foreach ( $data as $key => $entity ) {
		if ( ! is_array( $entity ) ) {
			continue;
		}
		if ( 'Person' === ( $entity['@type'] ?? '' ) ) {
			unset( $data[ $key ] );
			continue;
		}
		unset( $data[ $key ]['author'] );
	}

	return $data;
}

/**
 * FILTER authenticate: cùng một thông báo cho sai tên và sai mật khẩu.
 *
 * Đặt ở authenticate chứ không ở login_errors, vì form tài khoản của Woo không đi qua wp-login.php.
 * Priority 999: sau các hàm kiểm tra của core. Lỗi của plugin khác (khoá đăng nhập, 2FA) giữ nguyên để
 * người dùng biết phải làm gì.
 *
 * @param WP_User|WP_Error|null $user Kết quả xác thực đến lúc này.
 * @return WP_User|WP_Error|null
 */
function kio_store_generic_login_error( $user ) {
	if ( ! is_wp_error( $user ) ) {
		return $user;
	}

	// Chỉ thay khi không có lỗi nào khác: plugin khoá đăng nhập thêm mã của nó vào cùng WP_Error này.
	if ( array_diff( $user->get_error_codes(), array( 'invalid_username', 'invalid_email', 'incorrect_password' ) ) ) {
		return $user;
	}

	return new WP_Error( 'kio_login_failed', __( 'Tên đăng nhập hoặc mật khẩu không đúng.', 'kio-store' ) );
}

/**
 * FILTER shake_error_codes: giữ hiệu ứng rung form khi đăng nhập sai với mã lỗi mới.
 *
 * @param string[] $codes Mã lỗi làm rung form.
 * @return string[]
 */
function kio_store_shake_login_error( array $codes ): array {
	$codes[] = 'kio_login_failed';

	return $codes;
}

/**
 * FILTER gettext_limit-login-attempts-reloaded: dịch các câu khách thấy ở form đăng nhập.
 *
 * Plugin chưa có bản dịch tiếng Việt, mà form tài khoản của Woo là trang khách dùng.
 *
 * @param string $translation Bản dịch hiện có.
 * @param string $text        Câu gốc.
 * @return string
 */
function kio_store_translate_login_limit( string $translation, string $text ): string {
	$messages = array(
		'<strong>ERROR</strong>: Too many failed login attempts.' => '<strong>Lỗi:</strong> Đăng nhập sai quá nhiều lần.',
		'<strong>ERROR</strong>: Incorrect username or password.' => '<strong>Lỗi:</strong> Tên đăng nhập hoặc mật khẩu không đúng.',
	);

	return $messages[ $text ] ?? $translation;
}

/**
 * FILTER ngettext_limit-login-attempts-reloaded: câu có số đếm, tiếng Việt không có số nhiều.
 *
 * @param string $translation Bản dịch hiện có.
 * @param string $single      Câu gốc dạng số ít.
 * @return string
 */
function kio_store_translate_login_limit_plural( string $translation, string $single ): string {
	$messages = array(
		'Please try again in %d minute.' => 'Vui lòng thử lại sau %d phút.',
		'Please try again in %d hour.'   => 'Vui lòng thử lại sau %d giờ.',
	);

	return $messages[ $single ] ?? $translation;
}
