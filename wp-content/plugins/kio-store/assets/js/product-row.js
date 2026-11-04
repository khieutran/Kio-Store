/**
 * F6 — Chip thương hiệu của dải sản phẩm: đổi sản phẩm ngay trong dải, không tải lại trang.
 *
 * Chip vẫn là link thật: tắt JS, Ctrl/Cmd + bấm, hoặc yêu cầu bị lỗi thì khách tới trang danh mục
 * đã lọc như thường. JS chỉ chặn cú bấm thường, lấy HTML thẻ từ wc-ajax rồi thay danh sách.
 */
( function () {
	'use strict';

	var calm = window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	function setupRow( row ) {
		var chips = row.querySelector( '.kio-strip__chips' );
		var more = row.querySelector( '.kio-strip__more' );
		var cache = {};
		var controller = null;

		if ( ! chips ) {
			return;
		}

		function currentList() {
			return row.querySelector( 'ul.products' );
		}

		// Bản HTML để bấm lại "Tất cả". Bỏ lớp hiệu ứng cuộn của home.js, nếu không thẻ gắn lại sẽ ẩn mãi.
		function snapshot() {
			var copy = currentList().cloneNode( true );
			copy.querySelectorAll( '.kio-reveal' ).forEach( function ( item ) {
				item.classList.remove( 'kio-reveal', 'is-in' );
				item.style.removeProperty( '--kio-reveal-delay' );
			} );
			copy.classList.remove( 'kio-reveal', 'is-in' );
			return copy.outerHTML;
		}

		function select( chip ) {
			chips.querySelectorAll( '.kio-strip__chip' ).forEach( function ( item ) {
				item.removeAttribute( 'aria-current' );
			} );
			chip.setAttribute( 'aria-current', 'true' );
			if ( more ) {
				more.href = chip.href;
			}
		}

		function show( html ) {
			var template = document.createElement( 'template' );
			var next;

			template.innerHTML = html;
			next = template.content.querySelector( 'ul.products' );
			if ( ! next ) {
				return false;
			}

			currentList().replaceWith( next );

			if ( ! calm && next.animate ) {
				Array.prototype.forEach.call( next.children, function ( item, index ) {
					item.animate(
						[
							{ opacity: 0, transform: 'translateY(8px)' },
							{ opacity: 1, transform: 'none' },
						],
						{ duration: 250, delay: index * 40, easing: 'cubic-bezier(.2,.8,.2,1)', fill: 'backwards' }
					);
				} );
			}
			return true;
		}

		function load( chip ) {
			var brand = chip.dataset.brand;
			var url = new URL( row.dataset.endpoint, window.location.href );

			// Bấm nhanh sang chip khác: huỷ yêu cầu cũ để kết quả về sau không đè lựa chọn mới.
			if ( controller ) {
				controller.abort();
				controller = null;
			}

			select( chip );
			row.classList.remove( 'is-loading' );
			row.removeAttribute( 'aria-busy' );

			if ( cache[ brand ] ) {
				show( cache[ brand ] );
				return;
			}

			[ 'category', 'limit', 'orderby', 'columns' ].forEach( function ( key ) {
				url.searchParams.set( key, row.dataset[ key ] );
			} );
			url.searchParams.set( 'brand', brand );

			controller = new AbortController();
			row.classList.add( 'is-loading' );
			row.setAttribute( 'aria-busy', 'true' );

			fetch( url, { signal: controller.signal, credentials: 'same-origin' } )
				.then( function ( response ) {
					return response.ok ? response.json() : Promise.reject( new Error( 'HTTP ' + response.status ) );
				} )
				.then( function ( body ) {
					if ( ! body.success || ! show( body.data.html ) ) {
						throw new Error( 'empty' );
					}
					cache[ brand ] = body.data.html;
					row.classList.remove( 'is-loading' );
					row.removeAttribute( 'aria-busy' );
				} )
				.catch( function ( error ) {
					if ( 'AbortError' !== error.name ) {
						window.location.href = chip.href;
					}
				} );
		}

		chips.addEventListener( 'click', function ( event ) {
			var chip = event.target.closest( '.kio-strip__chip' );

			if ( ! chip || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
				return;
			}

			event.preventDefault();
			if ( chip.hasAttribute( 'aria-current' ) ) {
				return;
			}

			var active = chips.querySelector( '[aria-current]' );
			if ( active && ! cache[ active.dataset.brand ] && ! row.classList.contains( 'is-loading' ) ) {
				cache[ active.dataset.brand ] = snapshot();
			}

			load( chip );
		} );
	}

	document.querySelectorAll( '.kio-product-row[data-endpoint]' ).forEach( setupRow );
}() );
