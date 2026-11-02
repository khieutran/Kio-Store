<?php
/**
 * Kio Child — functions.php
 *
 * Child theme của Storefront. Chỉ chứa phần HIỂN THỊ của cửa hàng Kio Mobile:
 * enqueue CSS/JS, dựng lại header/footer bằng hook của theme cha, mega menu.
 *
 * Phần chức năng (nhãn giá, khuyến mãi, flash sale…) nằm trong plugin kio-store.
 *
 * @package KioChild
 */

defined( 'ABSPATH' ) || exit;

define( 'KIO_CHILD_VERSION', '0.1.0' );
define( 'KIO_CHILD_DIR', get_stylesheet_directory() );
define( 'KIO_CHILD_URI', get_stylesheet_directory_uri() );

require_once KIO_CHILD_DIR . '/inc/class-kio-child-category-menu-walker.php';

/**
 * Thiết lập theme: đăng ký vị trí menu, hỗ trợ logo.
 */
function kio_child_setup() {
	load_child_theme_textdomain( 'kio-child', KIO_CHILD_DIR . '/languages' );

	add_theme_support(
		'custom-logo',
		array(
			'height'      => 48,
			'width'       => 200,
			'flex-width'  => true,
			'flex-height' => true,
		)
	);

	// Thêm 3 vị trí menu riêng của child theme (Storefront đã có primary/secondary/handheld).
	register_nav_menus(
		array(
			'kio-categories'     => __( 'Danh mục (mega menu)', 'kio-child' ),
			'kio-footer-policy'  => __( 'Footer: Chính sách', 'kio-child' ),
			'kio-footer-support' => __( 'Footer: Hỗ trợ', 'kio-child' ),
		)
	);
}
add_action( 'after_setup_theme', 'kio_child_setup', 20 );

/**
 * Enqueue CSS: theme cha (Storefront) đã được nạp sẵn với handle "storefront-style".
 * Child theme tự nạp style.css (theme header + CSS variables) rồi main.css (layout).
 * Version = filemtime để trình duyệt không cache khi đang sửa CSS.
 */
function kio_child_enqueue_assets() {
	// Storefront tự nạp child style.css với version theo version theme; ta nạp lại
	// với version = thời gian sửa file để khi sửa CSS không phải hard-refresh.
	wp_dequeue_style( 'storefront-child-style' );
	wp_enqueue_style(
		'kio-child-style',
		get_stylesheet_uri(),
		array( 'storefront-style' ),
		(string) filemtime( KIO_CHILD_DIR . '/style.css' )
	);

	$main_css = KIO_CHILD_DIR . '/assets/css/main.css';
	wp_enqueue_style(
		'kio-child-main',
		KIO_CHILD_URI . '/assets/css/main.css',
		array( 'kio-child-style' ),
		(string) filemtime( $main_css )
	);

	$main_js = KIO_CHILD_DIR . '/assets/js/main.js';
	wp_enqueue_script(
		'kio-child-main',
		KIO_CHILD_URI . '/assets/js/main.js',
		array(),
		(string) filemtime( $main_js ),
		true
	);
}
add_action( 'wp_enqueue_scripts', 'kio_child_enqueue_assets', 40 );

/**
 * Header: gỡ toàn bộ thành phần mặc định của Storefront, thay bằng header tự dựng.
 *
 * Storefront dựng header bằng các hook trong storefront_header (xem
 * inc/storefront-template-hooks.php). Thay vì copy cả header.php, ta chỉ gỡ từng
 * action rồi nối layout riêng vào chính hook đó.
 */
