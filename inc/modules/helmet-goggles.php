<?php
/**
 * Oferta „Kask + gogle −20%”.
 *
 * Zasada: na każdy kask w koszyku jedne gogle (najdroższe w koszyku) tańsze o X% (Dostosuj → Narzędzia sklepu).
 * Rabat nalicza się sam – bez kodu. Nie łączy się z rabatem za komplet (liczy się korzystniejszy).
 *
 * Gdzie klient o tym słyszy (od pierwszego kontaktu do kasy):
 *  1. Karta kasku – pod przyciskiem: „Dobierz gogle −20%”.
 *  2. Okno „Produkt został dodany do koszyka” po dodaniu kasku – 3 pasujące gogle z ceną po rabacie
 *     i przyciskiem „Dodaj” (bez wychodzenia z okna).
 *  3. Karta gogli, gdy kask już jest w koszyku – cena po rabacie.
 *  4. Koszyk – brak gogli: przypomnienie z propozycjami; są gogle: cena przekreślona + „−20% z kaskiem”
 *     i wiersz „Rabat kask + gogle” w podsumowaniu.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

function fx_hg_pct() {
	return max( 0, min( 50, (float) fx_tool( 'hg_pct' ) ) );
}

/** Typ produktu: 'kask', 'gogle' albo ''. Po nazwie (KASK…/GOGLE…), a gdy nazwa nie mówi – po kategorii. */
function fx_hg_type( $product ) {
	$product = $product instanceof WC_Product ? $product : wc_get_product( $product );
	if ( ! $product ) {
		return '';
	}
	if ( $product->is_type( 'variation' ) ) {
		$product = wc_get_product( $product->get_parent_id() );
		if ( ! $product ) {
			return '';
		}
	}
	static $cache = array();
	$id = $product->get_id();
	if ( isset( $cache[ $id ] ) ) {
		return $cache[ $id ];
	}
	$name = mb_strtolower( $product->get_name(), 'UTF-8' );
	$type = '';
	// Szyby, folie, zrywki, daszki, wkładki itp. to akcesoria – nie gogle ani kask.
	if ( ! preg_match( '/\b(szyb|lens|folia|zrywk|tear|roll|daszek|daszk|visor|wkład|wklad|pads|poduszk|torb|pokrowiec|naklejk|sticker)/u', $name ) ) {
		if ( preg_match( '/^\s*(kask|helmet)/u', $name ) ) {
			$type = 'kask';
		} elseif ( preg_match( '/^\s*(gogl|goggle)/u', $name ) ) {
			$type = 'gogle';
		}
	}
	return $cache[ $id ] = apply_filters( 'fx_hg_type', $type, $product );
}

/**
 * Świat produktu: 'moto', 'rower' albo '' (nie wiadomo). Po kategoriach (Moto/Rower z Dostosuj albo „rower…” / „off-road”
 * w adresie kategorii), a gdy kategorie nie rozstrzygają – po nazwie („rowerowy”, „MTB” / „motocyklowy”, „cross”, „enduro”).
 */
function fx_hg_world( $id ) {
	static $cache = array();
	$id = (int) $id;
	if ( isset( $cache[ $id ] ) ) {
		return $cache[ $id ];
	}
	$found = array();
	$moto  = function_exists( 'fx_world_cat' ) ? (int) fx_world_cat( 'moto' ) : 0;
	$rower = function_exists( 'fx_world_cat' ) ? (int) fx_world_cat( 'rower' ) : 0;
	foreach ( wc_get_product_cat_ids( $id ) as $cid ) {
		$chain = array_merge( array( (int) $cid ), array_map( 'intval', get_ancestors( $cid, 'product_cat', 'taxonomy' ) ) );
		foreach ( $chain as $tid ) {
			$t    = get_term( $tid, 'product_cat' );
			$slug = $t && ! is_wp_error( $t ) ? $t->slug : '';
			if ( ( $rower && $tid === $rower ) || preg_match( '/rower|mtb|bike/', $slug ) ) {
				$found['rower'] = true;
			} elseif ( ( $moto && $tid === $moto ) || preg_match( '/off-road|motocross|motocykl|enduro|cross/', $slug ) ) {
				$found['moto'] = true;
			}
		}
	}
	if ( 1 !== count( $found ) ) {
		$found = array();
		$name  = mb_strtolower( get_the_title( $id ), 'UTF-8' );
		if ( preg_match( '/rowerow|\bmtb\b|\bbike\b|enduro mtb|downhill/u', $name ) ) {
			$found['rower'] = true;
		} elseif ( preg_match( '/motocykl|motocross|\bmx\b|crossow|\bcross\b|enduro/u', $name ) ) {
			$found['moto'] = true;
		}
	}
	return $cache[ $id ] = ( 1 === count( $found ) ? (string) key( $found ) : '' );
}

