/**
 * Xem 3D trên ảnh sản phẩm. Trang hiện ảnh chụp từ mô hình trước, khách không thao tác mới tải model-viewer
 * rồi chuyển sang máy đang xoay một vòng, nên không làm chậm lần hiện ảnh đầu tiên. Khung 3D chỉ phủ ảnh
 * chính, dải ảnh nhỏ vẫn bấm được. Mobile, tiết kiệm dữ liệu, giảm chuyển động, không có WebGL thì giữ ảnh và
 * nút bấm.
 */
( function ( $ ) {
	'use strict';

	var data = document.querySelector( '.kio-model-data' );
	var gallery = document.querySelector( '.woocommerce-product-gallery' );

	if ( ! data || ! gallery ) {
		return;
	}

	var colors = JSON.parse( data.dataset.colors || '{}' );
	var currentColor = '';
	var open = document.createElement( 'button' );
	var stage = null;
	var viewer = null;

	function canAutoplay() {
		var connection = navigator.connection;

		// Chỉ tự chạy trên desktop: đo trên mobile điểm Lighthouse tụt 98 xuống 66 (TBT 30ms lên 2.062ms)
		// vì thư viện 1 MB chiếm hết CPU điện thoại. Mobile dùng nút Xem 360°.
		return '1' === data.dataset.autoplay &&
			window.matchMedia( '(min-width: 1024px)' ).matches &&
			! ( connection && connection.saveData ) &&
			! window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches &&
			!! document.createElement( 'canvas' ).getContext( 'webgl2' );
	}

	function applyColor() {
		var hex = colors[ currentColor ];

		if ( ! viewer || ! viewer.model || ! hex ) {
			return;
		}
		[ 'back', 'frame' ].forEach( function ( name ) {
			var material = viewer.model.getMaterialByName( name );
			if ( material ) {
				material.pbrMetallicRoughness.setBaseColorFactor( hex );
			}
		} );
	}

	function show() {
		stage.hidden = false;
		open.hidden = true;
		gallery.classList.add( 'kio-model-active' );
	}

	function hide() {
		if ( stage ) {
			stage.hidden = true;
		}
		open.hidden = false;
		gallery.classList.remove( 'kio-model-active' );
	}

	function build() {
		var closeButton = document.createElement( 'button' );
		var note = document.createElement( 'p' );

		stage = document.createElement( 'div' );
		stage.className = 'kio-model-stage';

		viewer = document.createElement( 'model-viewer' );
		viewer.setAttribute( 'src', data.dataset.model );
		viewer.setAttribute( 'alt', 'Mô hình 3D minh hoạ ' + data.dataset.name );
		viewer.setAttribute( 'camera-controls', '' );
		viewer.setAttribute( 'auto-rotate', '' );
		viewer.setAttribute( 'auto-rotate-delay', '0' );
		viewer.setAttribute( 'rotation-per-second', '30deg' );
		viewer.setAttribute( 'interaction-prompt', 'none' );
		viewer.setAttribute( 'shadow-intensity', '0.6' );
		viewer.setAttribute( 'exposure', '1.1' );
		viewer.setAttribute( 'camera-orbit', data.dataset.orbit );
		viewer.setAttribute( 'touch-action', 'pan-y' );

		// Hiện khi mô hình đã vẽ xong, chuyển mờ từ ảnh sang để không bị giật.
		viewer.addEventListener( 'load', function () {
			applyColor();
			stage.classList.add( 'is-ready' );

			// Xoay một vòng (12 giây) rồi dừng: đứng yên thì model-viewer không vẽ lại, card đồ hoạ được nghỉ.
			window.setTimeout( function () {
				viewer.removeAttribute( 'auto-rotate' );
			}, 12000 );
		} );

		// Khách đã tự kéo thì thôi tự xoay, để họ xem đúng góc mình chọn.
		viewer.addEventListener( 'camera-change', function ( event ) {
			if ( 'user-interaction' === event.detail.source ) {
				viewer.removeAttribute( 'auto-rotate' );
			}
		} );

		closeButton.type = 'button';
		closeButton.className = 'kio-model-stage__close';
		closeButton.textContent = 'Xem ảnh';
		closeButton.addEventListener( 'click', function () {
			hide();
			open.focus();
		} );

		note.className = 'kio-model-stage__note';
		note.textContent = 'Mô hình minh hoạ. Kéo để xoay.';

		stage.append( viewer, closeButton, note );

		// Woo dựng .flex-viewport sau sự kiện load; gallery một ảnh thì không có.
		( gallery.querySelector( '.flex-viewport' ) || gallery ).append( stage );
	}

	function start() {
		return import( data.dataset.viewer ).then( function () {
			if ( ! stage ) {
				build();
			}
			show();
		} );
	}

	function setup() {
		open.type = 'button';
		open.className = 'kio-model-open';
		open.textContent = 'Xem 360°';
		gallery.append( open );

		open.addEventListener( 'click', function () {
			open.disabled = true;
			open.textContent = 'Đang tải…';
			start().then( function () {
				open.disabled = false;
				open.textContent = 'Xem 360°';
			} ).catch( function () {
				open.disabled = false;
				open.textContent = 'Không tải được, bấm để thử lại';
			} );
		} );

		gallery.addEventListener( 'click', function ( event ) {
			if ( event.target.closest( '.flex-control-thumbs' ) ) {
				hide();
			}
		} );

		if ( canAutoplay() ) {
			startWhenQuiet();
		}
	}

	// Lần vẽ đầu tiên biên dịch shader, có thể chặn trang tới 800ms. Chỉ chạy khi khung ảnh đang trên màn hình
	// và khách không thao tác, để không đúng lúc họ đang cuộn hay bấm.
	function startWhenQuiet() {
		var events = [ 'scroll', 'pointerdown', 'keydown', 'touchstart', 'wheel' ];
		var visible = false;
		var timer = 0;
		var observer;

		function stop() {
			events.forEach( function ( name ) {
				window.removeEventListener( name, wait );
			} );
			observer.disconnect();
		}

		function wait() {
			window.clearTimeout( timer );
			if ( visible ) {
				timer = window.setTimeout( function () {
					stop();
					start().catch( function () {} );
				}, 1200 );
			}
		}

		observer = new IntersectionObserver( function ( entries ) {
			visible = entries[ 0 ].isIntersecting;
			wait();
		} );
		observer.observe( gallery );

		events.forEach( function ( name ) {
			window.addEventListener( name, wait, { passive: true } );
		} );
	}

	if ( 'complete' === document.readyState ) {
		setup();
	} else {
		window.addEventListener( 'load', setup );
	}

	$( 'form.variations_form' ).on( 'found_variation', function ( event, variation ) {
		currentColor = variation.attributes[ 'attribute_pa_mau-sac' ] || currentColor;
		applyColor();
	} );
}( jQuery ) );
