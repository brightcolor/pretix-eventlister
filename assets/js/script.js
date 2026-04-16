document.addEventListener('DOMContentLoaded', function () {
	var containers = document.querySelectorAll('[data-pretix-events]');
	var enableTilt = window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches;

	if (!('IntersectionObserver' in window)) {
		containers.forEach(function (container) {
			container.classList.add('is-visible');
		});
		return;
	}

	var observer = new IntersectionObserver(function (entries) {
		entries.forEach(function (entry) {
			if (entry.isIntersecting) {
				entry.target.classList.add('is-visible');
				observer.unobserve(entry.target);
			}
		});
	}, {
		threshold: 0.15,
	});

	containers.forEach(function (container) {
		observer.observe(container);
	});

	if (!enableTilt) {
		return;
	}

	containers.forEach(function (container) {
		var cards = container.querySelectorAll('.pretix-eventlister__card');

		cards.forEach(function (card) {
			card.addEventListener('pointermove', function (event) {
				var rect = card.getBoundingClientRect();
				var rotateX = ((event.clientY - rect.top) / rect.height - 0.5) * -3;
				var rotateY = ((event.clientX - rect.left) / rect.width - 0.5) * 3;

				card.style.transform = 'translateY(-6px) rotateX(' + rotateX.toFixed(2) + 'deg) rotateY(' + rotateY.toFixed(2) + 'deg)';
			});

			card.addEventListener('pointerleave', function () {
				card.style.transform = '';
			});
		});
	});
});
