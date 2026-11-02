<?php
/**
 * Walker cho mega menu danh mục.
 *
 * Menu do script tạo: 6 danh mục (product_cat) là mục cấp 1, mỗi danh mục có
 * các thương hiệu (product_brand) là mục con. Walker này:
 *   - cấp 0: thêm icon SVG theo slug danh mục + class kio-mega__item;
 *   - cấp 1: class kio-mega__brand cho từng thương hiệu con.
 *
 * @package KioChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Walker đơn giản kế thừa Walker_Nav_Menu để chèn icon và class riêng.
 */
class Kio_Child_Category_Menu_Walker extends Walker_Nav_Menu {

	/**
	 * Mở thẻ <ul> của cấp con. Cấp 0 dùng class kio-mega__sub (bảng thương hiệu).
	 *
	 * @param string   $output HTML tích luỹ.
	 * @param int      $depth  Độ sâu.
	 * @param stdClass $args   Tham số wp_nav_menu.
	 */
	public function start_lvl( &$output, $depth = 0, $args = null ) {
		$class   = 0 === $depth ? 'kio-mega__sub' : 'sub-menu';
		$output .= '<ul class="' . esc_attr( $class ) . '">';
	}

	/**
	 * In một mục menu.
	 *
	 * @param string   $output HTML tích luỹ.
	 * @param WP_Post  $item   Mục menu.
	 * @param int      $depth  Độ sâu.
	 * @param stdClass $args   Tham số wp_nav_menu.
	 * @param int      $id     ID đối tượng.
	 */
	public function start_el( &$output, $item, $depth = 0, $args = null, $id = 0 ) {
		// Thẻ </li> KHÔNG đóng ở đây: Walker_Nav_Menu::end_el() tự đóng sau khi in xong cấp con.
		// Đóng ở đây sẽ thành </li></li> (HTML sai).
		$current = $item->current ? ' aria-current="page"' : '';

		if ( 0 === $depth ) {
			$output .= '<li class="kio-mega__item' . ( $item->current ? ' is-current' : '' ) . '">';
			$output .= '<a class="kio-mega__link" href="' . esc_url( $item->url ) . '"' . $current . '>';
			$output .= $this->category_icon( $item ) . '<span>' . esc_html( $item->title ) . '</span>';
			$output .= '</a>';
			return;
		}

		$output .= '<li class="kio-mega__brand">';
		$output .= '<a href="' . esc_url( $item->url ) . '"' . $current . '>' . esc_html( $item->title ) . '</a>';
	}

	/**
	 * Icon SVG theo slug danh mục. Không có icon thì trả chuỗi rỗng.
	 *
	 * @param WP_Post $item Mục menu.
	 * @return string HTML icon.
	 */
	private function category_icon( $item ): string {
		if ( 'product_cat' !== $item->object ) {
			return '';
		}
		$term = get_term( (int) $item->object_id, 'product_cat' );
		if ( is_wp_error( $term ) || ! $term ) {
			return '';
		}
		return kio_child_icon( $term->slug );
	}
}
