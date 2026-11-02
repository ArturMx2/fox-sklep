<?php
/**
 * Komunikat o wysyłce nad przyciskiem „Dodaj do koszyka” (jak w „FOX – Termin wysyłki i dostawy” 1.2.1).
 *
 * - konkretny dzień: towar w sklepie stacjonarnym (lista z synchronizacji Base) – „Wyślemy jeszcze dziś”
 *   przed godziną graniczną, pozostałe – „Wyślemy jutro (środa)”; plus przewidywana dostawa i licznik,
 * - produkt z rozmiarami: przed wyborem „Część rozmiarów wyślemy jeszcze dziś”, po wyborze – termin rozmiaru,
 * - produkty proste i wariantowe; ukryty dla niedostępnego wariantu,
 * - czas zawsze polski (Europe/Warsaw), polskie święta ustawowe liczone automatycznie,
 * - bez zapytań do BaseLinkera – ta sama lista i te same zasady co koszyk.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

/** Polskie dni wolne (święta ustawowe) w danym roku: ['YYYY-MM-DD', …]. */
function fx_pl_holidays( $y ) {
	$e   = fx_easter_ts( $y );
	$add = function ( $days ) use ( $e ) {
		return gmdate( 'Y-m-d', $e + $days * DAY_IN_SECONDS );
	};
	$fixed = array( '01-01', '01-06', '05-01', '05-03', '08-15', '11-01', '11-11', '12-24', '12-25', '12-26' );
	$out   = array();
	foreach ( $fixed as $md ) {
		$out[] = $y . '-' . $md;
	}
	$out[] = $add( 0 );  // Wielkanoc.
	$out[] = $add( 1 );  // Poniedziałek Wielkanocny.
	$out[] = $add( 49 ); // Zielone Świątki.
	$out[] = $add( 60 ); // Boże Ciało.
	return $out;
}

/** Data Wielkanocy (UTC, północ) – bez rozszerzenia calendar. */
function fx_easter_ts( $y ) {
	$a = $y % 19;
	$b = intdiv( $y, 100 );
	$c = $y % 100;
	$d = intdiv( $b, 4 );
	$e = $b % 4;
	$f = intdiv( $b + 8, 25 );
	$g = intdiv( $b - $f + 1, 3 );
	$h = ( 19 * $a + $b - $d - $g + 15 ) % 30;
	$i = intdiv( $c, 4 );
	$k = $c % 4;
	$l = ( 32 + 2 * $e + 2 * $i - $h - $k ) % 7;
	$m = intdiv( $a + 11 * $h + 22 * $l, 451 );
	$month = intdiv( $h + $l - 7 * $m + 114, 31 );
	$day   = ( ( $h + $l - 7 * $m + 114 ) % 31 ) + 1;
	return gmmktime( 0, 0, 0, $month, $day, $y );
}

/** Ustawienia komunikatu: z „Termin wysyłki FOX” (BaseLinker), a gdy go nie ma – z Narzędzi sklepu. */
function fx_ship_cta_conf() {
	$conf = array(
		'on'      => (bool) fx_tool( 'ship_cta' ),
		'cutoff'  => sprintf( '%02d:00', max( 0, min( 23, (int) fx_tool( 'ship_cutoff' ) ) ) ),
		'blocked' => array(),
	);
	if ( function_exists( 'fox_bl_delivery_get_settings' ) ) {
		$s               = fox_bl_delivery_get_settings();
		$conf['on']      = ! empty( $s['show_product_cta'] );
		$conf['cutoff']  = $s['cutoff_time'];
		$conf['blocked'] = function_exists( 'fox_bl_delivery_parse_blocked_dates' ) ? array_keys( fox_bl_delivery_parse_blocked_dates( $s['blocked_dates'] ) ) : array();
		$conf['days_local'] = (int) ( $s['days_local'] ?? 0 );
		$conf['days_other'] = (int) ( $s['days_other'] ?? 1 );
	}
	$conf += array( 'days_local' => 0, 'days_other' => 1 );
	$y               = (int) wp_date( 'Y' );
	$conf['blocked'] = array_values( array_unique( array_merge( $conf['blocked'], fx_pl_holidays( $y ), fx_pl_holidays( $y + 1 ) ) ) );
	return $conf;
}

