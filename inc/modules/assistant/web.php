<?php
/**
 * Asystent AI – informacje producenta z foxracing.co.uk / foxracing.com (po numerze modelu z SKU albo EAN).
 * Strony FOX mają adresy /product/[nazwa]/[model].html i /product/[nazwa]/[model]-[kolor]-[rozmiar].html.
 * Wynik (oczyszczony tekst strony) jest w pamięci 7 dni; brak wyniku – 1 dzień.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

/** Identyfikatory produktu do szukania: [ 'models' => [ '31509-001-OS', '31509' ], 'eans' => [ … ] ]. */
function fx_ai_fox_ids( WC_Product $p ) {
	$skus = array( (string) $p->get_sku() );
	$eans = array();
	$ids  = array( $p->get_id() );
	if ( $p->is_type( 'variable' ) ) {
		foreach ( array_slice( $p->get_children(), 0, 20 ) as $vid ) {
			$ids[] = $vid;
			$v     = wc_get_product( $vid );
			if ( $v ) {
				$skus[] = (string) $v->get_sku();
			}
		}
	}
	foreach ( $ids as $id ) {
		foreach ( array( '_global_unique_id', '_woosea_ean', '_alg_ean', '_ean', 'ean' ) as $k ) {
			$e = preg_replace( '/\D/', '', (string) get_post_meta( $id, $k, true ) );
			if ( strlen( $e ) >= 8 ) {
				$eans[] = $e;
			}
		}
	}
	// EAN zapisany w opisie („EAN: 1985…”), jak w części produktów z importu.
	if ( ! $eans && preg_match( '/EAN[:\s]*([0-9]{8,14})/i', wp_strip_all_tags( $p->get_description() ), $m ) ) {
		$eans[] = $m[1];
	}
	$models = array();
	foreach ( array_filter( $skus ) as $sku ) {
		if ( preg_match( '/(?<!\d)(\d{5})(?:[\s_\-]+([A-Z0-9]{3}))?(?:[\s_\-]+([A-Z0-9]{1,4}))?/i', strtoupper( $sku ), $m ) ) {
			if ( ! empty( $m[2] ) && ! empty( $m[3] ) ) {
				$models[] = $m[1] . '-' . $m[2] . '-' . $m[3];
			}
			$models[] = $m[1];
		}
	}
	return array( 'models' => array_values( array_unique( $models ) ), 'eans' => array_values( array_unique( $eans ) ) );
}

function fx_ai_http_get( $url ) {
	$r = wp_remote_get( $url, array(
		'timeout'     => 12,
		'redirection' => 4,
		'headers'     => array(
			'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
			'Accept'          => 'text/html,application/xhtml+xml',
			'Accept-Language' => 'en-GB,en;q=0.9',
		),
	) );
	if ( is_wp_error( $r ) || 200 !== (int) wp_remote_retrieve_response_code( $r ) ) {
		return '';
	}
	return (string) wp_remote_retrieve_body( $r );
}

/** Tekst strony produktu: tytuł + opis + specyfikacja (bez menu, stopki, skryptów). */
function fx_ai_page_text( $html ) {
	$title = '';
	if ( preg_match( '/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)/i', $html, $m ) ) {
		$title = html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' );
	} elseif ( preg_match( '/<h1[^>]*>(.*?)<\/h1>/is', $html, $m ) ) {
		$title = trim( wp_strip_all_tags( $m[1] ) );
	}
	$body = preg_replace( '#<(script|style|noscript|svg|header|footer|nav|form|iframe)[^>]*>.*?</\1>#is', ' ', $html );
	// Od nagłówka produktu, jeśli jest.
	$pos = stripos( $body, '<h1' );
	if ( false !== $pos ) {
		$body = substr( $body, $pos );
	}
	$body = preg_replace( '#<(li|p|br|h[1-6]|div|tr)[^>]*>#i', "\n", $body );
	$text = html_entity_decode( wp_strip_all_tags( $body ), ENT_QUOTES, 'UTF-8' );
	$text = preg_replace( "/[ \t\x{00A0}]+/u", ' ', $text );
	$text = preg_replace( "/\n\s*\n+/", "\n", $text );
	$text = trim( $text );
	// Najważniejsza część: od „Description” do końca „Specifications/Features”.
	if ( preg_match( '/(Description|Features|Specifications|Details)/i', $text, $m, PREG_OFFSET_CAPTURE ) ) {
		$text = substr( $text, max( 0, $m[0][1] - 200 ) );
	}
	$cut = preg_split( '/\n(Recommended|You may also like|Complete the look|Reviews\s*\[|Newsletter|Sign up)/i', $text );
	$text = trim( $cut[0] );
	return array( $title, mb_substr( $text, 0, 3500 ) );
}

