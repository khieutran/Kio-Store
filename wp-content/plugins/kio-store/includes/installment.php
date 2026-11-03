<?php
/**
 * F2 — Dòng "Trả góp 0% chỉ từ X đ/tháng" + trang cài đặt trong wp-admin.
 *
 * Tính tiền trả góp từ giá sản phẩm: X = giá ÷ số kỳ, làm tròn LÊN nghìn đồng.
 * Sản phẩm biến thể lấy giá thấp nhất ("chỉ từ"); trên trang sản phẩm số tiền đổi
 * theo biến thể đang chọn nhờ JS lắng nghe sự kiện found_variation của WooCommerce.
 *
 * Cấu hình lưu ở một option dạng mảng `kio_installment` (Options API), sửa qua trang
 * "WooCommerce → Kio: Trả góp" dựng bằng Settings API:
 *   - enabled:   bật/tắt dòng trả góp.
 *   - months:    số kỳ (6 / 9 / 12).
 *   - min_price: giá tối thiểu để hiện trả góp (mặc định 3.000.000 ₫).
 *
 * @package KioStore
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'kio_store_installment_admin_menu' );
add_action( 'admin_init', 'kio_store_installment_admin_init' );
add_action( 'wp_enqueue_scripts', 'kio_store_installment_enqueue_script' );
add_action( 'woocommerce_after_shop_loop_item_title', 'kio_store_render_loop_installment', 15 );
add_action( 'woocommerce_single_product_summary', 'kio_store_render_single_installment', 15 );
add_filter( 'option_page_capability_kio_installment', 'kio_store_installment_capability' );

/**
 * FILTER option_page_capability_{nhóm}: quyền cần có để LƯU cài đặt qua options.php.
 *
 * Mặc định options.php đòi manage_options (chỉ Quản trị viên). Trang này hiện cho ai có
 * manage_woocommerce (cả Quản lý cửa hàng), nên quyền lưu phải khớp, nếu không Quản lý cửa
 * hàng thấy trang nhưng bấm Lưu thì bị "Bạn không có quyền".
 *
 * @return string Capability.
 */
function kio_store_installment_capability(): string {
	return 'manage_woocommerce';
}

/**
 * Giá trị mặc định của cấu hình trả góp.
 *
 * @return array
 */
function kio_store_installment_defaults(): array {
	return array(
		'enabled'   => 1,
		'months'    => 6,
		'min_price' => 3000000,
	);
}

/**
 * Đọc option `kio_installment`, gộp với giá trị mặc định cho đủ khoá.
 *
 * @return array
 */
function kio_store_get_installment(): array {
	$option = get_option( 'kio_installment', array() );

	return wp_parse_args( is_array( $option ) ? $option : array(), kio_store_installment_defaults() );
}

/**
 * Sanitize dữ liệu từ form cài đặt trước khi lưu option `kio_installment`.
 *
 * Mỗi khoá xử lý riêng: enabled chỉ nhận 0/1, months phải nằm trong 6/9/12,
 * min_price ép thành số nguyên không âm. Khoá lạ bị bỏ đi.
 *
 * @param mixed $input Giá trị người dùng gửi lên.
 * @return array
 */
function kio_store_sanitize_installment( $input ): array {
	$defaults = kio_store_installment_defaults();
	$input    = is_array( $input ) ? $input : array();

	return array(
		'enabled'   => empty( $input['enabled'] ) ? 0 : 1,
		'months'    => in_array( (int) ( $input['months'] ?? 0 ), array( 6, 9, 12 ), true ) ? (int) $input['months'] : $defaults['months'],
		'min_price' => isset( $input['min_price'] ) ? max( 0, (int) $input['min_price'] ) : $defaults['min_price'],
	);
}

/**
 * Đăng ký trang con "Kio: Trả góp" trong menu WooCommerce.
 *
 * @return void
 */
function kio_store_installment_admin_menu(): void {
	add_submenu_page(
		'woocommerce',
		__( 'Kio: Trả góp', 'kio-store' ),
		__( 'Kio: Trả góp', 'kio-store' ),
		'manage_woocommerce',
		'kio-installment',
		'kio_store_installment_settings_page'
	);
}

/**
 * Đăng ký setting + các trường với Settings API.
 *
 * Settings API tự lo phần nonce, quyền và lưu option (options.php): ta chỉ khai báo
 * option, section và từng field, rồi ở trang cài đặt gọi settings_fields() +
 * do_settings_sections() để in form.
 *
 * @return void
 */
function kio_store_installment_admin_init(): void {
	register_setting(
		'kio_installment',
		'kio_installment',
		array(
			'type'              => 'array',
			'sanitize_callback' => 'kio_store_sanitize_installment',
		)
	);

	add_settings_section(
		'kio_installment_section',
		'',
		'__return_false',
		'kio-installment'
	);

	add_settings_field(
		'kio_installment_enabled',
		__( 'Bật tính năng', 'kio-store' ),
		'kio_store_installment_field_enabled',
		'kio-installment',
		'kio_installment_section'
	);

	add_settings_field(
		'kio_installment_months',
		__( 'Số kỳ trả góp', 'kio-store' ),
		'kio_store_installment_field_months',
		'kio-installment',
		'kio_installment_section'
	);

	add_settings_field(
		'kio_installment_min_price',
		__( 'Giá tối thiểu', 'kio-store' ),
		'kio_store_installment_field_min_price',
		'kio-installment',
		'kio_installment_section'
	);
}

