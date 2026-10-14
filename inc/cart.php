<?php
/**
 * Koszyk i zamówienie: kroki, czytelne pozycje, pasek darmowej dostawy, podsumowanie obok listy.
 *
 * Bez nadpisywania szablonów WooCommerce – same hooki, style i mały skrypt
 * (przyciski − / + przy ilości i automatyczne przeliczenie koszyka).
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

/* ---------- Kroki: Koszyk → Dane i dostawa → Gotowe ---------- */
function fx_checkout_steps( $current ) {
	$steps = array(
		1 => array( 'Koszyk', wc_get_cart_url() ),
		2 => array( 'Dane, dostawa i płatność', wc_get_checkout_url() ),
		3 => array( 'Potwierdzenie', '' ),
	);
	echo '<ol class="fx-steps" aria-label="Etapy zamówienia">';
	foreach ( $steps as $n => $s ) {
		$state = $n < $current ? 'is-done' : ( $n === $current ? 'is-current' : '' );
		echo '<li class="fx-steps__item ' . esc_attr( $state ) . '"' . ( $n === $current ? ' aria-current="step"' : '' ) . '><span class="fx-steps__dot">' . ( $n < $current ? '✓' : (int) $n ) . '</span>';
		echo ( $n < $current && $s[1] ) ? '<a href="' . esc_url( $s[1] ) . '">' . esc_html( $s[0] ) . '</a>' : '<span>' . esc_html( $s[0] ) . '</span>';
		echo '</li>';
	}
	echo '</ol>';
}
add_action( 'woocommerce_before_cart', function () {
	fx_checkout_steps( 1 );
}, 1 );
add_action( 'woocommerce_before_checkout_form', function () {
	fx_checkout_steps( 2 );
}, 1 );
add_action( 'woocommerce_before_thankyou', function () {
	fx_checkout_steps( 3 );
}, 1 );

/* ---------- Pozycje: nazwa modelu + „Rozmiar: M · Kod: 31372-005” ---------- */
add_filter( 'woocommerce_cart_item_name', function ( $name, $item, $key ) {
	if ( ! is_cart() || empty( $item['data'] ) || ! $item['data'] instanceof WC_Product ) {
		return $name;
	}
	$p      = $item['data'];
	$parent = $p->get_parent_id() ? wc_get_product( $p->get_parent_id() ) : $p;
	$title  = $parent ? $parent->get_name() : $p->get_name();
	$link   = $p->is_visible() ? $p->get_permalink( $item ) : '';
	$out    = $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title );
	$meta   = array();
	if ( $p->is_type( 'variation' ) ) {
		foreach ( $p->get_variation_attributes() as $attr => $val ) {
			$tax   = str_replace( 'attribute_', '', $attr );
			$label = wc_attribute_label( $tax, $p );
			if ( preg_match( '/blvariant|wariant/i', $tax . ' ' . $label ) ) {
				$label = 'Rozmiar';
			}
			$term  = taxonomy_exists( $tax ) ? get_term_by( 'slug', $val, $tax ) : null;
			$meta[] = esc_html( $label ) . ': <strong>' . esc_html( fx_size_label( $term ? $term->name : $val ) ) . '</strong>';
		}
	}
	if ( $p->get_sku() ) {
		$meta[] = 'Kod: ' . esc_html( $p->get_sku() );
	}
	if ( $meta ) {
		$out .= '<span class="fx-ci-meta">' . implode( '<span aria-hidden="true"> · </span>', $meta ) . '</span>';
	}
	return $out;
}, 10, 3 );

/* Rozmiar jest już pod nazwą – bez powtórki w danych pozycji. */
add_filter( 'woocommerce_get_item_data', function ( $data, $item ) {
	if ( is_cart() && ! empty( $item['variation'] ) ) {
		return array_values(
			array_filter(
				$data,
				function ( $d ) {
					return empty( $d['key'] ) || ! preg_match( '/rozmiar|wariant|kolor|size/i', wp_strip_all_tags( (string) $d['key'] ) );
				}
			)
		);
	}
	return $data;
}, 20, 2 );

