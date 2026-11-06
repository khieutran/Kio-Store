/**
 * Bấm "Xem tất cả thông số": bảng đầy đủ thay bảng rút gọn ngay tại chỗ, bấm lần nữa thì thu lại.
 */
( function () {
	'use strict';

	var section = document.querySelector( '.kio-spec-table' );
	var button = section && section.querySelector( '.kio-spec-table__more' );

	if ( ! button ) {
		return;
	}

	var full = section.querySelector( '.kio-spec-table__full' );
	var summary = section.querySelector( '.kio-spec-table__summary' );
	var moreText = button.textContent;

	button.addEventListener( 'click', function () {
		var expand = full.hidden;

		full.hidden = ! expand;
		summary.hidden = expand;
		button.setAttribute( 'aria-expanded', String( expand ) );
		button.textContent = expand ? button.dataset.less : moreText;

		// Thu gọn làm trang ngắn lại đột ngột: đưa về đầu mục, nếu không khách bị văng xuống phần dưới.
		if ( ! expand ) {
			section.scrollIntoView( { block: 'start' } );
		}
	} );
}() );
