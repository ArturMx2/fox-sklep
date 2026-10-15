<?php
/**
 * Porównanie kasków FOX V3 RS / V3 / V1 („3 poziomy ochrony”) na kartach tych kasków.
 *
 * - Pokazuje się na kaskach motocyklowych, w których nazwie jest V1, V3 albo V3 RS.
 * - Zdjęcie każdego modelu: oglądany kask albo kask tego modelu dostępny w sklepie
 *   (najchętniej Solid / Black – spokojna grafika).
 * - „od … zł” = najniższa cena dostępnego kasku danego modelu; przycisk prowadzi do kategorii modelu
 *   (gdy jej brak – do wyszukiwania).
 * - Treść porównania: filtr fx_hc_models.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

/** Modele i porównanie (kolejność kolumn). */
function fx_hc_models() {
	return apply_filters(
		'fx_hc_models',
		array(
			'v3rs' => array(
				'name' => 'V3 RS',
				'tag'  => 'Wyścigowa specyfikacja na nowo',
				'q'    => 'kask fox v3 rs',
			),
			'v3'   => array(
				'name' => 'V3',
				'tag'  => 'Kask motocrossowy gotowy na zawody',
				'q'    => 'kask fox v3',
			),
			'v1'   => array(
				'name' => 'V1',
				'tag'  => 'Nowy standard',
				'q'    => 'kask fox v1',
			),
		)
	);
}

/** Wiersze porównania: etykieta => [ v3rs, v3, v1 ]; true = ✓, '' = —. */
function fx_hc_rows() {
	return apply_filters(
		'fx_hc_rows',
		array(
			'Ochrona przed siłami rotacyjnymi' => array(
				'Mips® Integra Split – dwie warstwy EPS ograniczają siły rotacyjne działające na głowę przy upadku',
				'Mips® – warstwa o niskim tarciu między kaskiem a głową',
				'Mips® – warstwa o niskim tarciu między kaskiem a głową',
			),
			'Homologacje'                      => array( 'DOT i ECE 22.06', 'DOT i ECE 22.06', 'DOT i ECE 22.06' ),
			// Wagi wg tabel rozmiarów FOX (foxracing.com), tolerancja ±50 g.
			'Waga (rozmiar M)'                 => array(
				'<strong>1270 g</strong> ±50 g<br><small>XS–XXL: 1180–1400 g</small>',
				'<strong>1270 g</strong> ±50 g<br><small>XS–XXL: 1165–1400 g</small>',
				'<strong>1240 g</strong> ±50 g<br><small>XS–XXL: 1080–1440 g</small>',
			),
			'Rozmiary'                         => array(
				'Dorosłe: XS–XXL',
				'Młodzieżowe: YS–YL<br>Dorosłe: XS–XXL',
				'Młodzieżowe: YS–YL<br>Dorosłe: XS–XXL',
			),
			'Skorupa z włókna węglowego'       => array( 'Większa odporność na uderzenia przy mniejszej wadze', '', '' ),
			'Wentylacja'                       => array( '4 otwory wylotowe na górze kasku – lepsze chłodzenie', '', '' ),
			'Wyściółka'                        => array( 'Ionic+® odprowadza wilgoć, poduszki policzkowe z kamieniem Jade Stone Cold® dodatkowo chłodzą', '', '' ),
			'Awaryjne zdejmowanie poduszek policzkowych' => array( true, true, '' ),
			'Wnęki na głośniki interkomu'      => array( 'Pasują do większości systemów komunikacji', 'Pasują do większości systemów komunikacji', '' ),
			'Prowadzenie rurki do picia'       => array( 'Pij w trakcie jazdy', 'Pij w trakcie jazdy', '' ),
			'W zestawie'                       => array(
				'Zapasowy daszek z mocowaniem, nakładka przedłużająca daszek, daszek błotny z pianką, ustnik do systemu nawadniania, zapasowe poduszki policzkowe (grube i cienkie)',
				'Nakładka przedłużająca daszek, zapasowe mocowanie daszka, ustnik do systemu nawadniania',
				'',
			),
		)
	);
}

