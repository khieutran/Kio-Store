/**
 * F7 — Thanh mua hàng dính dưới màn hình (mobile, trang sản phẩm).
 *
 * - IntersectionObserver theo dõi nút "Thêm vào giỏ" gốc: nút đã nằm PHÍA TRÊN màn hình (khách
 *   cuộn qua) thì hiện thanh. Không cần nghe sự kiện scroll liên tục.
 * - Nút trên thanh bấm hộ nút gốc (giữ nguyên kiểm tra của Woo). Sản phẩm biến thể chưa chọn
 *   đủ phiên bản → cuộn lên khu chọn phiên bản thay vì bấm.
 * - Giá đổi theo biến thể: sự kiện jQuery found_variation (có price_html) / reset_data của Woo.
 *
 * JS thuần + jQuery có sẵn của WordPress, không có bước build.
 */
( function () {
	'use strict';

	var bar = document.querySelector( '.kio-buy-bar' );
	var original = document.querySelector( 'form.cart .single_add_to_cart_button' );
	if ( ! bar || ! original || ! ( 'IntersectionObserver' in window ) ) {
		return;
	}

	var priceEl = bar.querySelector( '.kio-buy-bar__price' );
	var button = bar.querySelector( '.kio-buy-bar__button' );
	var form = original.closest( 'form.cart' );
	var defaultPrice = priceEl.innerHTML;

	bar.hidden = false; // từ đây CSS điều khiển hiện/ẩn bằng lớp is-visible

	// Vùng quan sát = TOÀN BỘ khoảng phía trên mép trên màn hình (kéo dài lên 100000px, mép dưới
	// thu về đúng mép trên màn hình). Nút gốc "giao" với vùng này ⇔ nút đã bị cuộn qua.
	//
	// Không dùng cách "nút ra khỏi màn hình và top < 0": observer chỉ báo khi trạng thái giao
	// thay đổi, nên nếu trang NHẢY thẳng từ trên xuống (vuốt rất nhanh, link tới phần đánh giá,
	// trình duyệt khôi phục vị trí cuộn) nút đi từ dưới màn hình lên trên màn hình mà không lần
	// nào nằm trong màn hình → không có sự kiện, thanh không bao giờ hiện.
	new IntersectionObserver(
		function ( entries ) {
			var scrolledPast = entries[ 0 ].isIntersecting;
			bar.classList.toggle( 'is-visible', scrolledPast );
			document.body.classList.toggle( 'kio-buy-bar-visible', scrolledPast );
		},
		{ rootMargin: '100000px 0px -100% 0px' }
	).observe( original );

	button.addEventListener( 'click', function () {
		// Biến thể: nút gốc có lớp "disabled" khi chưa chọn đủ phiên bản hoặc phiên bản hết hàng.
		if ( original.classList.contains( 'disabled' ) || original.disabled ) {
			( form.querySelector( '.variations' ) || form ).scrollIntoView( { behavior: 'smooth', block: 'center' } );
			return;
		}
		original.click();
	} );

	if ( window.jQuery && form.classList.contains( 'variations_form' ) ) {
		window.jQuery( form )
			.on( 'found_variation', function ( event, variation ) {
				if ( variation && variation.price_html ) {
					priceEl.innerHTML = variation.price_html;
				}
			} )
			.on( 'reset_data', function () {
				priceEl.innerHTML = defaultPrice;
			} );
	}
} )();
