<?php
/**
 * Produkty za 0 zł: nie do kupienia, zamiast ceny „Cena na zapytanie”, na karcie produktu przycisk „Zapytaj o cenę”.
 *
 * - produkt prosty / wariant z ceną 0 (albo pustą) – nie da się go dodać do koszyka (też gdy już był w koszyku);
 * - produkt wariantowy z częścią wariantów za 0 zł – te warianty są niekupowalne, zakres ceny liczy się bez nich,
 *   a po wybraniu takiego wariantu przycisk „Do koszyka” zamienia się w „Zapytaj o cenę”;
 * - zapytanie (telefon lub e-mail + wiadomość) idzie e-mailem do sklepu.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

/** Cena „surowa” (bez dynamicznych przecen) wynosi 0 albo jest pusta. */
function fx_pa_zero_raw( $product ) {
	if ( ! $product instanceof WC_Product || $product->is_type( array( 'variable', 'grouped', 'external' ) ) ) {
		return false;
	}
	return (float) $product->get_price( 'edit' ) <= 0;
}

/** Produkt (także wariantowy) w całości „na zapytanie”. */
function fx_pa_is_ask( $product ) {
	if ( ! $product instanceof WC_Product ) {
		return false;
	}
	if ( $product->is_type( 'variable' ) ) {
		$prices = $product->get_variation_prices( false );
		return ! empty( $product->get_children() ) && ( empty( $prices['price'] ) || (float) max( $prices['price'] ) <= 0 );
	}
	return fx_pa_zero_raw( $product );
}

/** Kontakt: e-mail sklepu (asystent → nadawca WooCommerce → admin) i telefon. */
function fx_pa_contact() {
	$ai    = function_exists( 'fx_ai_settings' ) ? fx_ai_settings() : array();
	$email = ! empty( $ai['email'] ) && is_email( $ai['email'] ) ? $ai['email'] : get_option( 'woocommerce_email_from_address', get_option( 'admin_email' ) );
	$phone = function_exists( 'fx_mod' ) && fx_mod( 'store_phone' ) ? fx_mod( 'store_phone' ) : ( $ai['phone'] ?? '' );
	return array( 'email' => $email, 'phone' => (string) $phone );
}

/* ---------- Nie do kupienia ---------- */
add_filter( 'woocommerce_is_purchasable', function ( $ok, $product ) {
	return $ok && ! fx_pa_is_ask( $product );
}, 20, 2 );
add_filter( 'woocommerce_variation_is_purchasable', function ( $ok, $variation ) {
	return $ok && ! fx_pa_zero_raw( $variation );
}, 20, 2 );

/* ---------- Ceny ---------- */
// Zakres cen wariantowego bez wariantów za 0 zł (przed „Od…” i po przecenach).
add_filter( 'woocommerce_variation_prices', function ( $prices ) {
	if ( empty( $prices['price'] ) ) {
		return $prices;
	}
	$keep = array_filter( $prices['price'], function ( $p ) {
		return (float) $p > 0;
	} );
	if ( ! $keep || count( $keep ) === count( $prices['price'] ) ) {
		return $prices;
	}
	foreach ( array( 'price', 'regular_price', 'sale_price' ) as $k ) {
		if ( isset( $prices[ $k ] ) ) {
			$prices[ $k ] = array_intersect_key( $prices[ $k ], $keep );
		}
	}
	return $prices;
}, 99 );

add_filter( 'woocommerce_get_price_html', function ( $html, $product ) {
	if ( fx_pa_is_ask( $product ) ) {
		return '<span class="fx-ask-price">Cena na zapytanie</span>';
	}
	return $html;
}, 50, 2 );

// Wariant za 0 zł po wybraniu: zamiast „0,00 zł” – „Cena na zapytanie”.
add_filter( 'woocommerce_available_variation', function ( $data, $product, $variation ) {
	if ( fx_pa_zero_raw( $variation ) ) {
		$data['price_html']   = '<span class="price"><span class="fx-ask-price">Cena na zapytanie</span></span>';
		$data['fx_ask']       = 1;
		$data['availability_html'] = '';
	}
	return $data;
}, 20, 3 );

