/**
 * F4 — Nút "Xem thêm" của khối khuyến mãi trên trang sản phẩm.
 *
 * Khối .kio-promotions in sẵn 3 mục, phần còn lại nằm trong .kio-promotions__hidden
 * (bị CSS ẩn). Nút bấm bật/tắt lớp --open để hiện/ẩn và đổi chữ "Xem thêm"/"Thu gọn".
 *
 * JS thuần, không cần jQuery hay bước build.
 */
(function () {
	'use strict';

	var toggle = document.querySelector('.kio-promotions__toggle');
	if (!toggle) {
		return;
	}

	var block = toggle.closest('.kio-promotions');
	var hidden = block ? block.querySelector('.kio-promotions__hidden') : null;

	if (!hidden) {
		return;
	}

	toggle.addEventListener('click', function () {
		var opened = hidden.classList.toggle('kio-promotions__hidden--open');
		toggle.textContent = opened ? 'Thu gọn' : 'Xem thêm';
		toggle.setAttribute('aria-expanded', opened ? 'true' : 'false');
	});
})();
