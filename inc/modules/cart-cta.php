<?php
/**
 * Prowadzenie klienta do zamówienia.
 *
 * 1. Okno „Produkt został dodany do koszyka” (zdjęcie, rozmiar, cena, pasek darmowej dostawy,
 *    „Kontynuuj zakupy” / „Przejdź do koszyka”) – po dodaniu z karty produktu i z listy.
 * 2. Przycisk „Zamawiam” w nagłówku (komputer), gdy w koszyku coś jest.
 * 3. Pasek na dole ekranu (telefon): suma koszyka + „Zamawiam”.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

/* ---------- Zapamiętanie ostatnio dodanej pozycji ---------- */
add_action( 'woocommerce_add_to_cart', function ( $key ) {
	if ( WC()->session ) {
		WC()->session->set( 'fx_added', array( 'key' => $key, 't' => time() ) );
	}
}, 20 );

/** Treść okna dla ostatnio dodanej pozycji (albo ''). */
function fx_added_html() {
	if ( ! WC()->session || ! WC()->cart ) {
		return '';
	}
	$a = WC()->session->get( 'fx_added' );
	if ( empty( $a['key'] ) || time() - (int) $a['t'] > 600 ) {
		return '';
	}
	$item = WC()->cart->get_cart_item( $a['key'] );
	if ( ! $item || empty( $item['data'] ) ) {
		return '';
	}
	$p      = $item['data'];
	$parent = $p->get_parent_id() ? wc_get_product( $p->get_parent_id() ) : $p;
	$name   = $parent ? $parent->get_name() : $p->get_name();
	$meta   = array();
	if ( $p->is_type( 'variation' ) ) {
		foreach ( $p->get_variation_attributes() as $attr => $val ) {
			$tax   = str_replace( 'attribute_', '', $attr );
			$label = wc_attribute_label( $tax, $p );
			if ( preg_match( '/blvariant|wariant/i', $tax . ' ' . $label ) ) {
				$label = 'Rozmiar';
			}
			$term   = taxonomy_exists( $tax ) ? get_term_by( 'slug', $val, $tax ) : null;
			$meta[] = esc_html( $label ) . ': <strong>' . esc_html( function_exists( 'fx_size_label' ) ? fx_size_label( $term ? $term->name : $val ) : ( $term ? $term->name : $val ) ) . '</strong>';
		}
	}
	$img = $p->get_image( 'woocommerce_thumbnail', array( 'class' => 'fx-added__img' ) );
	ob_start();
	if ( function_exists( 'fx_free_ship_bar' ) ) {
		fx_free_ship_bar();
	}
	$ship = ob_get_clean();
	$count = WC()->cart->get_cart_contents_count();

	$h  = '<p class="fx-added__title" id="fx-added-title"><span class="fx-added__ok" aria-hidden="true">✓</span>Produkt został dodany do koszyka</p>';
	$h .= '<div class="fx-added__row">' . $img . '<div class="fx-added__info"><p class="fx-added__name">' . esc_html( $name ) . '</p>';
	if ( $meta ) {
		$h .= '<p class="fx-added__meta">' . implode( ' · ', $meta ) . '</p>';
	}
	$h .= '<p class="fx-added__price">' . wc_price( wc_get_price_to_display( $p ) ) . ' <span>× ' . (int) $item['quantity'] . '</span></p></div></div>';
	$h .= $ship;
	if ( function_exists( 'fox_bl_delivery_render_alert' ) ) {
		$h .= fox_bl_delivery_render_alert( true );
	}
	$h .= '<div class="fx-added__sum">W koszyku: <strong>' . (int) $count . ' ' . esc_html( function_exists( 'fx_pl' ) ? fx_pl( $count, 'produkt', 'produkty', 'produktów' ) : 'szt.' ) . '</strong> za <strong>' . WC()->cart->get_cart_subtotal() . '</strong></div>';
	$h .= '<div class="fx-added__btns"><button type="button" class="fx-btn fx-btn--outline" data-fx-added-close>Kontynuuj zakupy</button><a class="fx-btn" href="' . esc_url( wc_get_cart_url() ) . '">Przejdź do koszyka</a></div>';
	$h .= '<a class="fx-added__direct" href="' . esc_url( wc_get_checkout_url() ) . '">lub od razu: <strong>Zamawiam</strong> →</a>';
	return $h;
}

/* ---------- Przycisk „Zamawiam” w nagłówku i pasek na telefonie ---------- */
function fx_header_cta() {
	$n = ( function_exists( 'WC' ) && WC()->cart ) ? WC()->cart->get_cart_contents_count() : 0;
	echo '<a class="fx-hcta" data-count="' . (int) $n . '" href="' . esc_url( wc_get_checkout_url() ) . '">Zamawiam' . ( $n ? '<span class="fx-hcta__sum">' . wp_kses_post( WC()->cart->get_cart_subtotal() ) . '</span>' : '' ) . '</a>';
}

