/**
 * F5 — Đồng hồ đếm ngược của khối Flash Sale.
 *
 * Đọc data-ends-at (Unix timestamp, giây) từ phần tử đồng hồ mà PHP in ra, đếm ngược
 * mỗi giây. Khi hết giờ thì ẩn toàn bộ khối .kio-flash-sale.
 *
 * JS thuần, không cần build.
 */
(function () {
	'use strict';

	var block = document.querySelector('.kio-flash-sale');
	if (!block) {
		return;
	}

	var timer = block.querySelector('.kio-flash-sale__timer[data-ends-at]');
	if (!timer) {
		return;
	}

	var endsAt = parseInt(timer.getAttribute('data-ends-at'), 10);
	if (!endsAt) {
		return;
	}

	var units = {
		days: timer.querySelector('[data-unit="days"]'),
		hours: timer.querySelector('[data-unit="hours"]'),
		minutes: timer.querySelector('[data-unit="minutes"]'),
		seconds: timer.querySelector('[data-unit="seconds"]'),
	};

	var calm = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	function pad(n) {
		return n < 10 ? '0' + n : String(n);
	}

	// Chỉ đổi và lật khi số thật sự thay đổi. element.animate() chạy hiệu ứng mà không cần thêm
	// rồi gỡ lớp CSS; chỉ đụng transform + opacity.
	function show(element, value) {
		if (!element || element.textContent === String(value)) {
			return;
		}
		element.textContent = value;
		if (!calm && element.animate) {
			element.animate(
				[
					{ transform: 'rotateX(-80deg)', opacity: 0.3 },
					{ transform: 'rotateX(0)', opacity: 1 },
				],
				{ duration: 250, easing: 'cubic-bezier(.2,.8,.2,1)' }
			);
		}
	}

	function tick() {
		var diff = endsAt - Math.floor(Date.now() / 1000);

		if (diff <= 0) {
			block.style.display = 'none';
			clearInterval(timerId); // hết giờ: dừng hẳn, không chạy mỗi giây mãi mãi
			return;
		}

		var days = Math.floor(diff / 86400);
		var hours = Math.floor((diff % 86400) / 3600);
		var minutes = Math.floor((diff % 3600) / 60);
		var seconds = diff % 60;

		show(units.days, days);
		show(units.hours, pad(hours));
		show(units.minutes, pad(minutes));
		show(units.seconds, pad(seconds));
	}

	var timerId = setInterval(tick, 1000);
	tick();
})();
