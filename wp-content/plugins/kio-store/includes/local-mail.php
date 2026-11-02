<?php
/**
 * Gửi mọi email của WordPress vào Mailpit khi chạy local.
 *
 * WordPress gửi mail bằng hàm wp_mail(), bên trong dùng thư viện PHPMailer.
 * Ngay trước khi gửi, wp_mail() chạy hook "phpmailer_init" và truyền đối tượng
 * PHPMailer vào. Ta gắn một hàm vào hook đó để đổi cách gửi từ "sendmail"
 * (không có trong container) sang SMTP tới Mailpit.
 *
 * Không sửa dòng nào trong code WordPress hay WooCommerce: chỉ "gắn thêm" vào hook.
 *
 * An toàn khi đưa lên server thật: chỉ chạy khi môi trường là "local" VÀ có hằng số
 * KIO_SMTP_HOST (định nghĩa trong docker-compose.yml → wp-config.php).
 *
 * @package KioStore
 */

defined( 'ABSPATH' ) || exit;

add_action( 'phpmailer_init', 'kio_store_local_smtp' );

/**
 * ACTION phpmailer_init: chuyển PHPMailer sang gửi SMTP tới Mailpit.
 *
 * Action chỉ "làm thêm việc", không cần trả về gì.
 *
 * @param PHPMailer\PHPMailer\PHPMailer $mailer Đối tượng PHPMailer mà wp_mail() sắp dùng để gửi.
 */
function kio_store_local_smtp( PHPMailer\PHPMailer\PHPMailer $mailer ): void {
	if ( 'local' !== wp_get_environment_type() || ! defined( 'KIO_SMTP_HOST' ) ) {
		return;
	}

	// Tên thuộc tính (Host, SMTPAuth…) do thư viện PHPMailer đặt, không phải code của ta,
	// nên bỏ qua quy tắc đặt tên snake_case của WordPress cho đúng khối này.
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	$mailer->isSMTP();
	$mailer->Host        = KIO_SMTP_HOST;
	$mailer->Port        = defined( 'KIO_SMTP_PORT' ) ? KIO_SMTP_PORT : 1025;
	$mailer->SMTPAuth    = false; // Mailpit không cần đăng nhập.
	$mailer->SMTPAutoTLS = false; // Và không dùng mã hoá.
	// phpcs:enable
}

add_filter( 'wp_mail_from', 'kio_store_local_mail_from' );

/**
 * FILTER wp_mail_from: sửa địa chỉ người gửi khi chạy local.
 *
 * Địa chỉ mặc định của WordPress là "wordpress@<tên miền site>". Ở local tên miền là
 * "localhost" (không có dấu chấm) nên PHPMailer coi là không hợp lệ và wp_mail() thất bại.
 * Trên server thật (wordpress@kiomobile.vn) không có lỗi này.
 *
 * Filter nhận giá trị hiện tại và PHẢI trả về một giá trị (giữ nguyên hoặc đã sửa).
 *
 * @param string $from Địa chỉ người gửi hiện tại.
 * @return string Địa chỉ người gửi sẽ dùng.
 */
function kio_store_local_mail_from( string $from ): string {
	if ( 'local' === wp_get_environment_type() && str_ends_with( $from, '@localhost' ) ) {
		return 'no-reply@kio.local';
	}
	return $from;
}