/** Czy kask i gogle są z tego samego świata (moto z moto, rower z rowerem; nieznany pasuje do wszystkiego). */
function fx_hg_same_world( $helmet_id, $goggle_id ) {
	$a = fx_hg_world( $helmet_id );
	$b = fx_hg_world( $goggle_id );
	return '' === $a || '' === $b || $a === $b;
}

/** Kaski i gogle w koszyku: [ 'helmets' => liczba sztuk, 'goggles' => [klucz pozycji => sztuki], 'hw' => [świat => sztuki] ]. */
function fx_hg_cart_state( $cart = null ) {
	$cart = $cart ? $cart : ( function_exists( 'WC' ) ? WC()->cart : null );
	$out  = array( 'helmets' => 0, 'goggles' => array(), 'hw' => array() );
	if ( ! $cart ) {
		return $out;
	}
	foreach ( $cart->get_cart() as $k => $item ) {
		$t = fx_hg_type( (int) $item['product_id'] );
		if ( 'kask' === $t ) {
			$out['helmets'] += (int) $item['quantity'];
			$w = fx_hg_world( (int) $item['product_id'] );
			$out['hw'][ $w ] = ( isset( $out['hw'][ $w ] ) ? $out['hw'][ $w ] : 0 ) + (int) $item['quantity'];
		} elseif ( 'gogle' === $t ) {
			$out['goggles'][ $k ] = (int) $item['quantity'];
		}
	}
	return $out;
}