/** Model kasku z nazwy produktu: v3rs / v3 / v1 albo ''. Tylko kaski motocyklowe. */
function fx_hc_model( $product ) {
	$p = $product instanceof WC_Product ? $product : wc_get_product( $product );
	if ( ! $p ) {
		return '';
	}
	$n = ' ' . mb_strtolower( $p->get_name() ) . ' ';
	// Tylko same kaski: nazwa zaczyna się od „Kask”; bez części („gąbki do kasku”, daszki, wizjery…).
	if ( ! preg_match( '/^\s*kask/u', $n ) || preg_match( '/rower|mtb|bike|wizjer|daszek|poduszk|gąbk|gabk|szybk|część|czesc|do kasku/u', $n ) ) {
		return '';
	}
	if ( preg_match( '/\bv3\s*-?\s*rs\b/u', $n ) ) {
		return 'v3rs';
	}
	if ( preg_match( '/\bv3\b/u', $n ) ) {
		return 'v3';
	}
	if ( preg_match( '/\bv1\b/u', $n ) ) {
		return 'v1';
	}
	return '';
}

/** Dane modeli: dostępne kaski, cena od, link. Cache 12 h (czyszczony z pamięcią motywu). */
function fx_hc_pool() {
	$pool = get_transient( 'fx_hc_pool2' );
	if ( is_array( $pool ) ) {
		return $pool;
	}
	global $wpdb;
	$ids  = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish' AND post_title LIKE '%kask%' AND ( post_title LIKE '%v1%' OR post_title LIKE '%v3%' )" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$pool = array();
	foreach ( array_keys( fx_hc_models() ) as $m ) {
		$pool[ $m ] = array( 'ids' => array(), 'img' => 0, 'score' => -1, 'min' => 0, 'url' => '' );
	}
	foreach ( $ids as $id ) {
		$p = wc_get_product( (int) $id );
		if ( ! $p || ! $p->is_visible() ) {
			continue;
		}
		$m = fx_hc_model( $p );
		if ( ! $m || ! isset( $pool[ $m ] ) || ( function_exists( 'fx_hg_world' ) && 'rower' === fx_hg_world( (int) $id ) ) ) {
			continue;
		}
		$in    = $p->is_in_stock();
		$price = $p->is_type( 'variable' ) ? (float) $p->get_variation_price( 'min', true ) : (float) wc_get_price_to_display( $p );
		// „od … zł”: tylko pełnoprawne kaski – progiem odcinamy akcesoria/części z „V1/V3” w nazwie.
		if ( $in && $price >= (float) apply_filters( 'fx_hc_min_price', 500 ) && ( ! $pool[ $m ]['min'] || $price < $pool[ $m ]['min'] ) ) {
			$pool[ $m ]['min'] = $price;
		}
		// Zdjęcie modelu: dostępny kask, najchętniej spokojna grafika (Solid / Black).
		$score = ( $in ? 2 : 0 ) + ( preg_match( '/solid|black|czarn/i', $p->get_name() ) ? 1 : 0 );
		if ( $p->get_image_id() && $score > $pool[ $m ]['score'] ) {
			$pool[ $m ]['img']   = (int) $p->get_image_id();
			$pool[ $m ]['score'] = $score;
		}
		$pool[ $m ]['ids'][] = (int) $id;
	}
	// Kategoria modelu (np. „V3 RS”, „Kaski V3”), inaczej wyszukiwanie.
	$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true ) );
	foreach ( fx_hc_models() as $m => $def ) {
		foreach ( is_wp_error( $terms ) ? array() : $terms as $t ) {
			$name = mb_strtolower( preg_replace( '/[\s\-]+/u', ' ', $t->name ) );
			$want = mb_strtolower( $def['name'] );
			if ( preg_match( '/^(kaski?\s+)?(fox\s+)?' . preg_quote( $want, '/' ) . '$/u', $name ) ) {
				$link = get_term_link( $t );
				if ( ! is_wp_error( $link ) && false === strpos( $link, 'rower' ) ) {
					$pool[ $m ]['url'] = $link;
					break;
				}
			}
		}
		if ( ! $pool[ $m ]['url'] ) {
			$pool[ $m ]['url'] = add_query_arg( array( 's' => $def['q'], 'post_type' => 'product' ), home_url( '/' ) );
		}
	}
	set_transient( 'fx_hc_pool2', $pool, 12 * HOUR_IN_SECONDS );
	return $pool;
}
add_action( 'fx_flush_caches', function () {
	delete_transient( 'fx_hc_pool2' );
}, 20 );

