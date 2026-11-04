<?php
/**
 * Video review dạng reels: CPT kio_video + shortcode [kio_video_reels].
 *
 * Chủ shop dán link YouTube trong wp-admin, plugin lấy ID video rồi hiện thẻ dọc 9:16.
 *
 * Trang chỉ tải ảnh bìa: bấm vào thẻ mới tạo iframe (JS), nên khách không bấm thì không tải player
 * của YouTube. Iframe dùng youtube-nocookie.com. Chưa có video thì không in gì, không để khung trống.
 *
 * Không có JS (hoặc trình duyệt thiếu <dialog>): thẻ là link thường mở YouTube.
 *
 * @package KioStore
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'kio_store_register_video_cpt' );
add_action( 'add_meta_boxes', 'kio_store_video_add_meta_box' );
add_action( 'save_post_kio_video', 'kio_store_video_save', 10, 1 );
add_action( 'admin_notices', 'kio_store_video_admin_notice' );
add_action( 'woocommerce_after_single_product_summary', 'kio_store_render_product_videos', 15 );
add_shortcode( 'kio_video_reels', 'kio_store_video_reels_shortcode' );

/**
 * Đăng ký CPT kio_video.
 *
 * Không public: video không có trang riêng, chỉ hiện qua shortcode. Có page-attributes để dùng ô
 * "Thứ tự" (menu_order) cho chủ shop tự sắp xếp.
 *
 * @return void
 */
function kio_store_register_video_cpt(): void {
	register_post_type(
		'kio_video',
		array(
			'labels'        => array(
				'name'          => __( 'Video review', 'kio-store' ),
				'singular_name' => __( 'Video review', 'kio-store' ),
				'add_new_item'  => __( 'Thêm video review', 'kio-store' ),
				'edit_item'     => __( 'Sửa video review', 'kio-store' ),
				'search_items'  => __( 'Tìm video', 'kio-store' ),
			),
			'public'        => false,
			'show_ui'       => true,
			'show_in_menu'  => true,
			'menu_icon'     => 'dashicons-video-alt3',
			'menu_position' => 57,
			'supports'      => array( 'title', 'thumbnail', 'page-attributes' ),
			'rewrite'       => false,
		)
	);
}

/**
 * Lấy ID video từ link YouTube.
 *
 * Chỉ nhận đúng tên miền của YouTube và ID đúng 11 ký tự: giá trị này sau đó nằm trong địa chỉ
 * iframe nên không được để lọt link của site khác.
 *
 * @param string $url Link khách dán: watch?v=, youtu.be/, shorts/, embed/, live/.
 * @return string ID video; rỗng nếu link không hợp lệ.
 */
function kio_store_youtube_id( string $url ): string {
	$parts = wp_parse_url( trim( $url ) );
	if ( empty( $parts['host'] ) || ! in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) ) {
		return '';
	}

	$host = strtolower( preg_replace( '/^(www|m)\./', '', $parts['host'] ) );
	$path = trim( $parts['path'] ?? '', '/' );
	$id   = '';

	if ( 'youtu.be' === $host ) {
		$id = explode( '/', $path )[0];
	} elseif ( in_array( $host, array( 'youtube.com', 'youtube-nocookie.com' ), true ) ) {
		if ( 'watch' === $path ) {
			parse_str( $parts['query'] ?? '', $query );
			$id = (string) ( $query['v'] ?? '' );
		} elseif ( preg_match( '#^(?:shorts|embed|live)/([^/]+)#', $path, $match ) ) {
			$id = $match[1];
		}
	}

	return 1 === preg_match( '/^[A-Za-z0-9_-]{11}$/', $id ) ? $id : '';
}

/**
 * Đăng ký meta box.
 *
 * @return void
 */
function kio_store_video_add_meta_box(): void {
	add_meta_box( 'kio_video_details', __( 'Thông tin video', 'kio-store' ), 'kio_store_video_meta_box', 'kio_video', 'normal', 'high' );
}

/**
 * Nội dung meta box.
 *
 * @param WP_Post $post Video đang sửa.
 * @return void
 */
