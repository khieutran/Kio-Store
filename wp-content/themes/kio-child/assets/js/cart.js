/**
 * Giỏ hàng: nút − + và tự cập nhật khi đổi số lượng.
 *
 * Woo đã cập nhật giỏ bằng AJAX khi bấm "Cập nhật giỏ hàng", nên ở đây chỉ tự bấm nút đó (đã ẩn bằng
 * CSS) sau khi khách ngừng đổi số. Woo thay cả form sau mỗi lần cập nhật, nên phải gắn lại nút − +
 * và trả focus về đúng ô số lượng.
 */
( function ( $ ) {
	'use strict';

	var DELAY = 500;
	var timer = 0;
	var focusName = '';
	var live = document.createElement( 'div' );

	live.className = 'screen-reader-text';
	live.setAttribute( 'role', 'status' );
	document.body.append( live );

	function makeButton( text, label, step ) {
		var button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'kio-qty__btn';
		button.dataset.step = step;
		button.setAttribute( 'aria-label', label );
		button.textContent = text;
		return button;
	}

	function enhance() {
		document.querySelectorAll( '.woocommerce-cart-form .quantity' ).forEach( function ( box ) {
			var input = box.querySelector( 'input.qty' );

			// Sản phẩm chỉ được mua 1 cái: Woo dùng input ẩn, không có gì để tăng giảm.
			if ( ! input || 'hidden' === input.type || box.classList.contains( 'kio-qty' ) ) {
				return;
			}

			box.classList.add( 'kio-qty' );
			box.prepend( makeButton( '−', 'Giảm số lượng', -1 ) );
			box.append( makeButton( '+', 'Tăng số lượng', 1 ) );
		} );

		if ( focusName ) {
			$( '.woocommerce-cart-form input.qty' ).filter( function () {
				return this.name === focusName;
			} ).trigger( 'focus' );
			focusName = '';
			live.textContent = 'Đã cập nhật giỏ hàng';
		}
	}

	function scheduleUpdate( input ) {
		window.clearTimeout( timer );
		timer = window.setTimeout( function () {
			var button = document.querySelector( '.woocommerce-cart-form [name="update_cart"]' );

			if ( button ) {
				focusName = input.name;
				button.disabled = false;
				button.click();
			}
		}, DELAY );
	}

	$( document ).on( 'click', '.kio-qty__btn', function () {
		var input = this.parentElement.querySelector( 'input.qty' );
		var min = Number( input.min ) || 1;
		var max = Number( input.max ) || Infinity;
		var value = Math.min( max, Math.max( min, ( Number( input.value ) || min ) + Number( this.dataset.step ) ) );

		if ( value !== Number( input.value ) ) {
			input.value = value;
			scheduleUpdate( input );
		}
	} );

	$( document ).on( 'change', '.woocommerce-cart-form input.qty', function () {
		scheduleUpdate( this );
	} );

	$( document.body ).on( 'updated_wc_div', enhance );
	enhance();
}( jQuery ) );
