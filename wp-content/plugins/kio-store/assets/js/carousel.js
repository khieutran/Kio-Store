/**
 * Băng chuyền dùng chung (Flash Sale, video reels).
 *
 * Việc cuộn và dừng đúng thẻ do CSS làm (overflow + scroll-snap), nên vuốt, bàn di chuột và phím
 * đã chạy sẵn. JS chỉ thêm nút trước/sau và tắt nút khi đã tới đầu/cuối.
 */
( function () {
	'use strict';

	var calm = window.matchMedia( '(prefers-reduced-motion: reduce)' );

	function makeButton( side, label ) {
		var button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'kio-carousel__nav kio-carousel__nav--' + side;
		button.setAttribute( 'aria-label', label );
		return button;
	}

	document.querySelectorAll( '[data-kio-carousel]' ).forEach( function ( root ) {
		var track = root.querySelector( '.kio-carousel__track, ul.products' );
		if ( ! track ) {
			return;
		}

		var prev = makeButton( 'prev', 'Xem các mục trước' );
		var next = makeButton( 'next', 'Xem các mục sau' );

		function update() {
			var max = track.scrollWidth - track.clientWidth;
			root.classList.toggle( 'has-overflow', max > 1 );
			prev.disabled = track.scrollLeft <= 1;
			next.disabled = track.scrollLeft >= max - 1;
		}

		function move( direction ) {
			track.scrollBy( {
				left: direction * track.clientWidth * 0.8,
				behavior: calm.matches ? 'auto' : 'smooth',
			} );
		}

		// Vùng cuộn phải focus được thì người dùng bàn phím mới cuộn được bằng phím mũi tên.
		track.setAttribute( 'tabindex', '0' );
		track.setAttribute( 'aria-label', root.dataset.label || 'Băng chuyền' );
		track.classList.add( 'kio-carousel__track' );

		prev.addEventListener( 'click', function () {
			move( -1 );
		} );
		next.addEventListener( 'click', function () {
			move( 1 );
		} );
		track.addEventListener( 'scroll', update, { passive: true } );
		window.addEventListener( 'resize', update );

		root.classList.add( 'kio-carousel' );
		root.append( prev, next );
		update();
	} );
}() );
