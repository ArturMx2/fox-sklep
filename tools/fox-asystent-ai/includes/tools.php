<?php
/**
 * Asystent AI – narzędzia modelu (function calling) i karty produktów.
 *
 * @package fox-asystent-ai
 */

defined( 'ABSPATH' ) || exit;

function fx_ai_tool_defs() {
	$f = function ( $name, $desc, $props, $req = array() ) {
		return array(
			'type'     => 'function',
			'function' => array(
				'name'        => $name,
				'description' => $desc,
				'parameters'  => array( 'type' => 'object', 'properties' => (object) $props, 'required' => $req ),
			),
		);
	};
	return array(
		$f( 'szukaj_produktow', 'Szuka produktów FOX w sklepie (ta sama wyszukiwarka co na stronie: synonimy, literówki). Zwraca maks. 10 pozycji z ceną, rozmiarami na stanie i terminem wysyłki każdego rozmiaru. Krótkie zapytania: rodzaj + model/grafika/świat, np. „spodnie mx”, „kask rowerowy enduro”, „bluza 180 nitro”.', array(
			'zapytanie'      => array( 'type' => 'string', 'description' => 'Fraza po polsku dla JEDNEGO rodzaju produktu, np. „spodnie motocrossowe junior”, „kask mtb full face”. Kilka rodzajów – osobne wywołania (wiele naraz zostanie i tak rozdzielone).' ),
			'cena_min'       => array( 'type' => 'number' ),
			'cena_max'       => array( 'type' => 'number' ),
			'rozmiar'        => array( 'type' => 'string', 'description' => 'NAZWA rozmiaru, który musi być na stanie, np. M, XL, 32 (spodnie w calach), YM, 10 (buty US). NIE wpisuj tu centymetrów (np. obwodu głowy 58) – do tego jest obwod_glowy_cm. Pomiń, gdy klient nie podał.' ),
			'obwod_glowy_cm' => array( 'type' => 'number', 'description' => 'Dla kasków: obwód głowy klienta w cm (np. 58). Rozmiar zostanie dobrany osobno dla każdego modelu z jego tabeli rozmiarów.' ),
			'wzrost_cm'      => array( 'type' => 'number', 'description' => 'Wzrost osoby w cm – do doboru rozmiaru bluz, spodni, kompletów (osobno dla każdego modelu z jego tabeli).' ),
			'waga_kg'        => array( 'type' => 'number', 'description' => 'Tylko gdy klient SAM podał wagę – nie pytaj o nią.' ),
			'wiek'           => array( 'type' => 'number', 'description' => 'Wiek dziecka w latach (rozmiary juniorskie).' ),
			'pas_cm'         => array( 'type' => 'number', 'description' => 'Obwód pasa w cm (spodnie).' ),
			'klatka_cm'      => array( 'type' => 'number', 'description' => 'Obwód klatki piersiowej w cm (bluzy, kurtki).' ),
			'biodra_cm'      => array( 'type' => 'number', 'description' => 'Obwód bioder w cm (spodnie, szczególnie damskie).' ),
			'dlon_cm'        => array( 'type' => 'number', 'description' => 'Rękawice: długość dłoni w cm – od zgięcia nadgarstka do czubka środkowego palca (nie obwód).' ),
			'rozmiar_eu'     => array( 'type' => 'number', 'description' => 'Buty: numer w skali EU podany przez klienta (np. 46) – dobierze rozmiar modelu (US) z jego tabeli. NIE wpisuj go w pole rozmiar.' ),
			'dla_kogo'       => array( 'type' => 'string', 'enum' => array( 'meskie', 'damskie', 'junior', 'dzieciece' ) ),
			'swiat'          => array( 'type' => 'string', 'enum' => array( 'moto', 'rower', 'casual' ), 'description' => 'Świat produktu: moto (MX/enduro), rower (MTB), casual (odzież na co dzień: bluzy z kapturem, t-shirty, czapki). „Bluza z kapturem” / „hoodie” to casual, a „bluza motocrossowa / rowerowa” (jersey) to moto / rower.' ),
			'kategoria'      => array( 'type' => 'string', 'description' => 'Opcjonalnie: nazwa (albo jej fragment) kategorii sklepu, w której szukać, np. „Zbroje i buzery”, „Nakolanniki”, „Kaski MTB”. Używaj, gdy instrukcja właściciela wskazuje kategorię albo gdy wyniki są z innego rodzaju produktu.' ),
			'kolor'          => array( 'type' => 'string', 'description' => 'Kolor, którego szuka klient, po polsku, np. „czarny”, „biały”, „czerwony”, „niebieski”, „różowy”, „camo”. Kilka kolorów – oddziel przecinkiem („czarny, szary”). Pomiń, gdy klientowi kolor jest obojętny. NIE dopisuj koloru do pola zapytanie.' ),
			'tylko_dostepne' => array( 'type' => 'boolean', 'description' => 'Domyślnie true' ),
		), array( 'zapytanie' ) ),
		$f( 'szczegoly_produktu', 'Szczegóły produktu: opis, parametry, rozmiary na stanie z terminem wysyłki, tabela rozmiarów, części kompletu w tej samej grafice.', array(
			'id' => array( 'type' => 'integer' ),
		), array( 'id' ) ),
		$f( 'dopasuj_rozmiar', 'Dobiera rozmiar KONKRETNEGO modelu z jego tabeli rozmiarów na podstawie wymiarów osoby (wzrost, u dzieci wiek, obwód klatki, pasa, bioder, głowy; waga tylko gdy klient sam ją podał) i mówi, czy ten rozmiar jest na stanie. Zwraca też pełny tekst tabeli. Rozmiary różnią się między modelami – używaj dla każdego produktu osobno.', array(
			'id'             => array( 'type' => 'integer' ),
			'wzrost_cm'      => array( 'type' => 'number' ),
			'waga_kg'        => array( 'type' => 'number' ),
			'wiek'           => array( 'type' => 'number' ),
			'pas_cm'         => array( 'type' => 'number' ),
			'klatka_cm'      => array( 'type' => 'number' ),
			'biodra_cm'      => array( 'type' => 'number' ),
			'dlon_cm'        => array( 'type' => 'number' ),
			'rozmiar_eu'     => array( 'type' => 'number', 'description' => 'Numer buta / rozmiar w skali EU podany przez klienta (np. 46) – przelicza na rozmiar tego modelu (np. US) z jego tabeli.' ),
			'rozmiar_uk'     => array( 'type' => 'number', 'description' => 'Rozmiar w skali UK podany przez klienta.' ),
			'obwod_glowy_cm' => array( 'type' => 'number' ),
			'stopa_cm'       => array( 'type' => 'number' ),
		), array( 'id' ) ),
		$f( 'pokaz_formularz_telefonu', 'Pokazuje pod odpowiedzią okienko „Zostaw numer – oddzwonimy” (klient wpisuje telefon, sklep oddzwania). Używaj, gdy nie ma propozycji, klient woli rozmowę albo trzeba sprawdzić dostępność/dobór u sprzedawcy.', array(
			'powod' => array( 'type' => 'string' ),
		), array() ),
		$f( 'sprawdz_u_producenta', 'Sprawdza informacje o produkcie na stronach FOX (foxracing.co.uk, foxracing.com) po numerze modelu z SKU albo EAN: opis, specyfikację, materiał, pojemność, wagę, technologie. Używaj, gdy klient pyta o konkretny parametr, którego nie ma w szczegoly_produktu.', array(
			'id' => array( 'type' => 'integer' ),
		), array( 'id' ) ),
		$f( 'pokaz_produkty', 'Pokazuje klientowi karty produktów pod odpowiedzią (zdjęcie, cena, link). Używaj ZAWSZE, gdy polecasz produkty. Do 5 id na rodzaj produktu; przy kilku rodzajach (np. bluzy i spodnie) podaj je razem – każdy rodzaj będzie w osobnym wierszu z przyciskiem „Więcej”.', array(
			'ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'minItems' => 1, 'maxItems' => 10 ),
		), array( 'ids' ) ),
		$f( 'status_zamowienia', 'Status zamówienia, zwrotu i przesyłki. Wymaga numeru zamówienia i telefonu podanego przy zamówieniu.', array(
			'numer'   => array( 'type' => 'string' ),
			'telefon' => array( 'type' => 'string' ),
		), array( 'numer', 'telefon' ) ),
		$f( 'przekaz_do_obslugi', 'Przekazuje sprawę do obsługi sklepu (e-mail z całą rozmową). Wymaga adresu e-mail klienta.', array(
			'email'        => array( 'type' => 'string' ),
			'imie'         => array( 'type' => 'string' ),
			'telefon'      => array( 'type' => 'string' ),
			'streszczenie' => array( 'type' => 'string', 'description' => 'Krótko: o co chodzi, numer zamówienia jeśli jest' ),
		), array( 'email', 'streszczenie' ) ),
	);
}

function fx_ai_run_tool( $name, array $a ) {
	switch ( $name ) {
		case 'szukaj_produktow':
			return fx_ai_tool_search( $a );
		case 'szczegoly_produktu':
			return fx_ai_tool_details( (int) ( $a['id'] ?? 0 ) );
		case 'dopasuj_rozmiar':
			return fx_ai_tool_fit( (int) ( $a['id'] ?? 0 ), $a );
		case 'pokaz_formularz_telefonu':
			$GLOBALS['fx_ai_run']['callback'] = 1;
			return array( 'ok' => true, 'info' => 'Pod Twoją odpowiedzią pojawi się okienko na numer telefonu. Napisz krótko, że oddzwonimy, i podaj też telefon sklepu.' );
		case 'sprawdz_u_producenta':
			return function_exists( 'fx_ai_tool_fox' ) ? fx_ai_tool_fox( (int) ( $a['id'] ?? 0 ) ) : array( 'blad' => 'Niedostępne.' );
		case 'pokaz_produkty':
			return fx_ai_tool_show( (array) ( $a['ids'] ?? array() ) );
		case 'status_zamowienia':
			return fx_ai_tool_order( (string) ( $a['numer'] ?? '' ), (string) ( $a['telefon'] ?? '' ) );
		case 'przekaz_do_obslugi':
			return fx_ai_tool_handoff( $a );
	}
	return array( 'blad' => 'Nieznane narzędzie.' );
}