/* ---------- Przycisk i formularz na karcie produktu ---------- */
add_action( 'woocommerce_single_product_summary', function () {
	global $product;
	if ( ! $product instanceof WC_Product ) {
		return;
	}
	$all     = fx_pa_is_ask( $product );
	$partial = ! $all && $product->is_type( 'variable' ) && array_filter( $product->get_children(), function ( $id ) {
		return fx_pa_zero_raw( wc_get_product( $id ) );
	} );
	if ( ! $all && ! $partial ) {
		return;
	}
	$c = fx_pa_contact();
	?>
	<div class="fx-ask<?php echo $all ? '' : ' fx-hide'; ?>" data-fx-ask data-product="<?php echo (int) $product->get_id(); ?>">
		<button type="button" class="fx-btn fx-btn--block fx-ask__open" aria-expanded="false" aria-controls="fx-ask-form">Zapytaj o cenę</button>
		<form id="fx-ask-form" class="fx-ask__form" hidden novalidate>
			<p class="fx-ask__lead">Podaj telefon lub e-mail – odpowiemy z ceną i terminem dostawy, zwykle tego samego dnia roboczego.</p>
			<label class="screen-reader-text" for="fx-ask-contact">Telefon lub e-mail</label>
			<input type="text" id="fx-ask-contact" name="contact" placeholder="Telefon lub e-mail" required autocomplete="email">
			<label class="screen-reader-text" for="fx-ask-msg">Wiadomość</label>
			<textarea id="fx-ask-msg" name="message" rows="2" placeholder="Rozmiar, kolor, pytanie (opcjonalnie)"></textarea>
			<input type="text" name="website" tabindex="-1" autocomplete="off" class="fx-hp" aria-hidden="true">
			<input type="hidden" name="variation_id" value="0">
			<button type="submit" class="fx-btn fx-btn--block">Wyślij zapytanie</button>
			<div class="fx-ask__msg" role="status" aria-live="polite"></div>
		</form>
		<?php if ( $c['phone'] ) : ?>
			<p class="fx-ask__tel">albo zadzwoń: <a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $c['phone'] ) ); ?>"><?php echo esc_html( $c['phone'] ); ?></a></p>
		<?php endif; ?>
	</div>
	<?php
}, 31 );

add_action( 'wc_ajax_fx_price_ask', function () {
	// phpcs:disable WordPress.Security.NonceVerification -- formularz publiczny, chroniony polem-pułapką i limitem.
	if ( ! empty( $_POST['website'] ) ) {
		wp_send_json_success( array( 'message' => 'Dziękujemy!' ) );
	}
	$contact = isset( $_POST['contact'] ) ? sanitize_text_field( wp_unslash( $_POST['contact'] ) ) : '';
	$message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
	$pid     = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
	$vid     = isset( $_POST['variation_id'] ) ? absint( $_POST['variation_id'] ) : 0;
	// phpcs:enable
	$email = is_email( $contact ) ? $contact : '';
	$phone = preg_replace( '/[^0-9+]/', '', $contact );
	if ( ! $email && strlen( preg_replace( '/\D/', '', $phone ) ) < 9 ) {
		wp_send_json_error( array( 'message' => 'Podaj numer telefonu albo adres e-mail.' ) );
	}
	$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$rkey = 'fx_pa_rate_' . md5( $ip );
	$hits = (int) get_transient( $rkey );
	if ( $hits >= 5 ) {
		wp_send_json_error( array( 'message' => 'Zbyt wiele zapytań w krótkim czasie. Zadzwoń do nas albo spróbuj za kilka minut.' ) );
	}
	set_transient( $rkey, $hits + 1, 10 * MINUTE_IN_SECONDS );
	$p = wc_get_product( $pid );
	if ( ! $p || 'publish' !== $p->get_status() ) {
		wp_send_json_error( array( 'message' => 'Nie znaleziono produktu.' ) );
	}
	$v       = $vid ? wc_get_product( $vid ) : null;
	$variant = ( $v && $v->get_parent_id() === $pid ) ? wc_get_formatted_variation( $v, true, false, false ) : '';
	$sku     = $v && $v->get_sku() ? $v->get_sku() : $p->get_sku();
	$c       = fx_pa_contact();
	$body    = "Zapytanie o cenę ze sklepu\n\n"
		. 'Produkt: ' . $p->get_name() . ( $variant ? ' (' . $variant . ')' : '' ) . "\n"
		. ( $sku ? 'SKU: ' . $sku . "\n" : '' )
		. 'Link: ' . get_permalink( $pid ) . "\n\n"
		. 'Kontakt: ' . $contact . "\n"
		. ( '' !== $message ? "Wiadomość:\n" . $message . "\n" : '' );
	$headers = $email ? array( 'Reply-To: ' . $email ) : array();
	$sent    = wp_mail( $c['email'], 'Zapytanie o cenę: ' . $p->get_name(), $body, $headers );
	if ( ! $sent ) {
		wp_send_json_error( array( 'message' => 'Nie udało się wysłać. Zadzwoń: ' . $c['phone'] ) );
	}
	wp_send_json_success( array( 'message' => 'Dziękujemy! Odezwiemy się ' . ( $email ? 'mailowo' : 'telefonicznie' ) . ' z ceną.' ) );
} );
