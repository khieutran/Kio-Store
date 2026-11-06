<?php
/**
 * Widget "Khoảng giá": thanh kéo 2 đầu hít vào các mốc giá.
 *
 * Giữ cảm giác kéo của thanh giá nhưng chỉ dừng ở mốc cố định (1, 3, 5, 7, 10, 20 triệu), không ra
 * những con số lẻ. Dùng 2 thẻ input range với step 1 theo vị trí mốc: mốc cách đều dù giá không cách đều,
 * bàn phím và trình đọc màn hình chạy sẵn. Lọc vẫn do Woo làm qua min_price, max_price. Tắt JS thì dùng
 * chip giá phía trên danh sách.
 *
 * @package KioChild
 */

defined( 'ABSPATH' ) || exit;

/**
 * Widget khoảng giá có mốc.
 */
class Kio_Child_Price_Bands_Widget extends WP_Widget {

	/**
	 * Đăng ký widget.
	 */
	public function __construct() {
		parent::__construct(
			'kio_price_bands',
			__( 'Kio: Khoảng giá', 'kio-child' ),
			array( 'classname' => 'widget_kio_price_bands' )
		);
	}

	/**
	 * In widget.
	 *
	 * @param array $args     Khung của sidebar.
	 * @param array $instance Cài đặt: title.
	 * @return void
	 */
	public function widget( $args, $instance ): void {
		if ( ! is_shop() && ! is_product_taxonomy() ) {
			return;
		}

		$stops = kio_child_price_stops();
		if ( count( $stops ) < 2 ) {
			return;
		}

		$last = count( $stops ) - 1;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- chỉ đọc bộ lọc đang áp dụng.
		$from = isset( $_GET['min_price'] ) ? array_search( absint( $_GET['min_price'] ), $stops, true ) : 0;
		$to   = isset( $_GET['max_price'] ) ? array_search( absint( $_GET['max_price'] ), $stops, true ) : $last;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- do theme đăng ký sidebar.
		if ( ! empty( $instance['title'] ) ) {
			echo $args['before_title'] . esc_html( $instance['title'] ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- do theme đăng ký sidebar.
		}
		?>
		<div class="kio-price-range" data-stops="<?php echo esc_attr( (string) wp_json_encode( $stops ) ); ?>" data-url="<?php echo esc_url( kio_child_archive_url( array(), array( 'min_price', 'max_price' ) ) ); ?>">
			<div class="kio-price-range__slider">
				<div class="kio-price-range__track"><div class="kio-price-range__fill"></div></div>
				<input class="kio-price-range__input kio-price-range__input--min" type="range" min="0" max="<?php echo esc_attr( (string) $last ); ?>" step="1" value="<?php echo esc_attr( (string) ( false === $from ? 0 : $from ) ); ?>" aria-label="<?php esc_attr_e( 'Giá thấp nhất', 'kio-child' ); ?>">
				<input class="kio-price-range__input kio-price-range__input--max" type="range" min="0" max="<?php echo esc_attr( (string) $last ); ?>" step="1" value="<?php echo esc_attr( (string) ( false === $to ? $last : $to ) ); ?>" aria-label="<?php esc_attr_e( 'Giá cao nhất', 'kio-child' ); ?>">
			</div>
			<div class="kio-price-range__marks" aria-hidden="true">
				<?php foreach ( $stops as $index => $stop ) : ?>
					<span style="--kio-at: <?php echo esc_attr( (string) round( $index / $last, 4 ) ); ?>"><?php echo esc_html( $this->mark_label( $stops, $index ) ); ?></span>
				<?php endforeach; ?>
			</div>
			<p class="kio-price-range__value" aria-live="polite"></p>
		</div>
		<?php
		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- do theme đăng ký sidebar.
	}

	/**
	 * Chữ dưới mốc, đơn vị triệu. Mốc không giới hạn ghi như 20+.
	 *
	 * @param int[] $stops Các mốc.
	 * @param int   $index Vị trí mốc.
	 * @return string
	 */
	private function mark_label( array $stops, int $index ): string {
		if ( 0 === $stops[ $index ] && $index > 0 ) {
			return ( $stops[ $index - 1 ] / 1000000 ) . '+';
		}

		return (string) ( $stops[ $index ] / 1000000 );
	}
}