/**
 * In trang cài đặt trả góp.
 *
 * @return void
 */
function kio_store_installment_settings_page(): void {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	?>
	<div class="wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<form method="post" action="options.php">
			<?php
			settings_fields( 'kio_installment' );
			do_settings_sections( 'kio-installment' );
			submit_button();
			?>
		</form>
	</div>
	<?php
}

/**
 * Trường "Bật tính năng" (checkbox).
 *
 * @return void
 */
function kio_store_installment_field_enabled(): void {
	$options = kio_store_get_installment();
	printf(
		'<label><input type="checkbox" name="kio_installment[enabled]" value="1" %s /> %s</label>',
		checked( 1, $options['enabled'], false ),
		esc_html__( 'Hiện dòng trả góp trên thẻ và trang sản phẩm', 'kio-store' )
	);
}

/**
 * Trường "Số kỳ trả góp" (select 6/9/12).
 *
 * @return void
 */
function kio_store_installment_field_months(): void {
	$options = kio_store_get_installment();
	echo '<select name="kio_installment[months]">';
	foreach ( array( 6, 9, 12 ) as $months ) {
		printf(
			'<option value="%1$d" %2$s>%3$s</option>',
			(int) $months,
			selected( $options['months'], $months, false ),
			/* translators: %d: số kỳ (tháng). */
			esc_html( sprintf( __( '%d tháng', 'kio-store' ), $months ) )
		);
	}
	echo '</select>';
}

/**
 * Trường "Giá tối thiểu" (số nguyên, bước 1000 ₫).
 *
 * @return void
 */
function kio_store_installment_field_min_price(): void {
	$options = kio_store_get_installment();
	printf(
		'<input type="number" name="kio_installment[min_price]" value="%1$d" min="0" step="1000" /> %2$s',
		(int) $options['min_price'],
		esc_html__( '₫ — sản phẩm dưới mức này không hiện trả góp', 'kio-store' )
	);
}

/**
 * Số tiền trả góp mỗi tháng, hoặc null nếu không áp dụng.
 *
 * @param WC_Product $product Sản phẩm cần tính.
 * @return int|null Số tiền đã làm tròn LÊN nghìn đồng.
 */
function kio_store_installment_amount( WC_Product $product ): ?int {
	$options = kio_store_get_installment();

	if ( ! $options['enabled'] ) {
		return null;
	}

	$price = (float) $product->get_price();
	if ( $price < (float) $options['min_price'] ) {
		return null;
	}

	return (int) ceil( $price / (int) $options['months'] / 1000 ) * 1000;
}

/**
 * HTML dòng trả góp của một sản phẩm; chuỗi rỗng nếu không hiện.
 *
 * @param WC_Product $product Sản phẩm cần in dòng trả góp.
 * @return string HTML đã escape.
 */
function kio_store_installment_html( WC_Product $product ): string {
	$options = kio_store_get_installment();
	$amount  = kio_store_installment_amount( $product );

	if ( null === $amount ) {
		return '';
	}

	return sprintf(
		'<div class="kio-installment" data-months="%1$d" data-min-price="%2$d">%3$s <span class="kio-installment__amount">%4$s</span>%5$s</div>',
		(int) $options['months'],
		(int) $options['min_price'],
		esc_html__( 'Trả góp 0% chỉ từ', 'kio-store' ),
		esc_html( number_format( $amount, 0, ',', '.' ) . ' ₫' ),
		esc_html__( '/tháng', 'kio-store' )
	);
}

/**
 * ACTION woocommerce_after_shop_loop_item_title: in dòng trả góp trên thẻ sản phẩm.
 *
 * @return void
 */
function kio_store_render_loop_installment(): void {
	global $product;

	if ( ! $product instanceof WC_Product ) {
		return;
	}

	echo kio_store_installment_html( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- đã escape từng phần trong hàm.
}

/**
 * ACTION woocommerce_single_product_summary: in dòng trả góp trên trang sản phẩm.
 *
 * @return void
 */
function kio_store_render_single_installment(): void {
	global $product;

	if ( ! $product instanceof WC_Product ) {
		return;
	}

	echo kio_store_installment_html( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- đã escape từng phần trong hàm.
}

/**
 * Enqueue JS đếm tiền theo biến thể, chỉ trên trang sản phẩm (is_product).
 *
 * JS đọc data-months / data-min-price từ phần tử .kio-installment mà PHP in ra,
 * nên không cần truyền cấu hình qua wp_localize_script.
 *
 * @return void
 */
function kio_store_installment_enqueue_script(): void {
	if ( ! is_product() ) {
		return;
	}

	wp_enqueue_script(
		'kio-store-installment',
		KIO_STORE_URL . 'assets/js/installment.js',
		array( 'jquery' ),
		KIO_STORE_VERSION,
		true
	);
}