/* ---------- Rabat w koszyku (po rabacie za komplet – bierzemy korzystniejszy) ---------- */
add_action( 'woocommerce_before_calculate_totals', function ( $cart ) {
	if ( ( is_admin() && ! wp_doing_ajax() ) || ! $cart ) {
		return;
	}
	$GLOBALS['fx_hg_saved'] = 0;
	$pct = fx_hg_pct();
	if ( $pct <= 0 ) {
		return;
	}
	$st = fx_hg_cart_state( $cart );
	if ( ! $st['helmets'] || ! $st['goggles'] ) {
		return;
	}
	// Najdroższe gogle najpierw – klient zyskuje najwięcej.
	$lines = array();
	$regs  = array();
	foreach ( $st['goggles'] as $k => $q ) {
		$item = $cart->cart_contents[ $k ];
		$line = wc_get_product( $item['variation_id'] ? $item['variation_id'] : $item['product_id'] );
		if ( $line ) {
			$lines[ $k ] = (float) $line->get_price();
			$regs[ $k ]  = (float) $line->get_regular_price();
		}
	}
	arsort( $lines );
	$hw = $st['hw']; // kaski do wykorzystania wg świata (moto / rower / '')
	foreach ( $lines as $k => $orig ) {
		if ( $orig <= 0 ) {
			continue;
		}
		$item  = $cart->cart_contents[ $k ];
		$qty   = (int) $item['quantity'];
		$gw    = fx_hg_world( (int) $item['product_id'] );
		$units = 0;
		// Gogle moto – tylko z kaskiem moto, rowerowe – z rowerowym; świat nieznany pasuje do wszystkiego.
		foreach ( array_keys( $hw ) as $w ) {
			if ( $units >= $qty ) {
				break;
			}
			if ( '' !== $gw && '' !== $w && $w !== $gw ) {
				continue;
			}
			$take      = min( $hw[ $w ], $qty - $units );
			$units    += $take;
			$hw[ $w ] -= $take;
		}
		if ( $units <= 0 ) {
			continue;
		}
		// −X% liczone od ceny REGULARNEJ: gogle w promocji kosztują wtedy reg × 0,8,
		// a gdy promocja jest już głębsza – zostaje cena promocyjna (bez dodatkowego rabatu).
		$reg   = ! empty( $regs[ $k ] ) ? $regs[ $k ] : $orig;
		$unit  = min( $orig, $reg * ( 1 - $pct / 100 ) );
		$hg    = ( $unit * $units + $orig * ( $qty - $units ) ) / $qty;
		$now   = (float) $item['data']->get_price(); // po ewentualnym rabacie za komplet
		if ( $hg < $now ) {
			$item['data']->set_price( round( $hg, wc_get_price_decimals() ) );
			$cart->cart_contents[ $k ]['fx_hg_units'] = $units;
			$cart->cart_contents[ $k ]['fx_hg_orig']  = $reg;
			$cart->cart_contents[ $k ]['fx_kit_units'] = 0; // bez podwójnego oznaczenia rabatu za komplet
			if ( isset( $GLOBALS['fx_kit_saved'] ) ) {
				$GLOBALS['fx_kit_saved'] = max( 0, $GLOBALS['fx_kit_saved'] - ( $orig - $now ) * $qty );
			}
			$GLOBALS['fx_hg_saved'] += ( $orig - $hg ) * $qty;
		}
	}
}, 25 );

add_filter( 'woocommerce_cart_item_price', function ( $price, $item ) {
	if ( empty( $item['fx_hg_units'] ) || empty( $item['fx_hg_orig'] ) ) {
		return $price;
	}
	return '<del>' . wc_price( $item['fx_hg_orig'] ) . '</del> ' . $price . '<br><small class="fx-kit-badge fx-hg-badge">−' . esc_html( wc_format_localized_decimal( fx_hg_pct() ) ) . '% od ceny regularnej z kaskiem</small>';
}, 25, 2 );

function fx_hg_saved_row() {
	$saved = isset( $GLOBALS['fx_hg_saved'] ) ? (float) $GLOBALS['fx_hg_saved'] : 0;
	if ( $saved > 0.009 ) {
		echo '<tr class="fx-kit-saved fx-hg-saved"><th>Rabat kask + gogle</th><td data-title="Rabat kask + gogle">−' . wc_price( $saved ) . ' <small>(już w cenach)</small></td></tr>'; // phpcs:ignore
	}
}
add_action( 'woocommerce_cart_totals_before_order_total', 'fx_hg_saved_row', 6 );
add_action( 'woocommerce_review_order_before_order_total', 'fx_hg_saved_row', 6 );

/* ---------- Propozycje gogli ---------- */

/** Słowa z nazwy (małe litery, bez „fox”, „gogle” itp.). */
function fx_hg_words( $name ) {
	$w = preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( wp_strip_all_tags( (string) $name ), 'UTF-8' ), -1, PREG_SPLIT_NO_EMPTY );
	return array_values( array_diff( $w, array( 'fox', 'racing', 'gogle', 'goggle', 'goggles', 'kask', 'helmet', 'motocyklowy', 'motocyklowe', 'rowerowy', 'rowerowe', 'crossowy', 'crossowe', 'mx', 'mtb', 'na', 'do', 'z', 'i' ) ) );
}