/* Przekreślona cena regularna przy produktach w promocji. */
add_filter( 'woocommerce_cart_item_price', function ( $price, $item ) {
	if ( ! empty( $item['fx_kit_units'] ) || empty( $item['data'] ) ) {
		return $price;
	}
	$p = $item['data'];
	if ( $p->is_on_sale() && (float) $p->get_regular_price() > (float) $p->get_price() ) {
		return '<del>' . wc_price( wc_get_price_to_display( $p, array( 'price' => $p->get_regular_price() ) ) ) . '</del> <ins>' . $price . '</ins>';
	}
	return $price;
}, 10, 2 );

/* ---------- Darmowa dostawa: ile brakuje ---------- */
function fx_free_ship_threshold() {
	return (float) fx_tool( 'free_ship' );
}
function fx_free_ship_bar() {
	$min = fx_free_ship_threshold();
	if ( $min <= 0 || ! WC()->cart ) {
		return;
	}
	$sum  = (float) WC()->cart->get_displayed_subtotal() - (float) WC()->cart->get_discount_total() - ( WC()->cart->display_prices_including_tax() ? (float) WC()->cart->get_discount_tax() : 0 );
	$left = $min - $sum;
	$pct  = max( 0, min( 100, $sum / $min * 100 ) );
	echo '<div class="fx-freeship' . ( $left <= 0 ? ' is-done' : '' ) . '">';
	echo $left > 0 ? '<p>Brakuje Ci <strong>' . wc_price( $left ) . '</strong> do <strong>darmowej dostawy</strong>.</p>' : '<p>Masz <strong>darmową dostawę</strong>.</p>'; // phpcs:ignore
	echo '<div class="fx-freeship__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . (int) $pct . '"><span style="width:' . esc_attr( round( $pct, 1 ) ) . '%"></span></div></div>';
}
add_action( 'woocommerce_before_cart_totals', 'fx_free_ship_bar', 5 );

/* ---------- Podsumowanie: przycisk, „Kontynuuj zakupy”, atuty ---------- */
remove_action( 'woocommerce_cart_collaterals', 'woocommerce_cross_sell_display' );
add_action( 'woocommerce_after_cart', function () {
	woocommerce_cross_sell_display( 3, 3 );
}, 10 );
add_filter( 'woocommerce_cross_sells_columns', function () {
	return 3;
} );

add_action( 'woocommerce_proceed_to_checkout', function () {
	$shop = wc_get_page_permalink( 'shop' );
	echo '<a class="fx-cart-continue" data-fx-continue href="' . esc_url( $shop ) . '">← Kontynuuj zakupy</a>';
	$points = array_filter( array( fx_mod( 'trust_1' ), fx_mod( 'trust_3' ), 'Bezpieczne płatności online' ) );
	echo '<ul class="fx-cart-trust">';
	foreach ( $points as $pt ) {
		echo '<li>' . esc_html( $pt ) . '</li>';
	}
	echo '</ul>';
}, 30 );

/* Tekst przycisku przejścia do zamówienia. */
add_filter( 'gettext', function ( $t, $text, $domain ) {
	if ( 'woocommerce' === $domain && 'Proceed to checkout' === $text ) {
		return 'Przejdź do zamówienia';
	}
	return $t;
}, 20, 3 );

/* ---------- Zamówienie: wygodniej dla klienta ---------- */

/* „Wysłać na inny adres?” domyślnie odznaczone – większość wysyła na adres z formularza. */
add_filter( 'woocommerce_ship_to_different_address_checked', '__return_false', 99 );

/*
 * Oświadczenie Przelewy24 (regulamin i obowiązek informacyjny) zaznaczone od razu.
 * Pole dodaje wtyczka płatności i odświeża je przy każdej zmianie metody / dostawy,
 * dlatego zaznaczamy je po każdym odświeżeniu formularza. Klient nadal może je odznaczyć.
 * Wyłączenie: add_filter( 'fx_p24_precheck', '__return_false' );
 */
