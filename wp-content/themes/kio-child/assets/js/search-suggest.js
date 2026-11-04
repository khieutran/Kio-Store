/**
 * Gợi ý sản phẩm khi gõ vào ô tìm kiếm.
 *
 * Dùng Store API có sẵn của Woo, không viết endpoint riêng. Form gốc giữ nguyên: Enter khi chưa chọn
 * gợi ý vẫn gửi form tới trang kết quả, tắt JS thì tìm kiếm chạy như cũ.
 *
 * Theo mẫu combobox của ARIA: focus ở lại ô nhập, aria-activedescendant báo mục đang chọn.
 */
( function () {
	'use strict';

	var MIN_LENGTH = 2;
	var DELAY = 250;
	var config = window.kioSearch;
	var counter = 0;

	if ( ! config ) {
		return;
	}

	function decode( text ) {
		var box = document.createElement( 'textarea' );
		box.innerHTML = text;
		return box.value;
	}

	function formatPrice( prices ) {
		var range = prices.price_range;
		var amount = Number( range ? range.min_amount : prices.price ).toLocaleString( 'vi-VN' ) + ' ₫';
		return range && range.min_amount !== range.max_amount ? 'Từ ' + amount : amount;
	}

	// Tên sản phẩm đi vào DOM bằng text node, không bằng innerHTML.
	function highlight( target, name, query ) {
		var index = name.toLowerCase().indexOf( query.toLowerCase() );
		var mark;

		if ( index < 0 ) {
			target.textContent = name;
			return;
		}

		mark = document.createElement( 'mark' );
		mark.textContent = name.slice( index, index + query.length );
		target.append( name.slice( 0, index ), mark, name.slice( index + query.length ) );
	}

	function setup( form ) {
		var input = form.querySelector( 'input[type="search"]' );
		var host = form.parentElement;
		var panel = document.createElement( 'div' );
		var list = document.createElement( 'ul' );
		var status = document.createElement( 'p' );
		var all = document.createElement( 'button' );
		var live = document.createElement( 'span' );
		var memory = {};
		var controller = null;
		var timer = 0;
		var active = -1;
		var id = 'kio-suggest-' + ( ++counter );

		if ( ! input || ! host ) {
			return;
		}

		panel.className = 'kio-suggest';
		panel.hidden = true;
		list.className = 'kio-suggest__list';
		list.id = id;
		list.setAttribute( 'role', 'listbox' );
		list.setAttribute( 'aria-label', 'Gợi ý sản phẩm' );
		status.className = 'kio-suggest__status';
		all.type = 'button';
		all.className = 'kio-suggest__all';
		live.className = 'screen-reader-text';
		live.setAttribute( 'role', 'status' );
		panel.append( status, list, all );
		host.classList.add( 'kio-suggest-host' );
		host.append( panel, live );

		input.setAttribute( 'role', 'combobox' );
		input.setAttribute( 'aria-autocomplete', 'list' );
		input.setAttribute( 'aria-expanded', 'false' );
		input.setAttribute( 'aria-controls', id );
		input.setAttribute( 'autocomplete', 'off' );

		function options() {
			return list.querySelectorAll( '[role="option"]' );
		}

		function setActive( index ) {
			var items = options();

			active = index;
			items.forEach( function ( item, position ) {
				item.setAttribute( 'aria-selected', position === index ? 'true' : 'false' );
			} );
			if ( items[ index ] ) {
				input.setAttribute( 'aria-activedescendant', items[ index ].id );
				items[ index ].scrollIntoView( { block: 'nearest' } );
			} else {
				input.removeAttribute( 'aria-activedescendant' );
			}
		}

		function close() {
			panel.hidden = true;
			input.setAttribute( 'aria-expanded', 'false' );
			setActive( -1 );
		}

		function open() {
			panel.hidden = false;
			input.setAttribute( 'aria-expanded', 'true' );
		}

		function showStatus( text ) {
			list.replaceChildren();
			status.textContent = text;
			status.hidden = false;
			all.hidden = true;
			open();
		}

		function render( products, query ) {
			list.replaceChildren();
			active = -1;
			input.removeAttribute( 'aria-activedescendant' );

			if ( ! products.length ) {
				showStatus( 'Không tìm thấy sản phẩm cho “' + query + '”' );
				live.textContent = 'Không có gợi ý';
				return;
			}

			products.forEach( function ( product, index ) {
				var item = document.createElement( 'li' );
				var link = document.createElement( 'a' );
				var image = document.createElement( 'img' );
				var text = document.createElement( 'span' );
				var name = document.createElement( 'span' );
				var price = document.createElement( 'span' );

				item.id = id + '-' + index;
				item.setAttribute( 'role', 'option' );
				item.setAttribute( 'aria-selected', 'false' );
				link.href = product.permalink;
				link.className = 'kio-suggest__item';
				link.tabIndex = -1;
				image.alt = '';
				image.width = 48;
				image.height = 48;
				if ( product.images[ 0 ] ) {
					image.src = product.images[ 0 ].thumbnail;
				}
				text.className = 'kio-suggest__text';
				name.className = 'kio-suggest__name';
				price.className = 'kio-suggest__price';
				highlight( name, decode( product.name ), query );
				price.textContent = formatPrice( product.prices );
				text.append( name, price );
				link.append( image, text );
				item.append( link );
				list.append( item );
			} );

			status.hidden = true;
			all.hidden = false;
			all.textContent = 'Xem tất cả kết quả cho “' + query + '”';
			live.textContent = products.length + ' gợi ý';
			open();
		}

		function search( query ) {
			var url;

			if ( controller ) {
				controller.abort();
			}

			if ( memory[ query ] ) {
				render( memory[ query ], query );
				return;
			}

			url = new URL( config.endpoint );
			url.searchParams.set( 'search', query );
			url.searchParams.set( 'per_page', '5' );
			url.searchParams.set( 'orderby', 'popularity' );
			url.searchParams.set( 'order', 'desc' );
			url.searchParams.set( '_fields', 'id,name,permalink,images,prices' );

			controller = new AbortController();
			showStatus( 'Đang tìm…' );

			fetch( url, { signal: controller.signal } )
				.then( function ( response ) {
					return response.ok ? response.json() : Promise.reject( new Error( 'HTTP ' + response.status ) );
				} )
				.then( function ( products ) {
					memory[ query ] = products;
					render( products, query );
				} )
				.catch( function ( error ) {
					// Gợi ý chỉ là phần thêm: lỗi thì đóng lại, khách vẫn Enter để tìm bình thường.
					if ( 'AbortError' !== error.name ) {
						close();
					}
				} );
		}

		input.addEventListener( 'input', function () {
			var query = input.value.trim();

			window.clearTimeout( timer );
			if ( query.length < MIN_LENGTH ) {
				if ( controller ) {
					controller.abort();
				}
				close();
				return;
			}
			timer = window.setTimeout( function () {
				search( query );
			}, DELAY );
		} );

		input.addEventListener( 'keydown', function ( event ) {
			var count = options().length;
			var link;

			if ( 'Escape' === event.key && ! panel.hidden ) {
				event.preventDefault();
				close();
			} else if ( panel.hidden || ! count ) {
				return;
			} else if ( 'ArrowDown' === event.key ) {
				event.preventDefault();
				setActive( ( active + 1 ) % count );
			} else if ( 'ArrowUp' === event.key ) {
				event.preventDefault();
				setActive( ( active - 1 + count ) % count );
			} else if ( 'Enter' === event.key && active >= 0 ) {
				event.preventDefault();
				link = options()[ active ].querySelector( 'a' );
				window.location.href = link.href;
			}
		} );

		all.addEventListener( 'click', function () {
			form.submit();
		} );

		document.addEventListener( 'click', function ( event ) {
			if ( ! host.contains( event.target ) ) {
				close();
			}
		} );

		host.addEventListener( 'focusout', function ( event ) {
			if ( event.relatedTarget && ! host.contains( event.relatedTarget ) ) {
				close();
			}
		} );
	}

	document.querySelectorAll( 'form.woocommerce-product-search' ).forEach( setup );
}() );
