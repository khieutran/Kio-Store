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
 */

defined( 'ABSPATH' ) || exit;

add_action( 'phpmailer_init', 'kio_store_local_smtp' );

function kio_store_local_smtp( PHPMailer\PHPMailer\PHPMailer $mailer ): void {
	if ( 'local' !== wp_get_environment_type() || ! defined( 'KIO_SMTP_HOST' ) ) {
		return;
	}

	$mailer->isSMTP();
	$mailer->Host        = KIO_SMTP_HOST;
	$mailer->Port        = defined( 'KIO_SMTP_PORT' ) ? KIO_SMTP_PORT : 1025;
	$mailer->SMTPAuth    = false;   // Mailpit không cần đăng nhập
	$mailer->SMTPAutoTLS = false;   // và không dùng mã hoá
}

/**
 * Địa chỉ người gửi mặc định của WordPress là "wordpress@<tên miền site>".
 * Ở local tên miền là "localhost" (không có dấu chấm) nên PHPMailer coi là không hợp lệ
 * và wp_mail() thất bại. Trên server thật (wordpress@kiomobile.vn) không có lỗi này.
 *
 * "wp_mail_from" là một FILTER: nhận giá trị hiện tại, trả về giá trị mới.
 * (Khác ACTION ở trên: action chỉ "làm thêm việc", không cần trả về gì.)
 */
add_filter( 'wp_mail_from', 'kio_store_local_mail_from' );

function kio_store_local_mail_from( string $from ): string {
	if ( 'local' === wp_get_environment_type() && str_ends_with( $from, '@localhost' ) ) {
		return 'no-reply@kio.local';
	}
	return $from;
}