add_action( 'wp_footer', function () {
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() || ! apply_filters( 'fx_p24_precheck', true ) ) {
		return;
	}
	?>
	<script>
	(function () {
		function fxP24() {
			document.querySelectorAll('.woocommerce-checkout input[type="checkbox"]').forEach(function (cb) {
				if (cb.dataset.fxP24) { return; }
				var box = cb.closest('label, p, li, div');
				var txt = ((box && box.textContent) || '') + ' ' + (cb.name || '') + ' ' + (cb.id || '');
				if (/przelewy24|p24/i.test(txt) && /regulamin|regulation|obowi[aą]zk|rodo|accept/i.test(txt)) {
					cb.dataset.fxP24 = '1';
					if (!cb.checked) {
						cb.checked = true;
						cb.dispatchEvent(new Event('change', { bubbles: true }));
					}
				}
			});
		}
		/* Kafelki: klasa .fx-on dla przeglądarek bez :has() */
		function fxTiles() {
			document.querySelectorAll('#payment ul.payment_methods > li, ul#shipping_method li').forEach(function (li) {
				var r = li.querySelector('input[type="radio"]');
				li.classList.toggle('fx-on', !!(r && r.checked));
			});
		}
		fxP24(); fxTiles();
		document.addEventListener('change', function () { setTimeout(fxTiles, 10); });
		if (window.jQuery) {
			jQuery(document.body).on('updated_checkout payment_method_selected', function () { setTimeout(fxTiles, 60); });
		}
		if (window.jQuery) {
			jQuery(document.body).on('updated_checkout payment_method_selected', function () { setTimeout(fxP24, 50); });
		}
		document.addEventListener('change', function (e) { if (e.target && e.target.name === 'payment_method') { setTimeout(fxP24, 50); } });
	})();
	</script>
	<?php
}, 99 );

/* ---------- Koszyk bez wyboru dostawy (wybór tylko w zamówieniu) ---------- */

/* Na stronie koszyka WooCommerce nie liczy dostawy – nie pokazuje metod ani adresu. */
add_filter( 'woocommerce_cart_ready_to_calc_shipping', function ( $ready ) {
	return ( function_exists( 'is_cart' ) && is_cart() ) ? false : $ready;
}, 99 );

/** Najniższy koszt płatnej dostawy z ustawień stref (np. 15 zł) albo null. */
function fx_ship_min_cost() {
	$min = get_transient( 'fx_ship_min' );
	if ( false !== $min ) {
		return '' === $min ? null : (float) $min;
	}
	$costs = array();
	$zones = class_exists( 'WC_Shipping_Zones' ) ? WC_Shipping_Zones::get_zones() : array();
	$zones[] = array( 'shipping_methods' => ( new WC_Shipping_Zone( 0 ) )->get_shipping_methods( true ) );
	foreach ( $zones as $z ) {
		foreach ( (array) ( isset( $z['shipping_methods'] ) ? $z['shipping_methods'] : array() ) as $m ) {
			if ( ! is_object( $m ) || 'yes' !== $m->enabled || in_array( $m->id, array( 'local_pickup', 'free_shipping', 'pickup_location' ), true ) ) {
				continue;
			}
			$c = isset( $m->instance_settings['cost'] ) ? str_replace( ',', '.', (string) $m->instance_settings['cost'] ) : '';
			if ( is_numeric( $c ) && (float) $c > 0 ) {
				$costs[] = (float) $c;
			}
		}
	}
	$min = $costs ? min( $costs ) : null;
	set_transient( 'fx_ship_min', null === $min ? '' : $min, HOUR_IN_SECONDS );
	return $min;
}
add_action( 'woocommerce_update_options_shipping', function () {
	delete_transient( 'fx_ship_min' );
} );

/* Zamiast wyboru: jedna linijka „Dostawa – wybierzesz w następnym kroku”. */
add_action( 'woocommerce_cart_totals_before_order_total', function () {
	if ( ! WC()->cart || ! WC()->cart->needs_shipping() ) {
		return;
	}
	$free = fx_free_ship_threshold();
	$sum  = (float) WC()->cart->get_displayed_subtotal() - (float) WC()->cart->get_discount_total();
	if ( $free > 0 && $sum >= $free ) {
		$txt = '<strong class="fx-ship-free">Gratis</strong>';
	} else {
		$min = fx_ship_min_cost();
		$txt = $min ? 'od ' . wc_price( $min ) : '';
	}
	echo '<tr class="fx-ship-info"><th>Dostawa</th><td data-title="Dostawa">' . $txt . '<small>Sposób dostawy wybierzesz w następnym kroku</small></td></tr>'; // phpcs:ignore
} );
