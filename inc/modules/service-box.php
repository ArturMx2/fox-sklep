<?php
/**
 * Rekomendowany serwis (np. amortyzatory FOX → Tucan Bike) na karcie produktu z wybranych kategorii.
 *
 * - ramka „Rekomendowany serwis” z oceną Google, adresem, godzinami i przyciskami (Zadzwoń, Zleć serwis online, Mapa);
 * - pomiar kliknięć (Zadzwoń / Zleć serwis / strona / mapa / e-mail) – dzień po dniu, telefon i komputer osobno,
 *   statystyki w WooCommerce → Serwis (rekomendacja); do GA4 idzie też zdarzenie `service_click`;
 * - ustawienia w tym samym miejscu (dane serwisu, kategorie).
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

define( 'FX_SVC_OPT', 'fx_svc' );
define( 'FX_SVC_STATS', 'fx_svc_stats' );

function fx_svc_defaults() {
	return array(
		'on'      => 1,
		'cats'    => 'amortyzatory-fox',
		'label'   => 'Rekomendowany serwis amortyzatorów FOX',
		'name'    => 'Tucan Bike',
		'badge'   => 'Autoryzowany serwis amortyzacji',
		'text'    => 'Serwis amortyzatorów i damperów FOX (34, 36, 38, 40, Float X, DPX2, DHX2) we Wrocławiu – na miejscu albo wysyłkowo w całej Polsce. Standardowo 2–4 dni robocze od dostarczenia.',
		'rating'  => '5.0',
		'reviews' => '',
		'address' => 'al. Armii Krajowej 8C/2, 50-541 Wrocław',
		'hours'   => 'Pon.–pt. 10:00–18:00 (najlepiej umówić się telefonicznie)',
		'phone'   => '+48 660 463 486',
		'email'   => 'tucanbike@wp.pl',
		'url'     => 'https://tucan.bike/',
		'form'    => 'https://tucan.bike/zlec-serwis-amortyzacji/',
		'maps'    => 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( 'Tucan Bike, al. Armii Krajowej 8C, Wrocław' ),
	);
}

function fx_svc() {
	$s = get_option( FX_SVC_OPT, array() );
	return array_merge( fx_svc_defaults(), is_array( $s ) ? $s : array() );
}

/** Czy produkt jest w jednej z kategorii serwisu (także w podkategoriach). */
function fx_svc_applies( $product ) {
	$s = fx_svc();
	if ( empty( $s['on'] ) || ! $product instanceof WC_Product ) {
		return false;
	}
	$slugs = array_filter( array_map( 'trim', explode( ',', (string) $s['cats'] ) ) );
	if ( ! $slugs ) {
		return false;
	}
	$ids = array();
	foreach ( $slugs as $slug ) {
		$t = get_term_by( 'slug', $slug, 'product_cat' );
		if ( $t ) {
			$ids[] = (int) $t->term_id;
		}
	}
	if ( ! $ids ) {
		return false;
	}
	foreach ( $product->get_category_ids() as $cid ) {
		if ( in_array( (int) $cid, $ids, true ) ) {
			return true;
		}
		foreach ( get_ancestors( (int) $cid, 'product_cat', 'taxonomy' ) as $a ) {
			if ( in_array( (int) $a, $ids, true ) ) {
				return true;
			}
		}
	}
	return false;
}

