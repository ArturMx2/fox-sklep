/* Asystent AI – dymek czatu (bez bibliotek, ładowany z defer). */
(function () {
	'use strict';
	var C = window.fxAI;
	if (!C || !C.url) { return; }

	var KEY = 'fxai';
	var st = load();
	var root, panel, list, input, sendBtn, chips, tz, busy = false;
	var CTX = C.ctx || { type: 'other', teaser: '', chips: C.quick || [] };
	// Pierwsza wiadomość asystenta: dopasowana do strony (produkt, kask, koszyk…), a na głównej – powitanie z ustawień.
	var HELLO = (CTX.teaser && CTX.type !== 'home' && CTX.type !== 'other') ? CTX.teaser : C.hello;
	function track(ev) { try { (window.dataLayer = window.dataLayer || []).push({ event: ev, assistant_context: CTX.type }); } catch (e) {} }

	function load() {
		var s = null;
		try { s = JSON.parse(sessionStorage.getItem(KEY) || 'null'); } catch (e) {}
		if (!s || !s.sid) {
			s = { sid: rid(), msgs: [], open: false };
		}
		return s;
	}
	function save() { try { sessionStorage.setItem(KEY, JSON.stringify(st)); } catch (e) {} }
	function rid() {
		var a = 'abcdefghijklmnopqrstuvwxyz0123456789', s = '';
		for (var i = 0; i < 24; i++) { s += a.charAt(Math.floor(Math.random() * a.length)); }
		return s;
	}
	function esc(s) {
		return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
	}
	function linkify(s) {
		return esc(s).replace(/(https?:\/\/[^\s<]+[^\s<.,;:!?)])/g, '<a href="$1" target="_blank" rel="noopener nofollow">$1</a>');
	}
	function el(tag, cls, html) {
		var e = document.createElement(tag);
		if (cls) { e.className = cls; }
		if (html != null) { e.innerHTML = html; }
		return e;
	}
	function beacon(type, id) {
		var body = JSON.stringify({ sid: st.sid, type: type, id: id || 0 });
		try {
			if (navigator.sendBeacon && navigator.sendBeacon(C.ev, new Blob([body], { type: 'application/json' }))) { return; }
		} catch (e) {}
		try { fetch(C.ev, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: body, keepalive: true }); } catch (e) {}
	}
	function cookie() {
		document.cookie = 'fx_ai_sid=' + st.sid + ';path=/;max-age=' + (7 * 86400) + ';SameSite=Lax' + (location.protocol === 'https:' ? ';Secure' : '');
	}

	/* ---------- Budowa ---------- */
	function build() {
		var css = document.createElement('link');
		css.rel = 'stylesheet';
		css.href = C.css;
		document.head.appendChild(css);

		root = el('div', 'fx-ai');
		root.innerHTML =
			'<div class="fx-ai__tz" hidden><button type="button" class="fx-ai__tz-x" aria-label="Zamknij zachętę">×</button>' +
			'<div class="fx-ai__tz-who"><span class="fx-ai__tz-dot" aria-hidden="true"></span>' + esc(C.name) + ' · online</div><p class="fx-ai__tz-txt"></p><span class="fx-ai__tz-cta">Napisz →</span></div>' +
			'<button type="button" class="fx-ai__btn" aria-expanded="false" aria-controls="fx-ai-panel" aria-label="Otwórz czat: ' + esc(C.name) + '">' +
			'<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l1.6 4.4L18 9l-4.4 1.6L12 15l-1.6-4.4L6 9l4.4-1.6z"/><path d="M19 14l.8 2.2L22 17l-2.2.8L19 20l-.8-2.2L16 17l2.2-.8z"/><path d="M5 15l.6 1.4L7 17l-1.4.6L5 19l-.6-1.4L3 17l1.4-.6z"/></svg><span class="fx-ai__lbl">Zapytaj asystenta</span><span class="fx-ai__live" aria-hidden="true"></span><span class="fx-ai__badge" aria-hidden="true" hidden>1</span></button>' +
			'<section class="fx-ai__panel" id="fx-ai-panel" role="dialog" aria-label="' + esc(C.name) + '" hidden>' +
			'<header class="fx-ai__head"><div><strong>' + esc(C.name) + '</strong><span>' + (C.test ? 'tryb testowy · ' : '') + 'odpowiada od razu</span></div>' +
			'<div class="fx-ai__tools"><button type="button" class="fx-ai__new" title="Nowa rozmowa" hidden><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg><span>Nowa rozmowa</span></button>' +
			'<button type="button" class="fx-ai__x" aria-label="Zamknij czat"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></button></div></header>' +
			'<div class="fx-ai__list" aria-live="polite"></div>' +
			'<div class="fx-ai__chips"></div>' +
			'<form class="fx-ai__form"><textarea rows="1" maxlength="800" placeholder="Napisz wiadomość…" aria-label="Wiadomość"></textarea>' +
			'<button type="submit" class="fx-ai__send" aria-label="Wyślij"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></button></form>' +
			'<p class="fx-ai__note">Odpowiedzi tworzy AI (OpenAI) na podstawie danych sklepu' + (C.privacy ? ' · <a href="' + esc(C.privacy) + '">Prywatność</a>' : '') + '</p>' +
			'</section>';
		document.body.appendChild(root);
		panel = root.querySelector('.fx-ai__panel');
		list = root.querySelector('.fx-ai__list');
		input = root.querySelector('textarea');
		sendBtn = root.querySelector('.fx-ai__send');
		chips = root.querySelector('.fx-ai__chips');
		tz = root.querySelector('.fx-ai__tz');
		tz.addEventListener('click', function (e) {
			if (e.target.closest('.fx-ai__tz-x')) { hideTeaser(); return; }
			track('assistant_teaser_click');
			toggle(true);
		});

		root.querySelector('.fx-ai__btn').addEventListener('click', function () { toggle(!st.open); });
		root.querySelector('.fx-ai__x').addEventListener('click', function () { toggle(false); });
		root.querySelector('.fx-ai__new').addEventListener('click', function () {
			if (busy) { return; }
			st = { sid: rid(), msgs: [], open: true }; // nowa rozmowa = nowy identyfikator (osobno w statystykach)
			save();
			render();
			input.value = '';
			grow();
			input.focus();
		});
		root.querySelector('form').addEventListener('submit', function (e) { e.preventDefault(); send(input.value); });
		input.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(input.value); }
		});
		input.addEventListener('input', grow);
		document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && st.open) { toggle(false); } });
		list.addEventListener('click', onListClick);

		place();
		window.addEventListener('resize', place);
		// Pasek koszyka pojawia się po dodaniu produktu – przelicz pozycję dymka.
		if (window.jQuery) {
			window.jQuery(document.body).on('added_to_cart removed_from_cart wc_fragments_refreshed wc_fragments_loaded', function () { setTimeout(place, 50); });
		}
		render();
		if (st.open) { toggle(true, true); }
		armTeaser();
	}

	/* ---------- Zaczepka: po kilku sekundach albo po przewinięciu 40% strony, raz na typ strony ---------- */
	function tzKey() { return 'fxai_tz_' + CTX.type; }
	function armTeaser() {
		if (!C.teaserS || !CTX.teaser || st.open || st.msgs.length) { return; }
		var seen = null, n = 0;
		try { seen = sessionStorage.getItem(tzKey()); n = +(sessionStorage.getItem('fxai_tzn') || 0); } catch (e) {}
		if (seen || n >= 3) { return; }
		var done = false;
		var show = function () {
			if (done || st.open || st.msgs.length) { return; }
			var wa = document.querySelector('.fx-wa[data-state="open"], .fx-wa__teaser:not([hidden])');
			if (wa) { return; } // nie zagadujemy dwoma dymkami naraz
			done = true;
			window.removeEventListener('scroll', onScroll);
			tz.querySelector('.fx-ai__tz-txt').textContent = CTX.teaser;
			tz.hidden = false;
			root.querySelector('.fx-ai__badge').hidden = false;
			requestAnimationFrame(function () { tz.classList.add('is-in'); });
			try { sessionStorage.setItem(tzKey(), '1'); sessionStorage.setItem('fxai_tzn', String(n + 1)); } catch (e) {}
			track('assistant_teaser');
		};
		var onScroll = function () {
			var h = document.documentElement.scrollHeight - window.innerHeight;
			if (h > 0 && window.scrollY / h >= 0.4) { show(); }
		};
		setTimeout(show, C.teaserS * 1000);
		window.addEventListener('scroll', onScroll, { passive: true });
	}
	function hideTeaser() {
		if (!tz) { return; }
		tz.hidden = true;
		tz.classList.remove('is-in');
		root.querySelector('.fx-ai__badge').hidden = true;
	}

	/* Pozycja: nad dymkiem WhatsApp, nad dolnym paskiem zakupu na telefonie. */
	function place() {
		var wa = document.querySelector('.fx-wa');
		root.classList.toggle('is-stack', !!wa);
		var bar = document.querySelector('.fx-mbar');
		var lift = false;
		if (bar) {
			var cs = getComputedStyle(bar);
			lift = cs.display !== 'none' && cs.visibility !== 'hidden' && bar.offsetHeight > 0;
		}
		root.classList.toggle('is-lift', lift);
	}

	function grow() {
		input.style.height = 'auto';
		input.style.height = Math.min(input.scrollHeight, 120) + 'px';
	}

	function toggle(open, quiet) {
		st.open = !!open;
		save();
		panel.hidden = !open;
		root.classList.toggle('is-open', open);
		root.querySelector('.fx-ai__btn').setAttribute('aria-expanded', open ? 'true' : 'false');
		if (open) {
			hideTeaser();
			if (!quiet) { track('assistant_open'); }
		}
		document.documentElement.classList.toggle('fx-ai-lock', open && window.matchMedia('(max-width: 640px)').matches);
		if (open) {
			list.scrollTop = list.scrollHeight;
			if (!quiet || !window.matchMedia('(max-width: 640px)').matches) { setTimeout(function () { input.focus(); }, 30); }
		}
	}

	/* ---------- Wiadomości ---------- */
	function render() {
		list.innerHTML = '';
		list.appendChild(bubble({ role: 'assistant', content: HELLO }));
		st.msgs.forEach(function (m) { list.appendChild(bubble(m)); });
		chips.innerHTML = '';
		var quick = (CTX.chips && CTX.chips.length) ? CTX.chips : (C.quick || []);
		if (!st.msgs.length && quick.length) {
			quick.forEach(function (q) {
				var b = el('button', 'fx-ai__chip', esc(q));
				b.type = 'button';
				b.addEventListener('click', function () { send(q); });
				chips.appendChild(b);
			});
		}
		chips.hidden = !chips.children.length;
		root.querySelector('.fx-ai__new').hidden = !st.msgs.length;
		list.scrollTop = list.scrollHeight;
	}

	function bubble(m) {
		var w = el('div', 'fx-ai__msg fx-ai__msg--' + (m.role === 'user' ? 'me' : 'ai'));
		w.appendChild(el('div', 'fx-ai__txt', m.role === 'user' ? esc(m.content) : linkify(m.content)));
		if (m.cards && m.cards.length) {
			var row = el('div', 'fx-ai__cards');
			m.cards.forEach(function (c) {
				var card = el('div', 'fx-ai__card');
				card.innerHTML =
					'<a class="fx-ai__cimg" href="' + esc(c.url) + '" data-id="' + c.id + '"><img src="' + esc(c.img) + '" alt="" loading="lazy" width="160" height="160">' + (c.today ? '<span class="fx-ai__today">Wysyłka dziś</span>' : '') + '</a>' +
					'<a class="fx-ai__cname" href="' + esc(c.url) + '" data-id="' + c.id + '">' + esc(c.name) + '</a>' +
					'<div class="fx-ai__cprice">' + (c.price || '') + '</div>' +
					(c.add ? '<button type="button" class="fx-ai__add" data-add="' + c.id + '">Do koszyka</button>' : '<a class="fx-ai__more" href="' + esc(c.url) + '" data-id="' + c.id + '">Wybierz rozmiar</a>');
				row.appendChild(card);
			});
			w.appendChild(row);
		}
		if (m.more && m.more.url) {
			var more = el('a', 'fx-ai__all', esc(m.more.label) + ' <span aria-hidden="true">→</span>');
			more.href = m.more.url;
			more.setAttribute('data-id', '0');
			w.appendChild(more);
		}
		if (m.callback) {
			var f = el('form', 'fx-ai__cb');
			if (m.cbDone) {
				f.innerHTML = '<p class="fx-ai__cb-ok">' + esc(m.cbDone) + '</p>';
			} else {
				f.innerHTML = '<strong>Zostaw numer – oddzwonimy</strong>' +
					'<div class="fx-ai__cb-row"><input type="tel" name="phone" required placeholder="Telefon" autocomplete="tel" inputmode="tel" aria-label="Telefon"><input type="text" name="name" placeholder="Imię (opcjonalnie)" autocomplete="given-name" aria-label="Imię"></div>' +
					'<input type="text" name="website" tabindex="-1" autocomplete="off" class="fx-ai__hp" aria-hidden="true">' +
					'<button type="submit">Poproś o telefon</button>' +
					'<small>Numer wykorzystamy tylko do kontaktu w tej sprawie' + (C.phone ? ' · albo zadzwoń: <a href="tel:' + esc(C.phone.replace(/[^\d+]/g, '')) + '">' + esc(C.phone) + '</a>' : '') + '</small><p class="fx-ai__cb-err" hidden></p>';
				f.addEventListener('submit', function (e) { e.preventDefault(); callback(f, m); });
			}
			w.appendChild(f);
		}
		if (m.wa) {
			var wa = el('a', 'fx-ai__wa', 'Napisz na WhatsApp');
			wa.href = m.wa;
			wa.target = '_blank';
			wa.rel = 'noopener';
			w.appendChild(wa);
		}
		return w;
	}

	function onListClick(e) {
		var a = e.target.closest('[data-id]');
		if (a) { beacon('click', +a.getAttribute('data-id')); return; }
		var b = e.target.closest('[data-add]');
		if (!b || !C.addUrl) { return; }
		e.preventDefault();
		b.disabled = true;
		b.textContent = 'Dodaję…';
		var fd = new FormData();
		fd.append('product_id', b.getAttribute('data-add'));
		fd.append('quantity', '1');
		fetch(C.addUrl, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (res) {
			if (res && res.error) { throw new Error('add'); }
			b.textContent = 'W koszyku ✓';
			beacon('cart', +b.getAttribute('data-add'));
			if (window.jQuery && res && res.fragments) {
				window.jQuery(document.body).trigger('added_to_cart', [res.fragments, res.cart_hash, window.jQuery(b)]);
			}
		}).catch(function () {
			b.disabled = false;
			b.textContent = 'Spróbuj ponownie';
		});
	}

	function callback(f, m) {
		var btn = f.querySelector('button'), err = f.querySelector('.fx-ai__cb-err');
		btn.disabled = true;
		fetch(C.cb, { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify({ sid: st.sid, phone: f.phone.value, name: f.name.value, website: f.website.value }) })
			.then(function (r) { return r.json(); }).then(function (res) {
				if (res && res.ok) {
					m.cbDone = res.msg;
					save();
					render();
					track('assistant_callback');
				} else {
					err.textContent = (res && res.msg) || 'Spróbuj ponownie.';
					err.hidden = false;
					btn.disabled = false;
				}
			}).catch(function () { err.textContent = 'Brak połączenia – zadzwoń do nas.'; err.hidden = false; btn.disabled = false; });
	}

	function typing(on) {
		var t = list.querySelector('.fx-ai__typing');
		if (on && !t) {
			t = el('div', 'fx-ai__msg fx-ai__msg--ai fx-ai__typing', '<div class="fx-ai__txt"><i></i><i></i><i></i></div>');
			list.appendChild(t);
			list.scrollTop = list.scrollHeight;
		} else if (!on && t) {
			t.remove();
		}
	}

	function send(text) {
		text = String(text || '').trim().slice(0, 800);
		if (!text || busy) { return; }
		busy = true;
		sendBtn.disabled = true;
		input.value = '';
		grow();
		st.msgs.push({ role: 'user', content: text });
		save();
		cookie();
		render();
		typing(true);
		var history = st.msgs.slice(-24).map(function (m) { return { role: m.role, content: m.content }; });
		var body = JSON.stringify({ sid: st.sid, messages: history, product: C.product || 0, page: location.href.split('#')[0] });
		post(body, !!C.nonce).then(function (res) {
			st.msgs.push({ role: 'assistant', content: (res && res.text) || 'Przepraszam, spróbuj jeszcze raz.', cards: (res && res.cards) || [], more: (res && res.more) || null, wa: (res && res.wa) || '', callback: !!(res && res.callback) });
		}).catch(function () {
			st.msgs.push({ role: 'assistant', content: 'Nie mogę się teraz połączyć. Spróbuj za chwilę.' });
		}).then(function () {
			busy = false;
			sendBtn.disabled = false;
			typing(false);
			save();
			render();
			if (window.matchMedia('(min-width: 641px)').matches) { input.focus(); }
		});
	}

	function post(body, withNonce) {
		var h = { 'Content-Type': 'application/json' };
		if (withNonce) { h['X-WP-Nonce'] = C.nonce; }
		return fetch(C.url, { method: 'POST', headers: h, body: body, credentials: 'same-origin' }).then(function (r) {
			if (r.status === 403 && withNonce) { return post(body, false); } // nieaktualny nonce z cache strony
			if (!r.ok) { throw new Error('http'); }
			return r.json();
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', build);
	} else {
		build();
	}
})();
