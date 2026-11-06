<?php
/**
 * Asystent AI – narzędzia modelu (function calling) i karty produktów.
 *
 * @package foxsklep
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
			'zapytanie'      => array( 'type' => 'string', 'description' => 'Fraza po polsku, np. „spodnie motocrossowe”, „kask mtb”' ),
			'cena_min'       => array( 'type' => 'number' ),
			'cena_max'       => array( 'type' => 'number' ),
			'rozmiar'        => array( 'type' => 'string', 'description' => 'NAZWA rozmiaru, który musi być na stanie, np. M, XL, 32 (spodnie w calach), YM, 10 (buty US). NIE wpisuj tu centymetrów (np. obwodu głowy 58) – do tego jest obwod_glowy_cm. Pomiń, gdy klient nie podał.' ),
			'obwod_glowy_cm' => array( 'type' => 'number', 'description' => 'Dla kasków: obwód głowy klienta w cm (np. 58). Rozmiar zostanie dobrany osobno dla każdego modelu z jego tabeli rozmiarów.' ),
			'dla_kogo'       => array( 'type' => 'string', 'enum' => array( 'meskie', 'damskie', 'junior', 'dzieciece' ) ),
			'tylko_dostepne' => array( 'type' => 'boolean', 'description' => 'Domyślnie true' ),
		), array( 'zapytanie' ) ),
		$f( 'szczegoly_produktu', 'Szczegóły produktu: opis, parametry, rozmiary na stanie z terminem wysyłki, tabela rozmiarów, części kompletu w tej samej grafice.', array(
			'id' => array( 'type' => 'integer' ),
		), array( 'id' ) ),
		$f( 'dopasuj_rozmiar', 'Dobiera rozmiar KONKRETNEGO modelu z jego tabeli rozmiarów na podstawie wymiaru klienta (cm) i mówi, czy ten rozmiar jest na stanie. Rozmiary różnią się między modelami – używaj zawsze, gdy klient poda wymiar.', array(
			'id'         => array( 'type' => 'integer' ),
			'wymiar'     => array( 'type' => 'string', 'enum' => array( 'obwod_glowy', 'pas', 'klatka', 'wzrost', 'stopa', 'biodra' ) ),
			'wartosc_cm' => array( 'type' => 'number' ),
		), array( 'id', 'wymiar', 'wartosc_cm' ) ),
		$f( 'pokaz_produkty', 'Pokazuje klientowi karty produktów pod odpowiedzią (zdjęcie, cena, link). Używaj ZAWSZE, gdy polecasz produkty. 2–6 id z wyników narzędzi.', array(
			'ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'minItems' => 1, 'maxItems' => 6 ),
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
			return fx_ai_tool_fit( (int) ( $a['id'] ?? 0 ), (string) ( $a['wymiar'] ?? '' ), (float) ( $a['wartosc_cm'] ?? 0 ) );
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

function fx_ai_tool_search( array $a ) {
	$q    = trim( mb_substr( wp_strip_all_tags( (string) ( $a['zapytanie'] ?? '' ) ), 0, 120 ) );
	$min  = isset( $a['cena_min'] ) ? (float) $a['cena_min'] : 0;
	$max  = isset( $a['cena_max'] ) ? (float) $a['cena_max'] : 0;
	$size = isset( $a['rozmiar'] ) ? fx_ai_size_key( $a['rozmiar'] ) : '';
	$dla  = isset( $a['dla_kogo'] ) ? sanitize_key( $a['dla_kogo'] ) : '';
	$only = ! isset( $a['tylko_dostepne'] ) || false !== $a['tylko_dostepne'];
	$head = isset( $a['obwod_glowy_cm'] ) ? (float) str_replace( ',', '.', (string) $a['obwod_glowy_cm'] ) : 0;
	// Bezpiecznik: „rozmiar 58” przy kaskach to obwód głowy, nie nazwa rozmiaru.
	if ( ! $head && preg_match( '/^\d{2}([.,]\d)?$/', $size ) && (float) $size >= 46 && (float) $size <= 66 && preg_match( '/kask|helmet|full ?face/iu', $q ) ) {
		$head = (float) str_replace( ',', '.', $size );
		$size = '';
	}
	$skipped = 0;
	if ( '' === $q ) {
		return array( 'blad' => 'Podaj zapytanie.' );
	}
	$ids = array();
	if ( function_exists( 'fx_srch_query' ) ) {
		$r   = fx_srch_query( $q );
		$ids = array_slice( (array) $r['ids'], 0, 150 );
	}
	if ( ! $ids ) { // zapas: zwykłe wyszukiwanie WordPressa
		$ids = get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 's' => $q, 'posts_per_page' => 60, 'fields' => 'ids' ) );
	}
	$dla_ids = null;
	if ( $dla && taxonomy_exists( 'fx_dla' ) ) {
		$slugs = array( $dla );
		if ( in_array( $dla, array( 'meskie', 'damskie' ), true ) ) {
			$slugs[] = 'unisex';
		}
		$dla_ids = array_flip( array_map( 'intval', get_objects_in_term( array_map( function ( $s ) {
			$t = get_term_by( 'slug', $s, 'fx_dla' );
			return $t ? (int) $t->term_id : 0;
		}, $slugs ), 'fx_dla' ) ) );
	}
	$out = array();
	foreach ( $ids as $id ) {
		if ( count( $out ) >= 10 ) {
			break;
		}
		$p = wc_get_product( $id );
		if ( ! $p || 'publish' !== $p->get_status() || ! $p->is_visible() ) {
			continue;
		}
		if ( null !== $dla_ids && ! isset( $dla_ids[ (int) $id ] ) ) {
			continue;
		}
		$price = (float) ( $p->is_type( 'variable' ) ? $p->get_variation_price( 'min', true ) : wc_get_price_to_display( $p ) );
		if ( $price <= 0 || ( $min && $price < $min ) || ( $max && $price > $max ) ) {
			continue;
		}
		$sizes = fx_ai_sizes( $p );
		if ( $only && ! $sizes ) {
			continue;
		}
		if ( '' !== $size ) {
			$sizes = array_values( array_filter( $sizes, function ( $s ) use ( $size ) {
				foreach ( preg_split( '/\s*\/\s*/', $s['r'] ) as $part ) {
					if ( fx_ai_size_key( $part ) === $size ) {
						return true;
					}
				}
				return false;
			} ) );
			if ( ! $sizes ) {
				continue; // brak tego rozmiaru na stanie (także produkt bez rozmiarów)
			}
		}
		$fit_note = '';
		if ( $head > 0 ) {
			$fit = fx_ai_fit( $p, 'obwod_glowy', $head );
			if ( $fit['rozmiar'] ) {
				$ok = array_values( array_filter( $sizes, function ( $s ) use ( $fit ) {
					return fx_ai_size_key( preg_split( '/\s*\/\s*/', $s['r'] )[0] ) === fx_ai_size_key( $fit['rozmiar'] );
				} ) );
				if ( ! $ok ) {
					$skipped++;
					continue; // pasujący rozmiar tego modelu niedostępny
				}
				$fit_note = $fit['rozmiar'] . ( $fit['zakres'] ? ' (' . $fit['zakres'] . ')' : '' ) . ': ' . $ok[0]['wysylka'];
			} else {
				$fit_note = $fit['tabela'] ? 'obwód poza tabelą modelu – ' . $fit['tabela'] : 'brak tabeli w formie tekstu – rozmiar trzeba sprawdzić w „Tabela rozmiarów” na karcie produktu';
			}
		}
		$row = array(
			'id'       => $p->get_id(),
			'nazwa'    => $p->get_name(),
			'kategoria' => fx_ai_cat_name( $p->get_id() ),
			'swiat'    => fx_ai_world( $p->get_id() ),
		) + fx_ai_price_row( $p );
		if ( '' !== $fit_note ) {
			$row['pasujacy_rozmiar'] = $fit_note;
		}
		$row['rozmiary'] = array_map( function ( $s ) {
			return $s['r'] . ': ' . $s['wysylka'];
		}, array_slice( $sizes, 0, 12 ) );
		$out[] = $row;
	}
	if ( ! $out ) {
		$GLOBALS['fx_ai_run']['notfound'][] = $q . ( $size ? ' [' . $size . ']' : '' ) . ( $head ? ' [' . $head . ' cm]' : '' );
		return array( 'wyniki' => array(), 'uwaga' => 'Nic nie znaleziono' . ( $size ? ' w rozmiarze ' . $size : '' ) . ( $skipped ? ' – ' . $skipped . ' modeli ma pasujący rozmiar chwilowo niedostępny' : '' ) . '. Spróbuj szerszej frazy (np. sam rodzaj produktu) albo bez rozmiaru; zaproponuj kontakt ze sklepem. Nie wymyślaj produktów.' );
	}
	$res = array( 'wyniki' => $out );
	if ( $head ) {
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
	if ( ! $pairs ) {
		return $out;
	}
	$fmt = function ( $a, $b ) {
		$f = function ( $x ) {
			return rtrim( rtrim( number_format( $x, 1, ',', '' ), '0' ), ',' );
		};
		return $a === $b ? $f( $a ) . ' cm' : $f( $a ) . '–' . $f( $b ) . ' cm';
	};
	$out['tabela'] = implode( ', ', array_map( function ( $x ) use ( $fmt ) {
		return $x[0] . ' ' . $fmt( $x[1], $x[2] );
	}, $pairs ) );
	foreach ( $pairs as $x ) {
		// Zakres „57–58” obejmuje też 58,5 (do następnego pełnego cm).
		if ( $cm >= $x[1] - 0.01 && $cm < $x[2] + 1 ) {
			$out['rozmiar'] = $x[0];
			$out['zakres']  = $fmt( $x[1], $x[2] );
			break;
		}
	}
	return $out;
}

