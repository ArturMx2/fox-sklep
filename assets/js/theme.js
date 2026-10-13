/* FOX.SKLEP.PL – skrypty motywu */
(function ($) {
	'use strict';

	/* ---------- Szuflady: menu, wyszukiwarka, filtry ---------- */
	var lastTrigger = null;

	function openDrawer(id, trigger) {
		var el = document.getElementById(id);
		if (!el) { return; }
		lastTrigger = trigger || null;
		el.classList.add('is-open');
		el.setAttribute('aria-hidden', 'false');
		document.documentElement.style.overflow = 'hidden';
		if (trigger) { trigger.setAttribute('aria-expanded', 'true'); }
		var focusable = el.querySelector('input[type=search], button, a');
		if (focusable) { setTimeout(function () { focusable.focus(); }, 30); }
	}

	function closeDrawer(el) {
		el.classList.remove('is-open');
		el.setAttribute('aria-hidden', 'true');
		document.documentElement.style.overflow = '';
		document.querySelectorAll('[data-fx-open="' + el.id + '"]').forEach(function (b) { b.setAttribute('aria-expanded', 'false'); });
		if (lastTrigger) { lastTrigger.focus(); lastTrigger = null; }
	}

	document.addEventListener('click', function (e) {
		var opener = e.target.closest('[data-fx-open]');
		if (opener) {
			e.preventDefault();
			openDrawer(opener.getAttribute('data-fx-open'), opener);
			return;
		}
		var closer = e.target.closest('[data-fx-close]');
		if (closer) {
			var d = closer.closest('.fx-drawer');
			if (d) { closeDrawer(d); }
		}
	});

	document.addEventListener('keydown', function (e) {
		if (e.key !== 'Escape') { return; }
		var open = document.querySelector('.fx-drawer.is-open');
		if (open) { closeDrawer(open); }
	});

	/* ---------- Menu mobilne: rozwijane podmenu ---------- */
	document.querySelectorAll('.fx-mnav li.menu-item-has-children').forEach(function (li) {
		var link = li.querySelector(':scope > a');
		if (!link) { return; }
		var row = document.createElement('div');
		row.className = 'fx-mnav__row';
		li.insertBefore(row, link);
		row.appendChild(link);
		var btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'fx-mnav__toggle';
		btn.setAttribute('aria-expanded', 'false');
		btn.setAttribute('aria-label', 'Rozwiń: ' + link.textContent.trim());
		btn.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>';
		row.appendChild(btn);
		btn.addEventListener('click', function () {
			var open = li.classList.toggle('is-open');
			btn.setAttribute('aria-expanded', open ? 'true' : 'false');
		});
	});

	/* ---------- Opinie w akordeonie: otwórz po kliknięciu w gwiazdki lub #reviews ---------- */
	function openReviews() {
		var acc = document.getElementById('reviews-acc');
		if (acc) { acc.open = true; acc.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
	}
	$(document).on('click', '.woocommerce-review-link', function (e) { e.preventDefault(); openReviews(); });
	if (/^#(reviews|comment-\d+)/.test(window.location.hash)) { openReviews(); }

	/* ---------- Rozmiary jako przyciski ---------- */
	var sizePattern = /(rozmiar|size|wielko)/i;

	function buildSwatches($form) {
		$form.find('table.variations select').each(function () {
			var select = this;
			var $select = $(select);
			var name = (select.getAttribute('data-attribute_name') || select.name || '') + ' ' + ($select.closest('tr').find('th label').text() || '');
			if (!sizePattern.test(name)) { return; }
			var wrap = $select.siblings('.fx-swatches');
			if (!wrap.length) {
				wrap = $('<div class="fx-swatches" role="group"></div>');
				wrap.attr('aria-label', $select.closest('tr').find('th label').text() || 'Rozmiar');
				$select.after(wrap);
				$select.addClass('screen-reader-text').attr('tabindex', '-1').attr('aria-hidden', 'true');
			}
			wrap.empty();
			$select.find('option').each(function () {
				if (!this.value) { return; }
				var opt = this;
				var b = $('<button type="button"></button>').text(opt.text);
				var unavailable = opt.disabled || ($(opt).hasClass('enabled') === false && $select.find('option.enabled').length > 0);
				if (unavailable) { b.prop('disabled', true).attr('title', 'Niedostępny'); }
				if (select.value === opt.value) { b.addClass('is-active').attr('aria-pressed', 'true'); } else { b.attr('aria-pressed', 'false'); }
				b.on('click', function () {
					$select.val(select.value === opt.value ? '' : opt.value).trigger('change');
				});
				wrap.append(b);
			});
		});
	}

	$(document).on('wc_variation_form woocommerce_update_variation_values', 'form.variations_form', function () {
		buildSwatches($(this));
	});
	$(document).on('change', 'form.variations_form table.variations select', function () {
		buildSwatches($(this).closest('form'));
	});

	/* ---------- Przyklejony przycisk na telefonie ---------- */
	var sticky = document.querySelector('.fx-sticky-atc');
	var atcForm = document.querySelector('.fx-pdp form.cart');
	if (sticky && atcForm && 'IntersectionObserver' in window) {
		new IntersectionObserver(function (entries) {
			entries.forEach(function (en) {
				var below = en.boundingClientRect.top < 0;
				sticky.classList.toggle('is-visible', !en.isIntersecting && below);
				sticky.setAttribute('aria-hidden', (!en.isIntersecting && below) ? 'false' : 'true');
			});
		}).observe(atcForm);
		sticky.querySelector('[data-fx-scroll-atc]').addEventListener('click', function () {
			atcForm.scrollIntoView({ behavior: 'smooth', block: 'center' });
		});
	}

	/* ---------- Zbuduj komplet ---------- */
	var kit = document.querySelector('[data-fx-kit]');
	if (kit && window.fxKit) {
		var cfg = window.fxKit;

		var fmt = function (n) {
			var fixed = Number(n).toFixed(cfg.dec);
			var parts = fixed.split('.');
			parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, cfg.ts);
			var num = parts.join(cfg.ds);
			switch (cfg.pos) {
				case 'left': return cfg.symbol + num;
				case 'left_space': return cfg.symbol + ' ' + num;
				case 'right': return num + cfg.symbol;
				default: return num + ' ' + cfg.symbol;
			}
		};

		var rows = function () { return Array.prototype.slice.call(kit.querySelectorAll('.fx-kit__item')); };

		var state = function () {
			var chosen = 0, missing = 0, total = 0, items = [];
			rows().forEach(function (row) {
				var on = row.querySelector('input[type=checkbox]').checked;
				row.classList.toggle('is-off', !on);
				if (!on) { return; }
				chosen++;
				var size = row.querySelector('[data-fx-kit-size]');
				var isSelect = size.tagName === 'SELECT';
				var opt = isSelect ? size.options[size.selectedIndex] : size;
				var vid = isSelect ? size.value : '0';
				if (isSelect && !vid) { missing++; return; }
				var price = parseFloat(opt.getAttribute('data-price')) || 0;
				total += price;
				items.push({
					product_id: parseInt(row.getAttribute('data-product-id'), 10),
					variation_id: parseInt(vid, 10) || 0
				});
			});
			return { chosen: chosen, missing: missing, total: total, items: items };
		};

		var render = function () {
			var s = state();
			var n = rows().length;
			var word = s.chosen === 1 ? 'część' : 'części';
			kit.querySelector('[data-fx-kit-summary]').textContent = 'Wybrano ' + s.chosen + ' ' + word + ' z ' + n + (s.missing ? ' · wybierz rozmiar: ' + s.missing : '');
			kit.querySelector('[data-fx-kit-total]').textContent = s.total ? fmt(s.total) : '';
			var btn = kit.querySelector('[data-fx-kit-add]');
			btn.textContent = s.chosen ? 'Dodaj komplet (' + s.chosen + ')' : 'Wybierz części';
			btn.disabled = !s.chosen;
		};

		kit.addEventListener('change', render);
		render();

		var msg = kit.querySelector('[data-fx-kit-msg]');
		kit.querySelector('[data-fx-kit-add]').addEventListener('click', function () {
			var s = state();
			msg.classList.remove('is-error');
			if (s.missing) {
				msg.textContent = 'Wybierz rozmiar dla każdej zaznaczonej części.';
				msg.classList.add('is-error');
				var first = rows().filter(function (r) {
					var sel = r.querySelector('select[data-fx-kit-size]');
					return r.querySelector('input[type=checkbox]').checked && sel && !sel.value;
				})[0];
				if (first) { first.querySelector('select').focus(); }
				return;
			}
			var btn = this;
			btn.disabled = true;
			msg.textContent = 'Dodaję do koszyka…';
			$.post(cfg.url, { items: JSON.stringify(s.items) })
				.done(function (res) {
					if (!res || !res.success) {
						msg.textContent = (res && res.data && res.data.message) || 'Nie udało się dodać kompletu.';
						msg.classList.add('is-error');
						return;
					}
					var d = res.data;
					$.each(d.fragments || {}, function (sel, html) { $(sel).replaceWith(html); });
					$(document.body).trigger('added_to_cart', [d.fragments, d.cart_hash, $(btn)]);
					$(document.body).trigger('wc_fragment_refresh');
					var text = 'Dodano do koszyka: ' + d.added.length + ' ' + (d.added.length === 1 ? 'produkt' : 'produkty') + '. ';
					if (d.errors && d.errors.length) { text += d.errors.join(' ') + ' '; }
					msg.innerHTML = '';
					msg.appendChild(document.createTextNode(text));
					var a = document.createElement('a');
					a.href = cfg.cartUrl;
					a.textContent = 'Przejdź do koszyka';
					a.style.textDecoration = 'underline';
					msg.appendChild(a);

					if (cfg.datalayer) {
						var value = d.added.reduce(function (sum, it) { return sum + it.price * it.quantity; }, 0);
						window.dataLayer = window.dataLayer || [];
						window.dataLayer.push({ ecommerce: null });
						window.dataLayer.push({
							event: 'add_to_cart',
							ecommerce: { currency: cfg.currency, value: Math.round(value * 100) / 100, items: d.added }
						});
					}
				})
				.fail(function () {
					msg.textContent = 'Błąd połączenia. Spróbuj ponownie.';
					msg.classList.add('is-error');
				})
				.always(function () { btn.disabled = false; render(); });
		});
	}
})(jQuery);
