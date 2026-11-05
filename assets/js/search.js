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
			P = { items: [], vocab: {}, catCount: {}, cats: [], catById: {} };
			d.items.forEach(function (it) {
				var tw = norm(it.t).split(' ');
				tw.forEach(function (w) { if (w.length > 2 && !/^\d+$/.test(w)) { P.vocab[w] = (P.vocab[w] || 0) + 1; } });
				it.c.forEach(function (c) { P.catCount[c] = (P.catCount[c] || 0) + 1; });
				P.items.push({ it: it, tw: tw, xw: it.x ? it.x.split(' ') : [], all: tw.join(' ') + ' ' + it.x });
			});
			Object.keys(d.cats).forEach(function (id) {
				var c = d.cats[id];
				P.catById[id] = { id: +id, name: c[0], url: c[1], parent: c[2], w: norm(c[0]).split(' ') };
				if (P.catCount[id]) { P.cats.push(P.catById[id]); }
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

	/*
	 * Synonimy-frazy („nakolanniki, ochraniacze kolan”): gdy zapytanie zawiera jedną z fraz grupy
	 * (z odmianą: „ochraniacz kolana” = „ochraniacze kolan”), szukamy też wersji z pozostałymi frazami.
	 * Wynik produktu = najlepszy z wersji (zamienione trochę słabiej niż wpisane). Pojedyncze słowa
	 * obsługuje synOf() na poziomie słowa.
	 */
	function sameWord(a, b) { return a === b || (stem(a) || a) === (stem(b) || b); }
	function findSeq(qw, mw) {
		for (var i = 0; i + mw.length <= qw.length; i++) {
			var ok = true;
			for (var j = 0; j < mw.length; j++) { if (!sameWord(qw[i + j], mw[j])) { ok = false; break; } }
			if (ok) { return i; }
		}
		return -1;
	}
	function variants(q) {
		var qw = norm(q).split(' ').filter(Boolean), out = [{ q: qw.join(' '), f: 1 }], seen = {};
		seen[out[0].q] = 1;
		(D.syn || []).forEach(function (g) {
			g.forEach(function (m) {
				var mw = m.split(' '), i = findSeq(qw, mw);
				if (i < 0) { return; }
				g.forEach(function (o) {
					if (o === m || (m.indexOf(' ') < 0 && o.indexOf(' ') < 0)) { return; } // słowo ↔ słowo: synOf()
					var v = qw.slice(0, i).concat(o.split(' '), qw.slice(i + mw.length)).join(' ');
					if (!seen[v] && out.length < 8) { seen[v] = 1; out.push({ q: v, f: 0.75 }); }
				});
			});
		});
		return out;
	}
	function search(q) {
		// Poprawka z panelu (Analityka → Bez wyników): „nakolanniki” → „ochraniacz kolan”.
		var mapped = D.map && D.map[norm(q)];
		var vars = variants(mapped || q), tk = null, best = {}, bestPart = {}, mk = [];
		vars.forEach(function (v, vi) {
			var t = tokens(v.q), n = t.tokens.length;
			if (!vi) { tk = t; }
			if (!n) { return; }
			var nds = t.tokens.map(needles);
			P.items.forEach(function (row) {
				var sum = 0, hit = 0;
				for (var k = 0; k < n; k++) {
					var s = maybe(row.all, nds[k]) ? tokScore(t.tokens[k], row.tw, row.xw) : 0;
					if (s > 0) { hit++; sum += s; }
				}
				if (!hit) { return; }
				var sc = sum / n * row.it.w * v.f, id = row.it.id; // średnia na słowo – wersje frazy o różnej długości porównywalne
				if (hit === n) { if (!best[id] || best[id][0] < sc) { best[id] = [sc, row.it]; } } else if (n > 1 && hit >= Math.ceil(n / 2)) {
					sc = sc * hit / n;
					if (!bestPart[id] || bestPart[id][0] < sc) { bestPart[id] = [sc, row.it]; }
				}
			});
			mk = mk.concat(t.tokens); // podświetlenie także słów z synonimu
		});
		if (mapped) { tk.fixed = mapped; }
		if (!tk || !tk.tokens.length) { return { items: [], cats: [], fixed: '', partial: false, tk: tk || { tokens: [], words: [] } }; }
		var full = Object.keys(best).map(function (k) { return best[k]; }), part = Object.keys(bestPart).filter(function (k) { return !best[k]; }).map(function (k) { return bestPart[k]; });
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
		return { items: items, cats: catsFor(tk, items), fixed: tk.fixed, partial: partial, tk: tk, mk: { tokens: mk } };
	}

	/* Kategorie: nazwa pasuje do wpisanych słów albo najczęstsza kategoria wśród wyników. */
	/*
	 * Kategorie w podpowiedziach: najwęższa kategoria, w której mieści się większość wyników
	 * z danego świata (dział główny: OFF ROAD / ROWEROWY …), ze ścieżką nad nazwą.
	 * „kask fox” → OFF ROAD › Kaski i ROWEROWY › Kaski rowerowe (wyniki w wielu modelach),
	 * „ochraniacz kolan” → OFF ROAD › Ochraniacze › Kolana i łokcie (a nie wszystkie ochraniacze).
	 * Gdy w świecie nie ma jednej wspólnej kategorii – jego 2 największe kategorie pod działem.
	 */
	function chain(id) {
		var ids = [], cur = id, c = D.cats[cur], g = 0;
		while (c && g++ < 8) { ids.unshift(+cur); cur = c[2]; c = cur ? D.cats[cur] : null; }
		return ids;
	}
	function catsFor(tk, items) {
		var worlds = {}, order = [];
		items.forEach(function (it) {
			if (!it.m) { return; }
			var ch = chain(it.m);
			if (ch.length < 2) { return; } // tylko dział główny – nic nie mówi klientowi
			var w = worlds[ch[0]];
			if (!w) { w = worlds[ch[0]] = { n: 0, cnt: {}, depth: {} }; order.push(ch[0]); }
			w.n++;
			for (var i = 1; i < ch.length; i++) { w.cnt[ch[i]] = (w.cnt[ch[i]] || 0) + 1; w.depth[ch[i]] = i; }
		});
		order.sort(function (a, b) { return worlds[b].n - worlds[a].n; });
		var out = [];
		order.forEach(function (root) {
			var w = worlds[root], best = 0, ids = Object.keys(w.cnt);
			if (w.n < 2 && out.length) { return; } // pojedyncze, przypadkowe trafienie
			ids.forEach(function (id) {
				if (w.cnt[id] >= Math.ceil(w.n * 0.8) && (!best || w.depth[id] > w.depth[best] || (w.depth[id] === w.depth[best] && w.cnt[id] > w.cnt[best]))) { best = id; }
			});
			if (best) {
				if (P.catById[best]) { out.push({ c: P.catById[best], n: w.cnt[best] }); }
				return;
			}
			ids.filter(function (id) { return w.depth[id] === 1; }).sort(function (a, b) { return w.cnt[b] - w.cnt[a]; }).slice(0, 2).forEach(function (id) {
				if (P.catById[id]) { out.push({ c: P.catById[id], n: w.cnt[id] }); }
			});
		});
		return out.slice(0, 4);
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
	/*
	 * Dane do analityki (bez danych osobowych):
	 * - fx_sid: losowy identyfikator sesji przeglądarki (ciasteczko sesyjne) – ile osób szuka, co wpisują po braku wyników,
	 * - urządzenie (telefon / komputer), pozycja klikniętego wyniku,
	 * - fx_sq: kliknięte z wyszukiwarki produkty z frazą (7 dni) – przypisanie koszyka i zamówienia do frazy.
	 */
	function cookie(name) {
		var m = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
		return m ? decodeURIComponent(m[1]) : '';
	}
	function sid() {
		var v = cookie('fx_sid');
		if (!/^[a-z0-9]{12,16}$/.test(v)) {
			v = (Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2)).replace(/[^a-z0-9]/g, '').slice(0, 14);
			document.cookie = 'fx_sid=' + v + ';path=/;SameSite=Lax';
		}
		return v;
	}
	function clicks() {
		var out = [];
		cookie('fx_sq').split('|').forEach(function (x) { var p = x.split('~'); if (p.length === 2 && /^\d+$/.test(p[0])) { out.push([p[0], p[1]]); } });
		return out;
	}
	function remember(pid, q) {
		var list = clicks().filter(function (x) { return x[0] !== String(pid); });
		list.unshift([String(pid), String(q).replace(/[|~;]/g, ' ').slice(0, 60)]);
		document.cookie = 'fx_sq=' + encodeURIComponent(list.slice(0, 10).map(function (x) { return x[0] + '~' + x[1]; }).join('|')) + ';path=/;max-age=604800;SameSite=Lax';
	}
	function qFor(pid) {
		var q = '';
		clicks().forEach(function (x) { if (!q && x[0] === String(pid)) { q = x[1]; } });
		return q;
	}
	/* Skąd klient przyszedł na stronę wyników: wpisane + Enter, „Często szukane”, podpowiedź frazy, „Zobacz wszystkie”. */
	function setVia(v) { try { sessionStorage.setItem('fxvia', v); } catch (e) { /* tryb prywatny */ } }
	function takeVia() {
		var v = 'link';
		try { v = sessionStorage.getItem('fxvia') || 'link'; sessionStorage.removeItem('fxvia'); } catch (e) { /* tryb prywatny */ }
		return v;
	}
	function log(data) {
		try {
			data.sid = sid();
			data.dev = window.innerWidth < 768 ? 'm' : 'd';
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
					return '<li><a data-nav data-via="phr" href="' + esc(allUrl(p)) + '">' + mark(p, res.mk || res.tk) + '</a></li>';
				}).join('') + '</ul></div>';
			}
			if (res.cats.length) {
				cats = '<div class="fx-sd__catbox"><p class="fx-sd__h">Kategorie</p><ul class="fx-sd__cats">' + res.cats.map(function (x) {
					// Ścieżka nad kategorią (np. „Off Road › Ochraniacze”) – każdy poziom klikalny.
					var pth = catPath(x.c.id);
					if (pth.length > 2) { pth = [pth[0], pth[pth.length - 1]]; } // dział + bezpośredni rodzic
					var path = pth.map(function (p) {
						return '<a data-nav data-cat="' + esc(p[0]) + '" href="' + esc(url(p[1])) + '">' + esc(p[0]) + '</a>';
					}).join(' <span aria-hidden="true">›</span> ');
					return '<li>' + (path ? '<span class="fx-sd__path">' + path + ' <span aria-hidden="true">›</span></span>' : '') +
						'<a class="fx-sd__cat" data-nav data-cat="' + esc(x.c.name) + '" href="' + esc(url(x.c.url)) + '"><span>' + esc(x.c.name) + '</span> <span class="fx-sd__n">' + x.n + '</span></a></li>';
				}).join('') + '</ul></div>';
			}
			// Produkty: duże zdjęcie, cena, nazwa (jak u największych sklepów); bez rozmiarów i oznaczeń.
			var cards = res.items.slice(0, C.max).map(function (it) {
				return '<a class="fx-sd__card" data-nav data-pid="' + it.id + '" href="' + esc(url(it.u)) + '">' +
					'<span class="fx-sd__img">' + (it.i ? '<img src="' + esc(/^https?:/.test(it.i) ? it.i : D.img + it.i) + '" alt="" loading="lazy" width="300" height="300">' : '') +
					(it.pr ? '<span class="fx-sd__tag is-pre">Przedsprzedaż</span>' : '') + '</span>' +
					'<span class="fx-sd__title">' + mark(it.t, res.mk || res.tk) + '</span>' +
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
					'<div class="fx-sd__main"><div class="fx-sd__head"><p class="fx-sd__h">Produkty</p><a class="fx-sd__more" data-via="all" href="' + esc(allUrl(res.fixed || q)) + '">Zobacz wszystkie (' + n + ')</a></div>' + note + '<div class="fx-sd__cards">' + cards + '</div>' +
					'<a class="fx-sd__all" data-nav data-via="all" href="' + esc(allUrl(res.fixed || q)) + '">Zobacz wszystkie wyniki (' + n + ') <span aria-hidden="true">»</span></a></div></div>';
			}
			el.innerHTML = h;
			open();
			clearTimeout(timer);
			timer = setTimeout(function () {
				var key = norm(q);
				if (key.length >= 2 && key !== lastLogged) { lastLogged = key; log({ q: q, n: n, src: 'dd', via: via }); }
			}, 1500);
		}

		var raf = 0;
		function onInput() { // szybkie pisanie: jedno przeliczenie na klatkę obrazu
			if (!D) { load().then(render); return; }
			cancelAnimationFrame(raf);
			raf = requestAnimationFrame(render);
		}
		var via = 'typed'; // skąd fraza: wpisana / „Często szukane”
		input.addEventListener('input', function () { via = 'typed'; onInput(); });
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
			if (a.dataset.via) { setVia(a.dataset.via); }
			if (a.dataset.cat) { log({ q: q, n: res ? res.items.length : 0, src: 'cat', x: a.dataset.cat }); }
			if (a.dataset.q) { e.preventDefault(); e.stopPropagation(); via = 'pop'; input.value = a.dataset.q; lastQ = null; render(); input.focus(); var l = input.value.length; try { input.setSelectionRange(l, l); } catch (x) { /* type=search */ } return; }
			if (a.dataset.pid) {
				var cards = Array.prototype.slice.call(el.querySelectorAll('[data-pid]'));
				log({ q: q, n: res ? res.items.length : 0, pid: a.dataset.pid, pos: cards.indexOf(a) + 1, src: 'ddc' });
				remember(a.dataset.pid, q);
			}
		});
		el.addEventListener('mousedown', function (e) { if (e.target.closest('a')) { e.preventDefault(); } }); // bez utraty fokusu przed kliknięciem
		form.addEventListener('submit', function () {
			setVia(via === 'pop' ? 'pop' : 'enter');
			if (res && res.fixed) { input.value = res.fixed; }
		});
		document.addEventListener('click', function (e) {
			// Element podmieniony w trakcie kliknięcia (np. „Często szukane”) nie jest już w dokumencie – to nie klik „poza oknem”.
			if (!el.hidden && e.target.isConnected && !wrap.contains(e.target)) { close(); }
		});
		return { close: close };
	}

	function init() {
		document.querySelectorAll('form.fx-search input[name="s"]').forEach(function (input) {
			if (!input.dataset.fxSd) { input.dataset.fxSd = 1; Box(input); }
		});
		// Dodanie do koszyka produktu, który klient znalazł wyszukiwarką (do 7 dni od kliknięcia).
		function cartLog(pid) {
			var q = qFor(pid);
			if (q) { log({ q: q, pid: pid, src: 'cart' }); }
		}
		document.addEventListener('submit', function (e) {
			var f = e.target.closest && e.target.closest('form.cart');
			if (!f) { return; }
			var b = f.querySelector('[name="add-to-cart"]'), pid = b && b.value;
			if (pid) { cartLog(pid); }
		}, true);
		if (window.jQuery) {
			window.jQuery(document.body).on('added_to_cart', function (e, frags, hash, btn) {
				var pid = btn && (btn.data ? btn.data('product_id') : '');
				if (pid) { cartLog(pid); }
			});
		}
		// Strona wyników: zapis wyszukania i kliknięć w produkty.
		if (C.q) {
			if (!/\/page\/\d+/.test(location.pathname) && !/[?&]paged=/.test(location.search) && !/[?&](dla|rozmiar|kolor|orderby|promocja|wysylka24|wsklepie)=/.test(location.search)) {
				log({ q: C.q, n: C.n, src: 'page', via: takeVia() });
			}
			// Filtry i sortowanie użyte na wynikach (raz na frazę i zestaw filtrów w sesji).
			try {
				var sp = new URLSearchParams(location.search), fl = [];
				['dla', 'rozmiar', 'kolor', 'promocja', 'wysylka24', 'wsklepie', 'przedsprzedaz', 'orderby'].forEach(function (k) { var v = sp.get(k); if (v) { fl.push(k + '=' + v); } });
				var fk = 'fxflt|' + C.q + '|' + fl.join('|');
				if (fl.length && !sessionStorage.getItem(fk)) { sessionStorage.setItem(fk, '1'); log({ q: C.q, n: C.n, src: 'flt', x: fl.join('|') }); }
			} catch (e) { /* stara przeglądarka / tryb prywatny */ }
			// Strona, od której klient zaczął (lista doładowuje kolejne strony i zmienia adres na /page/N/).
			var startPage = +((location.pathname.match(/\/page\/(\d+)/) || [0, 1])[1]);
			document.addEventListener('click', function (e) {
				// Tylko główna lista wyników (nie produkty z menu, „ostatnio oglądane” itp.).
				var a = e.target.closest('#fx-main ul.products li.product a');
				if (!a || a.closest('header, nav, .fx-mega')) { return; }
				var li = a.closest('li.product'), m = li && li.className.match(/\bpost-(\d+)\b/);
				if (m) {
					var all = Array.prototype.slice.call(li.parentNode.children).filter(function (x) { return x.matches && x.matches('li.product'); });
					log({ q: C.q, n: C.n, pid: m[1], pos: all.indexOf(li) + 1 + (startPage - 1) * (C.pp || 12), src: 'pagec' });
					remember(m[1], C.q);
				}
			});
		}
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
	window.fxSearchEngine = { load: load, search: function (q) { return D ? search(q) : null; } };
}());