/** Kolory w słowach (angielskie i polskie nazwy sprowadzone do jednej). */
function fx_hg_colors( $words ) {
	$map = array(
		'black' => 'black', 'blk' => 'black', 'czarny' => 'black', 'czarne' => 'black', 'czarna' => 'black',
		'white' => 'white', 'wht' => 'white', 'biały' => 'white', 'białe' => 'white', 'biala' => 'white',
		'blue' => 'blue', 'niebieski' => 'blue', 'niebieskie' => 'blue', 'navy' => 'blue', 'cobalt' => 'blue', 'granatowy' => 'blue',
		'red' => 'red', 'czerwony' => 'red', 'czerwone' => 'red', 'burgundy' => 'red', 'maroon' => 'red',
		'pink' => 'pink', 'różowy' => 'pink', 'różowe' => 'pink', 'purple' => 'purple', 'fioletowy' => 'purple', 'lava' => 'purple',
		'grey' => 'grey', 'gray' => 'grey', 'szary' => 'grey', 'szare' => 'grey', 'steel' => 'grey', 'silver' => 'grey', 'chrome' => 'grey',
		'green' => 'green', 'zielony' => 'green', 'olive' => 'green', 'mint' => 'green', 'teal' => 'green',
		'yellow' => 'yellow', 'żółty' => 'yellow', 'fluo' => 'yellow', 'fluorescent' => 'yellow', 'citrus' => 'yellow', 'flo' => 'yellow',
		'orange' => 'orange', 'pomarańczowy' => 'orange', 'gold' => 'gold', 'złoty' => 'gold', 'camo' => 'camo',
	);
	$out = array();
	foreach ( $words as $w ) {
		if ( isset( $map[ $w ] ) ) {
			$out[ $map[ $w ] ] = true;
		}
	}
	return array_keys( $out );
}

/**
 * Do 3 gogli pasujących do kasku. Kolejność:
 * 1. ta sama grafika (np. „Frame”, „Nitro”), 2. ten sam kolor, 3. podobna nazwa, 4. czarne albo białe (pasują do wszystkiego).
 * Gogle bez żadnego z tych powiązań nie są proponowane.
 */
function fx_hg_suggest( $helmet_id = 0, $limit = 3 ) {
	$pool = get_transient( 'fx_hg_pool2' );
	if ( ! is_array( $pool ) ) {
		$pool = array();
		$q    = wc_get_products(
			array(
				'status'       => 'publish',
				'stock_status' => 'instock',
				'limit'        => 300,
				'orderby'      => 'popularity',
				'order'        => 'DESC',
				'return'       => 'ids',
				's'            => 'gogle',
			)
		);
		foreach ( $q as $id ) {
			if ( 'gogle' === fx_hg_type( $id ) ) {
				$pool[] = (int) $id;
			}
		}
		set_transient( 'fx_hg_pool2', $pool, 6 * HOUR_IN_SECONDS );
	}
	$in_cart = array();
	if ( WC()->cart ) {
		foreach ( WC()->cart->get_cart() as $item ) {
			$in_cart[ (int) $item['product_id'] ] = true;
		}
	}
	$hw      = $helmet_id ? fx_hg_words( get_the_title( $helmet_id ) ) : array();
	$hcolors = fx_hg_colors( $hw );
	$hjunior = (bool) array_intersect( $hw, array( 'junior', 'youth', 'kids' ) );
	$same    = 0;
	if ( $helmet_id && function_exists( 'fx_kit_gear_for' ) && function_exists( 'fx_kit_parse_name' ) ) {
		list( , $key ) = fx_kit_parse_name( get_the_title( $helmet_id ) );
		$same          = $key ? (int) fx_kit_gear_for( 'gogle', $key ) : 0;
	}
	$scored = array();
	foreach ( $pool as $rank => $id ) {
		if ( ! empty( $in_cart[ $id ] ) ) {
			continue;
		}
		$p = wc_get_product( $id );
		if ( ! $p || ! $p->is_visible() || ! $p->is_in_stock() || ! $p->is_purchasable() ) {
			continue;
		}
		if ( $helmet_id && ! fx_hg_same_world( $helmet_id, $id ) ) {
			continue; // do kasku motocyklowego tylko gogle moto, do rowerowego – rowerowe
		}
		$gw = fx_hg_words( $p->get_name() );
		if ( (bool) array_intersect( $gw, array( 'junior', 'youth', 'kids' ) ) !== $hjunior ) {
			continue; // junior do junior, dorośli do dorosłych
		}
		$gcolors = fx_hg_colors( $gw );
		$color   = count( array_intersect( $gcolors, $hcolors ) );
		$name    = count( array_filter( array_intersect( $gw, $hw ), function ( $t ) { return mb_strlen( $t ) > 2 && ! preg_match( '/^\d+$/', $t ); } ) ) - $color;
		$bw      = ( in_array( 'black', $gcolors, true ) || in_array( 'white', $gcolors, true ) ) ? 1 : 0;
		$graphic = ( $id === $same ) ? 1 : 0;
		if ( ! $graphic && ! $color && $name <= 0 && ! $bw ) {
			continue;
		}
		$scored[] = array( array( $graphic, $color, max( 0, $name ), $bw, -$rank ), $id );
	}
	usort(
		$scored,
		function ( $a, $b ) {
			return $b[0] <=> $a[0];
		}
	);
	$ids = array_map( function ( $r ) { return $r[1]; }, $scored );
	// Trzy półki cenowe: MAIN, AIRSPACE, VUE – z każdej najlepiej pasujące gogle (od najtańszej półki).
	if ( apply_filters( 'fx_hg_by_model', true ) ) {
		$tiers = array();
		foreach ( $ids as $id ) {
			$m = fx_hg_model( $id );
			if ( $m && ! isset( $tiers[ $m ] ) ) {
				$tiers[ $m ] = $id;
			}
		}
		if ( count( $tiers ) >= 2 ) {
			$out = array();
			foreach ( array_keys( fx_hg_models() ) as $m ) {
				if ( isset( $tiers[ $m ] ) ) {
					$out[] = $tiers[ $m ];
				}
			}
			return array_slice( $out, 0, $limit );
		}
	}
	return array_slice( $ids, 0, $limit );
}