/* =====================================================================
 * Dane produktów
 * ===================================================================== */

/** Termin wysyłki wariantu / produktu prostego (tekst dla klienta). */
function fx_ai_ship( $id ) {
	static $po = null;
	if ( null === $po ) {
		$po = get_option( 'fxpo_active', array() );
		$po = is_array( $po ) ? $po : array();
	}
	if ( isset( $po[ $id ]['date'] ) ) {
		return 'przedsprzedaż – dostawa do nas ok. ' . wp_date( 'j.m.Y', strtotime( $po[ $id ]['date'] ) ) . ', wysyłka zaraz po dostawie';
	}
	$bl  = function_exists( 'fox_bl_delivery_get_settings' ) ? fox_bl_delivery_get_settings() : array();
	$cut = (string) ( $bl['cutoff_time'] ?? '15:00' );
	if ( function_exists( 'fx_ls_data' ) ) {
		$d = fx_ls_data();
		if ( empty( $d['stale'] ) && (int) ( $d['ship'][ $id ] ?? 0 ) > 0 ) {
			return 'wysyłka dziś przy zamówieniu do ' . $cut . ' (dni robocze)';
		}
	}
	$days = (int) ( $bl['days_other'] ?? 1 );
	return $days <= 1 ? 'wysyłka następnego dnia roboczego' : 'wysyłka w ' . $days . ' dni robocze';
}

function fx_ai_ship_today( $id ) {
	if ( ! function_exists( 'fx_ls_data' ) ) {
		return false;
	}
	$d = fx_ls_data();
	return empty( $d['stale'] ) && (int) ( $d['ship'][ $id ] ?? 0 ) > 0;
}

/** Nazwa rozmiaru wariantu („M”, „32”, „YM”). */
function fx_ai_size_label( WC_Product_Variation $v ) {
	$parts = array();
	foreach ( $v->get_variation_attributes() as $tax => $val ) {
		$tax = str_replace( 'attribute_', '', $tax );
		$t   = taxonomy_exists( $tax ) ? get_term_by( 'slug', $val, $tax ) : null;
		$n   = $t ? $t->name : $val;
		if ( function_exists( 'fx_size_norm' ) ) {
			$n = fx_size_norm( $n );
		}
		$parts[] = function_exists( 'fx_size_label' ) ? fx_size_label( $n ) : $n;
	}
	return implode( ' / ', array_filter( $parts, 'strlen' ) );
}

/** Rozmiary do kupienia: [ [ r, id, wysylka, przedsprzedaz ] ]. */
function fx_ai_sizes( WC_Product $p ) {
	$out = array();
	if ( $p->is_type( 'variable' ) ) {
		foreach ( $p->get_children() as $vid ) {
			$v = wc_get_product( $vid );
			if ( ! $v || ! $v->is_purchasable() || ! $v->is_in_stock() || ! $v->variation_is_visible() ) {
				continue;
			}
			$out[] = array( 'r' => fx_ai_size_label( $v ), 'id' => $vid, 'wysylka' => fx_ai_ship( $vid ) );
		}
	} elseif ( $p->is_purchasable() && $p->is_in_stock() ) {
		$out[] = array( 'r' => 'jeden rozmiar', 'id' => $p->get_id(), 'wysylka' => fx_ai_ship( $p->get_id() ) );
	}
	return $out;
}

function fx_ai_size_key( $s ) {
	$s = strtoupper( preg_replace( '/\s+/', '', (string) $s ) );
	$s = str_replace( array( 'UNIWERSALNY', 'ONESIZE' ), 'OS', $s );
	$map = array( 'XXL' => '2XL', 'XXXL' => '3XL', 'SM' => 'S', 'MD' => 'M', 'LG' => 'L' );
	return $map[ $s ] ?? $s;
}

function fx_ai_world( $id ) {
	$w = function_exists( 'fx_hg_world' ) ? fx_hg_world( $id ) : '';
	return 'moto' === $w ? 'Moto' : ( 'rower' === $w ? 'Rower' : '' );
}

function fx_ai_cat_name( $id ) {
	$t = function_exists( 'fx_main_category' ) ? fx_main_category( $id ) : null;
	if ( ! $t ) {
		$terms = get_the_terms( $id, 'product_cat' );
		$t     = $terms && ! is_wp_error( $terms ) ? $terms[0] : null;
	}
	return $t ? $t->name : '';
}

function fx_ai_price_row( WC_Product $p ) {
	if ( $p->is_type( 'variable' ) ) {
		$min = (float) $p->get_variation_price( 'min', true );
		$max = (float) $p->get_variation_price( 'max', true );
		$reg = (float) $p->get_variation_regular_price( 'max', true );
		$row = array( 'cena' => $min === $max ? wc_format_decimal( $min, 2 ) : 'od ' . wc_format_decimal( $min, 2 ) );
		if ( $reg > $max + 0.009 ) {
			$row['cena_regularna'] = wc_format_decimal( $reg, 2 );
		}
		return $row;
	}
	$row = array( 'cena' => wc_format_decimal( (float) wc_get_price_to_display( $p ), 2 ) );
	if ( $p->is_on_sale() ) {
		$row['cena_regularna'] = wc_format_decimal( (float) wc_get_price_to_display( $p, array( 'price' => $p->get_regular_price() ) ), 2 );
	}
	return $row;
}

/* =====================================================================
 * szukaj_produktow
 * ===================================================================== */

/** Słowa rodzajów produktów – zapytanie o kilka rodzajów naraz dzielimy na osobne wyszukiwania. */
function fx_ai_types() {
	// Kolejność ma znaczenie: najpierw rodzaje szczegółowe (zbroja przed bluzą – „koszulka z ochraniaczami”),
	// na końcu ogólne „ochraniacze”. Zbroja / buzer to NIE nakolanniki.
	return apply_filters( 'fx_ai_types', array(
		'zbroja'       => '/\b(zbro[ij]\w*|buz+er\w*|bazer\w*|koszul\w* z ochrani\w*|ochrani\w* (klatk|plec|pleców|kr[eę]gos)\w*|os[lł]on\w* (klatk|plec)\w*|[zż][oó][lł]w\w*|roost\w*|chest ?protector\w*|raceframe|airframe|titan race|proframe)/u',
		'nakolanniki'  => '/\b(nakolan\w*|ochrani\w* kolan\w*|orte\w*|knee\w*|ochrani\w* (piszczel|go[lł]e)\w*)/u',
		'nałokietniki' => '/\b(na[lł]okietn\w*|ochrani\w* [lł]ok\w*|elbow\w*)/u',
		'kołnierz'     => '/\b(ko[lł]nierz\w*|neck ?brace\w*|stabilizator\w* szyi)/u',
		'bluza z kapturem' => '/\b(bluz\w* z kaptur\w*|kaptur\w*|hood\w*|bluz\w* casual|bluz\w* (dresow|rozpinan|codzienn)\w*|sweat\w*|pullover\w*|crew ?neck)/u',
		'kask'         => '/\b(kask\w*|helmet\w*)/u',
		'gogle'        => '/\b(gogl\w*|goggle\w*)/u',
		'rękawice'     => '/\b(r[eę]kawic\w*|r[eę]kawiczk\w*|glove\w*)/u',
		'bluza'        => '/\b(bluz\w*|jersey\w*|koszul\w*)/u',
		'spodnie'      => '/\b(spodn\w*|pants?)\b/u',
		'buty'         => '/\b(but(y|ów|ach)?|boots?)\b/u',
		'kurtka'       => '/\b(kurtk\w*|jacket\w*)/u',
		'ochraniacze'  => '/\b(ochrani\w*|protector\w*|kolan\w*|zbro[ij]\w*|buz+er\w*|orte\w*|nakolan\w*|na[lł]okietn\w*|ko[lł]nierz\w*)/u',
	) );
}

/** Świat z zapytania: casual (na co dzień) / rower / moto albo ''. */
function fx_ai_world_from( $q ) {
	$q = mb_strtolower( (string) $q, 'UTF-8' );
	if ( preg_match( '/\b(casual\w*|lifestyle|na co dzie[nń]|codzienn\w*|streetwear|do szko[lł]y|na miasto)/u', $q ) ) {
		return 'casual';
	}
	if ( preg_match( '/\b(rower\w*|mtb|enduro rower\w*|downhill|dh|trail\w*|gravel)\b/u', $q ) ) {
		return 'rower';
	}
	if ( preg_match( '/\b(mx|motocross\w*|cross\w*|motocykl\w*|moto|quad\w*|enduro)\b/u', $q ) ) {
		return 'moto';
	}
	return '';
}

/** Świat produktu: moto / rower / casual (casual = poza Moto i Rowerem albo kategoria „casual”). */
function fx_ai_world_key( $id ) {
	$w = function_exists( 'fx_hg_world' ) ? (string) fx_hg_world( $id ) : '';
	if ( 'moto' === $w || 'rower' === $w ) {
		return $w;
	}
	$cats = mb_strtolower( implode( ' ', (array) wc_get_product_terms( $id, 'product_cat', array( 'fields' => 'slugs' ) ) ), 'UTF-8' );
	if ( preg_match( '/casual|lifestyle|odziez-codz/', $cats ) ) {
		return 'casual';
	}
	return function_exists( 'fx_hg_world' ) ? 'casual' : '';
}

/** Rodzaj produktu → slugi kategorii sklepu (filtr fx_ai_type_cat_slugs). */
function fx_ai_type_cat_slugs() {
	return (array) apply_filters( 'fx_ai_type_cat_slugs', array(
		'bluza z kapturem' => array( 'bluzy-odziez-codz' ),
		'zbroja'           => array( 'zbroje-i-buzery-ochraniacze' ),
	) );
}

