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

	/* ---------- Przyciski wariantów (wszystkie atrybuty; kolory jako kółka) ---------- */
	var swatchColors = (window.fxSwatches && window.fxSwatches.colors) || {};

	function buildSwatches($form) {
		$form.find('table.variations select').each(function () {
			var select = this;
			var $select = $(select);
			var attr = select.getAttribute('data-attribute_name') || select.name || '';
			var colors = swatchColors[attr] || null;
			var $row = $select.closest('tr');
			var $label = $row.find('th label, td.label label').first();
			var wrap = $select.siblings('.fx-swatches');
			if (!wrap.length) {
				wrap = $('<div class="fx-swatches" role="group"></div>');
				if (colors) { wrap.addClass('fx-swatches--color'); }
				wrap.attr('aria-label', $label.text() || 'Wariant');
				$select.after(wrap);
				$select.addClass('screen-reader-text').attr('tabindex', '-1').attr('aria-hidden', 'true');
			}
			wrap.empty();
			var enabledCount = $select.find('option.enabled').length;
			$select.find('option').each(function () {
				if (!this.value) { return; }
				var opt = this;
				var b = $('<button type="button"></button>');
				if (colors && colors[opt.value]) {
					var c = colors[opt.value];
					b.addClass('fx-swatch-color').attr('aria-label', opt.text).attr('title', opt.text)
						.css('background', c.length > 1 ? 'linear-gradient(135deg,' + c[0] + ' 50%,' + c[1] + ' 50%)' : c[0]);
				} else {
					b.text(opt.text);
				}
				var unavailable = opt.disabled || (enabledCount > 0 && !$(opt).hasClass('enabled'));
				if (unavailable) { b.addClass('is-unavailable').attr('title', opt.text + ' – niedostępny'); }
				var active = select.value === opt.value;
				b.toggleClass('is-active', active).attr('aria-pressed', active ? 'true' : 'false');
				b.on('click', function () {
					$select.val(select.value === opt.value ? '' : opt.value).trigger('change');
				});
				wrap.append(b);
			});
			// Wybrana wartość obok nazwy atrybutu.
			var chosen = $label.find('.fx-chosen');
			if (!chosen.length) { chosen = $('<span class="fx-chosen"></span>'); $label.append(chosen); }
			chosen.text(select.value ? ': ' + $select.find('option:selected').text() : '');
		});
	}

	$(document).on('wc_variation_form woocommerce_update_variation_values', 'form.variations_form', function () {
		buildSwatches($(this));
	});
	$(document).on('change', 'form.variations_form table.variations select', function () {
		buildSwatches($(this).closest('form'));
	});
	$(function () { $('form.variations_form').each(function () { buildSwatches($(this)); }); });

	/* ---------- Ulubione ---------- */
	var W = window.fxWish;
	if (W) {
		var WKEY = 'fx_wishlist';
		var readLocal = function () { try { var a = JSON.parse(localStorage.getItem(WKEY) || '[]'); return Array.isArray(a) ? a.map(Number).filter(Boolean) : []; } catch (e) { return []; } };
		var writeLocal = function (a) { try { localStorage.setItem(WKEY, JSON.stringify(a)); } catch (e) {} };
		var wish = readLocal();
		var endpoint = function (name) { return W.url.replace('%%endpoint%%', name); };
		var saveRemote = function () { if (W.loggedIn) { $.post(endpoint('fx_wish_save'), { ids: JSON.stringify(wish), nonce: W.nonce }); } };
		if (W.loggedIn) {
			var merged = (W.saved || []).map(Number);
			var extra = wish.filter(function (id) { return merged.indexOf(id) < 0; });
			wish = merged.concat(extra);
			writeLocal(wish);
			if (extra.length) { saveRemote(); }
		}
		var paintWish = function () {
			$('[data-fx-wish]').each(function () {
				var on = wish.indexOf(Number(this.getAttribute('data-fx-wish'))) >= 0;
				this.classList.toggle('is-active', on);
				this.setAttribute('aria-pressed', on ? 'true' : 'false');
			});
			$('.fx-wish-count').attr('data-count', wish.length).text(wish.length || '');
		};
		var renderWishPage = function () {
			var box = document.querySelector('[data-fx-wishlist]');
			if (!box) { return; }
			if (!wish.length) {
				box.innerHTML = '<p class="fx-wishlist__empty">Nie masz jeszcze ulubionych produktów. Kliknij serduszko przy produkcie, żeby go tu zapisać.</p>';
				return;
			}
			$.post(endpoint('fx_wish_render'), { ids: JSON.stringify(wish) }).done(function (res) {
				box.innerHTML = (res && res.success && res.data.html) ? res.data.html : '<p class="fx-wishlist__empty">Nie udało się wczytać ulubionych.</p>';
				paintWish();
			});
		};
		$(document).on('click', '[data-fx-wish]', function (e) {
			e.preventDefault();
			e.stopPropagation();
			var id = Number(this.getAttribute('data-fx-wish'));
			var i = wish.indexOf(id);
			if (i >= 0) { wish.splice(i, 1); } else { wish.unshift(id); }
			writeLocal(wish);
			saveRemote();
			paintWish();
			if (i >= 0 && document.querySelector('[data-fx-wishlist]')) { renderWishPage(); }
		});
		$(document.body).on('post-load updated_wc_div', paintWish);
		paintWish();
		renderWishPage();
	}

	/* ---------- Powiadomienie o dostępności ---------- */
	var alertForm = document.querySelector('[data-fx-alert]');
	if (alertForm && window.fxWish) {
		var $vf = $('form.variations_form');
		$vf.on('found_variation', function (e, variation) {
			var oos = variation && !variation.is_in_stock;
			alertForm.classList.toggle('fx-hide', !oos);
			alertForm.querySelector('[name=variation_id]').value = oos ? variation.variation_id : 0;
			alertForm.querySelector('.fx-alert__msg').textContent = '';
		});
		$vf.on('reset_data hide_variation', function () { alertForm.classList.add('fx-hide'); });
		alertForm.addEventListener('submit', function (e) {
			e.preventDefault();
			var msg = alertForm.querySelector('.fx-alert__msg');
			var btn = alertForm.querySelector('button[type=submit]');
			var email = alertForm.querySelector('[name=email]').value.trim();
			msg.classList.remove('is-error');
			if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { msg.textContent = 'Podaj poprawny adres e-mail.'; msg.classList.add('is-error'); return; }
			btn.disabled = true;
			$.post(window.fxWish.url.replace('%%endpoint%%', 'fx_stock_alert'), {
				email: email,
				website: alertForm.querySelector('[name=website]').value,
				product_id: alertForm.getAttribute('data-product'),
				variation_id: alertForm.querySelector('[name=variation_id]').value
			}).done(function (res) {
				msg.textContent = (res && res.data && res.data.message) || 'Gotowe!';
				msg.classList.toggle('is-error', !(res && res.success));
			}).fail(function () {
				msg.textContent = 'Błąd połączenia. Spróbuj ponownie.'; msg.classList.add('is-error');
			}).always(function () { btn.disabled = false; });
		});
	}

	/* ---------- Okna (tabela rozmiarów) ---------- */
	$(document).on('click', '[data-fx-dialog]', function (e) {
		var d = document.getElementById(this.getAttribute('data-fx-dialog'));
		if (d && d.showModal) { e.preventDefault(); d.showModal(); }
	});
	$(document).on('click', '[data-fx-dialog-close]', function () { var d = this.closest('dialog'); if (d) { d.close(); } });
	$(document).on('click', 'dialog.fx-dialog', function (e) { if (e.target === this) { this.close(); } });

	/* ---------- Kategorie: kolejne strony doładowywane przy przewijaniu ---------- */
	(function () {
		var list = document.querySelector('.fx-shop ul.products');
		var pager = document.querySelector('.fx-shop nav.woocommerce-pagination');
		if (!list || !pager || !('IntersectionObserver' in window) || !window.fetch) { return; }
		var next = pager.querySelector('a.next');
		if (!next) { return; }
		document.body.classList.add('fx-infinite');
		var loader = document.createElement('div');
		loader.className = 'fx-loader';
		loader.setAttribute('role', 'status');
		loader.innerHTML = '<span class="fx-loader__dot"></span><span class="screen-reader-text">Wczytuję kolejne produkty</span>';
		pager.parentNode.insertBefore(loader, pager);
		var busy = false;
		var io = new IntersectionObserver(function (entries) {
			if (!entries[0].isIntersecting || busy || !next) { return; }
			busy = true;
			loader.classList.add('is-loading');
			var url = next.href;
			fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.text(); }).then(function (html) {
				var doc = new DOMParser().parseFromString(html, 'text/html');
				var items = doc.querySelectorAll('.fx-shop ul.products > li.product');
				items.forEach(function (li) { list.appendChild(document.importNode(li, true)); });
				var newPager = doc.querySelector('.fx-shop nav.woocommerce-pagination');
				next = newPager ? newPager.querySelector('a.next') : null;
				if (newPager) { pager.innerHTML = newPager.innerHTML; }
				try { history.replaceState(null, '', url); } catch (e) {}
				$(document.body).trigger('post-load');
				if (!next) { io.disconnect(); loader.remove(); }
			}).catch(function () {
				// Błąd sieci: zostawiamy zwykłą paginację jako zapas.
				document.body.classList.remove('fx-infinite');
				io.disconnect(); loader.remove();
			}).finally(function () {
				busy = false; loader.classList.remove('is-loading');
				// Ponowna obserwacja: jeśli znacznik nadal jest blisko ekranu, wczytaj kolejną stronę.
				if (next && loader.isConnected) { io.unobserve(loader); io.observe(loader); }
			});
		}, { rootMargin: '800px 0px' });
		io.observe(loader);
	})();

	/* ---------- Filtry: zamykanie listy po kliknięciu obok i otwieranie jednej naraz ---------- */
	document.addEventListener('click', function (e) {
		document.querySelectorAll('.fx-filter[open]').forEach(function (d) { if (!d.contains(e.target)) { d.removeAttribute('open'); } });
	});
	document.querySelectorAll('.fx-filter').forEach(function (d) {
		d.addEventListener('toggle', function () {
			if (d.open) { document.querySelectorAll('.fx-filter[open]').forEach(function (o) { if (o !== d) { o.removeAttribute('open'); } }); }
		});
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

	/* Mega menu: otwiera się z intencją i nie przeskakuje.
	   - pierwsze otwarcie po krótkim zatrzymaniu kursora (120 ms), żeby nie wyskakiwało przy przejeżdżaniu,
	   - gdy panel jest otwarty, inna pozycja przejmuje go dopiero po 400 ms postoju na niej
	     (przejazd po skosie w dół do panelu nie przełącza menu),
	   - zamyka się 400 ms po wyjechaniu kursorem poza pozycję i panel. */
	(function () {
		var nav = document.querySelector('.fx-header__nav .fx-nav');
		if (!nav) { return; }
		var items = nav.querySelectorAll(':scope > ul > li.fx-has-mega');
		if (!items.length) { return; }
		nav.classList.add('fx-mega-js');
		var current = null, tOpen = 0, tClose = 0;
		function setOpen(li) {
			if (current === li) { return; }
			if (current) { current.classList.remove('is-open'); current.firstElementChild.setAttribute('aria-expanded', 'false'); }
			current = li;
			if (li) { li.classList.add('is-open'); li.firstElementChild.setAttribute('aria-expanded', 'true'); }
		}
		nav.querySelectorAll(':scope > ul > li').forEach(function (li) {
			var mega = li.classList.contains('fx-has-mega');
			if (mega) { li.firstElementChild.setAttribute('aria-haspopup', 'true'); li.firstElementChild.setAttribute('aria-expanded', 'false'); }
			li.addEventListener('mouseenter', function () {
				clearTimeout(tOpen);
				if (current === li) { clearTimeout(tClose); return; }
				var target = mega ? li : null;
				tOpen = setTimeout(function () { clearTimeout(tClose); setOpen(target); }, current ? 400 : 120);
			});
			li.addEventListener('mouseleave', function () {
				clearTimeout(tOpen);
				if (current !== li) { return; }
				clearTimeout(tClose);
				tClose = setTimeout(function () { setOpen(null); }, 400);
			});
		});
		/* klawiatura: Tab otwiera panel bieżącej pozycji, Esc zamyka */
		nav.addEventListener('focusin', function (e) {
			var li = e.target.closest('.fx-nav > ul > li');
			clearTimeout(tClose); setOpen(li && li.classList.contains('fx-has-mega') ? li : null);
		});
		nav.addEventListener('focusout', function (e) {
			if (!nav.contains(e.relatedTarget) && !(current && current.matches(':hover'))) { setOpen(null); }
		});
		document.addEventListener('keydown', function (e) {
			if ('Escape' === e.key && current) { var a = current.firstElementChild; setOpen(null); a.focus(); }
		});
		/* klik w link w panelu – zamknij od razu (przy wolnym ładowaniu strony nie wisi otwarte) */
		nav.addEventListener('click', function (e) { if (e.target.closest('.fx-mega a')) { setOpen(null); } });
	})();
})(jQuery);
