/**
 * Kio Child — main.js
 * JS nhỏ cho giao diện: header dính, mega menu trên mobile, bộ lọc, skeleton ảnh, phản hồi thêm vào giỏ.
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

	/**
	 * Ngăn kéo bộ lọc trên mobile: nút .kio-filter-toggle mở sidebar #secondary.
	 * Đóng bằng nút ×, bấm vào nền mờ, hoặc phím Esc. Desktop: sidebar hiển thị bình thường.
	 */
	function setupFilterDrawer() {
		var panel = document.getElementById( 'secondary' );
		if ( ! panel || ! document.querySelector( '.kio-filter-toggle' ) ) {
			return;
		}

		var close = document.createElement( 'button' );
		close.type = 'button';
		close.className = 'kio-filter-close';
		close.setAttribute( 'aria-label', 'Đóng bộ lọc' );
		close.textContent = '×';
		panel.insertBefore( close, panel.firstChild );

		var backdrop = document.createElement( 'div' );
		backdrop.className = 'kio-filter-backdrop';
		document.body.appendChild( backdrop );

		// Nút mở nằm trong vùng category-filter.js thay nội dung, nên luôn tìm lại và nghe ở document.
		function setOpen( open ) {
			var toggle = document.querySelector( '.kio-filter-toggle' );

			document.body.classList.toggle( 'kio-filters-open', open );
			if ( toggle ) {
				toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			}
			( open ? close : toggle || close ).focus();
		}

		document.addEventListener( 'click', function ( event ) {
			if ( event.target.closest( '.kio-filter-toggle' ) ) {
				setOpen( true );
			}
		} );
		close.addEventListener( 'click', function () {
			setOpen( false );
		} );
		backdrop.addEventListener( 'click', function () {
			setOpen( false );
		} );
		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && document.body.classList.contains( 'kio-filters-open' ) ) {
				setOpen( false );
			}
		} );
	}

	/**
	 * Tắt skeleton của khung ảnh khi ảnh đã tải xong (hoặc lỗi), để vệt sáng không chạy mãi.
	 * Sự kiện load không nổi bọt nhưng vẫn bắt được ở pha capture (tham số true), nên một listener
	 * ở document lo được cả ảnh lazy-load và thẻ thêm vào sau bằng AJAX.
	 */
	function setupCardMedia() {
		function done( img ) {
			var box = img.closest( '.kio-card-media' );
			if ( box ) {
				box.classList.add( 'is-loaded' );
			}
		}

		function onImage( event ) {
			if ( 'IMG' === event.target.tagName ) {
				done( event.target );
			}
		}

		document.addEventListener( 'load', onImage, true );
		document.addEventListener( 'error', onImage, true );
		document.querySelectorAll( '.kio-card-media img' ).forEach( function ( img ) {
			if ( img.complete ) {
				done( img );
			}
		} );
	}

	/**
	 * Header dính: cuộn xuống thì thanh danh mục trượt lên giấu sau phần chính, cuộn lên thì hiện lại.
	 * Chỉ đổi transform nên chiều cao trang không đổi. --kio-header-h cho các phần tử dính khác và
	 * cho scroll-padding biết chừa bao nhiêu chỗ.
	 */
	function setupStickyHeader() {
		var header = document.getElementById( 'masthead' );
		var nav = document.querySelector( '.kio-nav' );
		var mega = document.getElementById( 'kio-category-menu' );
		var last = window.scrollY;
		var ticking = false;
		var threshold = 0;

		if ( ! header || ! nav ) {
			return;
		}

		// Mốc "đã rời đầu trang" tính từ tài liệu, không dùng header.offsetTop: khi đang dính,
		// offsetTop tăng theo vị trí cuộn nên điều kiện lúc nào cũng đúng.
		function measure() {
			var utility = document.querySelector( '.kio-utility' );

			threshold = ( utility ? utility.offsetHeight : 0 ) + header.offsetHeight + nav.offsetHeight;
			document.documentElement.style.setProperty( '--kio-header-h', header.offsetHeight + 'px' );
			document.documentElement.style.setProperty( '--kio-nav-h', nav.offsetHeight + 'px' );
		}

		function update() {
			var y = window.scrollY;
			var menuOpen = nav.contains( document.activeElement ) || ( mega && mega.classList.contains( 'is-open' ) );

			ticking = false;
			if ( y <= threshold || menuOpen ) {
				header.classList.remove( 'is-compact' );
			} else if ( y > last + 4 ) {
				header.classList.add( 'is-compact' );
			} else if ( y < last - 4 ) {
				header.classList.remove( 'is-compact' );
			}
			if ( Math.abs( y - last ) > 4 ) {
				last = y;
			}
		}

		window.addEventListener( 'scroll', function () {
			if ( ! ticking ) {
				ticking = true;
				window.requestAnimationFrame( update );
			}
		}, { passive: true } );
		window.addEventListener( 'resize', measure );
		nav.addEventListener( 'focusin', function () {
			header.classList.remove( 'is-compact' );
		} );
		measure();
	}

	/**
	 * Sau khi thêm vào giỏ bằng AJAX: số giỏ nảy nhẹ và hiện thông báo nhỏ có link tới giỏ.
	 * added_to_cart là sự kiện jQuery của Woo, chạy sau khi các fragment (số giỏ) đã được thay.
	 */
	function setupCartFeedback() {
		var toast;
		var timer = 0;
		var calm = window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

		if ( ! window.jQuery || ! window.wc_add_to_cart_params ) {
			return;
		}

		function makeToast() {
			var text = document.createElement( 'span' );
			var link = document.createElement( 'a' );

			toast = document.createElement( 'div' );
			toast.className = 'kio-toast';
			toast.setAttribute( 'role', 'status' );
			text.className = 'kio-toast__text';
			link.className = 'kio-toast__link';
			link.href = window.wc_add_to_cart_params.cart_url;
			link.textContent = 'Xem giỏ hàng';
			toast.append( text, link );
			document.body.append( toast );

			toast.addEventListener( 'mouseenter', function () {
				window.clearTimeout( timer );
			} );
			toast.addEventListener( 'mouseleave', hideLater );
		}

		function hideLater() {
			window.clearTimeout( timer );
			timer = window.setTimeout( function () {
				toast.classList.remove( 'is-visible' );
			}, 3500 );
		}

		function show( text, isError ) {
			if ( ! toast ) {
				makeToast();
			}
			toast.querySelector( '.kio-toast__text' ).textContent = text;
			toast.classList.toggle( 'is-error', isError );
			toast.classList.add( 'is-visible' );
			hideLater();
		}

		// Tên kèm phiên bản khách đang chọn ở trang sản phẩm, vd. "Galaxy S26 512GB / Đen".
		function productLabel( button ) {
			var card = button && button[ 0 ] ? button[ 0 ].closest( '.product' ) : null;
			var title = card ? card.querySelector( '.summary .product_title, .woocommerce-loop-product__title' ) : null;
			var options = card ? card.querySelectorAll( '.summary .kio-chip.is-selected .kio-chip__label' ) : [];
			var chosen = Array.prototype.map.call( options, function ( option ) {
				return option.textContent;
			} );

			return title ? title.textContent + ( chosen.length ? ' ' + chosen.join( ' / ' ) : '' ) : '';
		}

		window.jQuery( document.body ).on( 'kio_toast', function ( event, text, isError ) {
			show( text, Boolean( isError ) );
		} );

		window.jQuery( document.body ).on( 'added_to_cart', function ( event, fragments, hash, button ) {
			var label = productLabel( button );

			show( label ? 'Đã thêm ' + label + ' vào giỏ' : 'Đã thêm vào giỏ', false );

			if ( ! calm ) {
				document.querySelectorAll( '.kio-cart-count' ).forEach( function ( count ) {
					count.animate(
						[ { transform: 'scale(1)' }, { transform: 'scale(1.5)' }, { transform: 'scale(1)' } ],
						{ duration: 400, easing: 'cubic-bezier(.2,.8,.2,1)' }
					);
				} );
			}
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		setupStickyHeader();
		setupCartFeedback();
		setupCardMedia();
		setupFilterDrawer();
		setupMegaMenu();
	} );
}() );
