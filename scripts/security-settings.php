<?php
/**
 * Cấu hình bảo mật nằm trong database. Chạy lặp lại được.
 *
 * Chạy sau khi đã nạp sản phẩm và trang: wp eval-file /scripts/security-settings.php
 *
 * @package KioStore
 */

// Bài và trang mới không mở bình luận, ping. Đánh giá sản phẩm không bị ảnh hưởng: Woo lưu
// comment_status của từng sản phẩm theo reviews_allowed, không theo 2 option này.
update_option( 'default_comment_status', 'closed' );
update_option( 'default_ping_status', 'closed' );
update_option( 'default_pingback_flag', 0 );

// Tên hiển thị và slug không được trùng tên đăng nhập: chúng xuất hiện công khai, tên đăng nhập thì
// không. Nếu không, chỗ nào in tên tác giả (phản hồi đánh giá, feed) cũng là một nửa thông tin đăng nhập.
foreach ( get_users( array( 'role' => 'administrator' ) ) as $kio_admin ) {
	wp_update_user(
		array(
			'ID'            => $kio_admin->ID,
			'display_name'  => 'Kio Mobile',
			'nickname'      => 'Kio Mobile',
			'user_nicename' => 'kio-mobile-' . $kio_admin->ID,
		)
	);
}

// Limit Login Attempts Reloaded: tên option tra trong core/Config.php, tiền tố limit_login_.
// 4 lần sai khoá 20 phút; 4 lần bị khoá thì khoá 24 giờ. IP lấy từ REMOTE_ADDR, không tin header khách gửi.
update_option( 'limit_login_allowed_retries', 4 );
update_option( 'limit_login_lockout_duration', 20 * MINUTE_IN_SECONDS );
update_option( 'limit_login_allowed_lockouts', 4 );
update_option( 'limit_login_long_duration', DAY_IN_SECONDS );
update_option( 'limit_login_trusted_ip_origins', array( 'REMOTE_ADDR' ) );
// Báo cáo email định kỳ: site demo không cần.
update_option( 'limit_login_digest_daily', 0 );
update_option( 'limit_login_digest_weekly', 0 );
update_option( 'limit_login_digest_monthly', 0 );

// Two Factor: chỉ cho app tạo mã và mã dự phòng. Bỏ Dummy (hiện khi WP_DEBUG bật, không cần mã thật)
// và Email (lộ hộp thư là lộ luôn lớp thứ 2).
update_option( 'two_factor_enabled_providers', array( 'Two_Factor_Totp', 'Two_Factor_Backup_Codes' ) );

// Editor có sẵn unfiltered_html: chèn <script> vào trang, script chạy khi admin mở trang, đủ để chiếm
// quyền admin. Shop Manager vốn không có. Lưu ý: Editor lưu trang chủ thì thuộc tính HTML lạ bị lược bỏ.
get_role( 'editor' )->remove_cap( 'unfiltered_html' );

// User mẫu chỉ có ở local để thử phân quyền. Mật khẩu ngẫu nhiên, không in ra: muốn đăng nhập thì đặt bằng
// wp user update <tên> --user_pass=... Không bao giờ tạo trên server thật.
if ( 'local' === wp_get_environment_type() ) {
	foreach ( array( 'kio-shop' => 'shop_manager', 'kio-editor' => 'editor' ) as $kio_login => $kio_role ) {
		if ( ! username_exists( $kio_login ) ) {
			wp_insert_user(
				array(
					'user_login'   => $kio_login,
					'user_pass'    => wp_generate_password( 32 ),
					'user_email'   => $kio_login . '@kio.local',
					'display_name' => 'shop_manager' === $kio_role ? 'Quản lý cửa hàng' : 'Biên tập viên',
					'role'         => $kio_role,
				)
			);
		}
	}
}

// HTML trong page cache còn dữ liệu cũ.
if ( class_exists( 'Cache_Enabler' ) ) {
	Cache_Enabler::clear_complete_cache();
}
wp_cache_flush();

WP_CLI::log( '    Đã cấu hình bình luận, tên hiển thị admin' );