function kio_child_replace_header() {
	remove_action( 'storefront_header', 'storefront_header_container', 0 );
	remove_action( 'storefront_header', 'storefront_skip_links', 5 );
	remove_action( 'storefront_header', 'storefront_site_branding', 20 );
	remove_action( 'storefront_header', 'storefront_secondary_navigation', 30 );
	remove_action( 'storefront_header', 'storefront_header_container_close', 41 );
	remove_action( 'storefront_header', 'storefront_primary_navigation_wrapper', 42 );
	remove_action( 'storefront_header', 'storefront_primary_navigation', 50 );
	remove_action( 'storefront_header', 'storefront_primary_navigation_wrapper_close', 68 );

	// Hai action này do Storefront đăng ký ở inc/woocommerce/storefront-woocommerce-template-hooks.php.
	remove_action( 'storefront_header', 'storefront_product_search', 40 );
	remove_action( 'storefront_header', 'storefront_header_cart', 60 );

	add_action( 'storefront_before_header', 'kio_child_skip_link', 5 );
	add_action( 'storefront_before_header', 'kio_child_utility_bar', 10 );
	add_action( 'storefront_header', 'kio_child_header', 10 );
}
add_action( 'init', 'kio_child_replace_header' );

/**
 * Link "Bỏ qua đến nội dung" cho người dùng bàn phím và trình đọc màn hình.
 *
 * Skip link của Storefront (storefront_skip_links) đã bị gỡ cùng header gốc và trỏ tới
 * #site-navigation, phần tử không còn tồn tại. Link này trỏ thẳng tới #content
 * (khung nội dung chính của Storefront), ẩn đi cho tới khi được focus bằng phím Tab.
 */
function kio_child_skip_link() {
	printf(
		'<a class="skip-link screen-reader-text" href="#content">%s</a>',
		esc_html__( 'Bỏ qua đến nội dung', 'kio-child' )
	);
}

/**
 * Footer: gỡ widget + credit của Storefront, thay bằng footer tự dựng.
 */
function kio_child_replace_footer() {
	remove_action( 'storefront_footer', 'storefront_footer_widgets', 10 );
	remove_action( 'storefront_footer', 'storefront_credit', 20 );
	add_action( 'storefront_footer', 'kio_child_footer', 10 );
}
add_action( 'init', 'kio_child_replace_footer' );

/**
 * Thanh tiện ích trên cùng header.
 * Các mục cố định nằm ở đây; hotline đọc từ theme mod để chủ shop sửa được trong Customizer.
 */
function kio_child_utility_bar() {
	$hotline = get_theme_mod( 'kio_hotline', '1800 6868' );
	$items   = array(
		__( 'Hàng chính hãng', 'kio-child' ),
		__( 'Miễn phí giao hàng từ 300k', 'kio-child' ),
		__( 'Thu cũ đổi mới', 'kio-child' ),
		/* translators: %s: số hotline. */
		sprintf( __( 'Hotline %s', 'kio-child' ), $hotline ),
	);
	?>
	<div class="kio-utility">
		<div class="kio-container">
			<ul class="kio-utility__list">
				<?php foreach ( $items as $item ) : ?>
					<li class="kio-utility__item"><?php echo esc_html( $item ); ?></li>
				<?php endforeach; ?>
			</ul>
			<span class="kio-utility__tagline"><?php esc_html_e( 'Website demo — dữ liệu không có thật', 'kio-child' ); ?></span>
		</div>
	</div>
	<?php
}

/**
 * Toàn bộ header chính: logo, ô tìm kiếm, tài khoản, tra cứu đơn, giỏ hàng, mega menu.
 */