/* ---------- Karta produktu ---------- */
add_action( 'woocommerce_single_product_summary', function () {
	global $product;
	if ( ! fx_svc_applies( $product ) ) {
		return;
	}
	$s      = fx_svc();
	$tel    = preg_replace( '/[^0-9+]/', '', (string) $s['phone'] );
	$rating = (float) str_replace( ',', '.', (string) $s['rating'] );
	$stars  = $rating > 0 ? str_repeat( '★', (int) round( $rating ) ) . str_repeat( '☆', max( 0, 5 - (int) round( $rating ) ) ) : '';
	?>
	<aside class="fx-svc" data-fx-svc data-pid="<?php echo (int) $product->get_id(); ?>" aria-label="<?php echo esc_attr( $s['label'] ); ?>">
		<p class="fx-svc__kicker"><?php echo esc_html( $s['label'] ); ?></p>
		<div class="fx-svc__head">
			<strong class="fx-svc__name"><?php echo esc_html( $s['name'] ); ?></strong>
			<?php if ( $s['badge'] ) : ?><span class="fx-svc__badge"><?php echo esc_html( $s['badge'] ); ?></span><?php endif; ?>
		</div>
		<?php if ( $rating > 0 ) : ?>
			<a class="fx-svc__rating" href="<?php echo esc_url( $s['maps'] ); ?>" target="_blank" rel="noopener nofollow" data-t="maps">
				<span class="fx-svc__stars" aria-hidden="true"><?php echo esc_html( $stars ); ?></span>
				<b><?php echo esc_html( number_format( $rating, 1, ',', '' ) ); ?></b> w Google Maps<?php echo $s['reviews'] ? ' · ' . esc_html( $s['reviews'] ) . ' opinii' : ''; ?>
			</a>
		<?php endif; ?>
		<?php if ( $s['text'] ) : ?><p class="fx-svc__text"><?php echo esc_html( $s['text'] ); ?></p><?php endif; ?>
		<div class="fx-svc__btns">
			<?php if ( $tel ) : ?>
				<a class="fx-btn fx-svc__call" href="tel:<?php echo esc_attr( $tel ); ?>" data-t="tel">Zadzwoń: <?php echo esc_html( $s['phone'] ); ?></a>
			<?php endif; ?>
			<?php if ( $s['form'] ) : ?>
				<a class="fx-btn fx-btn--outline fx-svc__form" href="<?php echo esc_url( $s['form'] ); ?>" target="_blank" rel="noopener" data-t="form">Zleć serwis online</a>
			<?php endif; ?>
		</div>
		<ul class="fx-svc__info">
			<?php if ( $s['address'] ) : ?><li><span>Adres</span> <a href="<?php echo esc_url( $s['maps'] ); ?>" target="_blank" rel="noopener nofollow" data-t="maps"><?php echo esc_html( $s['address'] ); ?></a></li><?php endif; ?>
			<?php if ( $s['hours'] ) : ?><li><span>Godziny</span> <?php echo esc_html( $s['hours'] ); ?></li><?php endif; ?>
			<?php if ( $s['email'] ) : ?><li><span>E-mail</span> <a href="mailto:<?php echo esc_attr( $s['email'] ); ?>" data-t="mail"><?php echo esc_html( $s['email'] ); ?></a></li><?php endif; ?>
			<?php if ( $s['url'] ) : ?><li><span>Strona</span> <a href="<?php echo esc_url( $s['url'] ); ?>" target="_blank" rel="noopener" data-t="www"><?php echo esc_html( preg_replace( '#^https?://|/$#', '', $s['url'] ) ); ?></a></li><?php endif; ?>
		</ul>
	</aside>
	<script>
	(function(){var b=document.currentScript.previousElementSibling;if(!b||!b.matches('[data-fx-svc]'))return;
	b.addEventListener('click',function(e){var a=e.target.closest('a[data-t]');if(!a)return;var t=a.dataset.t,d=window.innerWidth<768?'m':'d';
	try{(window.dataLayer=window.dataLayer||[]).push({event:'service_click',click_type:t,service_name:<?php echo wp_json_encode( $s['name'] ); ?>})}catch(x){}
	try{var f=new FormData();f.append('t',t);f.append('d',d);f.append('pid',b.dataset.pid);navigator.sendBeacon?navigator.sendBeacon(<?php echo wp_json_encode( rest_url( 'fx/v1/serwis-klik' ) ); ?>,f):fetch(<?php echo wp_json_encode( rest_url( 'fx/v1/serwis-klik' ) ); ?>,{method:'POST',body:f,keepalive:true})}catch(x){}});})();
	</script>
	<?php
}, 38 );

/* ---------- Pomiar kliknięć ---------- */
function fx_svc_types() {
	return array( 'tel' => 'Zadzwoń', 'form' => 'Zleć serwis online', 'maps' => 'Mapa / opinie Google', 'www' => 'Strona serwisu', 'mail' => 'E-mail' );
}

