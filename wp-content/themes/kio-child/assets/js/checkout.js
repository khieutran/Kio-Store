/**
 * Trang thanh toán: gom hộp lỗi thành một dòng.
 *
 * Woo liệt kê mọi ô thiếu trong hộp lỗi, rồi in lại từng lỗi ngay dưới ô. Khi tất cả đều là lỗi của ô
 * nhập (li có data-id), hộp chỉ cần nói số mục và dẫn tới ô đầu tiên. Lỗi khác (hết hàng, thanh toán…)
 * không có ô để in lại nên giữ nguyên văn.
 */
( function ( $ ) {
	'use strict';

	function focusField( id ) {
		var field = document.getElementById( id );
		var row = field ? field.closest( '.form-row' ) : null;
		var visible;

		if ( ! row ) {
			return;
		}

		// Tỉnh/Thành phố dùng select2: <select> thật bị ẩn, phải focus phần tử khách nhìn thấy.
		visible = row.querySelector( '.select2-selection' ) || field;
		row.scrollIntoView( { block: 'center' } );
		visible.focus( { preventScroll: true } );
	}

	$( document.body ).on( 'checkout_error', function () {
		var box = document.querySelector( '.woocommerce-NoticeGroup-checkout .woocommerce-error' );
		var items = box ? Array.prototype.slice.call( box.children ) : [];
		var fields = items.filter( function ( item ) {
			return item.dataset.id;
		} );
		var link;

		if ( items.length < 2 || fields.length !== items.length ) {
			return;
		}

		link = document.createElement( 'a' );
		link.href = '#' + fields[ 0 ].dataset.id;
		link.textContent = 'Vui lòng kiểm tra ' + fields.length + ' mục được đánh dấu';

		fields[ 0 ].replaceChildren( link );
		fields.slice( 1 ).forEach( function ( item ) {
			item.remove();
		} );
	} );

	$( document.body ).on( 'click', '.woocommerce-NoticeGroup-checkout .woocommerce-error a[href^="#"]', function ( event ) {
		event.preventDefault();
		focusField( this.getAttribute( 'href' ).slice( 1 ) );
	} );
}( jQuery ) );
