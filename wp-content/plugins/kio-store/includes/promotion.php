<?php
/**
 * F4 — Khối "Khuyến mãi" quản lý trong wp-admin (Custom Post Type).
 *
 * Tạo CPT `kio_promotion` (không public, chỉ có giao diện admin) để quản lý các
 * chương trình khuyến mãi. Mỗi khuyến mãi = tiêu đề + nội dung ngắn, kèm meta:
 *   - _kio_promotion_products   : mảng ID sản phẩm áp dụng.
 *   - _kio_promotion_categories : mảng ID danh mục áp dụng.
 *   - _kio_promotion_start      : ngày bắt đầu (Y-m-d), rỗng = không giới hạn.
 *   - _kio_promotion_end        : ngày kết thúc (Y-m-d), rỗng = không giới hạn.
 *   - _kio_promotion_order      : thứ tự hiển thị (nhỏ hiện trước).
 *
 * Trang sản phẩm in khối "Khuyến mãi" đánh số, chỉ gồm các khuyến mãi đang hiệu
 * lực và áp dụng cho sản phẩm đó; quá 3 mục thì ẩn bớt + nút "Xem thêm".
 *
 * Bảo mật là trọng tâm của tính năng này:
 *   - nonce trong meta box, kiểm tra current_user_can('edit_post') khi lưu,
 *   - bỏ qua autosave/revision,
 *   - sanitize từng trường (absint cho ID, chuẩn hoá ngày Y-m-d),
 *   - escape khi in (esc_html/esc_attr).
 *
 * @package KioStore
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'kio_store_register_promotion_cpt' );
add_action( 'add_meta_boxes', 'kio_store_promotion_add_meta_box' );
add_action( 'save_post_kio_promotion', 'kio_store_promotion_save', 10, 1 );
add_action( 'woocommerce_single_product_summary', 'kio_store_render_promotions', 25 );
add_action( 'wp_enqueue_scripts', 'kio_store_promotions_enqueue_script' );

/**
 * Đăng ký Custom Post Type `kio_promotion`.
 *
 * Hàm register_post_type() phải chạy ở hook `init`: đây là lúc WordPress đã nạp xong plugin
 * và theme, mọi post type/taxonomy đăng ký trễ hơn sẽ không kịp cho query, admin menu…
 * `public => false` nên không có trang riêng ngoài site, không sinh rewrite rule →
 * KHÔNG cần flush rewrite khi kích hoạt (khác với CPT public, vốn cần flush vì rewrite
 * rule bị cache trong DB, xem Ghi chú).
 *
 * @return void
 */
function kio_store_register_promotion_cpt(): void {
	register_post_type(
		'kio_promotion',
		array(
			'labels'        => array(
				'name'          => __( 'Khuyến mãi', 'kio-store' ),
				'singular_name' => __( 'Khuyến mãi', 'kio-store' ),
				'add_new_item'  => __( 'Thêm khuyến mãi mới', 'kio-store' ),
				'edit_item'     => __( 'Sửa khuyến mãi', 'kio-store' ),
				'search_items'  => __( 'Tìm khuyến mãi', 'kio-store' ),
			),
			'public'        => false,
			'show_ui'       => true,
			'show_in_menu'  => true,
			'menu_icon'     => 'dashicons-megaphone',
			'menu_position' => 56,
			'supports'      => array( 'title', 'editor' ),
			'rewrite'       => false,
		)
	);
}

/**
 * Đăng ký meta box cho màn hình sửa khuyến mãi.
 *
 * @return void
 */
function kio_store_promotion_add_meta_box(): void {
	add_meta_box(
		'kio_promotion_meta',
		__( 'Áp dụng & thời hạn', 'kio-store' ),
		'kio_store_promotion_meta_box',
		'kio_promotion',
		'normal',
		'high'
	);
}

/**
 * In nội dung meta box.
 *
 * @param WP_Post $post Khuyến mãi đang sửa.
 * @return void
 */