add_action( 'rest_api_init', function () {
	register_rest_route(
		'fx/v1',
		'/serwis-klik',
		array(
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => function ( WP_REST_Request $r ) {
				$t = (string) $r->get_param( 't' );
				if ( ! isset( fx_svc_types()[ $t ] ) ) {
					return new WP_REST_Response( null, 204 );
				}
				$key = 'fx_svc_' . substr( md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) . $t ), 0, 12 ); // phpcs:ignore
				if ( get_transient( $key ) ) {
					return new WP_REST_Response( null, 204 ); // ta sama osoba i przycisk – raz na 10 minut
				}
				set_transient( $key, 1, 10 * MINUTE_IN_SECONDS );
				$dev = 'm' === $r->get_param( 'd' ) ? 'm' : 'd';
				$day = wp_date( 'Y-m-d' );
				$st  = get_option( FX_SVC_STATS, array() );
				$st  = is_array( $st ) ? $st : array();
				$st[ $day ][ $t ][ $dev ] = (int) ( $st[ $day ][ $t ][ $dev ] ?? 0 ) + 1;
				$pid = absint( $r->get_param( 'pid' ) );
				if ( $pid ) {
					$st[ $day ]['p'][ $pid ] = (int) ( $st[ $day ]['p'][ $pid ] ?? 0 ) + ( 'tel' === $t ? 1 : 0 );
				}
				ksort( $st );
				$st = array_slice( $st, -400, null, true );
				update_option( FX_SVC_STATS, $st, false );
				return new WP_REST_Response( null, 204 );
			},
		)
	);
} );

/* ---------- Panel: WooCommerce → Serwis (rekomendacja) ---------- */
add_action( 'admin_menu', function () {
	add_submenu_page( 'woocommerce', 'Rekomendowany serwis', 'Serwis (rekomendacja)', 'manage_woocommerce', 'fx-serwis', 'fx_svc_page' );
}, 60 );

add_action( 'admin_post_fx_svc_save', function () {
	if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( 'fx_svc_save' ) ) {
		wp_die( 'Brak uprawnień.' );
	}
	$in  = isset( $_POST['svc'] ) ? (array) wp_unslash( $_POST['svc'] ) : array(); // phpcs:ignore
	$out = array( 'on' => empty( $in['on'] ) ? 0 : 1 );
	foreach ( fx_svc_defaults() as $k => $v ) {
		if ( 'on' === $k ) {
			continue;
		}
		$val       = isset( $in[ $k ] ) ? (string) $in[ $k ] : '';
		$out[ $k ] = in_array( $k, array( 'url', 'form', 'maps' ), true ) ? esc_url_raw( $val ) : ( 'text' === $k ? sanitize_textarea_field( $val ) : sanitize_text_field( $val ) );
	}
	update_option( FX_SVC_OPT, $out, false );
	do_action( 'litespeed_purge_all' );
	wp_safe_redirect( admin_url( 'admin.php?page=fx-serwis&saved=1' ) );
	exit;
} );