/** ID produktów z kategorii, których nazwa pasuje do rodzaju (np. „Zbroje i buzery” dla „zbroja”) albo do podanej nazwy kategorii. */
function fx_ai_cat_products( $type_re = '', $cat_name = '', $limit = 200 ) {
	static $memo = array();
	$key = md5( $type_re . '|' . $cat_name );
	if ( isset( $memo[ $key ] ) ) {
		return $memo[ $key ];
	}
	$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true ) );
	$ids   = array();
	$want  = mb_strtolower( trim( (string) $cat_name ), 'UTF-8' );
	// Kategorie przypięte do rodzaju na sztywno (slug), np. bluzy z kapturem = „Bluzy” w odzieży codziennej.
	$type  = $type_re ? (string) array_search( $type_re, fx_ai_types(), true ) : '';
	$pin   = (array) ( fx_ai_type_cat_slugs()[ $type ] ?? array() );
	foreach ( (array) $terms as $t ) {
		$n = mb_strtolower( $t->name, 'UTF-8' );
		if ( in_array( $t->slug, $pin, true ) || ( $type_re && ! $pin && preg_match( $type_re, $n ) ) || ( '' !== $want && ( false !== mb_strpos( $n, $want ) || $t->slug === $want ) ) ) {
			$ids[] = (int) $t->term_id;
		}
	}
	if ( ! $ids ) {
		return $memo[ $key ] = array();
	}
	return $memo[ $key ] = get_posts( array(
		'post_type'      => 'product',
		'post_status'    => 'publish',
		'fields'         => 'ids',
		'posts_per_page' => $limit,
		'orderby'        => 'date',
		'order'          => 'DESC',
		'tax_query'      => array( array( 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $ids, 'include_children' => true ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
	) );
}

function fx_ai_is_kid( array $a, $q ) {
	return in_array( $a['dla_kogo'] ?? '', array( 'junior', 'dzieciece' ), true ) || preg_match( '/junior|youth|dzieci|dziecka|dziecko|syna|córk|corki|kids?\b|młodzie|mlodzie/iu', $q );
}

/** Rozmiary uznawane za „ten sam” – u dzieci M = YM, S = YS itd. */
function fx_ai_size_set( $size, $kid ) {
	if ( '' === $size ) {
		return array();
	}
	$set = array( $size );
	if ( $kid && preg_match( '/^(XS|S|M|L|XL)$/', $size ) ) {
		$set[] = 'Y' . $size;
	}
	if ( $kid && preg_match( '/^Y(XS|S|M|L|XL)$/', $size, $m ) ) {
		$set[] = $m[1];
	}
	return $set;
}

/** Wymiary klienta z parametrów narzędzia: [ rodzaj => wartość ]. */
function fx_ai_measures( array $a ) {
	$map = array( 'obwod_glowy_cm' => 'obwod_glowy', 'wzrost_cm' => 'wzrost', 'waga_kg' => 'waga', 'pas_cm' => 'pas', 'klatka_cm' => 'klatka', 'biodra_cm' => 'biodra', 'wiek' => 'wiek', 'stopa_cm' => 'stopa', 'dlon_cm' => 'dlon', 'rozmiar_eu' => 'eu', 'rozmiar_uk' => 'uk' );
	$out = array();
	foreach ( $map as $k => $kind ) {
		if ( isset( $a[ $k ] ) && (float) str_replace( ',', '.', (string) $a[ $k ] ) > 0 ) {
			$out[ $kind ] = (float) str_replace( ',', '.', (string) $a[ $k ] );
		}
	}
	return $out;
}

/** Jedno wyszukiwanie (jeden rodzaj produktu). @return array [ wyniki[], pasujace_id[], pominiete ] */
/** Wyrażenie dla koloru klienta (PL + EN, jak w nazwach FOX). Pusty string = bez filtra. */
function fx_ai_color_re( $kolor, $known_only = false ) {
	$map = array(
		'czarn|black'                    => 'black|blk|czarn\\w*',
		'bia[lł]|white'                  => 'white|wht|bia[lł]\\w*',
		'czerwon|red|bordo|burgund'      => 'red|flo red|czerwon\\w*|burgundy|maroon|bordo\\w*',
		'niebiesk|blue|granat|navy'      => 'blue|blu|navy|cobalt|indigo|niebiesk\\w*|granat\\w*',
		'szar|grey|gray|grafit|srebr|silver' => 'grey|gray|gry|graphite|steel|silver|charcoal|szar\\w*|grafit\\w*|srebr\\w*',
		'zielon|green|khaki|oliw|olive'  => 'green|grn|olive|khaki|army|moss|zielon\\w*|oliw\\w*',
		'r[oó][zż]|pink|magent'          => 'pink|pnk|magenta|fuchsia|r[oó][zż]ow\\w*',
		'pomara|orange'                  => 'orange|org|flo orange|pomara\\w*',
		'[zż][oó][lł]t|yellow|fluo'      => 'yellow|ylw|flo yellow|fluo\\w*|[zż][oó][lł]t\\w*',
		'fiolet|purple|lila'             => 'purple|prpl|violet|lilac|fiolet\\w*',
		'camo|moro|kamufl'               => 'camo|moro|kamufla\\w*',
		'br[aą]z|brown|be[zż]|beige|tan' => 'brown|brn|tan|beige|sand|chestnut|br[aą]zow\\w*|be[zż]ow\\w*',
		'z[lł]ot|gold'                   => 'gold|z[lł]ot\\w*',
		'turkus|teal|aqua|mint'          => 'teal|aqua|turquoise|mint|turkus\\w*',
	);
	$parts = array();
	foreach ( preg_split( '/\s*[,;\/]\s*|\s+(?:i|lub|albo|or|and)\s+/u', mb_strtolower( (string) $kolor, 'UTF-8' ) ) as $c ) {
		$c = trim( $c );
		if ( '' === $c || preg_match( '/oboj[eę]tn|dowoln|jakikolwiek|wszystko|any/u', $c ) ) {
			continue;
		}
		$hit = false;
		foreach ( $map as $k => $re ) {
			if ( preg_match( '/' . $k . '/u', $c ) ) {
				$parts[] = $re;
				$hit     = true;
			}
		}
		if ( ! $hit && ! $known_only ) {
			$parts[] = preg_quote( $c, '/' );
		}
	}
	return $parts ? '/\b(?:' . implode( '|', array_unique( $parts ) ) . ')\b/iu' : '';
}

/** Tekst do sprawdzenia koloru: nazwa + atrybuty z „kolor/color” w nazwie. */
function fx_ai_color_hay( WC_Product $p ) {
	$hay = $p->get_name();
	foreach ( $p->get_attributes() as $key => $attr ) {
		if ( ! preg_match( '/kolor|color|colour/i', $key . ' ' . ( is_object( $attr ) ? $attr->get_name() : '' ) ) ) {
			continue;
		}
		if ( is_object( $attr ) && $attr->is_taxonomy() ) {
			$hay .= ' ' . implode( ' ', wc_get_product_terms( $p->get_id(), $attr->get_name(), array( 'fields' => 'names' ) ) );
		} elseif ( is_object( $attr ) ) {
			$hay .= ' ' . implode( ' ', $attr->get_options() );
		}
	}
	return mb_strtolower( $hay, 'UTF-8' );
}

/** Popularne przekręcenia nazw modeli FOX → poprawne nazwy (filtr fx_ai_name_fixes). */
function fx_ai_fix_names( $q ) {
	$map = (array) apply_filters( 'fx_ai_name_fixes', array(
		'/\bflex\s?(e)?r\b|\bflex\s+air\b|\bflexeir\b/iu' => 'flexair',
		'/\bdirt\s+paw\b|\bdirtpow\b|\bdirt\s?pał\b/iu'    => 'dirtpaw',
		'/\bpa[vw]\s?tector\b|\bpaw\s+tector\b/iu'          => 'pawtector',
		'/\bair\s+frame\b/iu'                                 => 'airframe',
		'/\brace\s+frame\b/iu'                                => 'raceframe',
	) );
	return trim( preg_replace( array_keys( $map ), array_values( $map ), (string) $q ) );
}

function fx_ai_search_one( array $a, $limit = 10 ) {
	$q    = fx_ai_fix_names( trim( mb_substr( wp_strip_all_tags( (string) ( $a['zapytanie'] ?? '' ) ), 0, 120 ) ) );
	$min  = isset( $a['cena_min'] ) ? (float) $a['cena_min'] : 0;
	$max  = isset( $a['cena_max'] ) ? (float) $a['cena_max'] : 0;
	$size = isset( $a['rozmiar'] ) ? fx_ai_size_key( $a['rozmiar'] ) : '';
	$dla  = isset( $a['dla_kogo'] ) ? sanitize_key( $a['dla_kogo'] ) : '';
	$only = ! isset( $a['tylko_dostepne'] ) || false !== $a['tylko_dostepne'];
	$meas = fx_ai_measures( $a );
	$col  = fx_ai_color_re( $a['kolor'] ?? '' );
	// Bezpiecznik: „rozmiar 58” przy kaskach to obwód głowy, nie nazwa rozmiaru.
	if ( empty( $meas['obwod_glowy'] ) && preg_match( '/^\d{2}([.,]\d)?$/', $size ) && (float) $size >= 46 && (float) $size <= 66 && preg_match( '/kask|helmet|full ?face/iu', $q ) ) {
		$meas['obwod_glowy'] = (float) str_replace( ',', '.', $size );
		$size                = '';
	}
	// „rozmiar 150” to wzrost w cm, nie rozmiar.
	if ( empty( $meas['wzrost'] ) && preg_match( '/^\d{3}$/', $size ) && (int) $size >= 90 && (int) $size <= 210 ) {
		$meas['wzrost'] = (float) $size;
		$size           = '';
	}
	$sset    = fx_ai_size_set( $size, fx_ai_is_kid( $a, $q ) );
	$skipped = 0;
	// Rodzaj produktu z zapytania (kask, gogle…) – wynik musi być tego rodzaju (nazwa albo kategoria).
	$type_re = '';
	foreach ( fx_ai_types() as $re ) {
		if ( preg_match( $re, mb_strtolower( $q, 'UTF-8' ) ) ) {
			$type_re = $re;
			break;
		}
	}
	if ( '' === $q ) {
		return array( array(), array(), 0 );
	}
	$ids = array();
	if ( function_exists( 'fx_srch_query' ) ) {
		$r   = fx_srch_query( $q );
		$ids = array_slice( (array) $r['ids'], 0, 150 );
	}
	if ( ! $ids ) { // zapas: zwykłe wyszukiwanie WordPressa
		$ids = get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 's' => $q, 'posts_per_page' => 60, 'fields' => 'ids' ) );
	}
	// Rodzaj produktu → też produkty z pasującej kategorii (np. „zbroja” → kategoria „Zbroje i buzery”),
	// gdy wyszukiwarka po nazwie nie złapie odmiany słowa albo nazwy modelu.
	if ( $type_re ) {
		$ids = array_values( array_unique( array_merge( array_map( 'intval', $ids ), fx_ai_cat_products( $type_re ) ) ) );
	}
	// Świat (Moto / Rower / Casual): z pola swiat albo z zapytania („bluza casual”, „kask mtb”).
	$world = isset( $a['swiat'] ) && in_array( $a['swiat'], array( 'moto', 'rower', 'casual' ), true ) ? $a['swiat'] : fx_ai_world_from( $q );
	if ( $world ) {
		$keep = array_values( array_filter( $ids, function ( $id ) use ( $world ) {
			$w = fx_ai_world_key( $id );
			return '' === $w || $w === $world;
		} ) );
		if ( $keep ) {
			$ids = $keep; // gdy w danym świecie nic nie ma – zostają wszystkie wyniki
		}
	}
	// Podana kategoria (np. z instrukcji właściciela) – tylko produkty z niej.
	if ( ! empty( $a['kategoria'] ) ) {
		$in  = fx_ai_cat_products( '', (string) $a['kategoria'], 400 );
		if ( $in ) {
			$set = array_flip( $in );
			$hit = array_values( array_filter( $ids, function ( $id ) use ( $set ) {
				return isset( $set[ (int) $id ] );
			} ) );
			$ids = $hit ? array_values( array_unique( array_merge( $hit, $in ) ) ) : $in;
		}
	}
	$dla_ids = null;
	if ( $dla && taxonomy_exists( 'fx_dla' ) ) {
		$slugs = array( $dla );
		if ( in_array( $dla, array( 'meskie', 'damskie' ), true ) ) {
			$slugs[] = 'unisex';
		}
		if ( 'junior' === $dla ) {
			$slugs[] = 'dzieciece';
		} elseif ( 'dzieciece' === $dla ) {
			$slugs[] = 'junior';
		}
		$dla_ids = array_flip( array_map( 'intval', get_objects_in_term( array_map( function ( $s ) {
			$t = get_term_by( 'slug', $s, 'fx_dla' );
			return $t ? (int) $t->term_id : 0;
		}, $slugs ), 'fx_dla' ) ) );
	}
	$out   = array();
	$match = array();
	foreach ( $ids as $id ) {
		if ( count( $out ) >= $limit && count( $match ) >= 40 ) {
			break;
		}
		$p = wc_get_product( $id );
		if ( ! $p || 'publish' !== $p->get_status() || ! $p->is_visible() ) {
			continue;
		}
		if ( null !== $dla_ids && ! isset( $dla_ids[ (int) $id ] ) ) {
			continue;
		}
		if ( $col && ! preg_match( $col, fx_ai_color_hay( $p ) ) ) {
			continue;
		}
		if ( $type_re ) {
			$hay = mb_strtolower( $p->get_name(), 'UTF-8' );
			$pt  = fx_ai_product_type( $p->get_id() );
			$generic = ( fx_ai_types()['ochraniacze'] ?? '' ) === $type_re && in_array( $pt, array( 'zbroja', 'nakolanniki', 'nałokietniki', 'kołnierz' ), true );
			if ( '' !== $pt && ! $generic && ( fx_ai_types()[ $pt ] ?? '' ) !== $type_re ) {
				continue; // nazwa wskazuje inny rodzaj (np. kask w kategorii spodni)
			}
			if ( ! preg_match( $type_re, $hay ) ) {
				$cats = wc_get_product_terms( $p->get_id(), 'product_cat', array( 'fields' => 'names' ) );
				if ( ! preg_match( $type_re, mb_strtolower( implode( ' ', (array) $cats ), 'UTF-8' ) ) ) {
					continue;
				}
			}
		}
		$price = (float) ( $p->is_type( 'variable' ) ? $p->get_variation_price( 'min', true ) : wc_get_price_to_display( $p ) );
		if ( $price <= 0 || ( $min && $price < $min ) || ( $max && $price > $max ) ) {
			continue;
		}
		$sizes = fx_ai_sizes( $p );
		if ( $only && ! $sizes ) {
			continue;
		}
		if ( $sset ) {
			$sizes = array_values( array_filter( $sizes, function ( $s ) use ( $sset ) {
				foreach ( preg_split( '/\s*\/\s*/', $s['r'] ) as $part ) {
					if ( in_array( fx_ai_size_key( $part ), $sset, true ) ) {
						return true;
					}
				}
				return false;
			} ) );
			if ( ! $sizes ) {
				continue;
			}
		}
		$fit_note = '';
		if ( $meas ) {
			$fit = fx_ai_fit_multi( $p, $meas );
			if ( $fit['rozmiar'] ) {
				$ok = array_values( array_filter( $sizes, function ( $s ) use ( $fit ) {
					return fx_ai_size_key( preg_split( '/\s*\/\s*/', $s['r'] )[0] ) === fx_ai_size_key( $fit['rozmiar'] );
				} ) );
				if ( ! $ok ) {
					$skipped++;
					continue; // pasujący rozmiar tego modelu niedostępny
				}
				$fit_note = $fit['rozmiar'] . ' (' . $fit['opis'] . '): ' . $ok[0]['wysylka'];
			} else {
				$fit_note = $fit['tabela'] ? 'wymiary poza tabelą modelu – tabela: ' . $fit['tabela'] : 'brak tabeli w formie tekstu – rozmiar sprawdź narzędziem dopasuj_rozmiar albo w „Tabela rozmiarów”';
			}
		}
		$match[] = (int) $p->get_id();
		if ( count( $out ) >= $limit ) {
			continue;
		}
		$row = array(
			'id'        => $p->get_id(),
			'nazwa'     => $p->get_name(),
			'kategoria' => fx_ai_cat_name( $p->get_id() ),
			'swiat'     => fx_ai_world( $p->get_id() ),
		) + fx_ai_price_row( $p );
		if ( '' !== $fit_note ) {
			$row['pasujacy_rozmiar'] = $fit_note;
		}
		$row['rozmiary'] = array_map( function ( $s ) {
			return $s['r'] . ': ' . $s['wysylka'];
		}, array_slice( $sizes, 0, 12 ) );
		$out[] = $row;
	}
	return array( $out, $match, $skipped );
}