/** Szukanie na stronach FOX. @return array|null [ url, tytul, tresc ] */
function fx_ai_fox_lookup( WC_Product $p ) {
	$ids   = fx_ai_fox_ids( $p );
	$key   = 'fx_ai_fox_' . md5( wp_json_encode( $ids ) );
	$cache = get_transient( $key );
	if ( is_array( $cache ) ) {
		return $cache ? $cache : null;
	}
	$sites = apply_filters( 'fx_ai_fox_sites', array( 'https://foxracing.co.uk', 'https://www.foxracing.com' ) );
	$found = null;
	$start = microtime( true );
	foreach ( $sites as $base ) {
		$try = array();
		foreach ( $ids['models'] as $m ) {
			$try[] = array( $base . '/product/p/' . rawurlencode( $m ) . '.html', $m );
		}
		foreach ( array_slice( $ids['models'], -1 ) as $m ) {
			$try[] = array( $base . '/search?q=' . rawurlencode( $m ), $m );
		}
		foreach ( array_slice( $ids['eans'], 0, 1 ) as $e ) {
			$try[] = array( $base . '/search?q=' . rawurlencode( $e ), '' );
		}
		foreach ( $try as $t ) {
			if ( microtime( true ) - $start > 20 ) {
				break 2;
			}
			$html = fx_ai_http_get( $t[0] );
			if ( '' === $html ) {
				continue;
			}
			// Strona wyników wyszukiwania → pierwszy produkt z tym modelem.
			if ( false !== strpos( $t[0], '/search?' ) && ! preg_match( '/<h1[^>]*class="[^"]*product-name/i', $html ) ) {
				$re = $t[1] ? '#href="([^"]*/product/[^"]+/' . preg_quote( $t[1], '#' ) . '[^"/]*\.html)[^"]*"#i' : '#href="([^"]*/product/[^"]+/\d{5}[^"/]*\.html)[^"]*"#i';
				if ( ! preg_match( $re, $html, $m ) ) {
					continue;
				}
				$url  = 0 === strpos( $m[1], 'http' ) ? $m[1] : $base . $m[1];
				$html = fx_ai_http_get( $url );
				if ( '' === $html ) {
					continue;
				}
				$t[0] = $url;
			}
			list( $title, $text ) = fx_ai_page_text( $html );
			$model = $ids['models'] ? preg_replace( '/-.*/', '', end( $ids['models'] ) ) : '';
			// Bezpiecznik: strona musi dotyczyć tego modelu (numer w adresie albo treści) i mieć treść.
			if ( mb_strlen( $text ) < 80 || ( $model && false === strpos( $t[0] . ' ' . $html, $model ) ) ) {
				continue;
			}
			$found = array( 'url' => preg_replace( '/\?.*$/', '', $t[0] ), 'tytul' => $title, 'tresc' => $text );
			break 2;
		}
	}
	set_transient( $key, $found ? $found : array(), $found ? 7 * DAY_IN_SECONDS : DAY_IN_SECONDS );
	return $found;
}

function fx_ai_tool_fox( $id ) {
	$p = wc_get_product( $id );
	if ( $p && $p->is_type( 'variation' ) ) {
		$p = wc_get_product( $p->get_parent_id() );
	}
	if ( ! $p ) {
		return array( 'blad' => 'Nie ma takiego produktu.' );
	}
	if ( ! fx_ai_hit( 'fox|' . fx_ai_ip(), 15, HOUR_IN_SECONDS ) || ! fx_ai_hit( 'foxall', 300, HOUR_IN_SECONDS ) ) {
		return array( 'blad' => 'Chwilowo nie mogę sprawdzić strony producenta – zaproponuj kontakt ze sklepem.' );
	}
	$ids = fx_ai_fox_ids( $p );
	if ( ! $ids['models'] && ! $ids['eans'] ) {
		return array( 'blad' => 'Produkt nie ma numeru modelu FOX ani EAN – nie da się go znaleźć u producenta. Powiedz, że nie masz tej informacji, i zaproponuj kontakt ze sklepem.' );
	}
	$r = fx_ai_fox_lookup( $p );
	if ( ! $r ) {
		return array( 'wynik' => 'Na stronach FOX (foxracing.co.uk, foxracing.com) nie ma informacji o tym produkcie. Powiedz klientowi, że nie masz tej informacji, i zaproponuj kontakt ze sklepem.' );
	}
	return array(
		'zrodlo'   => $r['url'],
		'tytul'    => $r['tytul'],
		'tresc'    => $r['tresc'],
		'instrukcja' => 'To treść ze strony producenta (po angielsku). Odpowiedz po polsku tylko na podstawie tej treści, podaj, że to informacja producenta FOX, i nie dopisuj niczego od siebie. Jeśli odpowiedzi tu nie ma – powiedz, że nie masz tej informacji.',
	);
}