add_action( 'woocommerce_after_single_product_summary', 'fx_hc_section', 14 );
function fx_hc_section() {
	global $product;
	if ( ! $product instanceof WC_Product || ! apply_filters( 'fx_hc_enabled', true, $product ) ) {
		return;
	}
	$cur = fx_hc_model( $product );
	if ( ! $cur || ( function_exists( 'fx_hg_world' ) && 'rower' === fx_hg_world( $product->get_id() ) ) ) {
		return;
	}
	$models = fx_hc_models();
	$pool   = fx_hc_pool();
	$keys   = array_keys( $models );
	echo '<section class="fx-hc" aria-labelledby="fx-hc-title"><div class="fx-row-head"><h2 id="fx-hc-title">3 poziomy ochrony – porównanie kasków FOX</h2></div>';
	echo '<p class="fx-hc__hint">Przesuń tabelę w bok, żeby zobaczyć wszystkie modele →</p><div class="fx-hc__scroll"><table class="fx-hc__table"><thead><tr><td class="fx-hc__lead"></td>';
	foreach ( $models as $m => $def ) {
		$is   = $m === $cur;
		// Zdjęcie: z Dostosuj → FOX.SKLEP.PL → Porównanie kasków; inaczej oglądany kask / kask tego modelu ze sklepu.
		$img  = (int) fx_mod( 'hc_img_' . $m );
		$img  = $img ? $img : ( $is && $product->get_image_id() ? (int) $product->get_image_id() : (int) ( $pool[ $m ]['img'] ?? 0 ) );
		$url  = fx_mod( 'hc_url_' . $m ) ? fx_mod( 'hc_url_' . $m ) : ( $pool[ $m ]['url'] ?? '#' );
		$from = (float) ( $pool[ $m ]['min'] ?? 0 );
		echo '<th scope="col" class="fx-hc__model' . ( $is ? ' is-current' : '' ) . '">';
		echo '<div class="fx-hc__img">' . ( $img ? wp_get_attachment_image( $img, 'woocommerce_thumbnail', false, array( 'loading' => 'lazy', 'alt' => 'Kask FOX ' . $def['name'] ) ) : '' ) . ( $is ? '<span class="fx-hc__badge">Oglądasz</span>' : '' ) . '</div>'; // phpcs:ignore
		echo '<p class="fx-hc__name">' . esc_html( $def['name'] ) . '</p><p class="fx-hc__tag">' . esc_html( $def['tag'] ) . '</p>';
		if ( $from > 0 ) {
			echo '<p class="fx-hc__from">od <strong>' . wp_kses_post( wc_price( $from ) ) . '</strong></p>';
		}
		echo '<a class="fx-btn fx-btn--outline fx-hc__btn" href="' . esc_url( $url ) . '">' . ( $is ? 'Inne grafiki ' : 'Zobacz kaski ' ) . esc_html( $def['name'] ) . '</a>';
		echo '</th>';
	}
	echo '</tr></thead><tbody>';
	foreach ( fx_hc_rows() as $label => $vals ) {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th>';
		foreach ( $keys as $i => $m ) {
			$v = $vals[ $i ] ?? '';
			$c = $m === $cur ? ' class="is-current"' : '';
			if ( true === $v ) {
				echo '<td' . $c . '><span class="fx-hc__yes" aria-label="Tak">✓</span></td>'; // phpcs:ignore
			} elseif ( '' === $v ) {
				echo '<td' . $c . '><span class="fx-hc__no" aria-label="Nie">—</span></td>'; // phpcs:ignore
			} else {
				echo '<td' . $c . '>' . wp_kses( $v, array( 'br' => array(), 'strong' => array(), 'small' => array() ) ) . '</td>'; // phpcs:ignore
			}
		}
		echo '</tr>';
	}
	echo '</tbody></table></div></section>';
}