/**
 * Wyszukiwanie z poszerzaniem: gdy nic nie ma – bez koloru, bez rozmiaru/wymiarów, sam rodzaj produktu.
 * Nigdy nie kończy się „nie mamy” bez propozycji: na końcu – telefon i okienko „Zostaw numer”.
 */
function fx_ai_search_wide( array $a, $limit ) {
	$q      = trim( (string) ( $a['zapytanie'] ?? '' ) );
	$colors = '/\b(full ?black|black|czarn\w*|white|bia[lł]\w*|red|czerwon\w*|blue|niebiesk\w*|grey|gray|szar\w*|green|zielon\w*|pink|r[oó][zż]ow\w*|orange|pomara\w*|yellow|[zż][oó][lł]t\w*|camo|navy|fluo\w*|purple|fiolet\w*)\b/iu';
	$type   = '';
	foreach ( fx_ai_types() as $t => $re ) {
		if ( preg_match( $re, mb_strtolower( $q, 'UTF-8' ), $m ) ) {
			$type = $m[1];
			break;
		}
	}
	$kid   = fx_ai_is_kid( $a, $q );
	$steps = array( array( $a, '' ) );
	$noc   = trim( preg_replace( '/\s+/', ' ', preg_replace( $colors, ' ', $q ) ) );
	if ( $noc !== $q && '' !== $noc ) {
		$steps[] = array( array_merge( $a, array( 'zapytanie' => $noc ) ), 'w tym kolorze nic nie ma – pokazuję inne kolory' );
	}
	if ( ! empty( $a['kolor'] ) ) {
		$nc = $a;
		unset( $nc['kolor'] );
		$steps[] = array( $nc, 'w kolorze „' . sanitize_text_field( $a['kolor'] ) . '” nic nie pasuje – pokazuję inne kolory' );
	}
	$base = '' !== $noc ? $noc : $q;
	$b2   = $a;
	unset( $b2['kolor'], $b2['rozmiar'], $b2['obwod_glowy_cm'], $b2['wzrost_cm'], $b2['waga_kg'], $b2['pas_cm'], $b2['klatka_cm'], $b2['biodra_cm'], $b2['dlon_cm'], $b2['rozmiar_eu'], $b2['rozmiar_uk'], $b2['wiek'], $b2['stopa_cm'] );
	if ( $b2 !== $a ) {
		$steps[] = array( array_merge( $b2, array( 'zapytanie' => $base ) ), 'w tym rozmiarze nic nie ma na stanie – pokazuję te modele w dostępnych rozmiarach' );
	}
	if ( $type ) {
		$steps[] = array( array_merge( $b2, array( 'zapytanie' => $type . ( $kid ? ' junior' : '' ) ) ), 'pokazuję najbliższe dostępne: ' . $type . ( $kid ? ' junior' : '' ) );
		if ( $kid ) {
			$steps[] = array( array_merge( $b2, array( 'zapytanie' => $type ) ), 'pokazuję najbliższe dostępne: ' . $type );
		}
	}
	// Dziecko bez wskazanej grupy: najpierw produkty juniorskie/dziecięce, dopiero potem wszystkie.
	$jn = 0;
	if ( $kid && empty( $a['dla_kogo'] ) && taxonomy_exists( 'fx_dla' ) ) {
		$junior = array();
		foreach ( $steps as $st ) {
			$junior[] = array( array_merge( $st[0], array( 'dla_kogo' => 'junior' ) ), $st[1] );
		}
		$jn    = count( $junior );
		$steps = array_merge( $junior, $steps );
	}
	foreach ( $steps as $i => $st ) {
		list( $out, $match, $skipped ) = fx_ai_search_one( $st[0], $limit );
		if ( $out ) {
			$note = $st[1];
			if ( $jn && $i >= $jn ) {
				$note = trim( $note . ( $note ? '; ' : '' ) . 'w wersji juniorskiej nic nie ma – to produkty w rozmiarach dorosłych (powiedz to klientowi; przy goglach i części akcesoriów rozmiar bywa uniwersalny)' );
			}
			return array( $out, $match, $note, $skipped );
		}
	}
	return array( array(), array(), '', 0 );
}