/* Nad wyborem ilości i przyciskiem: konkretny dzień wysyłki i dostawy dla produktu / wybranego rozmiaru. */
add_action( 'woocommerce_before_add_to_cart_button', function () {
	if ( ! is_product() ) {
		return;
	}
	$conf = fx_ship_cta_conf();
	if ( ! $conf['on'] ) {
		return;
	}
	global $product;
	if ( ! $product instanceof WC_Product || ! $product->is_purchasable() ) {
		return;
	}
	$ok    = $product->is_in_stock();
	$local = array();
	if ( function_exists( 'fx_ls_data' ) ) {
		$st = (array) ( fx_ls_data()['store'] ?? array() );
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $vid ) {
				if ( (int) ( $st[ $vid ] ?? 0 ) > 0 ) {
					$local[] = $vid;
				}
			}
		} elseif ( (int) ( $st[ $product->get_id() ] ?? 0 ) > 0 ) {
			$local[] = $product->get_id();
		}
	}
	$state = $product->is_type( 'variable' ) ? ( $local ? 'some' : 'none' ) : ( $local ? 'all' : 'none' );
	/**
	 * Własny komunikat zamiast terminu (np. przedsprzedaż): [ 'title' => …, 'desc' => … ] albo null.
	 * Dla wariantów – pole „fx_ship_override” w danych wariantu (ten sam format).
	 */
	$override = $product->is_type( 'variable' ) ? null : apply_filters( 'fx_ship_cta_override', null, $product );
	?>
	<div class="fx-ship" id="fx-ship" data-cutoff="<?php echo esc_attr( $conf['cutoff'] ); ?>" data-holidays="<?php echo esc_attr( implode( ',', $conf['blocked'] ) ); ?>" data-available="<?php echo $ok ? '1' : '0'; ?>" data-local="<?php echo esc_attr( $state ); ?>" data-days-local="<?php echo (int) $conf['days_local']; ?>" data-days-other="<?php echo (int) $conf['days_other']; ?>"<?php echo $override ? ' data-override="' . esc_attr( wp_json_encode( $override ) ) . '"' : ''; ?> aria-live="polite"<?php echo $ok ? '' : ' hidden'; ?>>
		<span class="fx-ship__icon" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M3.5 6.5h10v9h-10zM13.5 9h3.4l3.1 3.2v3.3h-6.5z"/><circle cx="7" cy="17.3" r="1.7"/><circle cx="17" cy="17.3" r="1.7"/></svg></span>
		<div class="fx-ship__body">
			<div class="fx-ship__top"><p class="fx-ship__title" data-fx-ship-title>Wysyłka w 1–2 dni robocze</p><span class="fx-ship__badge" data-fx-ship-badge hidden></span></div>
			<p class="fx-ship__desc" data-fx-ship-desc></p>
			<p class="fx-ship__count" data-fx-ship-count hidden></p>
		</div>
	</div>
	<?php
}, 8 );