function fx_mobile_bar() {
	$n = ( function_exists( 'WC' ) && WC()->cart ) ? WC()->cart->get_cart_contents_count() : 0;
	echo '<div class="fx-mbar" data-count="' . (int) $n . '"><a class="fx-mbar__cart" href="' . esc_url( wc_get_cart_url() ) . '"><span class="fx-mbar__n">' . (int) $n . ' ' . esc_html( function_exists( 'fx_pl' ) ? fx_pl( $n, 'produkt', 'produkty', 'produktów' ) : 'szt.' ) . '</span><span class="fx-mbar__sum">' . ( $n ? wp_kses_post( WC()->cart->get_cart_subtotal() ) : '' ) . '</span></a><a class="fx-mbar__go" href="' . esc_url( wc_get_checkout_url() ) . '">Zamawiam</a></div>';
}

add_filter( 'woocommerce_add_to_cart_fragments', function ( $f ) {
	ob_start();
	fx_header_cta();
	$f['a.fx-hcta'] = ob_get_clean();
	ob_start();
	fx_mobile_bar();
	$f['div.fx-mbar'] = ob_get_clean();
	// Okno po dodaniu przez AJAX (lista produktów): treść do otwarcia na zdarzenie added_to_cart.
	$html                 = fx_added_html();
	$f['div.fx-added-src'] = '<div class="fx-added-src" hidden>' . $html . '</div>';
	$wcajax = isset( $_GET['wc-ajax'] ) ? sanitize_key( wp_unslash( $_GET['wc-ajax'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	if ( $html && WC()->session && ( ( $wcajax && 'get_refreshed_fragments' !== $wcajax ) || ( wp_doing_ajax() && ! $wcajax ) ) ) {
		WC()->session->set( 'fx_added', null );
	}
	return $f;
} );

add_action( 'wp_footer', function () {
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return;
	}
	if ( ! is_checkout() ) {
		fx_mobile_bar();
	}
	// Okno po zwykłym dodaniu (formularz na karcie produktu przeładowuje stronę).
	$html = ( is_cart() || is_checkout() ) ? '' : fx_added_html();
	if ( $html && WC()->session ) {
		WC()->session->set( 'fx_added', null );
	}
	echo '<div class="fx-added-src" hidden></div>';
	echo '<div class="fx-added" id="fx-added" role="dialog" aria-modal="true" aria-labelledby="fx-added-title"' . ( $html ? ' data-open="1"' : '' ) . ' hidden><div class="fx-added__bg" data-fx-added-close></div><div class="fx-added__box"><button type="button" class="fx-added__x" data-fx-added-close aria-label="Zamknij">×</button><div class="fx-added__body">' . $html . '</div></div></div>'; // phpcs:ignore
	?>
	<script>
	(function () {
		var m = document.getElementById('fx-added');
		if (!m) { return; }
		var last = null;
		function open() {
			last = document.activeElement;
			m.hidden = false;
			document.documentElement.classList.add('fx-added-open');
			// Standardowy komunikat „dodano do koszyka” jest zbędny przy oknie.
			document.querySelectorAll('.woocommerce-message').forEach(function (n) { if (n.querySelector('a.wc-forward, a[href*="koszyk"], a[href*="cart"]')) { n.style.display = 'none'; } });
			var b = m.querySelector('.fx-btn:not(.fx-btn--outline)') || m.querySelector('button');
			if (b) { b.focus(); }
		}
		function close() {
			m.hidden = true;
			document.documentElement.classList.remove('fx-added-open');
			if (last && last.focus) { last.focus(); }
		}
		m.addEventListener('click', function (e) { if (e.target.closest('[data-fx-added-close]')) { close(); } });
		document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !m.hidden) { close(); } });
		if (m.dataset.open) { open(); }
		if (window.jQuery) {
			jQuery(document.body).on('added_to_cart', function () {
				setTimeout(function () {
					var src = document.querySelector('.fx-added-src');
					if (src && src.innerHTML.trim()) {
						m.querySelector('.fx-added__body').innerHTML = src.innerHTML;
						open();
					}
				}, 30);
			});
		}
		function bar() {
			var b = document.querySelector('.fx-mbar');
			document.body.classList.toggle('fx-has-mbar', !!(b && b.getAttribute('data-count') !== '0'));
		}
		bar();
		if (window.jQuery) { jQuery(document.body).on('wc_fragments_refreshed wc_fragments_loaded added_to_cart removed_from_cart updated_cart_totals', function () { setTimeout(bar, 30); }); }
	})();
	</script>
	<?php
}, 30 );