function kio_child_header() {
	// Header dùng hàm của WooCommerce: tắt Woo thì in header rút gọn thay vì lỗi fatal.
	$has_woo      = function_exists( 'WC' );
	$account_url  = $has_woo ? wc_get_page_permalink( 'myaccount' ) : '';
	$tracking_id  = (int) get_option( 'kio_tracking_page_id' );
	$tracking_url = $tracking_id ? get_permalink( $tracking_id ) : ''; // 0 → get_permalink() trả link trang hiện tại, nên phải kiểm tra.
	?>
	<div class="kio-header">
		<div class="kio-container kio-header__main">
			<a class="kio-header__logo" href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home">
				<?php kio_child_logo_svg(); ?>
				<span class="screen-reader-text"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></span>
			</a>

			<div class="kio-header__search">
				<?php echo kio_child_product_search_form(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- form đã được escape bên trong. ?>
			</div>

			<?php if ( $has_woo ) : ?>
			<div class="kio-header__actions">
				<a class="kio-action" href="<?php echo esc_url( $account_url ); ?>">
					<?php echo kio_child_icon( 'account' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG đã lọc qua wp_kses. ?>
					<span><?php esc_html_e( 'Tài khoản', 'kio-child' ); ?></span>
				</a>
				<?php if ( $tracking_url ) : ?>
				<a class="kio-action" href="<?php echo esc_url( $tracking_url ); ?>">
					<?php echo kio_child_icon( 'truck' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG đã lọc qua wp_kses. ?>
					<span><?php esc_html_e( 'Tra cứu đơn', 'kio-child' ); ?></span>
				</a>
				<?php endif; ?>
				<a class="kio-action kio-action--cart" href="<?php echo esc_url( wc_get_cart_url() ); ?>">
					<span class="kio-action__icon">
						<?php echo kio_child_icon( 'cart' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG đã lọc qua wp_kses. ?>
						<?php echo kio_child_cart_count_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- đã escape bên trong hàm. ?>
					</span>
					<span><?php esc_html_e( 'Giỏ hàng', 'kio-child' ); ?></span>
				</a>
			</div>
			<?php endif; ?>
		</div>

		<nav class="kio-nav" aria-label="<?php esc_attr_e( 'Danh mục sản phẩm', 'kio-child' ); ?>">
			<div class="kio-container">
				<?php kio_child_category_menu(); ?>
			</div>
		</nav>
	</div>
	<?php
}

/**
 * Số lượng sản phẩm trong giỏ (huy hiệu trên icon giỏ hàng ở header).
 *
 * Dùng chung cho lần in đầu tiên (kio_child_header) và cho "cart fragment" bên dưới,
 * để hai nơi luôn ra cùng một markup.
 *
 * @return string HTML đã escape.
 */
function kio_child_cart_count_html(): string {
	$count = ( function_exists( 'WC' ) && WC()->cart ) ? WC()->cart->get_cart_contents_count() : 0;
	return sprintf(
		'<em class="kio-cart-count" data-count="%1$s">%1$s</em>',
		esc_html( (string) $count )
	);
}

/**
 * FILTER woocommerce_add_to_cart_fragments: cập nhật số giỏ hàng ở header sau khi thêm bằng AJAX.
 *
 * Khi khách bấm "Thêm vào giỏ" ở trang danh mục, Woo gửi AJAX (không tải lại trang) và trả về
 * các "fragment": cặp CSS selector → HTML mới, rồi JS của Woo thay từng phần tử khớp selector.
 * Storefront chỉ đăng ký fragment cho phần tử giỏ hàng của nó (a.cart-contents) mà header này
 * đã gỡ, nên phải thêm fragment cho .kio-cart-count, nếu không con số đứng yên tới khi tải lại.
 *
 * @param array $fragments Selector → HTML.
 * @return array
 */
function kio_child_cart_count_fragment( array $fragments ): array {
	$fragments['em.kio-cart-count'] = kio_child_cart_count_html();
	return $fragments;
}
add_filter( 'woocommerce_add_to_cart_fragments', 'kio_child_cart_count_fragment' );

/**
 * Customizer: ô "Số hotline" trong Giao diện → Tuỳ biến → Kio Mobile.
 *
 * Giá trị lưu ở theme mod "kio_hotline" (option theme_mods_kio-child), được thanh tiện ích
 * và footer đọc ra. sanitize_callback làm sạch dữ liệu trước khi lưu.
 *
 * @param WP_Customize_Manager $wp_customize Đối tượng Customizer.
 */
function kio_child_customize_register( WP_Customize_Manager $wp_customize ) {
	$wp_customize->add_section(
		'kio_child_store',
		array(
			'title'    => __( 'Kio Mobile', 'kio-child' ),
			'priority' => 30,
		)
	);
	$wp_customize->add_setting(
		'kio_hotline',
		array(
			'default'           => '1800 6868',
			'sanitize_callback' => 'sanitize_text_field',
		)
	);
	$wp_customize->add_control(
		'kio_hotline',
		array(
			'label'   => __( 'Số hotline', 'kio-child' ),
			'section' => 'kio_child_store',
			'type'    => 'text',
		)
	);
}
add_action( 'customize_register', 'kio_child_customize_register' );

/**
 * Nút "Bộ lọc" (chỉ hiện trên mobile) ở đầu danh sách sản phẩm.
 *
 * Trên mobile Storefront xếp sidebar (chứa bộ lọc) xuống dưới cùng danh sách sản phẩm, khách phải
 * cuộn hết mới thấy. Nút này mở sidebar thành ngăn kéo trượt từ phải (CSS + assets/js/main.js).
 * Chỉ in khi trang là danh sách sản phẩm và sidebar có widget.
 */
function kio_child_filter_toggle() {
	if ( ! is_active_sidebar( 'sidebar-1' ) ) {
		return;
	}
	printf(
		'<button type="button" class="kio-filter-toggle" aria-controls="secondary" aria-expanded="false">%s<span>%s</span></button>',
		kio_child_icon( 'grid' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG đã lọc qua wp_kses.
		esc_html__( 'Bộ lọc', 'kio-child' )
	);
}
add_action( 'woocommerce_before_shop_loop', 'kio_child_filter_toggle', 25 );

/**
 * Nút "Danh mục" + mega menu. Menu do script tạo, lưu ở vị trí kio-categories.
 */
function kio_child_category_menu() {
	if ( ! has_nav_menu( 'kio-categories' ) ) {
		return;
	}
	?>
	<button class="kio-nav__toggle" aria-controls="kio-category-menu" aria-expanded="false">
		<?php echo kio_child_icon( 'grid' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG đã lọc qua wp_kses. ?>
		<span><?php esc_html_e( 'Danh mục', 'kio-child' ); ?></span>
	</button>
	<div id="kio-category-menu" class="kio-mega">
		<?php
		wp_nav_menu(
			array(
				'theme_location' => 'kio-categories',
				'container'      => false,
				'menu_class'     => 'kio-mega__list',
				'walker'         => new Kio_Child_Category_Menu_Walker(),
				'fallback_cb'    => false,
			)
		);
		?>
	</div>
	<?php
}

/**
 * Form tìm kiếm sản phẩm của WooCommerce (post_type=product).
 *
 * @return string HTML form đã được escape.
 */
function kio_child_product_search_form() {
	if ( ! function_exists( 'get_product_search_form' ) ) {
		return '';
	}
	return get_product_search_form( false );
}

/**
 * Footer: cột công ty, chính sách, hỗ trợ, hotline + thanh toán.
 */
function kio_child_footer() {
	$hotline = get_theme_mod( 'kio_hotline', '1800 6868' );
	?>
	<div class="kio-footer">
		<div class="kio-container kio-footer__grid">
			<div class="kio-footer__col kio-footer__about">
				<?php kio_child_logo_svg(); ?>
				<p><?php esc_html_e( 'Cửa hàng điện thoại, máy tính bảng và đồ điện tử (website demo phục vụ học tập — mọi thông tin là giả định).', 'kio-child' ); ?></p>
				<ul class="kio-social">
					<?php
					foreach ( array( 'facebook', 'youtube', 'tiktok' ) as $network ) {
						printf(
							'<li><a href="#" class="kio-social__link">%s<span class="screen-reader-text">%s</span></a></li>',
							kio_child_icon( $network ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- icon là SVG tự vẽ, đã qua wp_kses trong kio_child_icon().
							esc_html( $network )
						);
					}
					?>
				</ul>
			</div>

			<div class="kio-footer__col">
				<h2 class="kio-footer__title"><?php esc_html_e( 'Chính sách', 'kio-child' ); ?></h2>
				<?php
				wp_nav_menu(
					array(
						'theme_location' => 'kio-footer-policy',
						'container'      => false,
						'menu_class'     => 'kio-footer__menu',
						'fallback_cb'    => false,
					)
				);
				?>
			</div>

			<div class="kio-footer__col">
				<h2 class="kio-footer__title"><?php esc_html_e( 'Hỗ trợ', 'kio-child' ); ?></h2>
				<?php
				wp_nav_menu(
					array(
						'theme_location' => 'kio-footer-support',
						'container'      => false,
						'menu_class'     => 'kio-footer__menu',
						'fallback_cb'    => false,
					)
				);
				?>
			</div>

			<div class="kio-footer__col">
				<h2 class="kio-footer__title"><?php esc_html_e( 'Tổng đài', 'kio-child' ); ?></h2>
				<p class="kio-footer__hotline"><?php echo esc_html( $hotline ); ?></p>
				<p class="kio-footer__hours"><?php esc_html_e( '8:00 – 21:30, kể cả thứ 7 & CN', 'kio-child' ); ?></p>
				<h2 class="kio-footer__title"><?php esc_html_e( 'Thanh toán', 'kio-child' ); ?></h2>
				<ul class="kio-payments">
					<li class="kio-payments__item">COD</li>
					<li class="kio-payments__item"><?php esc_html_e( 'Chuyển khoản', 'kio-child' ); ?></li>
				</ul>
			</div>
		</div>

		<div class="kio-footer__bottom">
			<div class="kio-container">
				<?php
				printf(
					/* translators: 1: tên công ty, 2: năm. */
					esc_html__( '© %1$s %2$s — Công ty TNHH Kio Mobile (DEMO). Giấy phép kinh doanh giả định.', 'kio-child' ),
					esc_html( get_bloginfo( 'name' ) ),
					esc_html( gmdate( 'Y' ) )
				);
				?>
			</div>
		</div>
	</div>
	<?php
}

/**
 * Logo SVG "Kio Mobile" tự vẽ, không phụ thuộc Customizer.
 */
function kio_child_logo_svg() {
	?>
	<svg class="kio-logo" width="150" height="40" viewBox="0 0 150 40" role="img" aria-label="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
		<rect width="150" height="40" rx="8" fill="var(--kio-brand)"></rect>
		<text x="12" y="27" font-family="Be Vietnam Pro, sans-serif" font-size="20" font-weight="700" fill="#ffffff">Kio <tspan fill="#ff6a00">Mobile</tspan></text>
	</svg>
	<?php
}

/**
 * Đọc file SVG trong assets/icons/ và trả về markup an toàn.
 *
 * @param string $name Tên file (không có .svg).
 * @return string SVG đã lọc qua wp_kses; chuỗi rỗng nếu không có file.
 */
function kio_child_icon( string $name ): string {
	$file = KIO_CHILD_DIR . '/assets/icons/' . $name . '.svg';
	if ( ! is_file( $file ) ) {
		return '';
	}

	$allowed = array(
		'svg'      => array(
			'class'        => true,
			'width'        => true,
			'height'       => true,
			'viewbox'      => true,
			'fill'         => true,
			'stroke'       => true,
			'stroke-width' => true,
			'role'         => true,
			'aria-hidden'  => true,
		),
		'path'     => array(
			'd'               => true,
			'fill'            => true,
			'stroke'          => true,
			'stroke-width'    => true,
			'stroke-linecap'  => true,
			'stroke-linejoin' => true,
		),
		'circle'   => array(
			'cx'           => true,
			'cy'           => true,
			'r'            => true,
			'fill'         => true,
			'stroke'       => true,
			'stroke-width' => true,
		),
		'rect'     => array(
			'x'      => true,
			'y'      => true,
			'width'  => true,
			'height' => true,
			'rx'     => true,
			'fill'   => true,
		),
		'g'        => array(
			'fill'         => true,
			'stroke'       => true,
			'stroke-width' => true,
			'transform'    => true,
		),
		'line'     => array(
			'x1'             => true,
			'y1'             => true,
			'x2'             => true,
			'y2'             => true,
			'stroke'         => true,
			'stroke-width'   => true,
			'stroke-linecap' => true,
		),
		'polyline' => array(
			'points'          => true,
			'fill'            => true,
			'stroke'          => true,
			'stroke-width'    => true,
			'stroke-linecap'  => true,
			'stroke-linejoin' => true,
		),
	);

	$svg = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- đọc file SVG tự vẽ, cần nội dung thô.
	return wp_kses( $svg, $allowed );
}
