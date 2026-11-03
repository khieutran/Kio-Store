<?php
/**
 * Render khối kio-store/flash-sale (render phía server).
 *
 * WordPress include file này khi cần in khối, sẵn các biến $attributes, $content, $block.
 * Chỉ việc chuyển thuộc tính limit sang hàm dùng chung với shortcode.
 *
 * @package KioStore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$kio_store_limit = isset( $attributes['limit'] ) ? absint( $attributes['limit'] ) : 8;

echo kio_store_flash_sale_render( array( 'limit' => $kio_store_limit ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- đã escape từng phần trong hàm.
