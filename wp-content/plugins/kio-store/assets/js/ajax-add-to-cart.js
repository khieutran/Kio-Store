/**
 * Nút mua trên trang sản phẩm: gửi form bằng fetch thay vì tải lại trang.
 *
 * Bắt sự kiện submit nên nút của thanh mua F7 (bấm hộ nút gốc) cũng đi qua đây. Trình duyệt chỉ phát
 * submit khi số lượng hợp lệ, và Woo chặn sẵn nút đang "disabled" (chưa chọn đủ phiên bản).
 * Thành công thì phát added_to_cart như nút trên thẻ, nên số giỏ và thông báo dùng chung một đường.
 */
( function ( $ ) {
	'use strict';

	var config = window.kioAddToCart;
	var form = document.querySelector( '.summary form.cart' );

	if ( ! config || ! form ) {
		return;
	}

	function fail( message ) {
		$( document.body ).trigger( 'kio_toast', [ message, true ] );
	}

	function applyFragments( fragments ) {
		Object.keys( fragments || {} ).forEach( function ( selector ) {
			$( selector ).replaceWith( fragments[ selector ] );
		} );
	}

	form.addEventListener( 'submit', function ( event ) {
		var button = form.querySelector( '.single_add_to_cart_button' );
		var data = new FormData( form, event.submitter );

		event.preventDefault();

		// Bấm liên tiếp: chỉ gửi một lần cho tới khi có kết quả.
		if ( button.classList.contains( 'loading' ) ) {
			return;
		}

		// Sản phẩm đơn: tham số add-to-cart nằm ở chính nút bấm.
		if ( ! data.has( 'add-to-cart' ) && button.value ) {
			data.set( 'add-to-cart', button.value );
		}

		button.classList.add( 'loading' );
		button.setAttribute( 'aria-busy', 'true' );

		fetch( config.endpoint, { method: 'POST', body: data, credentials: 'same-origin' } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( body ) {
				if ( body.fragments ) {
					applyFragments( body.fragments );
					$( document.body ).trigger( 'added_to_cart', [ body.fragments, body.cart_hash, $( button ) ] );
					return;
				}
				fail( ( body.data && body.data.messages && body.data.messages[ 0 ] ) || 'Không thêm được vào giỏ, vui lòng thử lại.' );
			} )
			.catch( function () {
				// Không gửi lại form tự động: yêu cầu có thể đã thành công, gửi lại sẽ thêm trùng.
				fail( 'Không thêm được vào giỏ, vui lòng kiểm tra mạng rồi thử lại.' );
			} )
			.finally( function () {
				button.classList.remove( 'loading' );
				button.removeAttribute( 'aria-busy' );
			} );
	} );
}( jQuery ) );