function fx_ai_tool_search( array $a ) {
	$q = trim( mb_substr( wp_strip_all_tags( (string) ( $a['zapytanie'] ?? '' ) ), 0, 120 ) );
	if ( '' === $q ) {
		return array( 'blad' => 'Podaj zapytanie.' );
	}
	$a['zapytanie'] = $q;
	$low            = mb_strtolower( $q, 'UTF-8' );
	$found          = array();
	$scan           = $low;
	foreach ( fx_ai_types() as $t => $re ) {
		if ( preg_match( $re, $scan ) ) {
			$found[] = $t;
			$scan    = preg_replace( $re, ' ', $scan ); // „koszulka z ochraniaczami” = zbroja, nie zbroja + bluza + ochraniacze
		}
	}
	$no_result = 'NIE pisz klientowi „nie mamy”. Napisz, że sprawdzimy dostępność albo zamówimy u dystrybutora, podaj telefon sklepu (' . fx_ai_settings()['phone'] . ') – pod odpowiedzią pojawi się okienko „Zostaw numer – oddzwonimy”.';
	// Kilka rodzajów naraz („kask, rękawice i gogle”) – osobne wyszukiwanie dla każdego.
	if ( count( $found ) >= 2 ) {
		$rest = $low;
		foreach ( fx_ai_types() as $re ) {
			$rest = preg_replace( $re, ' ', $rest );
		}
		$rest   = trim( preg_replace( '/\s+/', ' ', preg_replace( '/\b(i|oraz|a także|plus|do|lub|albo)\b|[,+&]/u', ' ', $rest ) ) );
		$groups = array();
		$all    = array();
		foreach ( $found as $t ) {
			list( $out, $match, $note ) = fx_ai_search_wide( array_merge( $a, array( 'zapytanie' => trim( $t . ' ' . $rest ) ) ), 4 );
			$g = array( 'produkt' => $t, 'wyniki' => $out );
			if ( $note ) {
				$g['uwaga'] = $note;
			}
			if ( ! $out ) {
				$g['uwaga']                        = 'brak propozycji – ' . $no_result;
				$GLOBALS['fx_ai_run']['notfound'][] = $t . ' ' . $rest;
				$GLOBALS['fx_ai_run']['callback']   = 1;
			}
			$groups[] = $g;
			$all      = array_merge( $all, $match );
		}
		$GLOBALS['fx_ai_run']['last'] = array( 'q' => $q, 'ids' => array_slice( array_unique( $all ), 0, 40 ), 'dla' => (string) ( $a['dla_kogo'] ?? '' ), 'typed' => true );
		fx_ai_remember_types( $all, (string) ( $a['dla_kogo'] ?? '' ) );
		return array(
			'grupy' => $groups,
			'info'  => 'Każdy produkt wyszukany osobno. Omów je po kolei (najpierw jeden, np. kask – rozmiar i propozycje, potem następny), rozmiar dobieraj osobno dla każdego modelu (dopasuj_rozmiar). Pokaż karty z każdej grupy.',
		);
	}
	list( $out, $match, $note, $skipped ) = fx_ai_search_wide( $a, 10 );
	if ( ! $out ) {
		$GLOBALS['fx_ai_run']['notfound'][] = $q . ( ! empty( $a['rozmiar'] ) ? ' [' . $a['rozmiar'] . ']' : '' );
		$GLOBALS['fx_ai_run']['callback']   = 1;
		return array( 'wyniki' => array(), 'uwaga' => 'Brak propozycji nawet po poszerzeniu wyszukiwania. ' . $no_result );
	}
	$GLOBALS['fx_ai_run']['last'] = array( 'q' => $q, 'ids' => $match, 'dla' => (string) ( $a['dla_kogo'] ?? '' ), 'typed' => (bool) $found || ! empty( $a['kategoria'] ) );
	fx_ai_remember_types( $match, (string) ( $a['dla_kogo'] ?? '' ) );
	$res = array( 'wyniki' => $out );
	if ( $note ) {
		$res['uwaga'] = ucfirst( $note ) . '. Powiedz to klientowi wprost i pokaż te propozycje (nie pisz „nie mamy”).';
	}
	if ( count( $match ) > count( $out ) ) {
		$res['wiecej'] = 'Pasuje więcej produktów (' . count( $match ) . '+) – pod kartami klient ma przycisk „Więcej” do kategorii.';
	}
	if ( fx_ai_measures( $a ) ) {
		$res['info'] = 'Rozmiar dobrany osobno dla każdego modelu z jego tabeli rozmiarów (pole pasujacy_rozmiar).' . ( $skipped ? ' Pominięto ' . $skipped . ' modeli, w których pasujący rozmiar jest niedostępny.' : '' );
	}
	return $res;
}

/* =====================================================================
 * Tabele rozmiarów: odczyt i dopasowanie wymiaru do rozmiaru modelu
 * ===================================================================== */

/** Tabele z tabeli rozmiarów modelu: [ [ [komórki wiersza 1], [wiersza 2] … ], … ]. */
function fx_ai_size_tables( WC_Product $p ) {
	$gid = function_exists( 'fx_size_guide_for' ) ? fx_size_guide_for( $p ) : 0;
	if ( ! $gid || ! class_exists( 'DOMDocument' ) ) {
		return array();
	}
	$html = (string) get_post_field( 'post_content', $gid );
	if ( false === stripos( $html, '<table' ) ) {
		$html = apply_filters( 'the_content', $html ); // np. bloki / shortcode
	}
	if ( false === stripos( $html, '<table' ) ) {
		return array();
	}
	$doc = new DOMDocument();
	libxml_use_internal_errors( true );
	$doc->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
	libxml_clear_errors();
	$out = array();
	foreach ( $doc->getElementsByTagName( 'table' ) as $t ) {
		$rows = array();
		foreach ( $t->getElementsByTagName( 'tr' ) as $tr ) {
			$cells = array();
			foreach ( $tr->childNodes as $td ) {
				if ( in_array( strtolower( (string) $td->nodeName ), array( 'td', 'th' ), true ) ) {
					$cells[] = trim( preg_replace( '/\s+/u', ' ', $td->textContent ) );
				}
			}
			if ( $cells ) {
				$rows[] = $cells;
			}
		}
		if ( $rows ) {
			$out[] = $rows;
		}
	}
	return $out;
}

/** Nazwa rozmiaru z komórki („M”, „L/XL”, „YM”, „32”) albo ''. */
function fx_ai_cell_size( $c ) {
	$c = trim( (string) $c );
	if ( preg_match( '/^((?:Y|K)?(?:XXS|XS|S|M|L|XL|XXL|[2-5]XL)(?:\s*\/\s*(?:XXS|XS|S|M|L|XL|XXL|[2-5]XL))?|YOS|OS|\d{1,2}(?:[.,]5)?)(?=$|[\s(])/i', $c, $m ) ) {
		return strtoupper( str_replace( ' ', '', $m[1] ) );
	}
	return '';
}

/** Zakres w cm z komórki („57-58”, „57–58 cm”, „58”) albo null. */
function fx_ai_cell_range( $c ) {
	if ( ! preg_match_all( '/\d+(?:[.,]\d+)?/', (string) $c, $m ) || ! $m[0] ) {
		return null;
	}
	$n = array_map( function ( $x ) {
		return (float) str_replace( ',', '.', $x );
	}, $m[0] );
	return array( min( $n ), max( $n ) );
}

/**
 * Rozmiar modelu dla wymiaru klienta: [ rozmiar, zakres, tabela (tekst zakresów) ].
 * Działa na tabelach wierszowych (Rozmiar | Obwód głowy) i kolumnowych (S M L / Obwód …).
 */
