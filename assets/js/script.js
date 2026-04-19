document.addEventListener('DOMContentLoaded', function () {
	var containers = document.querySelectorAll('[data-pretix-events]');
	var canHover = window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches;

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

	containers.forEach(function (container) {
		var shell = container.closest('.pretix-eventlister-shell') || document;
		var cards = container.querySelectorAll('.pretix-eventlister__card');
		var enableTilt = canHover && container.dataset && container.dataset.tilt === '1';
		var enableLoadMore = container.dataset && container.dataset.loadMore === '1';
		var pageSize = parseInt(container.dataset && container.dataset.pageSize ? container.dataset.pageSize : '0', 10);
		var composerConfig = null;
		if (container.dataset && container.dataset.composerConfig) {
			try {
				composerConfig = JSON.parse(container.dataset.composerConfig);
			} catch (error) {
				composerConfig = null;
			}
		}
		if (!pageSize || Number.isNaN(pageSize) || pageSize < 1) {
			pageSize = 9;
		}

		function applyComposerStyles(el, styles) {
			if (!el || !styles) {
				return;
			}
			el.style.padding = styles.padding || '';
			el.style.margin = styles.margin || '';
			el.style.color = styles.text_color || '';
			el.style.background = styles.background_color || '';
			el.style.borderColor = styles.border_color || '';
			el.style.borderWidth = styles.border_width || '';
			el.style.borderStyle = styles.border_width ? 'solid' : '';
			el.style.borderRadius = styles.border_radius || '';
			el.style.fontFamily = styles.font_family || '';
			el.style.fontSize = styles.font_size || '';
			el.style.fontWeight = styles.font_weight || '';
			el.style.lineHeight = styles.line_height || '';
			el.style.letterSpacing = styles.letter_spacing || '';
			el.style.textAlign = styles.text_align || '';
			el.style.boxShadow = styles.shadow || '';
			el.hidden = styles.visible === 0 || styles.visible === '0';
		}

		function applyComposerLayout() {
			if (!composerConfig || !composerConfig.enabled || !Array.isArray(composerConfig.layout)) {
				return;
			}
			cards.forEach(function (card) {
				var content = card.querySelector('.pretix-eventlister__content');
				if (!content) {
					return;
				}

				var blocks = {};
				content.querySelectorAll('[data-composer-block]').forEach(function (blockEl) {
					var blockKey = blockEl.getAttribute('data-composer-block');
					if (!blockKey) {
						return;
					}
					blocks[blockKey] = blockEl;
					blockEl.hidden = false;
					blockEl.style.padding = '';
					blockEl.style.margin = '';
					blockEl.style.color = '';
					blockEl.style.background = '';
					blockEl.style.borderColor = '';
					blockEl.style.borderWidth = '';
					blockEl.style.borderStyle = '';
					blockEl.style.borderRadius = '';
					blockEl.style.fontFamily = '';
					blockEl.style.fontSize = '';
					blockEl.style.fontWeight = '';
					blockEl.style.lineHeight = '';
					blockEl.style.letterSpacing = '';
					blockEl.style.textAlign = '';
					blockEl.style.boxShadow = '';
				});

				composerConfig.layout.forEach(function (blockKey) {
					var blockEl = blocks[blockKey];
					if (!blockEl) {
						return;
					}
					content.appendChild(blockEl);
					var styles = composerConfig.styles && composerConfig.styles[blockKey] ? composerConfig.styles[blockKey] : null;
					applyComposerStyles(blockEl, styles);
				});
			});
		}

		applyComposerLayout();

		function getVisibleCards() {
			return Array.prototype.filter.call(cards, function (card) {
				return !card.hidden;
			});
		}

		function applyPagination(reset) {
			if (!enableLoadMore) {
				return;
			}

			var visibleCards = getVisibleCards();
			var currentlyShown = reset ? 0 : visibleCards.filter(function (card) {
				return !card.classList.contains('pretix-eventlister__card--paged');
			}).length;

			var limit = reset ? pageSize : Math.min(visibleCards.length, currentlyShown + pageSize);

			visibleCards.forEach(function (card, idx) {
				card.classList.toggle('pretix-eventlister__card--paged', idx >= limit);
			});

			var loadMoreBtn = shell.querySelector('[data-pretix-load-more]');
			if (loadMoreBtn) {
				var remaining = visibleCards.filter(function (card) {
					return card.classList.contains('pretix-eventlister__card--paged');
				}).length;
				loadMoreBtn.disabled = remaining === 0;
				loadMoreBtn.style.display = remaining === 0 ? 'none' : '';
			}
		}

		if (enableLoadMore) {
			applyPagination(true);
			var loadMoreBtn = shell.querySelector('[data-pretix-load-more]');
			if (loadMoreBtn) {
				loadMoreBtn.addEventListener('click', function () {
					applyPagination(false);
				});
			}
		}

		var filters = shell.querySelector('[data-pretix-filters]');
		if (filters) {
			var organizerSelect = filters.querySelector('[data-filter="organizer"]');
			var timeframeSelect = filters.querySelector('[data-filter="timeframe"]');
			var locationInput = filters.querySelector('[data-filter="location"]');
			var searchInput = filters.querySelector('[data-filter="search"]');
			var resetBtn = filters.querySelector('[data-filter-reset]');
			var countEl = filters.querySelector('[data-filter-count]');

			function normalizeText(text) {
				return (text || '').toString().trim().toLowerCase();
			}

			function matches(card) {
				var organizer = organizerSelect ? organizerSelect.value : '';
				var timeframe = timeframeSelect ? timeframeSelect.value : '';
				var location = normalizeText(locationInput ? locationInput.value : '');
				var query = normalizeText(searchInput ? searchInput.value : '');

				if (organizer && card.dataset.organizer !== organizer) {
					return false;
				}

				if (timeframe) {
					var days = parseInt(card.dataset.daysUntil || '', 10);
					if (Number.isNaN(days)) {
						return false;
					}

					if (timeframe === 'today' && days !== 0) {
						return false;
					}

					var range = parseInt(timeframe, 10);
					if (!Number.isNaN(range) && (days < 0 || days > range)) {
						return false;
					}
				}

				if (location) {
					var hay = (card.dataset.location || '').toString();
					if (hay.indexOf(location) === -1) {
						return false;
					}
				}

				if (query) {
					var blob = (card.dataset.search || '').toString();
					if (blob.indexOf(query) === -1) {
						return false;
					}
				}

				return true;
			}

			function applyFilters() {
				var visible = 0;
				cards.forEach(function (card) {
					var ok = matches(card);
					card.hidden = !ok;
					if (ok) {
						visible += 1;
					}
				});

				if (countEl) {
					countEl.textContent = visible ? (visible + ' Events') : '0 Events';
				}

				applyPagination(true);
			}

			[organizerSelect, timeframeSelect, locationInput, searchInput].forEach(function (el) {
				if (!el) {
					return;
				}
				el.addEventListener('input', applyFilters);
				el.addEventListener('change', applyFilters);
			});

			if (resetBtn) {
				resetBtn.addEventListener('click', function () {
					if (organizerSelect) organizerSelect.value = '';
					if (timeframeSelect) timeframeSelect.value = '';
					if (locationInput) locationInput.value = '';
					if (searchInput) searchInput.value = '';
					applyFilters();
				});
			}

			applyFilters();
		}

		var modal = shell.querySelector('[data-pretix-modal]');
		if (modal) {
			var modalBody = modal.querySelector('[data-pretix-modal-body]');
			var closeEls = modal.querySelectorAll('[data-pretix-modal-close]');
			var lastFocus = null;

			function closeModal() {
				modal.hidden = true;
				if (modalBody) {
					modalBody.innerHTML = '';
				}
				if (lastFocus && lastFocus.focus) {
					lastFocus.focus();
				}
			}

			function openModal(fromButton) {
				lastFocus = fromButton;
				var card = fromButton.closest('.pretix-eventlister__card');
				if (!card) {
					return;
				}
				var tpl = card.querySelector('.pretix-eventlister__modal-template');
				if (!tpl || !modalBody) {
					return;
				}
				modalBody.innerHTML = tpl.innerHTML;
				modal.hidden = false;
				var closeBtn = modal.querySelector('.pretix-eventlister__modal-close');
				if (closeBtn) {
					closeBtn.focus();
				}
			}

			shell.addEventListener('click', function (event) {
				var openBtn = event.target.closest('[data-pretix-modal-open]');
				if (openBtn) {
					event.preventDefault();
					openModal(openBtn);
				}
			});

			closeEls.forEach(function (el) {
				el.addEventListener('click', function () {
					closeModal();
				});
			});

			document.addEventListener('keydown', function (event) {
				if (modal.hidden) {
					return;
				}
				if (event.key === 'Escape') {
					closeModal();
				}
			});
		}

		if (!enableTilt) {
			return;
		}

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