add_action( 'wp_footer', function () {
	if ( ! is_product() ) {
		return;
	}
	?>
	<script>
	(function () {
		var box = document.getElementById('fx-ship');
		if (!box) { return; }
		var t = box.querySelector('[data-fx-ship-title]'), d = box.querySelector('[data-fx-ship-desc]'), c = box.querySelector('[data-fx-ship-count]'), bdg = box.querySelector('[data-fx-ship-badge]');
		var cp = (box.dataset.cutoff || '15:00').split(':'), ch = parseInt(cp[0] || '15', 10), cm = parseInt(cp[1] || '0', 10), hol = (box.dataset.holidays || '').split(','), parentOk = box.dataset.available === '1';
		var dl = parseInt(box.dataset.daysLocal || '0', 10), dOther = parseInt(box.dataset.daysOther || '1', 10), state = box.dataset.local || 'none', cur = state;
		var ovr = null; try { ovr = box.dataset.override ? JSON.parse(box.dataset.override) : null; } catch (e) {} var ovr0 = ovr;
		var DN = ['niedziela','poniedziałek','wtorek','środa','czwartek','piątek','sobota'], DW = ['w niedzielę','w poniedziałek','we wtorek','w środę','w czwartek','w piątek','w sobotę'];
		function two(v) { return (v < 10 ? '0' : '') + v; }
		function now() {
			try {
				var p = {}; new Intl.DateTimeFormat('en-GB', { timeZone: 'Europe/Warsaw', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' }).formatToParts(new Date()).forEach(function (x) { p[x.type] = x.value; });
				return { date: p.year + '-' + p.month + '-' + p.day, s: (+p.hour) * 3600 + (+p.minute) * 60 + (+p.second) };
			} catch (e) {
				var n = new Date();
				return { date: n.getFullYear() + '-' + two(n.getMonth() + 1) + '-' + two(n.getDate()), s: n.getHours() * 3600 + n.getMinutes() * 60 + n.getSeconds() };
			}
		}
		function D(s) { var a = s.split('-'); return new Date(Date.UTC(+a[0], +a[1] - 1, +a[2], 12)); }
		function S(dt) { return dt.getUTCFullYear() + '-' + two(dt.getUTCMonth() + 1) + '-' + two(dt.getUTCDate()); }
		function work(dt) { var w = dt.getUTCDay(); return w !== 0 && w !== 6 && hol.indexOf(S(dt)) === -1; }
		function addBiz(dt, n) { var r = new Date(dt.getTime()); while (n > 0) { r.setUTCDate(r.getUTCDate() + 1); if (work(r)) { n--; } } return r; }
		function nextBiz(dt) { return addBiz(dt, 1); }
		function short(dt) { return two(dt.getUTCDate()) + '.' + two(dt.getUTCMonth() + 1); }
		function pl(n, a, b, cc) { var l = n % 10, ll = n % 100; return n === 1 ? a : (l >= 2 && l <= 4 && !(ll >= 12 && ll <= 14) ? b : cc); }
		function when(dt, today) {
			var diff = Math.round((dt - today) / 864e5);
			if (diff === 0) { return 'dziś'; }
			if (diff === 1) { return 'jutro (' + DN[dt.getUTCDay()] + ')'; }
			return DW[dt.getUTCDay()] + ' (' + short(dt) + ')';
		}
		function update() {
			if (box.hidden) { return; }
			if (ovr) {
				box.classList.remove('is-today'); box.classList.add('is-pre');
				t.textContent = ovr.title || ''; d.textContent = ovr.desc || '';
				bdg.textContent = ovr.badge || ''; bdg.hidden = !ovr.badge; c.textContent = ''; c.hidden = true;
				return;
			}
			box.classList.remove('is-pre');
			var x = now(), today = D(x.date), cut = ch * 3600 + cm * 60, before = work(today) && x.s < cut;
			var base = before ? today : nextBiz(today);
			var days = cur === 'none' ? dOther : dl;
			var ship = addBiz(base, days), dlv = addBiz(ship, 1), shipToday = S(ship) === x.date;
			box.classList.toggle('is-today', shipToday);
			if (cur === 'some') {
				t.textContent = shipToday ? 'Część rozmiarów wyślemy jeszcze dziś' : 'Część rozmiarów wyślemy ' + when(ship, today);
				d.textContent = 'Wybierz rozmiar – pokażemy dokładny termin wysyłki i dostawy.';
			} else {
				t.textContent = shipToday ? 'Wyślemy jeszcze dziś' : 'Wyślemy ' + when(ship, today);
				d.textContent = 'Przewidywana dostawa: ' + DN[dlv.getUTCDay()] + ', ' + short(dlv) + '.' + (cur === 'all' ? ' Produkt jest w naszym sklepie stacjonarnym.' : '');
			}
			if (before) {
				bdg.textContent = 'zamów do ' + two(ch) + ':' + two(cm); bdg.hidden = false;
				var m = Math.max(0, Math.ceil((cut - x.s) / 60)), h = Math.floor(m / 60); m = m % 60;
				c.textContent = 'Do godziny granicznej pozostało ' + (h > 0 ? h + ' ' + pl(h, 'godzina', 'godziny', 'godzin') + (m > 0 ? ' ' + m + ' min' : '') : m + ' ' + pl(m, 'minuta', 'minuty', 'minut'));
				c.hidden = false;
			} else {
				bdg.hidden = true; c.textContent = ''; c.hidden = true;
			}
		}
		function show() { box.hidden = false; update(); }
		function hide() { box.hidden = true; }
		if (window.jQuery) {
			var $f = jQuery('form.variations_form');
			$f.on('found_variation', function (e, v) { cur = v && v.fx_store ? 'all' : 'none'; ovr = v && v.fx_ship_override ? v.fx_ship_override : null; (v && v.is_in_stock && v.is_purchasable) ? show() : hide(); });
			$f.on('reset_data hide_variation', function () { cur = state; ovr = ovr0; parentOk ? show() : hide(); });
		}
		parentOk ? show() : hide();
		setInterval(update, 30000);
	})();
	</script>
	<?php
}, 50 );