function fx_ai_fit( WC_Product $p, $kind, $cm ) {
	$re = array(
		'obwod_glowy' => '/g[lł]ow|head|obw[oó]d(?!.*(pas|klat|bio))/iu',
		'pas'         => '/pas|talia|waist/iu',
		'klatka'      => '/klat|biust|chest/iu',
		'wzrost'      => '/wzrost|height/iu',
		'stopa'       => '/stop|wk[lł]ad|foot/iu',
		'biodra'      => '/biod|hip/iu',
		'waga'        => '/wag[ai]|weight|\bkg\b/iu',
		'wiek'        => '/wiek|\bage\b|\blat\b|years/iu',
		'dlon'        => '/d[lł]ugo[sś][cć] d[lł]oni|d[lł]o[nń]|hand/iu',
		'eu'          => '/^\s*(eu|eur|euro|europ\w*)\b|\b(eu|eur)\s*$|rozmiar eu/iu',
		'uk'          => '/^\s*uk\b|\buk\s*$|rozmiar uk/iu',
	);
	$out = array( 'rozmiar' => '', 'zakres' => '', 'tabela' => '' );
	if ( ! isset( $re[ $kind ] ) || $cm <= 0 ) {
		return $out;
	}
	$pairs = array(); // [ rozmiar, min, max ]
	foreach ( fx_ai_size_tables( $p ) as $rows ) {
		$head = $rows[0];
		// Wierszowa: kolumna z wymiarem w nagłówku.
		$col = -1;
		foreach ( $head as $i => $h ) {
			if ( preg_match( $re[ $kind ], $h ) ) {
				$col = $i;
				break;
			}
		}
		if ( $col >= 0 ) {
			$scol = 0;
			foreach ( $head as $i => $h ) {
				if ( preg_match( '/rozmiar|size/iu', $h ) ) {
					$scol = $i;
					break;
				}
			}
			// Przeliczenie EU / UK → rozmiar modelu (buty FOX w rozmiarach US): kolumna US, gdy jest.
			if ( in_array( $kind, array( 'eu', 'uk' ), true ) ) {
				foreach ( $head as $i => $h ) {
					if ( $i !== $col && preg_match( '/^\s*(us|usa)\b|\bus\s*$|rozmiar us/iu', $h ) ) {
						$scol = $i;
						break;
					}
				}
				if ( $scol === $col ) {
					$scol = 0 === $col ? 1 : 0;
				}
			}
			foreach ( array_slice( $rows, 1 ) as $r ) {
				$sz = fx_ai_cell_size( $r[ $scol ] ?? '' );
				$rg = fx_ai_cell_range( $r[ $col ] ?? '' );
				if ( $sz && $rg ) {
					$pairs[] = array( $sz, $rg[0], $rg[1] );
				}
			}
			continue;
		}
		// Kolumnowa: wiersz z wymiarem w pierwszej komórce, rozmiary w pierwszym wierszu.
		foreach ( $rows as $r ) {
			if ( preg_match( $re[ $kind ], $r[0] ?? '' ) ) {
				foreach ( $head as $i => $h ) {
					$sz = $i ? fx_ai_cell_size( $h ) : '';
					$rg = fx_ai_cell_range( $r[ $i ] ?? '' );
					if ( $sz && $rg ) {
						$pairs[] = array( $sz, $rg[0], $rg[1] );
					}
				}
			}
		}
		// Prosta tabela bez nagłówka: „M | 57-58” (tylko dla kasków – jedyny wymiar).
		if ( ! $pairs && 'obwod_glowy' === $kind ) {
			foreach ( $rows as $r ) {
				$sz = fx_ai_cell_size( $r[0] ?? '' );
				$rg = isset( $r[1] ) ? fx_ai_cell_range( $r[1] ) : null;
				if ( $sz && $rg && $rg[0] >= 40 && $rg[1] <= 70 ) {
					$pairs[] = array( $sz, $rg[0], $rg[1] );
				}
			}
		}
	}
	// Rękawice bez własnej tabeli: tabela wyjściowa sklepu dla dorosłych (długość dłoni) – nie dla damskich i juniorskich.
	$fallback = false;
	if ( ! $pairs && 'dlon' === $kind && 'rękawice' === fx_ai_product_type( $p->get_id() ) && ! preg_match( '/junior|youth|kids?|dzie(ci|ck)|women|lady|damsk/iu', $p->get_name() ) ) {
		$pairs    = fx_ai_glove_table();
		$fallback = true;
	}
	if ( ! $pairs ) {
		return $out;
	}
	$unit = array( 'waga' => ' kg', 'wiek' => ' lat', 'eu' => ' EU', 'uk' => ' UK' );
	$unit = $unit[ $kind ] ?? ' cm';
	$fmt  = function ( $a, $b ) use ( $unit ) {
		$f = function ( $x ) {
			return rtrim( rtrim( number_format( $x, 1, ',', '' ), '0' ), ',' );
		};
		return $a === $b ? $f( $a ) . $unit : $f( $a ) . '–' . $f( $b ) . $unit;
	};
	$out['tabela'] = implode( ', ', array_map( function ( $x ) use ( $fmt ) {
		return $x[0] . ' ' . $fmt( $x[1], $x[2] );
	}, $pairs ) );
	if ( $fallback ) {
		$out['tabela'] = 'tabela wyjściowa sklepu dla dorosłych (model nie ma własnej): ' . $out['tabela'];
	}
	if ( in_array( $kind, array( 'eu', 'uk' ), true ) ) { // numer buta: najbliższy w tabeli (przy remisie większy)
		$best = null;
		foreach ( $pairs as $x ) {
			$d = abs( ( $x[1] + $x[2] ) / 2 - $cm );
			if ( null === $best || $d < $best[0] - 0.001 || ( abs( $d - $best[0] ) < 0.001 && $x[1] > $best[1][1] ) ) {
				$best = array( $d, $x );
			}
		}
		if ( $best && $best[0] <= 1 ) {
			$out['rozmiar'] = $best[1][0];
			$out['zakres']  = $fmt( $best[1][1], $best[1][2] );
		}
		return $out;
	}
	$last = end( $pairs );
	foreach ( $pairs as $x ) {
		// Zakres „57–58” obejmuje też 58,5 (do następnego pełnego cm); długość dłoni – dokładne przedziały (18,7–19,4).
		$hit = 'dlon' === $kind
			? ( $cm >= $x[1] - 0.01 && ( $cm < $x[2] || ( $x === $last && $cm <= $x[2] + 0.01 ) ) )
			: ( $cm >= $x[1] - 0.01 && $cm < $x[2] + 1 );
		if ( $hit ) {
			$out['rozmiar'] = $x[0];
			$out['zakres']  = $fmt( $x[1], $x[2] );
			break;
		}
	}
	return $out;
}

/** Tabela wyjściowa rękawic dla dorosłych: długość dłoni (cm) – od nadgarstka do czubka środkowego palca. */
function fx_ai_glove_table() {
	return (array) apply_filters( 'fx_ai_glove_table', array(
		array( 'S', 18.0, 18.7 ),
		array( 'M', 18.7, 19.4 ),
		array( 'L', 19.4, 20.0 ),
		array( 'XL', 20.0, 20.6 ),
		array( 'XXL', 20.6, 21.3 ),
		array( '3XL', 21.3, 22.0 ),
		array( '4XL', 22.0, 22.5 ),
	) );
}

/**
 * Dobór po kilku wymiarach naraz (wzrost, waga, wiek, pas, klatka, obwód głowy) z tabeli modelu.
 * Gdy wymiary wskazują różne rozmiary – większy (bezpieczniej, zwłaszcza u rosnących dzieci).
 * @return array rozmiar, opis („wzrost 150 cm → YL; waga 40 kg → YM”), tabela
 */
function fx_ai_fit_multi( WC_Product $p, array $meas ) {
	$names = array( 'obwod_glowy' => 'obwód głowy', 'wzrost' => 'wzrost', 'waga' => 'waga', 'pas' => 'pas', 'klatka' => 'klatka', 'wiek' => 'wiek', 'stopa' => 'stopa', 'biodra' => 'biodra', 'dlon' => 'długość dłoni', 'eu' => 'rozmiar', 'uk' => 'rozmiar' );
	$units = array( 'waga' => ' kg', 'wiek' => ' lat', 'eu' => ' EU', 'uk' => ' UK' );
	$hits  = array();
	$desc  = array();
	$tabs  = array();
	foreach ( $meas as $kind => $val ) {
		$f = fx_ai_fit( $p, $kind, $val );
		if ( $f['tabela'] ) {
			$tabs[] = ( $names[ $kind ] ?? $kind ) . ': ' . $f['tabela'];
		}
		if ( $f['rozmiar'] ) {
			$hits[] = $f['rozmiar'];
			$desc[] = ( $names[ $kind ] ?? $kind ) . ' ' . rtrim( rtrim( number_format( $val, 1, ',', '' ), '0' ), ',' ) . ( $units[ $kind ] ?? ' cm' ) . ' → ' . $f['rozmiar'];
		}
	}
	$best = '';
	foreach ( $hits as $h ) {
		$r = function_exists( 'fx_size_rank' ) ? fx_size_rank( $h ) : 0;
		if ( '' === $best || $r > ( function_exists( 'fx_size_rank' ) ? fx_size_rank( $best ) : 0 ) ) {
			$best = $h;
		}
	}
	return array( 'rozmiar' => $best, 'opis' => implode( '; ', $desc ), 'tabela' => implode( ' | ', $tabs ) );
}

function fx_ai_tool_fit( $id, array $a ) {
	$p = wc_get_product( $id );
	if ( $p && $p->is_type( 'variation' ) ) {
		$p = wc_get_product( $p->get_parent_id() );
	}
	if ( ! $p || 'publish' !== $p->get_status() ) {
		return array( 'blad' => 'Nie ma takiego produktu.' );
	}
	$meas = fx_ai_measures( $a );
	if ( ! empty( $a['wymiar'] ) && ! empty( $a['wartosc_cm'] ) ) { // zgodność wstecz
		$meas[ sanitize_key( $a['wymiar'] ) ] = (float) $a['wartosc_cm'];
	}
	if ( ! $meas ) {
		return array( 'blad' => 'Podaj wymiary klienta: wzrost (cm), u dzieci wiek; przy bluzach/kurtkach opcjonalnie obwód klatki, przy spodniach obwód pasa lub bioder; przy kaskach obwód głowy. Nie pytaj o wagę.' );
	}
	$fit   = fx_ai_fit_multi( $p, $meas );
	$sizes = fx_ai_sizes( $p );
	$out   = array( 'produkt' => $p->get_name(), 'rozmiary_na_stanie' => $sizes ? implode( ', ', wp_list_pluck( $sizes, 'r' ) ) : 'brak' );
	if ( $fit['tabela'] ) {
		$out['tabela_modelu'] = $fit['tabela'];
	}
	// Pełny tekst tabeli rozmiarów – do samodzielnej oceny, gdy automat nie odczyta wszystkiego.
	$gid = function_exists( 'fx_size_guide_for' ) ? fx_size_guide_for( $p ) : 0;
	if ( $gid ) {
		$out['tabela_tekst'] = fx_ai_text( get_post_field( 'post_content', $gid ), 1800 );
	}
	if ( $fit['rozmiar'] ) {
		$out['pasujacy_rozmiar'] = $fit['rozmiar'] . ' (' . $fit['opis'] . ')';
		$stock                   = null;
		foreach ( $sizes as $s ) {
			if ( fx_ai_size_key( preg_split( '/\s*\/\s*/', $s['r'] )[0] ) === fx_ai_size_key( $fit['rozmiar'] ) ) {
				$stock = $s['wysylka'];
				break;
			}
		}
		$out['dostepnosc'] = null === $stock ? 'ten rozmiar jest chwilowo niedostępny – zaproponuj inny model w tym rozmiarze (szukaj_produktow z tymi wymiarami); nie pisz „nie mamy”' : 'na stanie: ' . $stock;
	} elseif ( ! empty( $out['tabela_tekst'] ) ) {
		$out['wynik'] = 'Automat nie dopasował rozmiaru – odczytaj go sam z tabela_tekst (wzrost/wiek/klatka/pas/biodra). Jeśli tabela nie podaje tych wymiarów, poproś o inny wymiar z tabeli albo zaproponuj telefon do sklepu.';
	} else {
		$out['wynik'] = 'Ten model nie ma tabeli rozmiarów w formie tekstu. Dorośli: najczęściej M lub L; dzieci: zaproponuj telefon do sklepu albo „Zostaw numer” – pomożemy dobrać. Nie zgaduj na pewno.';
	}
	return $out;
}