function kio_store_video_meta_box( WP_Post $post ): void {
	wp_nonce_field( 'kio_video_save', 'kio_video_nonce' );

	$url      = (string) get_post_meta( $post->ID, '_kio_video_url', true );
	$selected = (int) get_post_meta( $post->ID, '_kio_video_product', true );
	$products = wc_get_products(
		array(
			'limit'   => -1,
			'status'  => 'publish',
			'orderby' => 'title',
			'order'   => 'ASC',
		)
	);
	?>
	<p>
		<label for="kio_video_url"><strong><?php esc_html_e( 'Link YouTube', 'kio-store' ); ?></strong></label><br>
		<input type="url" id="kio_video_url" name="kio_video_url" value="<?php echo esc_attr( $url ); ?>" style="width:100%;max-width:480px;" placeholder="https://www.youtube.com/watch?v=..." />
		<span class="description"><?php esc_html_e( 'Nhận link watch, youtu.be, shorts, embed, live. Ảnh đại diện để trống thì dùng ảnh bìa của YouTube.', 'kio-store' ); ?></span>
	</p>
	<p>
		<label for="kio_video_product"><strong><?php esc_html_e( 'Sản phẩm liên quan', 'kio-store' ); ?></strong></label><br>
		<select id="kio_video_product" name="kio_video_product" style="width:100%;max-width:480px;">
			<option value="0"><?php esc_html_e( 'Không gắn sản phẩm', 'kio-store' ); ?></option>
			<?php foreach ( $products as $product ) : ?>
				<option value="<?php echo esc_attr( $product->get_id() ); ?>" <?php selected( $selected, $product->get_id() ); ?>><?php echo esc_html( $product->get_name() ); ?></option>
			<?php endforeach; ?>
		</select>
	</p>
	<?php
}

/**
 * Lưu meta box. Thứ tự kiểm tra giống F4: autosave → nonce → quyền → sanitize.
 *
 * Link sai vẫn giữ lại trong ô để chủ shop sửa, nhưng xoá ID nên video không hiện ngoài trang.
 *
 * @param int $post_id ID video.
 * @return void
 */