function fx_svc_page() {
	$s     = fx_svc();
	$st    = (array) get_option( FX_SVC_STATS, array() );
	$types = fx_svc_types();
	$sum   = function ( $days ) use ( $st, $types ) {
		$from = wp_date( 'Y-m-d', time() - ( $days - 1 ) * DAY_IN_SECONDS );
		$out  = array_fill_keys( array_keys( $types ), array( 'm' => 0, 'd' => 0 ) );
		foreach ( $st as $day => $row ) {
			if ( $day < $from ) {
				continue;
			}
			foreach ( $types as $t => $l ) {
				$out[ $t ]['m'] += (int) ( $row[ $t ]['m'] ?? 0 );
				$out[ $t ]['d'] += (int) ( $row[ $t ]['d'] ?? 0 );
			}
		}
		return $out;
	};
	$labels = array(
		'on'      => 'Włączona',
		'cats'    => 'Kategorie (slugi po przecinku, z podkategoriami)',
		'label'   => 'Nagłówek ramki',
		'name'    => 'Nazwa serwisu',
		'badge'   => 'Wyróżnik (np. „Autoryzowany serwis amortyzacji”)',
		'text'    => 'Opis',
		'rating'  => 'Ocena w Google Maps (np. 5.0; puste = bez oceny)',
		'reviews' => 'Liczba opinii (opcjonalnie)',
		'address' => 'Adres',
		'hours'   => 'Godziny',
		'phone'   => 'Telefon',
		'email'   => 'E-mail',
		'url'     => 'Strona serwisu',
		'form'    => 'Link „Zleć serwis online”',
		'maps'    => 'Link do Google Maps (wizytówka z opiniami)',
	);
	?>
	<div class="wrap">
		<h1>Rekomendowany serwis</h1>
		<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore ?><div class="notice notice-success"><p>Zapisano. Cache stron wyczyszczony.</p></div><?php endif; ?>
		<h2>Kliknięcia</h2>
		<p class="description">Liczone na karcie produktu (ta sama osoba i ten sam przycisk – najwyżej raz na 10 minut). „Zadzwoń” na komputerze oznacza zwykle kliknięcie numeru bez wybrania połączenia. Te same kliknięcia idą do GA4 jako zdarzenie <code>service_click</code> (parametr <code>click_type</code>).</p>
		<table class="widefat striped" style="max-width:760px">
			<thead><tr><th>Przycisk</th><th>7 dni</th><th>30 dni</th><th>90 dni</th><th>w tym telefon (30 dni)</th></tr></thead>
			<tbody>
			<?php
			$s7  = $sum( 7 );
			$s30 = $sum( 30 );
			$s90 = $sum( 90 );
			foreach ( $types as $t => $l ) :
				?>
				<tr><td><?php echo esc_html( $l ); ?></td><td><?php echo (int) ( $s7[ $t ]['m'] + $s7[ $t ]['d'] ); ?></td><td><strong><?php echo (int) ( $s30[ $t ]['m'] + $s30[ $t ]['d'] ); ?></strong></td><td><?php echo (int) ( $s90[ $t ]['m'] + $s90[ $t ]['d'] ); ?></td><td><?php echo (int) $s30[ $t ]['m']; ?></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$recent = array_slice( $st, -14, null, true );
		if ( $recent ) :
			?>
			<h3>Ostatnie dni – „Zadzwoń”</h3>
			<table class="widefat striped" style="max-width:420px"><thead><tr><th>Dzień</th><th>Telefon</th><th>Komputer</th></tr></thead><tbody>
			<?php foreach ( array_reverse( $recent, true ) as $day => $row ) : ?>
				<tr><td><?php echo esc_html( wp_date( 'D j.m', strtotime( $day ) ) ); ?></td><td><?php echo (int) ( $row['tel']['m'] ?? 0 ); ?></td><td><?php echo (int) ( $row['tel']['d'] ?? 0 ); ?></td></tr>
			<?php endforeach; ?>
			</tbody></table>
		<?php endif; ?>

		<h2 style="margin-top:32px">Ustawienia</h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="fx_svc_save">
			<?php wp_nonce_field( 'fx_svc_save' ); ?>
			<table class="form-table" role="presentation">
				<?php foreach ( $labels as $k => $l ) : ?>
					<tr>
						<th scope="row"><label for="svc-<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $l ); ?></label></th>
						<td>
							<?php if ( 'on' === $k ) : ?>
								<input type="checkbox" id="svc-on" name="svc[on]" value="1" <?php checked( ! empty( $s['on'] ) ); ?>>
							<?php elseif ( 'text' === $k ) : ?>
								<textarea id="svc-text" name="svc[text]" rows="3" class="large-text"><?php echo esc_textarea( $s['text'] ); ?></textarea>
							<?php else : ?>
								<input type="text" id="svc-<?php echo esc_attr( $k ); ?>" name="svc[<?php echo esc_attr( $k ); ?>]" value="<?php echo esc_attr( $s[ $k ] ); ?>" class="regular-text" style="width:100%;max-width:640px">
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
			<?php submit_button( 'Zapisz' ); ?>
		</form>
	</div>
	<?php
}
