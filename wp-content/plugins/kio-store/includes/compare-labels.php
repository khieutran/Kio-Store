<?php
/**
 * Chữ tiếng Việt cho plugin YITH Compare.
 *
 * WordPress.org chưa có bản dịch tiếng Việt của plugin này. Mọi chuỗi đi qua __() đều chạy filter
 * gettext_<text domain>, nên dịch ở đây mà không sửa file của plugin.
 *
 * @package KioStore
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'gettext_yith-woocommerce-compare', 'kio_store_compare_label', 10, 2 );
add_filter( 'gettext_with_context_yith-woocommerce-compare', 'kio_store_compare_label', 10, 2 );

/**
 * FILTER gettext: thay chuỗi tiếng Anh của plugin so sánh.
 *
 * @param string $translation Bản dịch hiện tại.
 * @param string $text        Chuỗi gốc trong code plugin.
 * @return string
 */
function kio_store_compare_label( string $translation, string $text ): string {
	$labels = array(
		'Add to cart'                                => 'Thêm vào giỏ hàng',
		'Added to compare'                           => 'Đã thêm so sánh',
		'Availability'                               => 'Tình trạng',
		'Close'                                      => 'Đóng',
		'Compare'                                    => 'So sánh',
		'Compare products'                           => 'So sánh sản phẩm',
		'Description'                                => 'Mô tả',
		'Dimensions'                                 => 'Kích thước',
		'Image'                                      => 'Hình ảnh',
		'In stock'                                   => 'Còn hàng',
		'No products added in the comparison table.' => 'Chưa có sản phẩm nào để so sánh.',
		'No products to compare'                     => 'Chưa có sản phẩm để so sánh',
		'Price'                                      => 'Giá',
		'Product Comparison'                         => 'So sánh sản phẩm',
		'Rating'                                     => 'Đánh giá',
		'Remove'                                     => 'Xoá',
		'Repeat Add to cart'                         => 'Thêm vào giỏ hàng',
		'Repeat price'                               => 'Giá',
		'SKU'                                        => 'Mã sản phẩm',
		'Select at least 2 products<br/>to compare'  => 'Chọn ít nhất 2 sản phẩm<br/>để so sánh',
		'Title'                                      => 'Tên sản phẩm',
		'View comparison'                            => 'Xem so sánh',
		'Weight'                                     => 'Khối lượng',
	);

	return $labels[ $text ] ?? $translation;
}