function kio_store_promotion_meta_box( WP_Post $post ): void {
	wp_nonce_field( 'kio_promotion_save', 'kio_promotion_nonce' );

	$products   = kio_store_promotion_meta_products( $post->ID );
	$categories = kio_store_promotion_meta_categories( $post->ID );
	$start      = (string) get_post_meta( $post->ID, '_kio_promotion_start', true );
	$end        = (string) get_post_meta( $post->ID, '_kio_promotion_end', true );
	$order      = (int) get_post_meta( $post->ID, '_kio_promotion_order', true );

	$all_products = wc_get_products(
		array(
			'limit'   => -1,
			'status'  => 'publish',
			'orderby' => 'title',
			'order'   => 'ASC',
		)
	);
	?>
	<p>
		<label for="kio_promotion_products"><strong><?php esc_html_e( 'Áp dụng cho sản phẩm', 'kio-store' ); ?></strong></label><br>
		<select id="kio_promotion_products" name="kio_promotion_products[]" multiple size="8" style="width:100%;max-width:480px;">
			<?php foreach ( $all_products as $product ) : ?>
				<option value="<?php echo esc_attr( $product->get_id() ); ?>" <?php selected( in_array( $product->get_id(), $products, true ), true ); ?>>
					<?php echo esc_html( $product->get_name() ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<span class="description"><?php esc_html_e( 'Giữ Ctrl/Cmd để chọn nhiều. Bỏ trống để áp dụng theo danh mục hoặc toàn bộ.', 'kio-store' ); ?></span>
	</p>

	<p>
		<strong><?php esc_html_e( 'Hoặc áp dụng cho danh mục', 'kio-store' ); ?></strong><br>
		<?php
		$all_categories = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);
		foreach ( $all_categories as $category ) :
			?>
			<label style="margin-right:12px;display:inline-block;">
				<input type="checkbox" name="kio_promotion_categories[]" value="<?php echo esc_attr( $category->term_id ); ?>" <?php checked( in_array( (int) $category->term_id, $categories, true ), true ); ?> />
				<?php echo esc_html( $category->name ); ?>
			</label>
		<?php endforeach; ?>
	</p>

	<p>
		<label for="kio_promotion_start"><strong><?php esc_html_e( 'Ngày bắt đầu', 'kio-store' ); ?></strong></label><br>
		<input type="date" id="kio_promotion_start" name="kio_promotion_start" value="<?php echo esc_attr( $start ); ?>" />
		<span class="description"><?php esc_html_e( 'Bỏ trống = không giới hạn', 'kio-store' ); ?></span>
	</p>

	<p>
		<label for="kio_promotion_end"><strong><?php esc_html_e( 'Ngày kết thúc', 'kio-store' ); ?></strong></label><br>
		<input type="date" id="kio_promotion_end" name="kio_promotion_end" value="<?php echo esc_attr( $end ); ?>" />
		<span class="description"><?php esc_html_e( 'Bỏ trống = không giới hạn', 'kio-store' ); ?></span>
	</p>

	<p>
		<label for="kio_promotion_order"><strong><?php esc_html_e( 'Thứ tự hiển thị', 'kio-store' ); ?></strong></label><br>
		<input type="number" id="kio_promotion_order" name="kio_promotion_order" value="<?php echo esc_attr( $order ); ?>" min="0" step="1" style="width:90px;" />
		<span class="description"><?php esc_html_e( 'Số nhỏ hiện trước', 'kio-store' ); ?></span>
	</p>
	<?php
}

/**
 * Đọc meta sản phẩm áp dụng, chuẩn hoá thành mảng int.
 *
 * @param int $post_id ID khuyến mãi.
 * @return array
 */
function kio_store_promotion_meta_products( int $post_id ): array {
	$value = get_post_meta( $post_id, '_kio_promotion_products', true );

	return array_map( 'intval', is_array( $value ) ? $value : array() );
}

/**
 * Đọc meta danh mục áp dụng, chuẩn hoá thành mảng int.
 *
 * @param int $post_id ID khuyến mãi.
 * @return array
 */
