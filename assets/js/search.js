/**
 * Wyszukiwarka FOX: podpowiedzi przy każdej literze, liczone w przeglądarce z pliku indeksu.
 * Zasady dopasowania są takie same jak w PHP (inc/modules/search.php) – strona wyników pokazuje
 * tę samą kolejność co okno podpowiedzi.
 */
(function () {
	'use strict';
	var C = window.fxSearch;
	if (!C || !window.fetch) { return; }

	var D = null, P = null, loading = null;
	var PL = { 'ą': 'a', 'ć': 'c', 'ę': 'e', 'ł': 'l', 'ń': 'n', 'ó': 'o', 'ś': 's', 'ź': 'z', 'ż': 'z' };
	var ENDS = ['owych', 'owego', 'owymi', 'owej', 'ami', 'ach', 'owy', 'owe', 'owa', 'ego', 'ych', 'ymi', 'ow', 'om', 'ie', 'y', 'i', 'e', 'a', 'u'];
	var STOP = ['dla', 'i', 'z', 'na', 'do', 'w', 'the', 'and', 'od', 'ze', 'oraz'];

	/* ---------- tekst ---------- */
	function norm(s) {
		s = String(s || '').toLowerCase().replace(/[ąćęłńóśźż]/g, function (c) { return PL[c]; });
		if (s.normalize) { s = s.normalize('NFD').replace(/[̀-ͯ]/g, ''); }
		return s.replace(/[^a-z0-9]+/g, ' ').trim();
	}
	function stem(w) {
		if (w.length < 5 || /\d/.test(w)) { return ''; }
		for (var i = 0; i < ENDS.length; i++) {
			var e = ENDS[i];
			if (w.slice(-e.length) === e && w.length - e.length >= 4) { return w.slice(0, -e.length); }
		}
		return '';
	}
	function starts(w, a) { return w.lastIndexOf(a, 0) === 0; }
	function lev(a, b) { // z zamianą sąsiednich liter – tak samo jak w PHP
		var m = a.length, n = b.length, d = [], i, j;
		for (i = 0; i <= m; i++) { d[i] = [i]; }
		for (j = 0; j <= n; j++) { d[0][j] = j; }
		for (i = 1; i <= m; i++) {
			for (j = 1; j <= n; j++) {
				d[i][j] = Math.min(d[i - 1][j] + 1, d[i][j - 1] + 1, d[i - 1][j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1));
				if (i > 1 && j > 1 && a[i - 1] === b[j - 2] && a[i - 2] === b[j - 1]) { d[i][j] = Math.min(d[i][j], d[i - 2][j - 2] + 1); }
			}
		}
		return d[m][n];
	}

	/* ---------- indeks ---------- */
	function load() {
		if (loading) { return loading; }
		loading = fetch(C.index, { credentials: 'omit' }).then(function (r) { return r.json(); }).then(function (d) {
			D = d;
			P = { items: [], vocab: {}, catCount: {}, cats: [] };
			d.items.forEach(function (it) {
				var tw = norm(it.t).split(' ');
				tw.forEach(function (w) { if (w.length > 2 && !/^\d+$/.test(w)) { P.vocab[w] = (P.vocab[w] || 0) + 1; } });
				it.c.forEach(function (c) { P.catCount[c] = (P.catCount[c] || 0) + 1; });
				P.items.push({ it: it, tw: tw, xw: it.x ? it.x.split(' ') : [], all: tw.join(' ') + ' ' + it.x });
			});
			Object.keys(d.cats).forEach(function (id) {
				var c = d.cats[id];
				if (P.catCount[id]) { P.cats.push({ id: +id, name: c[0], url: c[1], parent: c[2], w: norm(c[0]).split(' ') }); }
			});
			return d;
		}).catch(function () { loading = null; });
		return loading;
	}

	function synOf(w) {
		var out = [], st = stem(w);
		(D.syn || []).forEach(function (g) {
			for (var i = 0; i < g.length; i++) {
				if (g[i] === w || (st && stem(g[i]) === st)) {
					g.forEach(function (x) { if (x !== w && x.indexOf(' ') < 0) { out.push(x); } });
					break;
				}
			}
		});
		return out;
	}

	function tokens(q) {
		var words = norm(q).split(' ').filter(Boolean), fixed = false;
		if (words.length > 1) { words = words.filter(function (w) { return STOP.indexOf(w) < 0; }); }
		words = words.slice(0, 8);
		words = words.map(function (w) {
			if (w.length < 4 || /\d/.test(w)) { return w; }
			var st = stem(w), v;
			for (v in P.vocab) { if (starts(v, w) || (st && starts(v, st))) { return w; } }
			if (synOf(w).length) { return w; }
			var max = w.length <= 5 ? 1 : 2, best = '', bd = 99, bn = 0;
			for (v in P.vocab) {
				if (Math.abs(v.length - w.length) > max) { continue; }
				var dd = lev(w, v);
				if (dd <= max && (dd < bd || (dd === bd && P.vocab[v] > bn))) { best = v; bd = dd; bn = P.vocab[v]; }
			}
			if (best) { fixed = true; return best; }
			return w;
		});
		return {
			words: words,
			fixed: fixed ? words.join(' ') : '',
			tokens: words.map(function (w) {
				var a = [w];
				synOf(w).forEach(function (s) { if (a.indexOf(s) < 0) { a.push(s); } });
				return a;
			})
		};
	}

	/* Każde dopasowanie (całe słowo, początek, odmiana, fragment) jest podciągiem – szybkie odrzucenie. */
	function needles(alts) {
		var n = [];
		alts.forEach(function (a) { var s = stem(a); n.push(s || a); });
		return n;
	}
	function maybe(all, nd) {
		for (var i = 0; i < nd.length; i++) { if (all.indexOf(nd[i]) >= 0) { return true; } }
		return false;
	}
	function tokScore(alts, tw, xw) {
		var best = 0;
		for (var k = 0; k < alts.length; k++) {
			var a = alts[k], syn = k > 0 ? 0.8 : 1, st = stem(a), code = a.length >= 4 && /\d/.test(a), i, w, s;
			for (i = 0; i < tw.length; i++) {
				w = tw[i]; s = 0;
				if (w === a) { s = 12; } else if (starts(w, a)) { s = a.length >= 2 ? 9 : 6; } else if (st && starts(w, st)) { s = 8; } else if (a.length >= 3 && w.indexOf(a) >= 0) { s = 4; }
				if (s && i === 0) { s += 1; }
				if (s * syn > best) { best = s * syn; }
			}
			for (i = 0; i < xw.length; i++) {
				w = xw[i]; s = 0;
				if (w === a) { s = code ? 30 : 6; } else if (starts(w, a) && (code || a.length >= 2)) { s = code ? 18 : 5; } else if (st && starts(w, st)) { s = 4; } else if (a.length >= 4 && w.indexOf(a) >= 0) { s = 2; }
				if (s * syn > best) { best = s * syn; }
			}
		}
		return best;
	}

	function search(q) {
		var tk = tokens(q), n = tk.tokens.length, full = [], part = [];
		if (!n) { return { items: [], cats: [], fixed: '', partial: false, tk: tk }; }
		var nds = tk.tokens.map(needles);
		P.items.forEach(function (row) {
			var sum = 0, hit = 0;
			for (var t = 0; t < n; t++) {
				var s = maybe(row.all, nds[t]) ? tokScore(tk.tokens[t], row.tw, row.xw) : 0;
				if (s > 0) { hit++; sum += s; }
			}
			if (!hit) { return; }
			var sc = sum * row.it.w;
			if (hit === n) { full.push([sc, row.it]); } else if (n > 1 && hit >= Math.ceil(n / 2)) { part.push([sc * hit / n, row.it]); }
		});
		var partial = !full.length && part.length > 0, list = partial ? part : full;
		list.sort(function (a, b) { return b[0] - a[0]; });
		var items = list.map(function (x) { return x[1]; });
		var pins = (D.pins || {})[tk.words.join(' ')];
		if (pins) {
			var byId = {};
			items.forEach(function (it) { byId[it.id] = it; });
			var pinned = [];
			P.items.forEach(function (r) { if (pins.indexOf(r.it.id) >= 0) { byId[r.it.id] = r.it; } });
			pins.forEach(function (id) { if (byId[id]) { pinned.push(byId[id]); } });
			items = pinned.concat(items.filter(function (it) { return pins.indexOf(it.id) < 0; }));
		}
		return { items: items, cats: catsFor(tk, items), fixed: tk.fixed, partial: partial, tk: tk };
	}

	/* Kategorie: nazwa pasuje do wpisanych słów albo najczęstsza kategoria wśród wyników. */
	function catsFor(tk, items) {
		var out = [], seen = {};
		P.cats.forEach(function (c) {
			var ok = tk.tokens.every(function (alts) { return tokScore(alts, c.w, []) >= 8; });
			if (ok) { out.push({ c: c, n: P.catCount[c.id] }); }
		});
		out.sort(function (a, b) { return b.n - a.n; });
		out = out.slice(0, 3);
		out.forEach(function (x) { seen[x.c.id] = 1; });
		if (out.length < 3 && items.length) {
			var cnt = {};
			items.slice(0, 60).forEach(function (it) { if (it.m) { cnt[it.m] = (cnt[it.m] || 0) + 1; } });
			Object.keys(cnt).sort(function (a, b) { return cnt[b] - cnt[a]; }).forEach(function (id) {
				if (out.length >= 3 || seen[id]) { return; }
				var c = null;
				P.cats.forEach(function (x) { if (x.id === +id) { c = x; } });
				if (c && cnt[id] >= 2) { out.push({ c: c, n: cnt[id], inres: true }); seen[id] = 1; }
			});
		}
		return out;
	}

	/*
	 * Podpowiedzi fraz (lewa kolumna, jak u największych sklepów):
	 * 1) frazy, których klienci szukali (statystyki, z wynikami) pasujące do wpisanych słów,
	 * 2) dokończenia z nazw pasujących produktów: „kask” → „kask rowerowy”, „kask motocyklowy fox”.
	 */
	function suggest(q, res) {
		var typed = norm(q), out = [], seen = {}, nt = res.tk.tokens.length;
		seen[typed] = 1;
		function add(p) {
			var k = norm(p);
			if (!k || seen[k] || out.length >= 6) { return; }
			seen[k] = 1;
			out.push(p);
		}
		(D.qs || []).forEach(function (x) {
			var w = norm(x[0]).split(' ');
			if (out.length < 3 && res.tk.tokens.every(function (alts) { return tokScore(alts, w, []) >= 8; })) { add(x[0]); }
		});
		var cnt = {}, first = {};
		res.items.slice(0, 80).forEach(function (it) {
			var words = it.t.toLowerCase().split(/\s+/), tw = words.map(norm), i;
			for (i = 0; i < tw.length; i++) {
				if (tokScore(res.tk.tokens[0], [tw[i]], []) >= 8) { break; }
			}
			if (i >= tw.length) { return; }
			// Okno od pierwszego dopasowanego słowa: wpisane słowa + jedno następne („kask” → „kask rowerowy”).
			for (var len = nt + 1; len <= nt + 2; len++) {
				var win = tw.slice(i, i + len);
				if (win.length < len) { break; }
				if (!res.tk.tokens.every(function (alts) { return tokScore(alts, win, []) >= 8; })) { continue; }
				var phr = words.slice(i, i + len).join(' ').replace(/[,;:()\/]+/g, ' ').replace(/\s+/g, ' ').trim();
				if (norm(phr) !== typed && norm(phr).split(' ').length === len) {
					cnt[phr] = (cnt[phr] || 0) + 1;
					if (first[phr] === undefined) { first[phr] = Object.keys(first).length; }
				}
				break;
			}
		});
		Object.keys(cnt).sort(function (a, b) { return cnt[b] - cnt[a] || first[a] - first[b]; }).forEach(function (p) {
			if (cnt[p] >= 2 || out.length < 3) { add(p); }
		});
		return out.slice(0, 6);
	}

	/* Kategorie nadrzędne: [ [nazwa, adres], … ] od najwyższej. */
	function catPath(id) {
		var out = [], c = D.cats[id], guard = 0;
		while (c && c[2] && D.cats[c[2]] && guard++ < 6) {
			c = D.cats[c[2]];
			out.unshift([c[0], c[1]]);
		}
		return out;
	}

	/* ---------- „wysyłka dziś / jutro / w poniedziałek” (czas polski, święta) ---------- */
	function shipWord() {
		var label = C.ship || 'Wysyłka dziś';
		if (!/dziś/i.test(label)) { return label; }
		try {
			var p = {};
			new Intl.DateTimeFormat('en-GB', { timeZone: 'Europe/Warsaw', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(new Date()).forEach(function (x) { p[x.type] = x.value; });
			var day = new Date(Date.UTC(+p.year, +p.month - 1, +p.day, 12)), sec = (+p.hour) * 3600 + (+p.minute) * 60;
			var cp = String(C.cutoff || '15:00').split(':'), cut = (+cp[0]) * 3600 + (+cp[1] || 0) * 60, hol = C.hol || [];
			var two = function (v) { return (v < 10 ? '0' : '') + v; };
			var work = function (d) { var w = d.getUTCDay(); return w && w !== 6 && hol.indexOf(d.getUTCFullYear() + '-' + two(d.getUTCMonth() + 1) + '-' + two(d.getUTCDate())) < 0; };
			if (work(day) && sec < cut) { return label; }
			var n = new Date(day.getTime());
			do { n.setUTCDate(n.getUTCDate() + 1); } while (!work(n));
			var diff = Math.round((n - day) / 864e5);
			return label.replace(/dziś/i, diff === 1 ? 'jutro' : ['w niedzielę', 'w poniedziałek', 'we wtorek', 'w środę', 'w czwartek', 'w piątek', 'w sobotę'][n.getUTCDay()]);
		} catch (e) { return label; }
	}

	/* ---------- dziennik ---------- */
	function log(data) {
		try {
			var f = new FormData();
			Object.keys(data).forEach(function (k) { f.append(k, data[k]); });
			if (navigator.sendBeacon) { navigator.sendBeacon(C.log, f); } else { fetch(C.log, { method: 'POST', body: f, keepalive: true }); }
		} catch (e) { /* bez znaczenia dla klienta */ }
	}

	/* ---------- widok ---------- */
	var money = (function () {
		try { var f = new Intl.NumberFormat('pl-PL', { style: 'currency', currency: 'PLN' }); return function (v) { return f.format(v); }; } catch (e) { return function (v) { return v.toFixed(2).replace('.', ',') + ' zł'; }; }
	}());
	function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
	function mark(title, tk) {
		var alts = [];
		tk.tokens.forEach(function (a) { a.forEach(function (x) { alts.push(x); var s = stem(x); if (s) { alts.push(s); } }); });
		return title.split(' ').map(function (word) {
			var nw = norm(word), best = 0;
			alts.forEach(function (a) { if (a && starts(nw, a) && a.length > best) { best = a.length; } });
			if (!best) { return esc(word); }
			// Długość dopasowania w oryginalnym zapisie (polskie znaki = 1 znak).
			return '<b>' + esc(word.slice(0, Math.min(word.length, best))) + '</b>' + esc(word.slice(Math.min(word.length, best)));
		}).join(' ');
	}
	function url(path) { return /^https?:/.test(path) ? path : C.home + path; }
	function allUrl(q) { return C.home + '/?s=' + encodeURIComponent(q) + '&post_type=product'; }

	function Box(input) {
		var form = input.closest('form'), wrap = input.closest('.fx-header__search') || form.parentNode;
		var header = !!input.closest('.fx-header__search');
		var el = document.createElement('div');
		el.className = 'fx-sd';
		el.setAttribute('role', 'listbox');
		el.id = 'fx-sd-' + input.id;
		el.hidden = true;
		wrap.appendChild(el);
		input.setAttribute('aria-controls', el.id);
		input.setAttribute('aria-autocomplete', 'list');
		input.setAttribute('autocomplete', 'off');
		var cancel = null;
		if (header) {
			cancel = document.createElement('button');
			cancel.type = 'button';
			cancel.className = 'fx-sd__cancel';
			cancel.textContent = 'Anuluj';
			form.appendChild(cancel);
			cancel.addEventListener('click', function () { close(); input.blur(); });
		}
		var res = null, active = -1, timer = null, lastLogged = '', lastQ = null;

		function open() {
			el.hidden = false;
			document.documentElement.classList.add('fx-sd-open');
			wrap.classList.add('is-sd');
		}
		function close() {
			el.hidden = true;
			active = -1;
			document.documentElement.classList.remove('fx-sd-open');
			wrap.classList.remove('is-sd');
		}
		function navs() { return el.querySelectorAll('[data-nav]'); }
		function setActive(i) {
			var n = navs();
			if (!n.length) { return; }
			active = (i + n.length) % n.length;
			n.forEach(function (x, k) { x.classList.toggle('is-active', k === active); x.setAttribute('aria-selected', k === active); });
			n[active].scrollIntoView({ block: 'nearest' });
		}

		function render() {
			var q = input.value.trim();
			if (q === lastQ && !el.hidden) { return; }
			lastQ = q;
			active = -1;
			if (!D) { return; }
			var h = '';
			if (q.length < 2) {
				res = null;
				if (D.pop && D.pop.length) {
					h = '<div class="fx-sd__empty"><p class="fx-sd__h">Często szukane</p><div class="fx-sd__chips">' + D.pop.map(function (p) {
						return '<a class="fx-chip" data-nav data-q="' + esc(p) + '" href="' + esc(allUrl(p)) + '">' + esc(p) + '</a>';
					}).join('') + '</div></div>';
				}
				el.innerHTML = h;
				if (h) { open(); } else { close(); }
				return;
			}
			res = search(q);
			var n = res.items.length;
			var sugg = suggest(q, res), cats = '', sg = '';
			if (sugg.length) {
				sg = '<div class="fx-sd__sugg"><p class="fx-sd__h">Podpowiedzi</p><ul class="fx-sd__phr">' + sugg.map(function (p) {
					return '<li><a data-nav href="' + esc(allUrl(p)) + '">' + mark(p, res.tk) + '</a></li>';
				}).join('') + '</ul></div>';
			}
			if (res.cats.length) {
				cats = '<div class="fx-sd__catbox"><p class="fx-sd__h">Kategorie</p><ul class="fx-sd__cats">' + res.cats.map(function (x) {
					// Ścieżka nad kategorią (np. „Off Road › Ochraniacze”) – każdy poziom klikalny.
					var path = catPath(x.c.id).slice(-2).map(function (p) {
						return '<a data-nav href="' + esc(url(p[1])) + '">' + esc(p[0]) + '</a>';
					}).join(' <span aria-hidden="true">›</span> ');
					return '<li>' + (path ? '<span class="fx-sd__path">' + path + ' <span aria-hidden="true">›</span></span>' : '') +
						'<a class="fx-sd__cat" data-nav href="' + esc(url(x.c.url)) + '"><span>' + mark(x.c.name, res.tk) + '</span> <span class="fx-sd__n">' + x.n + '</span></a></li>';
				}).join('') + '</ul></div>';
			}
			// Produkty: duże zdjęcie, cena, nazwa (jak u największych sklepów); bez rozmiarów i oznaczeń.
			var cards = res.items.slice(0, C.max).map(function (it) {
				return '<a class="fx-sd__card" data-nav data-pid="' + it.id + '" href="' + esc(url(it.u)) + '">' +
					'<span class="fx-sd__img">' + (it.i ? '<img src="' + esc(/^https?:/.test(it.i) ? it.i : D.img + it.i) + '" alt="" loading="lazy" width="300" height="300">' : '') +
					(it.pr ? '<span class="fx-sd__tag is-pre">Przedsprzedaż</span>' : '') + '</span>' +
					'<span class="fx-sd__title">' + mark(it.t, res.tk) + '</span>' +
					'<span class="fx-sd__price">' + (it.r ? '<ins>' + money(it.p) + '</ins><del>' + money(it.r) + '</del>' : (it.p ? money(it.p) : '')) + '</span></a>';
			}).join('');
			h = '';
			if (!n) {
				h += (res.fixed ? '<p class="fx-sd__fix">Pokazujemy wyniki dla <b>„' + esc(res.fixed) + '”</b></p>' : '') +
					'<div class="fx-sd__none"><p>Nic nie znaleźliśmy dla „' + esc(q) + '”.</p><p>Spróbuj samego modelu (np. „V1”, „Proframe”) albo rodzaju produktu (np. „kask”, „gogle”).</p>' +
					(D.pop && D.pop.length ? '<div class="fx-sd__chips">' + D.pop.map(function (p) { return '<a class="fx-chip" data-nav data-q="' + esc(p) + '" href="' + esc(allUrl(p)) + '">' + esc(p) + '</a>'; }).join('') + '</div>' : '') + '</div>';
			} else {
				var note = res.fixed ? '<p class="fx-sd__fix">Pokazujemy wyniki dla <b>„' + esc(res.fixed) + '”</b></p>' : (res.partial ? '<p class="fx-sd__fix">Brak produktów ze wszystkimi słowami – najbliższe wyniki:</p>' : '');
				h += '<div class="fx-sd__grid' + (sg || cats ? '' : ' no-side') + '">' + sg + cats +
					'<div class="fx-sd__main"><div class="fx-sd__head"><p class="fx-sd__h">Produkty</p><a class="fx-sd__more" href="' + esc(allUrl(res.fixed || q)) + '">Zobacz wszystkie (' + n + ')</a></div>' + note + '<div class="fx-sd__cards">' + cards + '</div>' +
					'<a class="fx-sd__all" data-nav href="' + esc(allUrl(res.fixed || q)) + '">Zobacz wszystkie wyniki (' + n + ') <span aria-hidden="true">»</span></a></div></div>';
			}
			el.innerHTML = h;
			open();
			clearTimeout(timer);
			timer = setTimeout(function () {
				var key = norm(q);
				if (key.length >= 2 && key !== lastLogged) { lastLogged = key; log({ q: q, n: n, src: 'dd' }); }
			}, 1500);
		}

		var raf = 0;
		function onInput() { // szybkie pisanie: jedno przeliczenie na klatkę obrazu
			if (!D) { load().then(render); return; }
			cancelAnimationFrame(raf);
			raf = requestAnimationFrame(render);
		}
		input.addEventListener('input', onInput);
		input.addEventListener('focus', function () { lastQ = null; onInput(); });
		['pointerenter', 'touchstart'].forEach(function (ev) { input.addEventListener(ev, load, { passive: true }); });
		input.addEventListener('keydown', function (e) {
			if (el.hidden) { return; }
			if (e.key === 'ArrowDown') { e.preventDefault(); setActive(active + 1); } else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(active - 1); } else if (e.key === 'Escape') { close(); } else if (e.key === 'Enter' && active >= 0) {
				var a = navs()[active];
				if (a) { e.preventDefault(); a.click(); }
			}
		});
		el.addEventListener('click', function (e) {
			var a = e.target.closest('a');
			if (!a) { return; }
			var q = input.value.trim();
			if (a.dataset.q) { e.preventDefault(); input.value = a.dataset.q; lastQ = null; render(); input.focus(); return; }
			if (a.dataset.pid) { log({ q: q, n: res ? res.items.length : 0, pid: a.dataset.pid, src: 'ddc' }); }
		});
		el.addEventListener('mousedown', function (e) { if (e.target.closest('a')) { e.preventDefault(); } }); // bez utraty fokusu przed kliknięciem
		form.addEventListener('submit', function () {
			if (res && res.fixed) { input.value = res.fixed; }
		});
		document.addEventListener('click', function (e) {
			if (!el.hidden && !wrap.contains(e.target)) { close(); }
		});
		return { close: close };
	}

	function init() {
		document.querySelectorAll('form.fx-search input[name="s"]').forEach(function (input) {
			if (!input.dataset.fxSd) { input.dataset.fxSd = 1; Box(input); }
		});
		// Strona wyników: zapis wyszukania i kliknięć w produkty.
		if (C.q) {
			if (!/\/page\/\d+/.test(location.pathname) && !/[?&]paged=/.test(location.search) && !/[?&](rozmiar|kolor|orderby|promocja|wysylka24|wsklepie)=/.test(location.search)) {
				log({ q: C.q, n: C.n, src: 'page' });
			}
			document.addEventListener('click', function (e) {
				var a = e.target.closest('ul.products li.product a');
				if (!a) { return; }
				var li = a.closest('li.product'), m = li && li.className.match(/\bpost-(\d+)\b/);
				if (m) { log({ q: C.q, n: C.n, pid: m[1], src: 'pagec' }); }
			});
		}
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
	window.fxSearchEngine = { load: load, search: function (q) { return D ? search(q) : null; } };
}());