/* =====================================================================
 * szczegoly_produktu
 * ===================================================================== */

function fx_ai_text( $html, $len ) {
	$t = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( strip_shortcodes( (string) $html ) ) ) );
	return mb_strlen( $t ) > $len ? mb_substr( $t, 0, $len ) . '…' : $t;
}

function fx_ai_tool_details( $id ) {
	$p = wc_get_product( $id );
	if ( $p && $p->is_type( 'variation' ) ) {
		$p = wc_get_product( $p->get_parent_id() );
	}
	if ( ! $p || 'publish' !== $p->get_status() || ! $p->is_visible() ) {
		return array( 'blad' => 'Nie ma takiego produktu.' );
	}
	$out = array(
		'id'        => $p->get_id(),
		'nazwa'     => $p->get_name(),
		'kategoria' => fx_ai_cat_name( $p->get_id() ),
		'swiat'     => fx_ai_world( $p->get_id() ),
	) + fx_ai_price_row( $p );
	$out['opis'] = fx_ai_text( $p->get_short_description() . ' ' . $p->get_description(), 900 );
	$params      = array();
	foreach ( $p->get_attributes() as $attr ) {
		if ( ! $attr instanceof WC_Product_Attribute || $attr->get_variation() || ! $attr->get_visible() ) {
			continue;
		}
		$vals = $attr->is_taxonomy() ? wc_get_product_terms( $p->get_id(), $attr->get_name(), array( 'fields' => 'names' ) ) : $attr->get_options();
		if ( $vals ) {
			$params[ wc_attribute_label( $attr->get_name() ) ] = implode( ', ', (array) $vals );
		}
	}
	if ( $params ) {
		$out['parametry'] = $params;
	}
	$sizes = fx_ai_sizes( $p );
	$out['rozmiary_na_stanie'] = $sizes ? array_map( function ( $s ) {
		return $s['r'] . ': ' . $s['wysylka'];
	}, $sizes ) : 'brak – produkt chwilowo niedostępny';
	$gid = function_exists( 'fx_size_guide_for' ) ? fx_size_guide_for( $p ) : 0;
	if ( $gid ) {
		$out['tabela_rozmiarow'] = fx_ai_text( get_post_field( 'post_content', $gid ), 1500 );
		$out['tabela_rozmiarow_info'] = 'Na karcie produktu jest przycisk „Tabela rozmiarów”.';
	}
	if ( function_exists( 'fox_hc_v3_product_is_helmet' ) && fox_hc_v3_product_is_helmet( $p ) ) {
		$out['kask'] = 'Rozmiar kasku dobiera się po obwodzie głowy (centymetr nad brwiami i uszami) – do dopasowania użyj dopasuj_rozmiar. Przy zamówieniu kasku sklep prosi o podanie obwodu głowy.';
		$t = fx_ai_fit( $p, 'obwod_glowy', 1 );
		if ( $t['tabela'] ) {
			$out['rozmiary_wg_obwodu_glowy'] = $t['tabela'];
		}
	}
	if ( function_exists( 'fx_kit_resolve' ) ) {
		$kit = fx_kit_resolve( $p->get_id() );
		if ( ! empty( $kit['parts'] ) && count( $kit['parts'] ) > 1 ) {
			$parts = array();
			foreach ( $kit['parts'] as $pid ) {
				if ( (int) $pid !== $p->get_id() ) {
					$parts[] = array( 'id' => (int) $pid, 'nazwa' => get_the_title( $pid ) );
				}
			}
			$out['komplet'] = array( 'czesci_w_tej_samej_grafice' => $parts );
			if ( function_exists( 'fx_kit_discount' ) && fx_kit_discount( $p->get_id() ) > 0 ) {
				$out['komplet']['rabat_za_komplet'] = wc_format_localized_decimal( fx_kit_discount( $p->get_id() ) ) . '% w koszyku za cały komplet';
			}
		}
	}
	$out['link'] = get_permalink( $p->get_id() );
	$out['brak_danych'] = 'Jeśli klient pyta o parametr, którego tu nie ma (materiał, pojemność, waga, technologia…) – użyj sprawdz_u_producenta z tym id, zanim powiesz, że nie wiesz.';
	return $out;
}

/* =====================================================================
 * pokaz_produkty i karty
 * ===================================================================== */

/** Rodzaj produktu (kask, bluza, spodnie…) z nazwy albo kategorii; '' = inny. */
function fx_ai_product_type( $id ) {
	static $cache = array();
	$id = (int) $id;
	if ( isset( $cache[ $id ] ) ) {
		return $cache[ $id ];
	}
	$name = mb_strtolower( get_the_title( $id ), 'UTF-8' );
	foreach ( fx_ai_types() as $t => $re ) {
		if ( preg_match( $re, $name ) ) {
			return $cache[ $id ] = $t;
		}
	}
	$cats = mb_strtolower( implode( ' ', (array) wc_get_product_terms( $id, 'product_cat', array( 'fields' => 'names' ) ) ), 'UTF-8' );
	foreach ( fx_ai_types() as $t => $re ) {
		if ( preg_match( $re, $cats ) ) {
			return $cache[ $id ] = $t;
		}
	}
	return $cache[ $id ] = '';
}

/** Pasujące produkty z wyszukiwań tej odpowiedzi – według rodzaju (do „Więcej” w każdym wierszu kart). */
function fx_ai_remember_types( array $ids, $dla ) {
	// Liczy się ostatnie wyszukiwanie danego rodzaju (np. już z rozmiarem) – wcześniejsze wyniki tego rodzaju odpadają.
	foreach ( array_unique( array_map( 'fx_ai_product_type', $ids ) ) as $t ) {
		unset( $GLOBALS['fx_ai_run']['bytype'][ $t ] );
	}
	foreach ( $ids as $id ) {
		$t = fx_ai_product_type( $id );
		if ( ! isset( $GLOBALS['fx_ai_run']['bytype'][ $t ] ) ) {
			$GLOBALS['fx_ai_run']['bytype'][ $t ] = array( 'ids' => array(), 'dla' => $dla );
		}
		$GLOBALS['fx_ai_run']['bytype'][ $t ]['ids'][] = (int) $id;
	}
}

function fx_ai_tool_show( array $ids ) {
	$ok  = array();
	$per = array();
	foreach ( array_slice( array_map( 'intval', $ids ), 0, 12 ) as $id ) {
		$p = wc_get_product( $id );
		if ( $p && $p->is_type( 'variation' ) ) {
			$p = wc_get_product( $p->get_parent_id() );
		}
		if ( ! $p || 'publish' !== $p->get_status() || ! $p->is_visible() || in_array( $p->get_id(), $ok, true ) ) {
			continue;
		}
		$t = fx_ai_product_type( $p->get_id() );
		if ( ( $per[ $t ] ?? 0 ) >= 5 ) {
			continue; // maks. 5 kart na rodzaj – resztę pokaże „Więcej”
		}
		$per[ $t ] = ( $per[ $t ] ?? 0 ) + 1;
		$ok[]      = $p->get_id();
	}
	$GLOBALS['fx_ai_run']['cards'] = array_slice( array_values( array_unique( array_merge( $GLOBALS['fx_ai_run']['cards'], $ok ) ) ), 0, 10 );
	return $ok ? array( 'pokazano' => count( $ok ), 'info' => 'Karty są pod Twoją odpowiedzią, osobny wiersz dla każdego rodzaju produktu (np. bluzy, spodnie) z przyciskiem „Więcej”. Pasujące modele z wyszukiwania dołożę sam (do 5 na rodzaj). W tekście NIE wymieniaj nazw produktów ani cen – tylko model i rozmiar, np. „Kaski V1 i V3 w rozmiarze YM (54 cm) – wysyłka dziś”.' ) : array( 'blad' => 'Te produkty nie są dostępne.' );
}

/**
 * Dopełnia karty: gdy model pokazał mniej kart danego rodzaju niż pasuje z ostatniego wyszukiwania
 * (ten sam rozmiar, dostępne), dokłada kolejne – do 5 na rodzaj, 10 razem.
 */
