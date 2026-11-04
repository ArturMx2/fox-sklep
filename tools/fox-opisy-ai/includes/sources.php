<?php
/**
 * Ustawienia, połączenia (OpenAI, Serper, BaseLinker) i zbieranie FAKTÓW o produkcie.
 *
 * Zasada: AI nie wymyśla parametrów. Najpierw zbieramy źródła, z których model wypisuje fakty
 * (z oceną wiarygodności i numerami źródeł), a dopiero z tej listy powstaje opis (writer.php).
 * Fakty są wspólne dla modelu (np. kask V1 BNKR we wszystkich kolorach) – liczone raz, pamiętane 30 dni.
 *
 * @package fox-opisy-ai
 */

defined( 'ABSPATH' ) || exit;

/* =====================================================================
 * Ustawienia
 * ===================================================================== */

function fxd_defaults() {
	return array(
		'api_key'      => '',
		'serper_key'   => '',
		'model_write'  => 'gpt-4.1',
		'model_facts'  => 'gpt-4.1-mini',
		'auto'         => 1,
		'scope'        => 'instock',     // instock | all
		'content'      => 'weak',        // weak (puste, słabe, z v18 bez źródła) | all | off
		'excerpt'      => 'weak',        // weak (puste, słabe, z v18) | all | off
		'meta'         => 'weak',        // weak (puste, z v18) | all | off
		'weak_chars'   => 500,           // opis główny krótszy niż tyle znaków = słaby
		'vision'       => 1,             // cechy widoczne na zdjęciu jako źródło
		'brand'        => 'FOX',
		'domains'      => "foxracing.com\nfoxracing.eu\nfoxracing.co.uk\nfoxracing.de\nfoxracing.fr\nfoxracing.it\nfoxracing.es\nfoxracing.com.au\nfoxracing.ca\nrevzilla.com\nmotosport.com\nrockymountainatvmc.com\nmxstore.com.au\nchainreactioncycles.com\nbike24.com\nbike-discount.de\nworldwidecyclery.com\ncompetitivecyclist.com\nbikeinn.com\nmotardinn.com",
		'tech'         => "MIPS\nMVRS\nMagnetic Visor Release System\nD3O\nFluid Inside\nVarizorb\nTruDri\nTruDri™\nPoron\nPoron XRD\nKoroyd\nDual-Density\nDual Density\nEPS\nEPP\nCE\nCE 1621\nCE 1621-1\nEN 1078\nECE 22.06\nECE 22.05\nDOT\nSNELL\nASTM\nCPSC\nKevlar\nKevlar®\nCordura\nGore-Tex\nPrimaloft\nDryRide\nTruDri\nSOFTSHELL\nVent\nClarino\nVibram\nIsoGrip\nMoto-3\nKidney belt",
		'style'        => 'Rzeczowo, konkretnie, po polsku, jak doradca w sklepie motocyklowym. Krótkie zdania, bez marketingowych ogólników („najwyższa jakość”, „idealny wybór”), bez wykrzykników.',
	);
}

function fxd_settings() {
	if ( ! isset( $GLOBALS['fxd_settings_cache'] ) ) {
		$o                             = get_option( 'fxd_settings', array() );
		$GLOBALS['fxd_settings_cache'] = array_merge( fxd_defaults(), is_array( $o ) ? $o : array() );
	}
	return $GLOBALS['fxd_settings_cache'];
}
add_action( 'update_option_fxd_settings', function () {
	unset( $GLOBALS['fxd_settings_cache'] );
} );
add_action( 'add_option_fxd_settings', function () {
	unset( $GLOBALS['fxd_settings_cache'] );
} );

function fxd_opt( $k ) {
	return fxd_settings()[ $k ] ?? null;
}

/** Klucz OpenAI: stała w wp-config.php → ustawienia → klucz z wtyczki opisów zdjęć. */
function fxd_key() {
	foreach ( array( 'FOX_OPISY_AI_KEY', 'FOX_IMG_AI_KEY' ) as $c ) {
		if ( defined( $c ) && constant( $c ) ) {
			return (string) constant( $c );
		}
	}
	if ( fxd_opt( 'api_key' ) ) {
		return (string) fxd_opt( 'api_key' );
	}
	$img = get_option( 'fxi_settings', array() );
	return is_array( $img ) ? (string) ( $img['api_key'] ?? '' ) : '';
}

