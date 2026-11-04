/**
 * Trang chủ: banner tự chạy và hiệu ứng hiện khi cuộn tới.
 */
( function () {
	'use strict';

	var calm = window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
	var HERO_DELAY = 5000;

	/**
	 * Banner. Cuộn và dừng đúng slide do CSS làm; JS thêm chấm vị trí, nút dừng và phần tự chạy.
	 * Các nút do JS tạo vì không có JS thì chúng không có tác dụng.
	 * Dừng khi rê chuột, khi focus bên trong, khi tab bị ẩn; không tự chạy khi khách chọn giảm
	 * chuyển động. Nút dừng là yêu cầu của WCAG 2.2.2 cho nội dung tự chuyển động.
	 */
	function setupHero( hero ) {
		var track = hero.querySelector( '.kio-hero__track' );
		var slides = hero.querySelectorAll( '.kio-hero__slide' );
		var prev = hero.querySelector( '.kio-hero__prev' );
		var next = hero.querySelector( '.kio-hero__next' );
		var controls = document.createElement( 'div' );
		var dots = document.createElement( 'div' );
		var toggle = document.createElement( 'button' );
		var paused = calm;
		var hovering = false;
		var focused = false;

		if ( ! track || slides.length < 2 ) {
			return;
		}

		function current() {
			return Math.round( track.scrollLeft / track.clientWidth );
		}

		function goTo( index ) {
			var total = slides.length;
			track.scrollTo( {
				left: slides[ ( index + total ) % total ].offsetLeft,
				behavior: calm ? 'auto' : 'smooth',
			} );
		}

		function showToggle() {
			toggle.classList.toggle( 'is-paused', paused );
			toggle.setAttribute( 'aria-label', paused ? 'Chạy banner' : 'Dừng banner' );
		}

		function showDot() {
			var active = current();
			Array.prototype.forEach.call( dots.children, function ( dot, index ) {
				if ( index === active ) {
					dot.setAttribute( 'aria-current', 'true' );
				} else {
					dot.removeAttribute( 'aria-current' );
				}
			} );
		}

		dots.className = 'kio-hero__dots';
		slides.forEach( function ( slide, index ) {
			var dot = document.createElement( 'button' );
			dot.type = 'button';
			dot.className = 'kio-hero__dot';
			dot.setAttribute( 'aria-label', 'Banner ' + ( index + 1 ) );
			dot.addEventListener( 'click', function () {
				goTo( index );
			} );
			dots.append( dot );
		} );

		toggle.type = 'button';
		toggle.className = 'kio-hero__toggle';
		toggle.addEventListener( 'click', function () {
			paused = ! paused;
			showToggle();
		} );

		if ( prev ) {
			prev.addEventListener( 'click', function () {
				goTo( current() - 1 );
			} );
		}
		if ( next ) {
			next.addEventListener( 'click', function () {
				goTo( current() + 1 );
			} );
		}

		track.addEventListener( 'scroll', showDot, { passive: true } );
		hero.addEventListener( 'mouseenter', function () {
			hovering = true;
		} );
		hero.addEventListener( 'mouseleave', function () {
			hovering = false;
		} );
		hero.addEventListener( 'focusin', function () {
			focused = true;
		} );
		hero.addEventListener( 'focusout', function () {
			focused = false;
		} );

		controls.className = 'kio-hero__controls';
		controls.append( dots, toggle );
		hero.append( controls );
		showToggle();
		showDot();

		window.setInterval( function () {
			if ( ! paused && ! hovering && ! focused && ! document.hidden ) {
				goTo( current() + 1 );
			}
		}, HERO_DELAY );
	}

	/**
	 * Hiện dần khi cuộn tới. Lớp ẩn do JS thêm và chỉ thêm cho phần đang ở dưới màn hình: không có
	 * JS thì mọi thứ vẫn hiện, phần đầu trang không bị nháy. Chạy một lần rồi gỡ lớp để hover của
	 * thẻ sản phẩm dùng lại được transform.
	 */
	function setupReveal() {
		var targets = [];

		if ( calm || ! ( 'IntersectionObserver' in window ) ) {
			return;
		}

		function add( element, order ) {
			if ( element.getBoundingClientRect().top > window.innerHeight ) {
				element.classList.add( 'kio-reveal' );
				element.style.setProperty( '--kio-reveal-delay', Math.min( order, 5 ) * 60 + 'ms' );
				targets.push( element );
			}
		}

		function addList( list ) {
			// Danh sách cuộn ngang: thẻ ngoài khung nhìn chưa từng "giao" với màn hình, nên hiện cả khối.
			if ( list.scrollWidth > list.clientWidth + 1 ) {
				add( list, 0 );
				return;
			}
			Array.prototype.forEach.call( list.children, add );
		}

		document.querySelectorAll( '.kio-section-title, .kio-strip__head, .kio-strip__chips, .kio-flash-sale, .kio-reels' ).forEach( function ( element ) {
			add( element, 0 );
		} );
		document.querySelectorAll( '.kio-cats, .kio-brands, .kio-commit, .entry-content ul.products' ).forEach( function ( list ) {
			if ( ! list.closest( '.kio-flash-sale' ) ) {
				addList( list );
			}
		} );

		var observer = new IntersectionObserver(
			function ( entries ) {
				entries.forEach( function ( entry ) {
					var element = entry.target;
					if ( ! entry.isIntersecting ) {
						return;
					}
					observer.unobserve( element );
					element.classList.add( 'is-in' );
					window.setTimeout( function () {
						element.classList.remove( 'kio-reveal', 'is-in' );
						element.style.removeProperty( '--kio-reveal-delay' );
					}, 800 );
				} );
			},
			{ rootMargin: '0px 0px -5% 0px' }
		);

		targets.forEach( function ( element ) {
			observer.observe( element );
		} );
	}

	document.querySelectorAll( '.kio-hero' ).forEach( setupHero );
	setupReveal();
}() );