/** Modele gogli od najtańszego: klucz => nazwa wyświetlana. */
function fx_hg_models() {
	return apply_filters( 'fx_hg_models', array( 'main' => 'MAIN', 'airspace' => 'AIRSPACE', 'vue' => 'VUE' ) );
}

/** Model gogli z nazwy („GOGLE FOX AIRSPACE …” → airspace) albo ''. */
function fx_hg_model( $id ) {
	$w = fx_hg_words( get_the_title( $id ) );
	foreach ( array_keys( fx_hg_models() ) as $m ) {
		if ( in_array( $m, $w, true ) ) {
			return $m;
		}
	}
	return '';
}

/** Kategoria „inne kolory” dla gogli (najgłębsza kategoria produktu) i kategoria wszystkich gogli (przodek z „gogl” w nazwie). */
function fx_hg_cats( $id ) {
	$terms = get_the_terms( $id, 'product_cat' );
	if ( ! $terms || is_wp_error( $terms ) ) {
		return array( '', '' );
	}
	$deep = null;
	$max  = -1;
	foreach ( $terms as $t ) {
		$d = count( get_ancestors( $t->term_id, 'product_cat', 'taxonomy' ) );
		if ( $d > $max ) {
			$max  = $d;
			$deep = $t;
		}
	}
	$all = '';
	foreach ( array_merge( array( $deep->term_id ), get_ancestors( $deep->term_id, 'product_cat', 'taxonomy' ) ) as $tid ) {
		$t = get_term( $tid, 'product_cat' );
		if ( $t && preg_match( '/gogl/iu', $t->name ) && ! preg_match( '/\b(main|airspace|vue)\b/iu', $t->name ) ) {
			$all = get_term_link( $t );
			break;
		}
	}
	$one = get_term_link( $deep );
	return array( is_wp_error( $one ) ? '' : $one, is_wp_error( $all ) ? '' : $all );
}
add_action( 'woocommerce_update_product', function () {
	delete_transient( 'fx_hg_pool2' );
} );