function fx_ai_tool_fit( $id, $kind, $cm ) {
	$p = wc_get_product( $id );
	if ( $p && $p->is_type( 'variation' ) ) {
		$p = wc_get_product( $p->get_parent_id() );
	}
	if ( ! $p || 'publish' !== $p->get_status() ) {
		return array( 'blad' => 'Nie ma takiego produktu.' );
	}
	$fit = fx_ai_fit( $p, $kind, $cm );
	$out = array( 'produkt' => $p->get_name(), 'wymiar_klienta' => $cm . ' cm' );
	if ( ! $fit['tabela'] ) {
		$out['wynik'] = 'Nie umiem odczytać tabeli rozmiarów tego modelu (brak tabeli w formie tekstu). Odeślij klienta do przycisku „Tabela rozmiarów” na karcie produktu albo do telefonu sklepu. Nie zgaduj rozmiaru.';
		return $out;
	}
	$out['tabela_modelu'] = $fit['tabela'];
	if ( ! $fit['rozmiar'] ) {
		$out['wynik'] = 'Wymiar klienta jest poza tabelą tego modelu – powiedz to i zaproponuj inny model albo kontakt ze sklepem.';
		return $out;
	}
	$out['pasujacy_rozmiar'] = $fit['rozmiar'] . ' (' . $fit['zakres'] . ')';
	$stock = null;
	foreach ( fx_ai_sizes( $p ) as $s ) {
		if ( fx_ai_size_key( preg_split( '/\s*\/\s*/', $s['r'] )[0] ) === fx_ai_size_key( $fit['rozmiar'] ) ) {
			$stock = $s['wysylka'];
			break;
		}
	}
	$out['dostepnosc'] = null === $stock ? 'ten rozmiar jest chwilowo niedostępny – zaproponuj inny model w tym rozmiarze (szukaj_produktow z wymiarem)' : 'na stanie: ' . $stock;
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
	return $out;
}

/* =====================================================================
 * pokaz_produkty i karty
 * ===================================================================== */

function fx_ai_tool_show( array $ids ) {
	$ok = array();
	foreach ( array_slice( array_map( 'intval', $ids ), 0, 6 ) as $id ) {
		$p = wc_get_product( $id );
		if ( $p && $p->is_type( 'variation' ) ) {
			$p = wc_get_product( $p->get_parent_id() );
		}
		if ( $p && 'publish' === $p->get_status() && $p->is_visible() && ! in_array( $p->get_id(), $ok, true ) ) {
			$ok[] = $p->get_id();
		}
	}
	$GLOBALS['fx_ai_run']['cards'] = array_values( array_unique( array_merge( $GLOBALS['fx_ai_run']['cards'], $ok ) ) );
	return $ok ? array( 'pokazano' => count( $ok ), 'info' => 'Karty są pod Twoją odpowiedzią – nie wypisuj linków ani pełnej listy cen.' ) : array( 'blad' => 'Te produkty nie są dostępne.' );
}

/** Karty dla dymku. */
function fx_ai_cards( array $ids ) {
	$out = array();
	foreach ( array_slice( $ids, 0, 6 ) as $id ) {
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
