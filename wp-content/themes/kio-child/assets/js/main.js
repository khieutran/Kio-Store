/**
 * Kio Child — main.js
 * JS nhỏ cho giao diện: mega menu trên mobile, slider hero, làm mờ biến thể hết hàng.
 * Không dùng thư viện ngoài; chạy ở cuối trang.
 */
( function () {
	'use strict';

	function setupMegaMenu() {
		var toggle = document.querySelector( '.kio-nav__toggle' );
		var panel = document.getElementById( 'kio-category-menu' );
		if ( ! toggle || ! panel ) {
			return;
		}

		toggle.addEventListener( 'click', function () {
			var open = panel.classList.toggle( 'is-open' );
			toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		} );

		document.addEventListener( 'click', function ( event ) {
			if ( panel.classList.contains( 'is-open' ) && ! panel.contains( event.target ) && ! toggle.contains( event.target ) ) {
				panel.classList.remove( 'is-open' );
				toggle.setAttribute( 'aria-expanded', 'false' );
			}
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && panel.classList.contains( 'is-open' ) ) {
				panel.classList.remove( 'is-open' );
				toggle.setAttribute( 'aria-expanded', 'false' );
				toggle.focus();
			}
		} );
	}

	function setupHeroSlider() {
		var sliders = document.querySelectorAll( '.kio-hero' );
		sliders.forEach( function ( slider ) {
			var track = slider.querySelector( '.kio-hero__track' );
			var slides = slider.querySelectorAll( '.kio-hero__slide' );
			var prev = slider.querySelector( '.kio-hero__prev' );
			var next = slider.querySelector( '.kio-hero__next' );
			if ( ! track || ! slides.length ) {
				return;
			}

			function goTo( index ) {
				var slide = slides[ index ];
				if ( slide ) {
					track.scrollTo( { left: slide.offsetLeft, behavior: 'smooth' } );
				}
			}

			if ( prev ) {
				prev.addEventListener( 'click', function () {
					var slide = Math.round( track.scrollLeft / track.clientWidth );
					goTo( Math.max( 0, slide - 1 ) );
				} );
			}
			if ( next ) {
				next.addEventListener( 'click', function () {
					var slide = Math.round( track.scrollLeft / track.clientWidth );
					goTo( Math.min( slides.length - 1, slide + 1 ) );
				} );
			}
		} );
	}

	function setupVariationStock() {
		var forms = document.querySelectorAll( '.variations_form' );
		forms.forEach( function ( form ) {
			var raw = form.getAttribute( 'data-product_variations' );
			if ( ! raw ) {
				return;
			}
			var variations;
			try {
				variations = JSON.parse( raw );
			} catch ( e ) {
				return;
			}
			if ( ! Array.isArray( variations ) ) {
				return;
			}

			// Giá trị thuộc tính có sẵn (in stock). Chỉ làm mờ lựa chọn mà KHÔNG còn
			// biến thể nào có hàng ở bất kỳ tổ hợp nào (hết hàng hoàn toàn theo màu/dung lượng).
			// Trường hợp "hết hàng theo tổ hợp" (vd. 256GB + Xanh dương) để Phase 4 xử lý bằng hook.
			var selects = form.querySelectorAll( 'select' );
			selects.forEach( function ( select ) {
				var attrKey = select.getAttribute( 'name' );
				Array.prototype.forEach.call( select.options, function ( option ) {
					if ( ! option.value ) {
						return;
					}
					var hasStock = variations.some( function ( v ) {
						return v.attributes && v.attributes[ attrKey ] === option.value && v.is_in_stock;
					} );
					if ( ! hasStock ) {
						option.classList.add( 'kio-out-of-stock' );
					}
				} );
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		setupMegaMenu();
		setupHeroSlider();
		setupVariationStock();
	} );
}() );