/** Cena po rabacie (najniższa dostępna dla gogli z rozmiarami/wersjami). */
/**
 * [ cena regularna, cena z kaskiem ] – rabat od ceny regularnej, ale nigdy drożej niż obecna
 * cena (głębsza promocja zostaje). Dla wariantów: najtańsza opcja.
 */
function fx_hg_prices( $p ) {
	$k = 1 - fx_hg_pct() / 100;
	if ( $p->is_type( 'variable' ) ) {
		$vp   = $p->get_variation_prices( true );
		$best = null;
		foreach ( (array) $vp['price'] as $vid => $now ) {
			$r    = isset( $vp['regular_price'][ $vid ] ) && (float) $vp['regular_price'][ $vid ] > 0 ? (float) $vp['regular_price'][ $vid ] : (float) $now;
			$deal = min( (float) $now, $r * $k );
			if ( null === $best || $deal < $best[1] ) {
				$best = array( $r, $deal );
			}
		}
		return $best ? $best : array( 0, 0 );
	}
	$now = (float) wc_get_price_to_display( $p );
	$r   = $p->get_regular_price() ? (float) wc_get_price_to_display( $p, array( 'price' => $p->get_regular_price() ) ) : $now;
	return array( $r, min( $now, $r * $k ) );
}

/** Kafelki gogli z ceną po rabacie i przyciskiem dodania. */
function fx_hg_tiles( $ids, $context = 'modal' ) {
	if ( ! $ids ) {
		return '';
	}
	$models = fx_hg_models();
	$all    = '';
	$h      = '<ul class="fx-hg__list">';
	foreach ( $ids as $id ) {
		$p = wc_get_product( $id );
		if ( ! $p ) {
			continue;
		}
		list( $reg, $deal ) = fx_hg_prices( $p );
		list( $more, $any ) = fx_hg_cats( $id );
		$all                = $all ? $all : $any;
		$m                  = fx_hg_model( $id );
		$simple             = $p->is_type( 'simple' );
		$ajax               = 'modal' === $context ? ' ajax_add_to_cart add_to_cart_button' : '';
		$btn                = $simple
			? '<a href="' . esc_url( add_query_arg( 'add-to-cart', $id, wc_get_cart_url() ) ) . '" data-product_id="' . (int) $id . '" data-quantity="1" class="fx-hg__add' . $ajax . '" rel="nofollow">Dodaj</a>'
			: '<a href="' . esc_url( $p->get_permalink() ) . '" class="fx-hg__add">Wybierz</a>';
		$h .= '<li class="fx-hg__item"><a class="fx-hg__img" href="' . esc_url( $p->get_permalink() ) . '">' . $p->get_image( 'woocommerce_gallery_thumbnail' ) . '</a>'
			. '<div class="fx-hg__info">' . ( $m ? '<span class="fx-hg__model">' . esc_html( $models[ $m ] ) . '</span>' : '' )
			. '<a class="fx-hg__name" href="' . esc_url( $p->get_permalink() ) . '">' . esc_html( $p->get_name() ) . '</a>'
			. '<span class="fx-hg__price"><del>' . wc_price( $reg ) . '</del> <strong>' . wc_price( $deal ) . '</strong></span>'
			. ( $more ? '<a class="fx-hg__more" href="' . esc_url( $more ) . '">inne kolory' . ( $m ? ' ' . esc_html( $models[ $m ] ) : '' ) . ' →</a>' : '' )
			. '</div>' . $btn . '</li>';
	}
	$h .= '</ul>';
	if ( $all ) {
		$h .= '<a class="fx-hg__all" href="' . esc_url( $all ) . '">Zobacz wszystkie gogle i kolory</a>';
	}
	return $h;
}

