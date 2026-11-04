/**
 * Cửa sổ xem video reels.
 *
 * Bấm thẻ mới tạo iframe, đóng thì bỏ iframe (video dừng hẳn). <dialog> của trình duyệt lo phần
 * khó: giữ focus bên trong, phím Esc, trả focus về thẻ đã bấm.
 */
( function () {
	'use strict';

	if ( ! window.HTMLDialogElement ) {
		return; // thẻ vẫn là link mở YouTube.
	}

	document.querySelectorAll( '.kio-reels' ).forEach( function ( block ) {
		var dialog = block.querySelector( '.kio-reels__dialog' );
		var frame = dialog.querySelector( '.kio-reels__frame' );
		var info = dialog.querySelector( '.kio-reels__info' );
		var cards = Array.prototype.slice.call( block.querySelectorAll( '.kio-reels__card' ) );
		var prev = dialog.querySelector( '.kio-reels__nav--prev' );
		var next = dialog.querySelector( '.kio-reels__nav--next' );
		var current = 0;

		function show( index ) {
			var card;
			var iframe = document.createElement( 'iframe' );
			var template;

			current = ( index + cards.length ) % cards.length;
			card = cards[ current ];
			template = card.parentElement.querySelector( 'template' );

			iframe.src = 'https://www.youtube-nocookie.com/embed/' + encodeURIComponent( card.dataset.video ) + '?autoplay=1&rel=0&playsinline=1';
			iframe.title = card.dataset.title;
			iframe.allow = 'autoplay; encrypted-media; picture-in-picture';
			iframe.allowFullscreen = true;
			iframe.referrerPolicy = 'strict-origin-when-cross-origin';

			frame.replaceChildren( iframe );
			info.replaceChildren( template.content.cloneNode( true ) );
		}

		cards.forEach( function ( card, index ) {
			card.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				show( index );
				dialog.showModal();
			} );
		} );

		dialog.querySelector( '.kio-reels__close' ).addEventListener( 'click', function () {
			dialog.close();
		} );

		// Bấm vào nền mờ: đích của sự kiện chính là <dialog>, không phải phần tử bên trong.
		dialog.addEventListener( 'click', function ( event ) {
			if ( event.target === dialog ) {
				dialog.close();
			}
		} );

		dialog.addEventListener( 'close', function () {
			frame.replaceChildren();
		} );

		if ( prev && next ) {
			prev.addEventListener( 'click', function () {
				show( current - 1 );
			} );
			next.addEventListener( 'click', function () {
				show( current + 1 );
			} );
			dialog.addEventListener( 'keydown', function ( event ) {
				if ( 'ArrowLeft' === event.key ) {
					show( current - 1 );
				} else if ( 'ArrowRight' === event.key ) {
					show( current + 1 );
				}
			} );
		}
	} );
}() );