function fxd_serper_key() {
	if ( defined( 'FOX_OPISY_SERPER_KEY' ) && FOX_OPISY_SERPER_KEY ) {
		return (string) FOX_OPISY_SERPER_KEY;
	}
	return (string) fxd_opt( 'serper_key' );
}

function fxd_lines( $text ) {
	return array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) $text ) ) ) );
}

/* =====================================================================
 * Dziennik
 * ===================================================================== */

function fxd_log( $msg ) {
	$log   = (array) get_option( 'fxd_log', array() );
	$log[] = wp_date( 'd.m H:i:s' ) . '  ' . $msg;
	update_option( 'fxd_log', array_slice( $log, -300 ), false );
}

/* =====================================================================
 * OpenAI
 * ===================================================================== */

/**
 * Zapytanie do modelu. Zwraca tablicę z JSON-a albo WP_Error ('retry' = limit / awaria, spróbuj później).
 */
function fxd_ai( $model, $system, $user, $max = 2000, $image = '' ) {
	$key = fxd_key();
	if ( ! $key ) {
		return new WP_Error( 'nokey', 'Brak klucza OpenAI (Ustawienia).' );
	}
	$content = $image ? array( array( 'type' => 'text', 'text' => $user ), array( 'type' => 'image_url', 'image_url' => array( 'url' => $image, 'detail' => 'low' ) ) ) : $user;
	$body    = array(
		'model'           => $model,
		'response_format' => array( 'type' => 'json_object' ),
		'messages'        => array(
			array( 'role' => 'system', 'content' => $system ),
			array( 'role' => 'user', 'content' => $content ),
		),
	);
	// Modele „rozumujące” (gpt-5, o-…) nie przyjmują temperature i liczą limit inaczej.
	if ( preg_match( '/^(gpt-5|o\d)/', $model ) ) {
		$body['max_completion_tokens'] = $max * 4;
	} else {
		$body['temperature'] = 0.3;
		$body['max_tokens']  = $max;
	}
	$r = wp_remote_post(
		'https://api.openai.com/v1/chat/completions',
		array(
			'timeout' => 120,
			'headers' => array( 'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $body ),
		)
	);
	if ( is_wp_error( $r ) ) {
		return new WP_Error( 'retry', 'OpenAI: ' . $r->get_error_message() );
	}
	$code = (int) wp_remote_retrieve_response_code( $r );
	$data = json_decode( wp_remote_retrieve_body( $r ), true );
	if ( 429 === $code || $code >= 500 ) {
		return new WP_Error( 'retry', 'OpenAI: limit/awaria (' . $code . ')' );
	}
	if ( 200 !== $code ) {
		return new WP_Error( 401 === $code ? 'nokey' : 'api', 'OpenAI: ' . wp_strip_all_tags( $data['error']['message'] ?? ( 'HTTP ' . $code ) ) );
	}
	$j = json_decode( trim( (string) ( $data['choices'][0]['message']['content'] ?? '' ) ), true );
	if ( ! is_array( $j ) ) {
		return new WP_Error( 'json', 'OpenAI: niepoprawna odpowiedź (JSON).' );
	}
	$u = $data['usage'] ?? array();
	update_option( 'fxd_tokens', (int) get_option( 'fxd_tokens', 0 ) + (int) ( $u['total_tokens'] ?? 0 ), false );
	return $j;
}

/* =====================================================================
 * Dane produktu
 * ===================================================================== */

/** Numer modelu FOX z SKU: „40618-180-YS” → „40618”. */
function fxd_style( $p ) {
	$skus = array( $p->get_sku() );
	if ( $p->is_type( 'variable' ) ) {
		foreach ( array_slice( $p->get_children(), 0, 3 ) as $vid ) {
			$skus[] = (string) get_post_meta( $vid, '_sku', true );
		}
	}
	foreach ( $skus as $s ) {
		if ( preg_match( '/^(\d{4,6})(?:[-\s]|$)/', trim( (string) $s ), $m ) ) {
			return $m[1];
		}
	}
	return '';
}

/** Klucz grupy (model we wszystkich kolorach): numer modelu albo ID produktu. */
function fxd_group( $p ) {
	$st = fxd_style( $p );
	return $st ? 's' . $st : 'p' . $p->get_id();
}

/** Kontekst produktu dla AI: nazwa, SKU, kategoria, atrybuty, kolor. */
function fxd_context( $p ) {
	$id   = $p->get_id();
	$main = function_exists( 'fx_main_category' ) ? fx_main_category( $id ) : null;
	$path = array();
	if ( $main instanceof WP_Term ) {
		foreach ( array_reverse( get_ancestors( $main->term_id, 'product_cat', 'taxonomy' ) ) as $a ) {
			$t = get_term( $a, 'product_cat' );
			if ( $t && ! is_wp_error( $t ) ) {
				$path[] = $t->name;
			}
		}
		$path[] = $main->name;
	} else {
		$path = (array) wp_get_post_terms( $id, 'product_cat', array( 'fields' => 'names' ) );
	}
	$attrs = array();
	$color = '';
	foreach ( $p->get_attributes() as $tax => $a ) {
		$label = wc_attribute_label( $tax );
		$val   = $p->get_attribute( $tax );
		if ( '' === trim( (string) $val ) ) {
			continue;
		}
		if ( preg_match( '/rozmiar|size|blvariant|wielko/i', $tax . ' ' . $label ) ) {
			$label = 'Dostępne rozmiary';
		}
		if ( preg_match( '/kolor|color|colour/i', $tax . ' ' . $label ) ) {
			$color = $val;
		}
		$attrs[ $label ] = $val;
	}
	return array(
		'id'       => $id,
		'name'     => html_entity_decode( wp_strip_all_tags( $p->get_name() ), ENT_QUOTES, 'UTF-8' ),
		'sku'      => $p->get_sku(),
		'style'    => fxd_style( $p ),
		'category' => implode( ' › ', $path ),
		'attrs'    => $attrs,
		'color'    => $color,
		'brand'    => (string) fxd_opt( 'brand' ),
	);
}

/** Czysty tekst z HTML. */
function fxd_text( $html ) {
	$t = wp_strip_all_tags( preg_replace( '#<(br|/p|/li|/h\d|/div)[^>]*>#i', "\n", (string) $html ) );
	$t = html_entity_decode( $t, ENT_QUOTES, 'UTF-8' );
	return trim( preg_replace( '/[ \t]+/', ' ', preg_replace( "/\n{2,}/", "\n", $t ) ) );
}

/* =====================================================================
 * Źródło 1: BaseLinker – oryginalny opis i parametry (niezależne od tego, co jest w sklepie)
 * ===================================================================== */

function fxd_base_call( $method, $params ) {
	if ( function_exists( 'fx_ls_call' ) ) {
		return fx_ls_call( $method, $params ); // klient z motywu (token z „Terminu wysyłki FOX”)
	}
	return new WP_Error( 'nobase', 'Brak połączenia z BaseLinkerem (motyw FOX).' );
}

/** Dane produktu z Base po SKU (opis, dodatkowe opisy, parametry). */
function fxd_base_product( $sku ) {
	$sku = trim( (string) $sku );
	if ( '' === $sku || ! function_exists( 'fx_ls_api' ) ) {
		return null;
	}
	list( , $inv ) = fx_ls_api();
	if ( ! $inv ) {
		return null;
	}
	$ck  = 'fxd_base_' . md5( $sku );
	$hit = get_transient( $ck );
	if ( false !== $hit ) {
		return $hit ? $hit : null;
	}
	$out = '';
	$r   = fxd_base_call( 'getInventoryProductsList', array( 'inventory_id' => $inv, 'filter_sku' => $sku ) );
	if ( ! is_wp_error( $r ) && ! empty( $r['products'] ) ) {
		$bid = (int) array_key_first( $r['products'] );
		$d   = fxd_base_call( 'getInventoryProductsData', array( 'inventory_id' => $inv, 'products' => array( $bid ) ) );
		if ( ! is_wp_error( $d ) && ! empty( $d['products'][ $bid ] ) ) {
			$bp   = $d['products'][ $bid ];
			$tf   = (array) ( $bp['text_fields'] ?? array() );
			$desc = array();
			foreach ( $tf as $k => $v ) {
				if ( is_string( $v ) && preg_match( '/^description/', $k ) ) {
					$desc[] = fxd_text( $v );
				}
			}
			$feat = array();
			foreach ( (array) ( $tf['features'] ?? array() ) as $fk => $fv ) {
				if ( is_array( $fv ) ) { // [ nazwa, wartość ] albo { name: value }
					$fv = implode( ': ', array_map( 'strval', $fv ) );
					$feat[] = $fv;
				} else {
					$feat[] = $fk . ': ' . $fv;
				}
			}
			$out = array(
				'id'       => $bid,
				'name'     => (string) ( $tf['name'] ?? '' ),
				'desc'     => trim( implode( "\n", array_filter( $desc ) ) ),
				'features' => $feat,
			);
		}
	}
	set_transient( $ck, $out, 7 * DAY_IN_SECONDS );
	return $out ? $out : null;
}

/* =====================================================================
 * Źródło 2: internet – foxracing.com i zaufane sklepy (Serper = wyniki Google)
 * ===================================================================== */

function fxd_domain_ok( $url ) {
	$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	$host = preg_replace( '/^www\./', '', $host );
	foreach ( fxd_lines( fxd_opt( 'domains' ) ) as $d ) {
		$d = strtolower( preg_replace( '/^www\./', '', $d ) );
		if ( $host === $d || substr( $host, -strlen( '.' . $d ) ) === '.' . $d ) {
			return true;
		}
	}
	return false;
}

function fxd_is_official( $url ) {
	return (bool) preg_match( '#(^|\.)foxracing\.[a-z.]+$#', (string) wp_parse_url( $url, PHP_URL_HOST ) );
}

/** Wyszukiwanie Google przez Serper. */
function fxd_serper( $q ) {
	$key = fxd_serper_key();
	if ( ! $key ) {
		return array();
	}
	$ck  = 'fxd_s_' . md5( $q );
	$hit = get_transient( $ck );
	if ( is_array( $hit ) ) {
		return $hit;
	}
	$r = wp_remote_post(
		'https://google.serper.dev/search',
		array(
			'timeout' => 20,
			'headers' => array( 'X-API-KEY' => $key, 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array( 'q' => $q, 'num' => 10 ) ),
		)
	);
	if ( is_wp_error( $r ) || 200 !== (int) wp_remote_retrieve_response_code( $r ) ) {
		return array();
	}
	$d   = json_decode( wp_remote_retrieve_body( $r ), true );
	$out = array();
	foreach ( (array) ( $d['organic'] ?? array() ) as $o ) {
		if ( ! empty( $o['link'] ) ) {
			$out[] = array( 'url' => $o['link'], 'title' => (string) ( $o['title'] ?? '' ), 'snippet' => (string) ( $o['snippet'] ?? '' ) );
		}
	}
	set_transient( $ck, $out, 14 * DAY_IN_SECONDS );
	return $out;
}

/** Treść strony produktu: opis z danych strukturalnych + tekst strony (bez menu i skryptów). */
function fxd_fetch_page( $url ) {
	$ck  = 'fxd_pg_' . md5( $url );
	$hit = get_transient( $ck );
	if ( false !== $hit ) {
		return (string) $hit;
	}
	$r = wp_remote_get(
		$url,
		array(
			'timeout'     => 20,
			'redirection' => 3,
			'user-agent'  => 'Mozilla/5.0 (compatible; FOXSKLEP-opisy/1.0; +' . home_url( '/' ) . ')',
			'headers'     => array( 'Accept-Language' => 'en,pl;q=0.8' ),
		)
	);
	$text = '';
	if ( ! is_wp_error( $r ) && 200 === (int) wp_remote_retrieve_response_code( $r ) ) {
		$html  = (string) wp_remote_retrieve_body( $r );
		$parts = array();
		// Dane strukturalne Product (opis producenta / sklepu).
		if ( preg_match_all( '#<script[^>]+application/ld\+json[^>]*>(.*?)</script>#si', $html, $m ) ) {
			foreach ( $m[1] as $js ) {
				$j = json_decode( trim( $js ), true );
				foreach ( isset( $j['@graph'] ) ? (array) $j['@graph'] : array( $j ) as $node ) {
					if ( is_array( $node ) && ( 'Product' === ( $node['@type'] ?? '' ) || ( is_array( $node['@type'] ?? null ) && in_array( 'Product', $node['@type'], true ) ) ) && ! empty( $node['description'] ) ) {
						$parts[] = 'Opis produktu: ' . fxd_text( $node['description'] );
					}
				}
			}
		}
		if ( preg_match( '#<meta[^>]+(?:property|name)=["\'](?:og:)?description["\'][^>]+content=["\']([^"\']+)#i', $html, $mm ) ) {
			$parts[] = 'Meta: ' . html_entity_decode( $mm[1], ENT_QUOTES, 'UTF-8' );
		}
		// Tekst strony bez skryptów, menu i stopki.
		$body = preg_replace( '#<(script|style|noscript|svg|nav|header|footer|form|iframe)[^>]*>.*?</\1>#si', ' ', $html );
		$body = fxd_text( $body );
		// Fragment wokół słów „Features / Specifications / Details” (tam są cechy), inaczej początek.
		if ( preg_match( '/(features|specifications|tech specs|details|cechy|specyfikacja)/i', $body, $fm, PREG_OFFSET_CAPTURE ) ) {
			$body = substr( $body, max( 0, $fm[0][1] - 1500 ), 7000 );
		} else {
			$body = substr( $body, 0, 6000 );
		}
		$parts[] = $body;
		$text    = trim( implode( "\n", $parts ) );
	}
	set_transient( $ck, $text, 14 * DAY_IN_SECONDS );
	return $text;
}

/**
 * Strony z internetu o tym modelu: wyszukanie po numerze modelu (najpewniejsze) i po nazwie.
 *
 * @return array [ [ 'url', 'title', 'text', 'official' ] ]
 */
function fxd_web_sources( $ctx ) {
	if ( ! fxd_serper_key() ) {
		return array();
	}
	$name = trim( preg_replace( '/\b(kask|motocyklowy|rowerowy|bluza|spodnie|gogle|buty|rękawice|koszulka|kurtka|czapka|ochraniacz|ochraniacze|fox)\b/iu', ' ', $ctx['name'] ) );
	$qs   = array();
	if ( $ctx['style'] ) {
		$qs[] = 'fox racing ' . $ctx['style'];
	}
	$qs[] = 'fox racing ' . preg_replace( '/\s+/', ' ', $name );
	$hits = array();
	foreach ( $qs as $q ) {
		foreach ( fxd_serper( $q ) as $o ) {
			if ( fxd_domain_ok( $o['url'] ) && ! isset( $hits[ $o['url'] ] ) ) {
				$hits[ $o['url'] ] = $o;
			}
		}
	}
	// Najpierw oficjalne strony FOX, potem strony z numerem modelu w adresie / tytule.
	uasort( $hits, function ( $a, $b ) use ( $ctx ) {
		$sa = ( fxd_is_official( $a['url'] ) ? 2 : 0 ) + ( $ctx['style'] && false !== strpos( $a['url'] . $a['title'], $ctx['style'] ) ? 1 : 0 );
		$sb = ( fxd_is_official( $b['url'] ) ? 2 : 0 ) + ( $ctx['style'] && false !== strpos( $b['url'] . $b['title'], $ctx['style'] ) ? 1 : 0 );
		return $sb <=> $sa;
	} );
	$out = array();
	foreach ( array_slice( $hits, 0, 4 ) as $o ) {
		$text = fxd_fetch_page( $o['url'] );
		if ( '' === $text ) {
			$text = $o['snippet']; // strona nie dała się pobrać – zostaje fragment z Google
		}
		if ( '' !== trim( $text ) ) {
			$out[] = array( 'url' => $o['url'], 'title' => $o['title'], 'text' => $text, 'official' => fxd_is_official( $o['url'] ) );
		}
	}
	return $out;
}

/* =====================================================================
 * Fakty: źródła → lista faktów z oceną wiarygodności (cache na model, 30 dni)
 * ===================================================================== */

/** Czy obecny opis w sklepie jest wiarygodnym źródłem (nie pisał go stary generator v18 bez źródeł). */
function fxd_shop_desc_trusted( $p, $base ) {
	$own = fxd_text( $p->get_description() );
	if ( mb_strlen( $own ) < 80 ) {
		return false;
	}
	if ( ! get_post_meta( $p->get_id(), '_fox_v18_full_done', true ) ) {
		return true;
	}
	// v18 pisał opis, gdy był pusty – jeśli obecny opis to opis z Base, jest wiarygodny.
	$b = $base ? fxd_text( $base['desc'] ) : '';
	return '' !== $b && similar_text( mb_substr( $own, 0, 600 ), mb_substr( $b, 0, 600 ) ) > 0.6 * min( 600, mb_strlen( $b ) );
}

/**
 * Fakty o modelu. Zwraca [ 'facts' => [ [ 'f', 'src' => [..], 'c' => high|medium|low ] ], 'sources' => [..], 'at' ] albo WP_Error.
 */
function fxd_facts( $p, $force = false ) {
	$group = fxd_group( $p );
	$key   = 'fxd_facts_' . $group;
	$hit   = get_option( $key );
	if ( ! $force && is_array( $hit ) && $hit['at'] > time() - 30 * DAY_IN_SECONDS ) {
		return $hit;
	}
	$ctx  = fxd_context( $p );
	$srcs = array();
	// 1. BaseLinker (dystrybutor) – po SKU produktu albo pierwszego wariantu.
	$skus = array_filter( array( $p->get_sku() ) );
	if ( $p->is_type( 'variable' ) ) {
		foreach ( array_slice( $p->get_children(), 0, 2 ) as $vid ) {
			$skus[] = (string) get_post_meta( $vid, '_sku', true );
		}
	}
	$base = null;
	foreach ( array_unique( array_filter( $skus ) ) as $sku ) {
		$base = fxd_base_product( $sku );
		if ( $base ) {
			break;
		}
	}
	if ( $base && ( mb_strlen( $base['desc'] ) > 40 || $base['features'] ) ) {
		$srcs[] = array( 'id' => 'B', 'label' => 'BaseLinker (opis dystrybutora)', 'url' => '', 'text' => mb_substr( trim( $base['desc'] . "\n" . implode( "\n", $base['features'] ) ), 0, 6000 ), 'trust' => 'medium' );
	}
	// 2. Obecny opis w sklepie – tylko gdy wiarygodny.
	if ( fxd_shop_desc_trusted( $p, $base ) ) {
		$srcs[] = array( 'id' => 'S', 'label' => 'Obecny opis w sklepie', 'url' => '', 'text' => mb_substr( fxd_text( $p->get_description() ), 0, 5000 ), 'trust' => 'medium' );
	}
	// 3. Internet: foxracing.com i zaufane sklepy.
	foreach ( fxd_web_sources( $ctx ) as $i => $w ) {
		$srcs[] = array( 'id' => 'W' . ( $i + 1 ), 'label' => ( $w['official'] ? 'FOX (oficjalna strona): ' : 'Sklep: ' ) . wp_parse_url( $w['url'], PHP_URL_HOST ), 'url' => $w['url'], 'text' => mb_substr( $w['text'], 0, 7000 ), 'trust' => $w['official'] ? 'high' : 'medium' );
	}
	// 4. Dane produktu (kategoria, atrybuty) – pewne.
	$attr = 'Kategoria: ' . $ctx['category'] . "\n";
	foreach ( $ctx['attrs'] as $k => $v ) {
		$attr .= $k . ': ' . $v . "\n";
	}
	$srcs[] = array( 'id' => 'A', 'label' => 'Dane produktu w sklepie (kategoria, atrybuty)', 'url' => '', 'text' => $attr, 'trust' => 'high' );

	$blocks = '';
	foreach ( $srcs as $s ) {
		$blocks .= "\n=== ŹRÓDŁO [" . $s['id'] . '] ' . $s['label'] . ( $s['url'] ? ' – ' . $s['url'] : '' ) . " ===\n" . $s['text'] . "\n";
	}
	$system = 'Wyciągasz FAKTY o jednym konkretnym produkcie ze źródeł. Zasady: '
		. '1) Tylko to, co źródło wprost podaje o TYM modelu (numer modelu ' . ( $ctx['style'] ? $ctx['style'] : 'nieznany' ) . ', nazwa: ' . $ctx['name'] . '). Źródło o innym modelu, innej wersji albo ogólne o marce – pomiń. '
		. '2) Niczego nie dopowiadaj z własnej wiedzy. 3) Każdy fakt z numerami źródeł. '
		. '4) Pewność: high = oficjalna strona FOX albo co najmniej 2 niezależne źródła, albo dane produktu [A]; medium = jedno wiarygodne źródło; low = niejasne, sprzeczne albo tylko fragment wyszukiwarki. '
		. '5) Fakty krótko, po polsku, z zachowaniem nazw technologii i materiałów (np. MIPS, D3O, Fluid Inside) dokładnie jak w źródle. '
		. 'Zwróć JSON: {"model":"nazwa modelu","type":"rodzaj produktu po polsku","use":"przeznaczenie (np. motocross/enduro, MTB enduro/downhill, na co dzień)","facts":[{"f":"fakt","src":["W1","B"],"c":"high|medium|low"}],"conflicts":["sprzeczności między źródłami"]}';
	$user   = 'Produkt: ' . $ctx['name'] . ( $ctx['sku'] ? ' (SKU ' . $ctx['sku'] . ')' : '' ) . "\nMarka: " . $ctx['brand'] . "\nŹródła:" . $blocks;
	$image  = '';
	if ( fxd_opt( 'vision' ) && $p->get_image_id() ) {
		$img = wp_get_attachment_image_src( $p->get_image_id(), 'large' );
		if ( $img && 0 === strpos( $img[0], 'https://' ) ) {
			$image = $img[0];
			$user .= "\n=== ŹRÓDŁO [I] Zdjęcie produktu ===\nZ samego zdjęcia wolno podać TYLKO cechy jednoznacznie widoczne (np. daszek, zapięcie, kolorystyka, wzór), z pewnością medium.";
		}
	}
	$j = fxd_ai( (string) fxd_opt( 'model_facts' ), $system, $user, 2500, $image );
	if ( is_wp_error( $j ) && $image && 'retry' !== $j->get_error_code() ) {
		$j = fxd_ai( (string) fxd_opt( 'model_facts' ), $system, $user, 2500 ); // np. zdjęcie niedostępne dla OpenAI
	}
	if ( is_wp_error( $j ) ) {
		return $j;
	}
	$facts = array();
	foreach ( (array) ( $j['facts'] ?? array() ) as $f ) {
		if ( ! empty( $f['f'] ) ) {
			$facts[] = array( 'f' => sanitize_text_field( $f['f'] ), 'src' => array_map( 'sanitize_key', (array) ( $f['src'] ?? array() ) ), 'c' => in_array( $f['c'] ?? '', array( 'high', 'medium', 'low' ), true ) ? $f['c'] : 'low' );
		}
	}
	$out = array(
		'at'        => time(),
		'group'     => $group,
		'model'     => sanitize_text_field( $j['model'] ?? '' ),
		'type'      => sanitize_text_field( $j['type'] ?? '' ),
		'use'       => sanitize_text_field( $j['use'] ?? '' ),
		'facts'     => $facts,
		'conflicts' => array_map( 'sanitize_text_field', (array) ( $j['conflicts'] ?? array() ) ),
		'sources'   => array_map( function ( $s ) {
			return array( 'id' => $s['id'], 'label' => $s['label'], 'url' => $s['url'], 'trust' => $s['trust'] );
		}, $srcs ),
		'web'       => count( array_filter( $srcs, function ( $s ) {
			return 0 === strpos( $s['id'], 'W' );
		} ) ),
	);
	update_option( $key, $out, false );
	return $out;
}
