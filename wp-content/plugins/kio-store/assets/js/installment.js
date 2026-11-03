/**
 * F2 — Cập nhật số tiền trả góp theo biến thể đang chọn trên trang sản phẩm.
 *
 * WooCommerce bắn sự kiện jQuery "found_variation" (kèm giá của biến thể) và
 * "reset_data" (khi khách bỏ chọn) trên form .variations_form. File này lắng nghe
 * hai sự kiện đó để đổi số tiền trong .kio-installment mà PHP đã in sẵn.
 *
 * Không có bước build: JS thuần + jQuery (đã có sẵn trong WordPress).
 */
(function () {
	'use strict';

	var form = document.querySelector('.variations_form');
	var line = document.querySelector('.kio-installment');

	// Chỉ chạy khi trang có form biến thể VÀ có dòng trả góp.
	if (!form || !line) {
		return;
	}

	var months = parseInt(line.getAttribute('data-months'), 10) || 6;
	var minPrice = parseInt(line.getAttribute('data-min-price'), 10) || 0;
	var amountEl = line.querySelector('.kio-installment__amount');
	var defaultText = amountEl ? amountEl.textContent : '';

	/** Định dạng số kiểu Việt Nam: 3832000 → "3.832.000 ₫". */
	function format(n) {
		return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ' ₫';
	}

	/** Tính và hiện số tiền theo một mức giá; dưới giá tối thiểu thì ẩn dòng. */
	function update(price) {
		if (price >= minPrice) {
			amountEl.textContent = format(Math.ceil(price / months / 1000) * 1000);
			line.style.display = '';
		} else {
			line.style.display = 'none';
		}
	}

	jQuery(form)
		.on('found_variation', function (event, variation) {
			update(parseInt(variation.display_price, 10) || 0);
		})
		.on('reset_data', function () {
			amountEl.textContent = defaultText;
			line.style.display = '';
		});
})();