/* 1. Karta kasku: zapowiedź oferty pod przyciskiem. */
add_action( 'woocommerce_after_add_to_cart_button', function () {
	global $product;
	if ( fx_hg_pct() <= 0 || ! $product || 'kask' !== fx_hg_type( $product ) ) {
		return;
	}
	echo '<p class="fx-hg-teaser"><span class="fx-hg-teaser__tag">−' . esc_html( wc_format_localized_decimal( fx_hg_pct() ) ) . '%</span> Kup kask z goglami – <strong>gogle ' . esc_html( wc_format_localized_decimal( fx_hg_pct() ) ) . '% taniej</strong>. Rabat naliczy się sam w koszyku.</p>';
}, 30 );

/* 2. Okno po dodaniu kasku: gogle z ceną po rabacie. */
add_filter( 'fx_added_offer', function ( $html, $p, $item ) {
	if ( fx_hg_pct() <= 0 || 'kask' !== fx_hg_type( (int) $item['product_id'] ) ) {
		return $html;
	}
	$st = fx_hg_cart_state();
	if ( count( $st['goggles'] ) >= 1 && array_sum( $st['goggles'] ) >= $st['helmets'] ) {
		return $html . '<div class="fx-hg fx-hg--done"><strong>Masz kask i gogle w koszyku</strong> – gogle już są ' . esc_html( wc_format_localized_decimal( fx_hg_pct() ) ) . '% taniej.</div>';
	}
	$ids = fx_hg_suggest( (int) $item['product_id'], 3 );
	if ( ! $ids ) {
		return $html;
	}
	return $html . '<div class="fx-hg"><p class="fx-hg__title">Dorzuć gogle i zgarnij <span>−' . esc_html( wc_format_localized_decimal( fx_hg_pct() ) ) . '%</span></p><p class="fx-hg__sub">Rabat nalicza się automatycznie – bez kodu. Jedne gogle na każdy kask.</p>' . fx_hg_tiles( $ids ) . '</div>';
}, 10, 3 );

/* 3. Karta gogli, gdy kask jest w koszyku. */
add_action( 'woocommerce_single_product_summary', function () {
	global $product;
	if ( fx_hg_pct() <= 0 || ! $product || 'gogle' !== fx_hg_type( $product ) ) {
		return;
	}
	$st = fx_hg_cart_state();
	$gw = fx_hg_world( $product->get_id() );
	if ( ! $st['helmets'] || ( '' !== $gw && empty( $st['hw'][''] ) && empty( $st['hw'][ $gw ] ) ) ) {
		return; // kask z innego świata (np. rowerowy przy goglach moto) – brak oferty
	}
	list( $reg, $deal ) = fx_hg_prices( $product );
	echo '<p class="fx-hg-teaser fx-hg-teaser--on"><span class="fx-hg-teaser__tag">−' . esc_html( wc_format_localized_decimal( fx_hg_pct() ) ) . '%</span> Masz kask w koszyku – te gogle kupisz za <strong>' . wp_kses_post( ( $product->is_type( 'variable' ) ? 'od ' : '' ) . wc_price( $deal ) ) . '</strong>.</p>';
}, 11 );

/* 4. Koszyk: kask bez gogli – przypomnienie z propozycjami. */
add_action( 'woocommerce_after_cart_table', function () {
	if ( fx_hg_pct() <= 0 ) {
		return;
	}
	$st = fx_hg_cart_state();
	if ( ! $st['helmets'] || array_sum( $st['goggles'] ) >= $st['helmets'] ) {
		return;
	}
	$helmet = 0;
	foreach ( WC()->cart->get_cart() as $item ) {
		if ( 'kask' === fx_hg_type( (int) $item['product_id'] ) ) {
			$helmet = (int) $item['product_id'];
			break;
		}
	}
	$ids = fx_hg_suggest( $helmet, 3 );
	if ( ! $ids ) {
		return;
	}
	echo '<div class="fx-hg fx-hg--cart"><p class="fx-hg__title">Masz kask – dorzuć gogle <span>−' . esc_html( wc_format_localized_decimal( fx_hg_pct() ) ) . '%</span></p><p class="fx-hg__sub">Rabat nalicza się automatycznie w koszyku.</p>' . fx_hg_tiles( $ids, 'cart' ) . '</div>'; // phpcs:ignore
} );
