<?php
/**
 * Komunikat o wysyłce pod przyciskiem „Dodaj do koszyka” (jak na enduro.sklep.pl).
 *
 * - dzień roboczy przed godziną graniczną (domyślnie 15:00): „Zamów do 15:00 – większość
 *   produktów wysyłamy jeszcze dziś” + licznik czasu,
 * - po godzinie granicznej, w weekend i w święta: neutralny komunikat zachęcający do sprawdzenia
 *   terminu w koszyku,
 * - produkty proste i wariantowe; ukryty dla niedostępnego wariantu,
 * - czas zawsze polski (Europe/Warsaw), polskie święta ustawowe liczone automatycznie,
 * - bez zapytań do BaseLinkera – dokładny termin liczy koszyk.
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

add_action( 'woocommerce_after_add_to_cart_button', function () {
	if ( ! is_product() || ! fx_tool( 'ship_cta' ) ) {
		return;
	}
	global $product;
	if ( ! $product instanceof WC_Product || ! $product->is_purchasable() ) {
		return;
	}
	$y        = (int) wp_date( 'Y' );
	$holidays = array_merge( fx_pl_holidays( $y ), fx_pl_holidays( $y + 1 ) );
	$cutoff   = max( 0, min( 23, (int) fx_tool( 'ship_cutoff' ) ) );
	$ok       = $product->is_in_stock();
	?>
	<div class="fx-ship" id="fx-ship" data-cutoff="<?php echo (int) $cutoff; ?>" data-holidays="<?php echo esc_attr( implode( ',', $holidays ) ); ?>" data-available="<?php echo $ok ? '1' : '0'; ?>" aria-live="polite"<?php echo $ok ? '' : ' hidden'; ?>>
		<span class="fx-ship__icon" aria-hidden="true"><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h11v9H3zM14 10h4l3 3v3h-7"/><circle cx="7" cy="17.5" r="1.6"/><circle cx="17" cy="17.5" r="1.6"/></svg></span>
		<div class="fx-ship__body">
			<p class="fx-ship__title" data-fx-ship-title>Sprawdź dokładny termin wysyłki</p>
			<p class="fx-ship__desc" data-fx-ship-desc>Dodaj produkt do koszyka, aby zobaczyć przewidywaną datę wysyłki i dostawy.</p>
			<p class="fx-ship__count" data-fx-ship-count hidden></p>
		</div>
	</div>
	<?php
}, 25 );

add_action( 'wp_footer', function () {
	if ( ! is_product() || ! fx_tool( 'ship_cta' ) ) {
		return;
	}
	?>
	<script>
	(function () {
		var box = document.getElementById('fx-ship');
		if (!box) { return; }
		var t = box.querySelector('[data-fx-ship-title]'), d = box.querySelector('[data-fx-ship-desc]'), c = box.querySelector('[data-fx-ship-count]');
		var cutoff = parseInt(box.dataset.cutoff || '15', 10), hol = (box.dataset.holidays || '').split(','), parentOk = box.dataset.available === '1';
		function now() {
			try {
				var p = {}; new Intl.DateTimeFormat('en-GB', { timeZone: 'Europe/Warsaw', year: 'numeric', month: '2-digit', day: '2-digit', weekday: 'short', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' }).formatToParts(new Date()).forEach(function (x) { p[x.type] = x.value; });
				return { wd: { Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6, Sun: 7 }[p.weekday] || 7, date: p.year + '-' + p.month + '-' + p.day, s: (+p.hour) * 3600 + (+p.minute) * 60 + (+p.second) };
			} catch (e) {
				var n = new Date(), w = n.getDay() || 7, pad = function (v) { return (v < 10 ? '0' : '') + v; };
				return { wd: w, date: n.getFullYear() + '-' + pad(n.getMonth() + 1) + '-' + pad(n.getDate()), s: n.getHours() * 3600 + n.getMinutes() * 60 + n.getSeconds() };
			}
		}
		function pl(n, a, b, cc) { var l = n % 10, ll = n % 100; return n === 1 ? a : (l >= 2 && l <= 4 && !(ll >= 12 && ll <= 14) ? b : cc); }
		function update() {
			if (box.hidden) { return; }
			var x = now(), work = x.wd <= 5 && hol.indexOf(x.date) === -1, cut = cutoff * 3600;
			if (work && x.s < cut) {
				box.classList.add('is-today');
				t.textContent = 'Zamów do ' + (cutoff < 10 ? '0' : '') + cutoff + ':00 — większość produktów wysyłamy jeszcze dziś';
				d.textContent = 'Dodaj do koszyka, aby od razu sprawdzić dokładny termin wysyłki i dostawy.';
				var m = Math.max(0, Math.ceil((cut - x.s) / 60)), h = Math.floor(m / 60); m = m % 60;
				c.textContent = 'Do wysyłki dzisiaj zostało: ' + (h > 0 ? h + ' ' + pl(h, 'godzina', 'godziny', 'godzin') + (m > 0 ? ' ' + m + ' min' : '') : m + ' ' + pl(m, 'minuta', 'minuty', 'minut'));
				c.hidden = false;
			} else {
				box.classList.remove('is-today');
				t.textContent = 'Sprawdź dokładny termin wysyłki przed zakupem';
				d.textContent = 'Dodaj produkt do koszyka — przewidywaną datę wysyłki i dostawy pokażemy od razu. Nie musisz przechodzić do płatności.';
				c.textContent = ''; c.hidden = true;
			}
		}
		function show() { box.hidden = false; update(); }
		function hide() { box.hidden = true; }
		if (window.jQuery) {
			var $f = jQuery('form.variations_form');
			$f.on('found_variation', function (e, v) { (v && v.is_in_stock && v.is_purchasable) ? show() : hide(); });
			$f.on('reset_data hide_variation', function () { parentOk ? show() : hide(); });
		}
		parentOk ? show() : hide();
		setInterval(update, 30000);
	})();
	</script>
	<?php
}, 50 );