function fx_ai_fill_cards( array $ids ) {
	$bytype = $GLOBALS['fx_ai_run']['bytype'] ?? array();
	if ( ! $ids || ! $bytype ) {
		return $ids;
	}
	$per = array();
	foreach ( $ids as $id ) {
		$t         = fx_ai_product_type( $id );
		$per[ $t ] = ( $per[ $t ] ?? 0 ) + 1;
	}
	foreach ( array_keys( $per ) as $t ) {
		foreach ( (array) ( $bytype[ $t ]['ids'] ?? array() ) as $id ) {
			if ( count( $ids ) >= 10 || $per[ $t ] >= 5 ) {
				break;
			}
			if ( in_array( (int) $id, $ids, true ) ) {
				continue;
			}
			$ids[]     = (int) $id;
			$per[ $t ]++;
		}
	}
	return $ids;
}

/** Karty w wierszach według rodzaju: [ [ label, cards[], more ] ]. */
function fx_ai_card_groups( array $cards ) {
	$labels = array( 'bluza z kapturem' => 'Bluzy z kapturem', 'zbroja' => 'Zbroje i buzery', 'nakolanniki' => 'Nakolanniki i ortezy', 'nałokietniki' => 'Nałokietniki', 'kołnierz' => 'Kołnierze', 'kask' => 'Kaski', 'gogle' => 'Gogle', 'rękawice' => 'Rękawice', 'bluza' => 'Bluzy', 'spodnie' => 'Spodnie', 'buty' => 'Buty', 'ochraniacze' => 'Ochraniacze', 'kurtka' => 'Kurtki', '' => 'Propozycje' );
	$groups = array();
	foreach ( $cards as $c ) {
		$t = fx_ai_product_type( $c['id'] );
		if ( ! isset( $groups[ $t ] ) ) {
			$groups[ $t ] = array( 'label' => $labels[ $t ] ?? ucfirst( $t ), 'cards' => array(), 'more' => null );
		}
		if ( count( $groups[ $t ]['cards'] ) < 5 ) {
			$groups[ $t ]['cards'][] = $c;
		}
	}
	$bytype = $GLOBALS['fx_ai_run']['bytype'] ?? array();
	$last   = $GLOBALS['fx_ai_run']['last'] ?? array();
	foreach ( $groups as $t => &$g ) {
		$src       = ! empty( $bytype[ $t ]['ids'] ) ? $bytype[ $t ]['ids'] : wp_list_pluck( $g['cards'], 'id' );
		$dla       = (string) ( $bytype[ $t ]['dla'] ?? ( $last['dla'] ?? '' ) );
		$g['more'] = fx_ai_more_link( $src, 1 === count( $groups ) ? (string) ( $last['q'] ?? '' ) : ( '' !== $t ? $t : '' ), $dla, count( $g['cards'] ) + 3 );
	}
	unset( $g );
	return array_values( $groups );
}

/** Karty dla dymku. */
function fx_ai_cards( array $ids ) {
	$out = array();
	foreach ( array_slice( $ids, 0, 10 ) as $id ) {
		$p = wc_get_product( $id );
		if ( ! $p ) {
			continue;
		}
		$img   = wp_get_attachment_image_url( $p->get_image_id(), 'woocommerce_thumbnail' );
		$today = false;
		if ( $p->is_type( 'variable' ) ) {
			foreach ( $p->get_children() as $vid ) {
				if ( fx_ai_ship_today( $vid ) ) {
					$today = true;
					break;
				}
			}
		} else {
			$today = fx_ai_ship_today( $p->get_id() );
		}
		$out[] = array(
			'id'    => $p->get_id(),
			'name'  => $p->get_name(),
			'url'   => add_query_arg( 'src', 'asystent', get_permalink( $p->get_id() ) ),
			'img'   => $img ? $img : wc_placeholder_img_src( 'woocommerce_thumbnail' ),
			'price' => wp_kses_post( $p->get_price_html() ),
			'today' => $today,
			'add'   => $p->is_type( 'simple' ) && $p->is_purchasable() && $p->is_in_stock(),
		);
	}
	return $out;
}

/**
 * Link „Więcej”: najwęższa kategoria, w której jest co najmniej 80% pasujących produktów
 * (bez kategorii pomocniczych typu Wyprzedaż / Nowości); zapas – wyniki wyszukiwania.
 */
function fx_ai_more_link( array $ids, $q = '', $dla = '', $min_items = 0 ) {
	$ids = array_slice( array_values( array_unique( array_map( 'intval', $ids ) ) ), 0, 40 );
	if ( ! $ids ) {
		return null;
	}
	$weak  = function_exists( 'fx_mc_list' ) ? fx_mc_list( 'bc_weak' ) : array();
	$count = array();
	foreach ( $ids as $id ) {
		$seen = array();
		foreach ( wc_get_product_cat_ids( $id ) as $cid ) {
			foreach ( array_merge( array( (int) $cid ), array_map( 'intval', get_ancestors( $cid, 'product_cat', 'taxonomy' ) ) ) as $t ) {
				$seen[ $t ] = true;
			}
		}
		foreach ( array_keys( $seen ) as $t ) {
			$count[ $t ] = ( $count[ $t ] ?? 0 ) + 1;
		}
	}
	$need  = max( 1, (int) ceil( 0.8 * count( $ids ) ) );
	$cands = array();
	foreach ( $count as $tid => $n ) {
		if ( $n < $need ) {
			continue;
		}
		$t = get_term( $tid, 'product_cat' );
		if ( ! $t || is_wp_error( $t ) || 'uncategorized' === $t->slug || ( $weak && function_exists( 'fx_mc_is_weak' ) && fx_mc_is_weak( $t, $weak ) ) ) {
			continue;
		}
		$cands[] = array( $t, count( get_ancestors( $tid, 'product_cat', 'taxonomy' ) ), $n );
	}
	usort( $cands, function ( $a, $b ) {
		return array( $b[1], $b[2] ) <=> array( $a[1], $a[2] );
	} );
	// Najwęższa, ale z wyraźnie większym wyborem niż karty w czacie (nie kategoria jednego modelu).
	$min  = max( 6, $min_items );
	$best = null;
	foreach ( $cands as $c ) {
		$size = function_exists( 'fx_filter_cat_products' ) ? count( fx_filter_cat_products( $c[0] ) ) : (int) $c[0]->count;
		if ( $size >= $min ) {
			$best = $c[0];
			break;
		}
	}
	$args = array( 'src' => 'asystent' );
	if ( $dla && in_array( $dla, array( 'meskie', 'damskie', 'junior', 'dzieciece' ), true ) ) {
		$args['dla'] = $dla;
	}
	if ( $best ) {
		$url = get_term_link( $best );
		if ( ! is_wp_error( $url ) ) {
			return array( 'url' => add_query_arg( $args, $url ), 'label' => 'Więcej: ' . $best->name );
		}
	}
	if ( '' !== $q ) {
		return array( 'url' => add_query_arg( array_merge( array( 's' => $q, 'post_type' => 'product' ), $args ), home_url( '/' ) ), 'label' => 'Więcej wyników: „' . $q . '”' );
	}
	return null;
}

/* =====================================================================
 * przekaz_do_obslugi
 * ===================================================================== */

function fx_ai_tool_handoff( array $a ) {
	$s     = fx_ai_settings();
	$email = sanitize_email( (string) ( $a['email'] ?? '' ) );
	if ( ! is_email( $email ) ) {
		return array( 'blad' => 'Poproś klienta o poprawny adres e-mail.' );
	}
	if ( ! fx_ai_hit( 'handoff|' . fx_ai_ip(), 3, HOUR_IN_SECONDS ) ) {
		return array( 'blad' => 'Limit zgłoszeń – poproś klienta o telefon do sklepu: ' . $s['phone'] );
	}
	$run   = $GLOBALS['fx_ai_run'];
	$to    = is_email( $s['email'] ) ? $s['email'] : get_option( 'admin_email' );
	$name  = sanitize_text_field( (string) ( $a['imie'] ?? '' ) );
	$tel   = sanitize_text_field( (string) ( $a['telefon'] ?? '' ) );
	$sum   = sanitize_textarea_field( (string) ( $a['streszczenie'] ?? '' ) );
	$wa    = function_exists( 'fx_wa_number' ) ? fx_wa_number() : '';
	if ( $wa ) {
		$GLOBALS['fx_ai_run']['wa'] = 'https://wa.me/' . $wa . '?text=' . rawurlencode( 'Dzień dobry, piszę z czatu na stronie: ' . $sum );
	}
	if ( ! empty( $run['dry'] ) ) {
		return array( 'ok' => true, 'info' => '(tester – e-mail nie został wysłany)' );
	}
	// Cała rozmowa (historia z bieżącego zapytania – także pierwsza wiadomość, której nie ma jeszcze w bazie).
	$chat = fx_ai_chat_get( $run['ctx']['sid'] ?? '' );
	$txt  = '';
	foreach ( (array) ( $run['msgs'] ?? array() ) as $m ) {
		$txt .= ( 'user' === $m['role'] ? 'Klient: ' : 'Asystent: ' ) . $m['content'] . "\n\n";
	}
	$body  = "Zgłoszenie z czatu na fox.sklep.pl\n\nKlient: " . ( $name ? $name : '—' ) . "\nE-mail: {$email}\nTelefon: " . ( $tel ? $tel : '—' ) . "\n\nSprawa: {$sum}\n\n--- Rozmowa ---\n{$txt}";
	if ( $chat ) {
		$body .= "\nPodgląd w panelu: " . admin_url( 'admin.php?page=fx-ai&tab=rozmowy&chat=' . (int) $chat['id'] );
	}
	$sent = wp_mail( $to, 'Czat: ' . wp_trim_words( $sum, 8, '…' ), $body, array( 'Reply-To: ' . ( $name ? $name . ' ' : '' ) . '<' . $email . '>' ) );
	$GLOBALS['fx_ai_run']['handoff'] = 1;
	return $sent
		? array( 'ok' => true, 'info' => 'Zgłoszenie wysłane do obsługi. Odpowiedź przyjdzie na e-mail klienta.' . ( $wa ? ' Możesz też zaproponować WhatsApp – pod odpowiedzią pojawi się przycisk.' : '' ) )
		: array( 'blad' => 'Nie udało się wysłać zgłoszenia – podaj telefon sklepu: ' . $s['phone'] );
}