function kio_store_video_save( int $post_id ): void {
	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
		return;
	}

	if ( ! isset( $_POST['kio_video_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['kio_video_nonce'] ) ), 'kio_video_save' ) ) {
		return;
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$url = isset( $_POST['kio_video_url'] ) ? esc_url_raw( wp_unslash( $_POST['kio_video_url'] ) ) : '';
	$id  = kio_store_youtube_id( $url );

	update_post_meta( $post_id, '_kio_video_url', $url );
	update_post_meta( $post_id, '_kio_video_id', $id );
	update_post_meta( $post_id, '_kio_video_product', isset( $_POST['kio_video_product'] ) ? absint( $_POST['kio_video_product'] ) : 0 );

	if ( '' === $id ) {
		set_transient( 'kio_video_error_' . get_current_user_id(), 1, MINUTE_IN_SECONDS );
	}
}

/**
 * Báo link sai sau khi lưu. Lưu xong WordPress chuyển hướng nên phải nhớ lỗi qua transient.
 *
 * @return void
 */
function kio_store_video_admin_notice(): void {
	$key = 'kio_video_error_' . get_current_user_id();
	if ( get_transient( $key ) ) {
		delete_transient( $key );
		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html__( 'Link YouTube không hợp lệ: video này sẽ chưa hiện ngoài trang.', 'kio-store' ) );
	}
}

/**
 * Các video hợp lệ để hiển thị.
 *
 * @param int $limit      Số video tối đa.
 * @param int $product_id Chỉ lấy video của sản phẩm này; 0 = mọi video.
 * @return array<int,array{id:string,title:string,cover:string,product:int}>
 */
function kio_store_video_list( int $limit, int $product_id = 0 ): array {
	$args = array(
		'post_type'      => 'kio_video',
		'post_status'    => 'publish',
		'posts_per_page' => $limit,
		'orderby'        => array(
			'menu_order' => 'ASC',
			'date'       => 'DESC',
		),
	);
	if ( $product_id > 0 ) {
		$args['meta_key']   = '_kio_video_product'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- lọc theo sản phẩm, số video nhỏ.
		$args['meta_value'] = $product_id; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- như trên.
	}

	$videos = array();
	foreach ( get_posts( $args ) as $post ) {
		$id = (string) get_post_meta( $post->ID, '_kio_video_id', true );
		if ( '' === $id ) {
			continue;
		}

		$cover = get_the_post_thumbnail_url( $post, 'medium_large' );

		$videos[] = array(
			'id'      => $id,
			'title'   => get_the_title( $post ),
			'cover'   => $cover ? (string) $cover : 'https://i.ytimg.com/vi/' . rawurlencode( $id ) . '/hqdefault.jpg',
			'product' => (int) get_post_meta( $post->ID, '_kio_video_product', true ),
		);
	}

	return $videos;
}

/**
 * HTML thẻ sản phẩm hiện dưới video trong cửa sổ xem.
 *
 * @param int $product_id ID sản phẩm.
 * @return string HTML; rỗng nếu sản phẩm không còn.
 */
function kio_store_video_product_html( int $product_id ): string {
	$product = $product_id > 0 ? wc_get_product( $product_id ) : null;
	if ( ! $product ) {
		return '';
	}

	return sprintf(
		'<a class="kio-reels__product" href="%1$s">%2$s<span class="kio-reels__product-text"><span class="kio-reels__product-name">%3$s</span><span class="kio-reels__product-price">%4$s</span></span><span class="kio-reels__buy">%5$s</span></a>',
		esc_url( $product->get_permalink() ),
		$product->get_image( 'woocommerce_gallery_thumbnail' ),
		esc_html( $product->get_name() ),
		wp_kses_post( $product->get_price_html() ),
		esc_html__( 'Mua ngay', 'kio-store' )
	);
}

/**
 * In khối reels.
 *
 * Các thẻ là link thật tới YouTube; JS chặn lại để mở trong cửa sổ xem. Dùng <dialog>: showModal()
 * của trình duyệt đã lo giữ focus bên trong, phím Esc và trả focus về thẻ đã bấm.
 *
 * @param array  $videos Danh sách từ kio_store_video_list().
 * @param string $title  Tiêu đề khối.
 * @return string HTML; rỗng nếu không có video.
 */
function kio_store_video_reels_html( array $videos, string $title ): string {
	if ( empty( $videos ) ) {
		return '';
	}

	wp_enqueue_style( 'kio-store-video-reels', KIO_STORE_URL . 'assets/css/video-reels.css', array(), KIO_STORE_VERSION );
	wp_enqueue_script(
		'kio-store-video-reels',
		KIO_STORE_URL . 'assets/js/video-reels.js',
		array( 'kio-store-carousel' ),
		KIO_STORE_VERSION,
		array(
			'in_footer' => true,
			'strategy'  => 'defer',
		)
	);

	ob_start();
	?>
	<section class="kio-reels">
		<h2 class="kio-section-title"><?php echo esc_html( $title ); ?></h2>
		<div data-kio-carousel data-label="<?php echo esc_attr( $title ); ?>">
			<ul class="kio-reels__track kio-carousel__track">
				<?php foreach ( $videos as $video ) : ?>
					<li>
						<a class="kio-reels__card" href="<?php echo esc_url( 'https://www.youtube.com/watch?v=' . rawurlencode( $video['id'] ) ); ?>" target="_blank" rel="noopener" data-video="<?php echo esc_attr( $video['id'] ); ?>" data-title="<?php echo esc_attr( $video['title'] ); ?>">
							<img src="<?php echo esc_url( $video['cover'] ); ?>" alt="" loading="lazy" decoding="async">
							<span class="kio-reels__play" aria-hidden="true"></span>
							<span class="kio-reels__title"><?php echo esc_html( $video['title'] ); ?></span>
						</a>
						<template><?php echo kio_store_video_product_html( $video['product'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- đã escape trong hàm. ?></template>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<dialog class="kio-reels__dialog" aria-label="<?php echo esc_attr( $title ); ?>">
			<button type="button" class="kio-reels__close" aria-label="<?php esc_attr_e( 'Đóng', 'kio-store' ); ?>">&times;</button>
			<div class="kio-reels__frame"></div>
			<div class="kio-reels__info"></div>
			<?php if ( count( $videos ) > 1 ) : ?>
				<button type="button" class="kio-reels__nav kio-reels__nav--prev" aria-label="<?php esc_attr_e( 'Video trước', 'kio-store' ); ?>"></button>
				<button type="button" class="kio-reels__nav kio-reels__nav--next" aria-label="<?php esc_attr_e( 'Video sau', 'kio-store' ); ?>"></button>
			<?php endif; ?>
		</dialog>
	</section>
	<?php

	return (string) ob_get_clean();
}

/**
 * Shortcode [kio_video_reels limit="10" title="Video review"].
 *
 * @param array|string $atts Thuộc tính shortcode.
 * @return string HTML.
 */
function kio_store_video_reels_shortcode( $atts ): string {
	$atts = shortcode_atts(
		array(
			'limit' => 10,
			'title' => __( 'Video review', 'kio-store' ),
		),
		$atts,
		'kio_video_reels'
	);

	return kio_store_video_reels_html( kio_store_video_list( max( 1, min( 20, absint( $atts['limit'] ) ) ) ), (string) $atts['title'] );
}

/**
 * ACTION woocommerce_after_single_product_summary: video của chính sản phẩm đang xem.
 *
 * Priority 15: sau khối tab (10), trước sản phẩm liên quan (20).
 *
 * @return void
 */
function kio_store_render_product_videos(): void {
	global $product;

	if ( $product instanceof WC_Product ) {
		echo kio_store_video_reels_html( kio_store_video_list( 10, $product->get_id() ), __( 'Video review', 'kio-store' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- đã escape trong hàm.
	}
}
