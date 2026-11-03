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

	function pad(n) {
		return n < 10 ? '0' + n : String(n);
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

		if (units.days) {
			units.days.textContent = days;
		}
		if (units.hours) {
			units.hours.textContent = pad(hours);
		}
		if (units.minutes) {
			units.minutes.textContent = pad(minutes);
		}
		if (units.seconds) {
			units.seconds.textContent = pad(seconds);
		}
	}

	var timerId = setInterval(tick, 1000);
	tick();
})();
