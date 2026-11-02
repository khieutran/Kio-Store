<?php
/**
 * Số liệu hiệu năng của từng trang, chỉ ở môi trường local.
 *
 * In một dòng comment HTML ở cuối trang, ví dụ:
 *   <!-- kio-metrics queries=87 time=142ms memory=38MB -->
 * Script scripts/measure.py đọc dòng này để so sánh trước/sau khi cài plugin (Phase 3)
 * và trước/sau khi tối ưu (Phase 5). Không thay thế Query Monitor (xem chi tiết từng query),
 * mà để đo tự động, lặp lại được, từ góc nhìn của khách chưa đăng nhập.
 *
 * Số liệu tính tới lúc chạy hook wp_footer (gần cuối trang): các query sau đó (rất ít)
 * không được đếm.
 *
 * @package KioStore
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_footer', 'kio_store_print_metrics', PHP_INT_MAX );

/**
 * ACTION wp_footer: in số query, thời gian tạo trang, bộ nhớ đỉnh.
 *
 * Số query lấy từ get_num_queries() (đếm từ đầu request). Thời gian tính từ biến toàn cục
 * $timestart mà WordPress ghi lúc bắt đầu nạp (wp-settings.php).
 *
 * Không dùng timer_stop(): hàm này trả về chuỗi đã định dạng theo ngôn ngữ site. Site tiếng
 * Việt cho ra "0,142" (dấu phẩy thập phân), ép sang số thành 0.
 */
function kio_store_print_metrics(): void {
	if ( 'local' !== wp_get_environment_type() ) {
		return;
	}

	printf(
		"\n<!-- kio-metrics queries=%d time=%dms memory=%dMB -->\n",
		(int) get_num_queries(),
		(int) round( ( microtime( true ) - $GLOBALS['timestart'] ) * 1000 ),
		(int) round( memory_get_peak_usage() / 1048576 )
	);
}