function kio_store_promotion_meta_categories( int $post_id ): array {
	$value = get_post_meta( $post_id, '_kio_promotion_categories', true );

	return array_map( 'intval', is_array( $value ) ? $value : array() );
}

/**
 * Chuẩn hoá ngày về định dạng Y-m-d; giá trị sai thì trả chuỗi rỗng.
 *
 * @param string $date Giá trị ngày từ form.
 * @return string
 */
function kio_store_sanitize_date( string $date ): string {
	$date = sanitize_text_field( $date );
	if ( '' === $date ) {
		return '';
	}

	$parsed = DateTime::createFromFormat( 'Y-m-d', $date );

	return ( $parsed && $parsed->format( 'Y-m-d' ) === $date ) ? $date : '';
}

/**
 * Lưu meta của khuyến mãi.
 *
 * Thứ tự kiểm tra bảo mật (bắt buộc theo tài liệu):
 *   1. Bỏ qua autosave và revision.
 *   2. Xác minh nonce.
 *   3. Kiểm tra quyền sửa bài.
 *   4. Sanitize từng trường rồi mới lưu.
 *
 * @param int $post_id ID khuyến mãi.
 * @return void
 */
function kio_store_promotion_save( int $post_id ): void {
	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
		return;
	}

	if ( ! isset( $_POST['kio_promotion_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['kio_promotion_nonce'] ) ), 'kio_promotion_save' ) ) {
		return;
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$products = isset( $_POST['kio_promotion_products'] ) && is_array( $_POST['kio_promotion_products'] )
		? array_values( array_filter( array_map( 'absint', wp_unslash( $_POST['kio_promotion_products'] ) ) ) )
		: array();
	update_post_meta( $post_id, '_kio_promotion_products', $products );

	$categories = isset( $_POST['kio_promotion_categories'] ) && is_array( $_POST['kio_promotion_categories'] )
		? array_values( array_filter( array_map( 'absint', wp_unslash( $_POST['kio_promotion_categories'] ) ) ) )
		: array();
	update_post_meta( $post_id, '_kio_promotion_categories', $categories );

	update_post_meta( $post_id, '_kio_promotion_start', isset( $_POST['kio_promotion_start'] ) ? kio_store_sanitize_date( sanitize_text_field( wp_unslash( $_POST['kio_promotion_start'] ) ) ) : '' );
	update_post_meta( $post_id, '_kio_promotion_end', isset( $_POST['kio_promotion_end'] ) ? kio_store_sanitize_date( sanitize_text_field( wp_unslash( $_POST['kio_promotion_end'] ) ) ) : '' );

	$order = isset( $_POST['kio_promotion_order'] ) ? absint( $_POST['kio_promotion_order'] ) : 0;
	update_post_meta( $post_id, '_kio_promotion_order', $order );
}

/**
 * Mọi khuyến mãi đang trong thời hạn, đã sắp theo thứ tự hiển thị.
 *
 * Nhớ trong biến static: hàm được gọi cho TỪNG thẻ sản phẩm (dòng "+2 ưu đãi"), một trang có
 * vài chục thẻ mà danh sách thì như nhau trong cả request.
 *
 * @return WP_Post[]
 */
function kio_store_active_promotions(): array {
	static $active = null;
	if ( null !== $active ) {
		return $active;
	}

	// Không sắp xếp bằng 'meta_key' + 'orderby' => 'meta_value_num' trong query: khi có
	// 'meta_key', WordPress JOIN bảng postmeta và chỉ lấy bài CÓ meta đó, nên khuyến mãi
	// thiếu meta thứ tự (tạo qua import, REST, nhân bản bài…) sẽ biến mất. Lấy hết rồi sắp
	// trong PHP; get_posts() đã nạp sẵn meta vào cache nên get_post_meta() không tốn query.
	$all = get_posts(
		array(
			'post_type'      => 'kio_promotion',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'ASC',
		)
	);
	usort(
		$all,
		static function ( WP_Post $a, WP_Post $b ): int {
			return (int) get_post_meta( $a->ID, '_kio_promotion_order', true ) <=> (int) get_post_meta( $b->ID, '_kio_promotion_order', true );
		}
	);

	$today  = wp_date( 'Y-m-d' );
	$active = array_values(
		array_filter(
			$all,
			static function ( WP_Post $promotion ) use ( $today ): bool {
				$start = (string) get_post_meta( $promotion->ID, '_kio_promotion_start', true );
				$end   = (string) get_post_meta( $promotion->ID, '_kio_promotion_end', true );

				return ( '' === $start || $today >= $start ) && ( '' === $end || $today <= $end );
			}
		)
	);

	return $active;
}

/**
 * Danh sách khuyến mãi đang hiệu lực và áp dụng cho một sản phẩm.
 *
 * @param WC_Product $product Sản phẩm đang xem.
 * @return WP_Post[]
 */
function kio_store_product_promotions( WC_Product $product ): array {
	$product_id   = $product->get_id();
	$category_ids = array_map( 'intval', $product->get_category_ids() );
	$matches      = array();

	foreach ( kio_store_active_promotions() as $promotion ) {
		$apply_products   = kio_store_promotion_meta_products( $promotion->ID );
		$apply_categories = kio_store_promotion_meta_categories( $promotion->ID );

		$is_global      = empty( $apply_products ) && empty( $apply_categories );
		$match_product  = in_array( $product_id, $apply_products, true );
		$match_category = count( array_intersect( $category_ids, $apply_categories ) ) > 0;

		if ( $is_global || $match_product || $match_category ) {
			$matches[] = $promotion;
		}
	}

	return $matches;
}

/**
 * HTML khối "Khuyến mãi" của một sản phẩm; rỗng nếu không có khuyến mãi.
 *
 * @param WC_Product $product Sản phẩm đang xem.
 * @return string
 */
function kio_store_promotions_html( WC_Product $product ): string {
	$promotions = kio_store_product_promotions( $product );
	if ( empty( $promotions ) ) {
		return '';
	}

	$visible = array_slice( $promotions, 0, 3 );
	$hidden  = array_slice( $promotions, 3 );

	ob_start();
	?>
	<div class="kio-promotions">
		<h3 class="kio-promotions__title"><?php esc_html_e( 'Khuyến mãi', 'kio-store' ); ?></h3>
		<ol class="kio-promotions__list">
			<?php foreach ( $visible as $promotion ) : ?>
				<li><strong><?php echo esc_html( $promotion->post_title ); ?></strong> <?php echo esc_html( wp_strip_all_tags( $promotion->post_content ) ); ?></li>
			<?php endforeach; ?>
		</ol>
		<?php if ( ! empty( $hidden ) ) : ?>
			<ol class="kio-promotions__hidden" start="4">
				<?php foreach ( $hidden as $promotion ) : ?>
					<li><strong><?php echo esc_html( $promotion->post_title ); ?></strong> <?php echo esc_html( wp_strip_all_tags( $promotion->post_content ) ); ?></li>
				<?php endforeach; ?>
			</ol>
			<button type="button" class="kio-promotions__toggle" aria-expanded="false"><?php esc_html_e( 'Xem thêm', 'kio-store' ); ?></button>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * ACTION woocommerce_single_product_summary: in khối khuyến mãi.
 *
 * @return void
 */
function kio_store_render_promotions(): void {
	global $product;

	if ( ! $product instanceof WC_Product ) {
		return;
	}

	echo kio_store_promotions_html( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- đã escape từng phần trong hàm.
}

/**
 * Enqueue JS nút "Xem thêm", chỉ trên trang sản phẩm.
 *
 * @return void
 */
function kio_store_promotions_enqueue_script(): void {
	if ( ! is_product() ) {
		return;
	}

	wp_enqueue_script(
		'kio-store-promotions',
		KIO_STORE_URL . 'assets/js/promotions.js',
		array(),
		kio_store_asset_version( 'assets/js/promotions.js' ),
		true
	);
}
