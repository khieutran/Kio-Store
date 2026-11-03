<?php
/**
 * Tạo coupon từ data/coupons.json bằng WC_Coupon.
 * Coupon là post type "shop_coupon" trong wp_posts, điều kiện lưu ở wp_postmeta.
 *
 * Chạy lặp lại được. Chạy: docker compose run --rm wpcli eval-file /scripts/woo-coupons.php
 */

$coupons = json_decode( file_get_contents( '/data/coupons.json' ), true );

foreach ( $coupons as $data ) {
	$id     = wc_get_coupon_id_by_code( $data['code'] );
	$coupon = new WC_Coupon( $id ?: 0 );

	$coupon->set_code( $data['code'] );
	$coupon->set_description( $data['description'] );
	$coupon->set_discount_type( $data['discount_type'] );      // percent | fixed_cart | fixed_product
	$coupon->set_amount( $data['amount'] );
	$coupon->set_minimum_amount( $data['minimum_amount'] ?? '' );
	$coupon->set_usage_limit_per_user( $data['usage_limit_per_user'] ?? 0 );
	$coupon->set_free_shipping( ! empty( $data['free_shipping'] ) );
	$coupon->set_individual_use( ! empty( $data['individual_use'] ) );            // "Chỉ dùng riêng" (có sẵn của Woo)
	$coupon->update_meta_data( '_kio_max_discount', $data['max_discount'] ?? '' ); // F9: giảm tối đa (plugin kio-store)
	$coupon->set_product_categories( array_map(
		fn( $slug ) => get_term_by( 'slug', $slug, 'product_cat' )->term_id,
		$data['categories'] ?? []
	) );
	$coupon->save();

	WP_CLI::log( sprintf( '    %-10s %s (ID %d)', $data['code'], $id ? 'cập nhật' : 'tạo mới', $coupon->get_id() ) );
}
