<?php
/**
 * Animowana mapa dojazdu do sklepu stacjonarnego (sekcja „Przymierz, zanim kupisz” na stronie głównej).
 *
 * Mapa to lekki SVG z prawdziwych danych OpenStreetMap: granica Wrocławia, A8, A4 i trasy samochodem
 * z Rynku, lotniska, stadionu i z A4 do sklepu. Bez Google Maps, bez skryptów z innych domen,
 * bez ciasteczek – nie spowalnia strony i nie wymaga zgody RODO.
 * Trasa rysuje się, gdy klient dojedzie do sekcji; zakładki zmieniają punkt startu.
 * Dane mapy: inc/modules/store-map-data.php (generator: tools/store-map/build.py).
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

/** Dane mapy (granica, drogi, trasy). Filtr pozwala zmienić np. czasy przejazdu. */
function fx_store_map_data() {
	static $d = null;
	if ( null === $d ) {
		$d = apply_filters( 'fx_store_map_data', include __DIR__ . '/store-map-data.php' );
	}
	return $d;
}

/** Mapa SVG + zakładki + liczniki. */
function fx_store_map() {
	$d = fx_store_map_data();
	if ( ! is_array( $d ) || empty( $d['routes'] ) ) {
		return '';
	}
	$first = array_key_first( $d['routes'] );
	$r0    = $d['routes'][ $first ];
	$s     = $d['store'];
	$bar   = round( 2 * $d['km2px'], 1 ); // podziałka 2 km
	ob_start();
	?>
	<div class="fx-smap" data-fx-smap>
		<div class="fx-smap__stats" aria-live="polite">
			<div class="fx-smap__stat"><strong><span data-fx-smap-km><?php echo esc_html( str_replace( '.', ',', $r0['km'] ) ); ?></span> km</strong><span data-fx-smap-from><?php echo esc_html( mb_strtolower( $r0['label'] ) ); ?></span></div>
			<div class="fx-smap__stat"><strong>ok. <span data-fx-smap-min><?php echo (int) $r0['min']; ?></span> min</strong><span>samochodem</span></div>
			<div class="fx-smap__stat"><strong>&lt; 1 km</strong><span>od granicy Wrocławia</span></div>
		</div>
		<div class="fx-smap__tabs" role="tablist" aria-label="Skąd jedziesz?">
			<?php foreach ( $d['routes'] as $k => $r ) : ?>
				<button type="button" role="tab" class="fx-smap__tab" aria-selected="<?php echo $k === $first ? 'true' : 'false'; ?>" data-route="<?php echo esc_attr( $k ); ?>" data-km="<?php echo esc_attr( str_replace( '.', ',', $r['km'] ) ); ?>" data-min="<?php echo (int) $r['min']; ?>"><?php echo esc_html( $r['label'] ); ?></button>
			<?php endforeach; ?>
		</div>
		<svg class="fx-smap__svg" viewBox="0 0 <?php echo (int) $d['w']; ?> <?php echo (int) $d['h']; ?>" data-focus="<?php echo esc_attr( $d['focus'] ?? '' ); ?>" role="img" aria-label="Mapa: sklep FOX w Mokronosie Dolnym, niespełna kilometr za granicą Wrocławia, przy zjeździe z autostrady A8. Z Rynku ok. <?php echo esc_attr( str_replace( '.', ',', $r0['km'] ) ); ?> km.">
			<defs>
				<pattern id="fx-smap-dots" width="22" height="22" patternUnits="userSpaceOnUse"><circle cx="1.5" cy="1.5" r="1.5" fill="currentColor" opacity=".12"/></pattern>
			</defs>
			<path class="fx-smap__city" d="<?php echo esc_attr( $d['city'] ); ?>"/>
			<path class="fx-smap__dots" d="<?php echo esc_attr( $d['city'] ); ?>" fill="url(#fx-smap-dots)"/>
			<text class="fx-smap__cityname" x="<?php echo esc_attr( $d['city_label'][0] ); ?>" y="<?php echo esc_attr( $d['city_label'][1] ); ?>" text-anchor="middle">WROCŁAW</text>
			<path class="fx-smap__road" d="<?php echo esc_attr( $d['a4'] ); ?>"/>
			<path class="fx-smap__road" d="<?php echo esc_attr( $d['a8'] ); ?>"/>
			<g class="fx-smap__shield" transform="translate(<?php echo esc_attr( $d['a8_label'][0] ); ?> <?php echo esc_attr( $d['a8_label'][1] ); ?>)"><rect x="-22" y="-15" width="44" height="30" rx="6"/><text y="7" text-anchor="middle">A8</text></g>
			<g class="fx-smap__shield" transform="translate(<?php echo esc_attr( $d['a4_label'][0] ); ?> <?php echo esc_attr( $d['a4_label'][1] ); ?>)"><rect x="-22" y="-15" width="44" height="30" rx="6"/><text y="7" text-anchor="middle">A4</text></g>
			<?php foreach ( $d['routes'] as $k => $r ) : ?>
				<g class="fx-smap__route<?php echo $k === $first ? ' is-on' : ''; ?>" data-route="<?php echo esc_attr( $k ); ?>">
					<path class="fx-smap__line" pathLength="1" d="<?php echo esc_attr( $r['d'] ); ?>"/>
					<circle class="fx-smap__from" cx="<?php echo esc_attr( $r['at'][0] ); ?>" cy="<?php echo esc_attr( $r['at'][1] ); ?>" r="9"/>
					<text class="fx-smap__place" x="<?php echo esc_attr( $r['at'][0] + 16 ); ?>" y="<?php echo esc_attr( $r['at'][1] - 12 ); ?>"><?php echo esc_html( $r['place'] ); ?></text>
				</g>
			<?php endforeach; ?>
			<circle class="fx-smap__car" r="8" cx="-50" cy="-50"/>
			<g class="fx-smap__pin" transform="translate(<?php echo esc_attr( $s[0] ); ?> <?php echo esc_attr( $s[1] ); ?>)">
				<circle class="fx-smap__pulse" r="16"/>
				<circle class="fx-smap__pulse fx-smap__pulse--2" r="16"/>
				<circle class="fx-smap__dot" r="13"/>
				<g class="fx-smap__tag" transform="translate(-24 34)">
					<rect width="250" height="66" rx="10"/>
					<text x="16" y="28" class="fx-smap__tag-h">Sklep FOX</text>
					<text x="16" y="52" class="fx-smap__tag-s">Mokronos Dolny · zjazd z A8</text>
				</g>
			</g>
			<g class="fx-smap__scale" transform="translate(<?php echo (int) $d['w'] - 40 - $bar; ?> <?php echo (int) $d['h'] - 34; ?>)"><path d="M0 0 V8 H<?php echo esc_attr( $bar ); ?> V0"/><text x="<?php echo esc_attr( $bar / 2 ); ?>" y="-8" text-anchor="middle">2 km</text></g>
		</svg>
		<p class="fx-smap__note">Czasy przejazdu bez korków, trasy wg OpenStreetMap. © współtwórcy <a href="https://www.openstreetmap.org/copyright" rel="nofollow noopener" target="_blank">OpenStreetMap</a></p>
	</div>
	<script>
	(function(){var w=document.querySelector('[data-fx-smap]');if(!w||w.dataset.ready)return;w.dataset.ready=1;w.classList.add('is-js');
	var svg=w.querySelector('svg'),full=svg.getAttribute('viewBox');function fit(){var f=svg.dataset.focus;var z=f&&w.clientWidth<700;svg.setAttribute('viewBox',z?f:full);w.classList.toggle('is-zoom',!!z)}fit();window.addEventListener('resize',fit);
	var car=w.querySelector('.fx-smap__car'),km=w.querySelector('[data-fx-smap-km]'),mn=w.querySelector('[data-fx-smap-min]'),from=w.querySelector('[data-fx-smap-from]'),
	still=window.matchMedia&&matchMedia('(prefers-reduced-motion: reduce)').matches,raf;
	function play(key){var g=w.querySelector('.fx-smap__route[data-route="'+key+'"]');if(!g)return;
		w.querySelectorAll('.fx-smap__route').forEach(function(x){x.classList.toggle('is-on',x===g)});
		w.querySelectorAll('.fx-smap__tab').forEach(function(b){var on=b.dataset.route===key;b.setAttribute('aria-selected',on);if(on){km.textContent=b.dataset.km;mn.textContent=b.dataset.min;from.textContent=b.textContent.toLowerCase()}});
		var p=g.querySelector('.fx-smap__line'),L=p.getTotalLength();cancelAnimationFrame(raf);
		if(still){p.style.strokeDashoffset=0;car.style.opacity=0;return}
		p.style.strokeDashoffset=1;car.style.opacity=1;var t0=null,T=2200;
		function step(t){t0=t0||t;var k=Math.min(1,(t-t0)/T),e=k<.5?2*k*k:1-Math.pow(-2*k+2,2)/2,pt=p.getPointAtLength(L*e);
			p.style.strokeDashoffset=1-e;car.setAttribute('cx',pt.x);car.setAttribute('cy',pt.y);
			if(k<1)raf=requestAnimationFrame(step);else{car.style.opacity=0;w.classList.add('is-arrived');setTimeout(function(){w.classList.remove('is-arrived')},900)}}
		raf=requestAnimationFrame(step)}
	w.querySelectorAll('.fx-smap__tab').forEach(function(b){b.addEventListener('click',function(){play(b.dataset.route)})});
	var first=w.querySelector('.fx-smap__tab').dataset.route;
	if('IntersectionObserver' in window){var io=new IntersectionObserver(function(es){if(es[0].isIntersecting){io.disconnect();play(first)}},{threshold:.35});io.observe(w)}else play(first);
	})();
	</script>
	<?php
	return ob_get_clean();
}
