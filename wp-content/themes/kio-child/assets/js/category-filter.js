/**
 * Trang danh sách sản phẩm: lọc, sắp xếp, xem thêm không tải lại trang.
 *
 * Mọi nút đều là link thật. JS tải chính URL đó, lấy phần nội dung thay vào trang rồi đổi địa chỉ
 * bằng pushState: link vẫn chia sẻ được, nút Back chạy đúng, số đếm ở sidebar luôn khớp vì đều do
 * Woo tính. Lỗi thì chuyển trang như thường.
 */
( function ( $ ) {
	'use strict';

	var main = document.getElementById( 'main' );
	var sidebar = document.getElementById( 'secondary' );
	var calm = window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
	var live = document.createElement( 'div' );
	var controller = null;

	if ( ! main || ! document.querySelector( '.kio-archive-bar, .woocommerce-no-products-found' ) ) {
		return;
	}

	live.className = 'screen-reader-text';
	live.setAttribute( 'role', 'status' );
	document.body.append( live );

	function fetchPage( url ) {
		if ( controller ) {
			controller.abort();
		}
		controller = new AbortController();

		return fetch( url, { signal: controller.signal, credentials: 'same-origin' } )
			.then( function ( response ) {
				return response.ok || 404 === response.status ? response.text() : Promise.reject( new Error( 'HTTP ' + response.status ) );
			} )
			.then( function ( html ) {
				return new DOMParser().parseFromString( html, 'text/html' );
			} );
	}

	function swapSidebar( page ) {
		var next = page.getElementById( 'secondary' );

		if ( ! sidebar || ! next ) {
			return;
		}

		// Giữ nút đóng của ngăn kéo mobile, chỉ thay các widget. Woo có sẵn sự kiện dựng lại thanh trượt giá.
		sidebar.querySelectorAll( '.widget' ).forEach( function ( widget ) {
			widget.remove();
		} );
		sidebar.append.apply( sidebar, Array.prototype.slice.call( next.querySelectorAll( ':scope > .widget' ) ) );
		$( document.body ).trigger( 'init_price_filter' );
	}

	function animateCards( cards ) {
		if ( calm ) {
			return;
		}
		cards.forEach( function ( card, index ) {
			card.animate(
				[
					{ opacity: 0, transform: 'translateY(8px)' },
					{ opacity: 1, transform: 'none' },
				],
				{ duration: 250, delay: Math.min( index, 9 ) * 30, easing: 'cubic-bezier(.2,.8,.2,1)', fill: 'backwards' }
			);
		} );
	}

	function render( page ) {
		var next = page.getElementById( 'main' );
		var bar;
		var count;

		if ( ! next ) {
			throw new Error( 'no main' );
		}

		main.replaceChildren.apply( main, Array.prototype.slice.call( next.childNodes ) );
		swapSidebar( page );
		document.title = page.title;
		animateCards( Array.prototype.slice.call( main.querySelectorAll( 'ul.products > li' ) ) );

		count = main.querySelector( '.woocommerce-result-count, .woocommerce-no-products-found' );
		live.textContent = count ? count.textContent.trim() : '';

		// Link vừa bấm đã bị thay: chuyển focus về thanh công cụ để người dùng phím không mất chỗ.
		bar = main.querySelector( '.kio-archive-bar' );
		if ( bar && ! document.body.classList.contains( 'kio-filters-open' ) ) {
			bar.focus( { preventScroll: true } );
			if ( bar.getBoundingClientRect().top < 0 ) {
				bar.scrollIntoView( { behavior: calm ? 'auto' : 'smooth', block: 'start' } );
			}
		}
	}

	function load( url, push ) {
		main.classList.add( 'is-loading' );
		main.setAttribute( 'aria-busy', 'true' );

		fetchPage( url )
			.then( function ( page ) {
				render( page );
				if ( push ) {
					window.history.pushState( { kioArchive: true }, '', url );
				}
				main.classList.remove( 'is-loading' );
				main.removeAttribute( 'aria-busy' );
			} )
			.catch( function ( error ) {
				if ( 'AbortError' !== error.name ) {
					window.location.href = url;
				}
			} );
	}

	function loadMore( button ) {
		var list = main.querySelector( 'ul.products' );
		var total = Number( button.dataset.total );
		var box = button.parentElement;

		button.classList.add( 'is-loading' );
		button.setAttribute( 'aria-busy', 'true' );

		fetchPage( button.href )
			.then( function ( page ) {
				var added = Array.prototype.slice.call( page.querySelectorAll( '#main ul.products > li' ) );
				var next = page.querySelector( '.kio-load-more' );
				var oldPager = main.querySelector( '.woocommerce-pagination' );
				var newPager = page.querySelector( '#main .woocommerce-pagination' );
				var count = main.querySelector( '.woocommerce-result-count' );
				var firstLink;

				if ( ! added.length ) {
					throw new Error( 'empty' );
				}

				list.append.apply( list, added );
				animateCards( added );

				if ( next ) {
					next.querySelector( 'a' ).dataset.total = total;
					box.replaceWith( next );
				} else {
					box.remove();
				}
				if ( oldPager && newPager ) {
					oldPager.replaceWith( newPager );
				}
				if ( count ) {
					count.textContent = 'Hiển thị ' + list.children.length + ' trên ' + total + ' sản phẩm';
				}
				live.textContent = 'Đã tải thêm ' + added.length + ' sản phẩm';

				// Nút đã biến mất hoặc đổi: đưa focus tới sản phẩm đầu tiên vừa thêm.
				firstLink = added[ 0 ].querySelector( 'a' );
				if ( firstLink ) {
					firstLink.focus( { preventScroll: true } );
				}
			} )
			.catch( function ( error ) {
				if ( 'AbortError' !== error.name ) {
					window.location.href = button.href;
				}
			} );
	}

	document.addEventListener( 'click', function ( event ) {
		var link = event.target.closest( 'a' );

		if ( ! link || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
			return;
		}

		if ( link.matches( '.kio-load-more__button' ) ) {
			event.preventDefault();
			if ( ! link.classList.contains( 'is-loading' ) ) {
				loadMore( link );
			}
		} else if ( link.matches( '.kio-archive-link, #secondary .widget_layered_nav a' ) ) {
			event.preventDefault();
			load( link.href, true );
		}
	} );

	// Form của thanh trượt giá gửi bằng GET: dựng URL như trình duyệt rồi đi cùng đường.
	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		var url;

		if ( ! form.closest( '#secondary .widget_price_filter' ) ) {
			return;
		}

		event.preventDefault();
		url = new URL( form.action || window.location.href );
		url.search = new URLSearchParams( new FormData( form ) ).toString();
		load( url.href, true );
	} );

	window.history.replaceState( { kioArchive: true }, '', window.location.href );
	window.addEventListener( 'popstate', function ( event ) {
		if ( event.state && event.state.kioArchive ) {
			load( window.location.href, false );
		}
	} );
}( jQuery ) );
