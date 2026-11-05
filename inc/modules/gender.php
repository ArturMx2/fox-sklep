<?php
/**
 * Filtr „Dla kogo”: Męskie / Damskie / Junior / Dziecięce / Unisex.
 *
 * Każdy produkt dostaje jedną wartość w ukrytej taksonomii fx_dla (szybkie filtrowanie, bez zapytań po nazwach).
 * Skąd wartość – pierwsze pasujące:
 *  1) ręcznie w produkcie (Dane produktu → Ogólne → „Dla kogo”),
 *  2) atrybut produktu „Płeć / Gender / Dla kogo / Rodzaj” (np. z importera; „Damskie|Męskie” = Unisex),
 *  3) nazwa produktu: lady/women/damsk → Damskie, junior/youth → Junior, kids/dziecięc → Dziecięce, unisex, men/męsk,
 *  4) kategorie produktu (z nadrzędnymi), np. „Odzież damska”, „Dziecięce”,
 *  5) rozmiary: same młodzieżowe (YS, YM, YL, YOS…) → Junior, same dziecięce (KS, 2T…) → Dziecięce,
 *  6) produkty „dla każdego” (kask, gogle, plecak, akcesoria… – lista w Narzędziach sklepu) → Unisex,
 *  7) pozostałe → Męskie (dorosła odzież i sprzęt bez oznaczenia, jak na foxracing.com).
 * Wybór „Męskie” albo „Damskie” pokazuje też produkty Unisex (do wyłączenia w Narzędziach sklepu).
 * Adres: ?dla=damskie,junior – strony z filtrem mają noindex jak pozostałe filtry.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

define( 'FX_DLA_VER', 1 ); // zmiana reguł = ponowne przypisanie wszystkich produktów w tle

/** Wartości w kolejności wyświetlania. */
function fx_dla_labels() {
	return array(
		'meskie'    => 'Męskie',
		'damskie'   => 'Damskie',
		'junior'    => 'Junior',
		'dzieciece' => 'Dziecięce',
		'unisex'    => 'Unisex',
	);
}

add_action( 'init', function () {
	register_taxonomy(
		'fx_dla',
		'product',
		array(
			'label'              => 'Dla kogo',
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => false,
			'show_in_rest'       => false,
			'query_var'          => false,
			'rewrite'            => false,
			'hierarchical'       => false,
		)
	);
}, 5 );

/** Tekst do porównań: małe litery, bez polskich znaków, słowa rozdzielone spacjami. */
function fx_dla_norm( $s ) {
	$s = mb_strtolower( wp_strip_all_tags( (string) $s ), 'UTF-8' );
	$s = remove_accents( $s );
	$s = preg_replace( '/[^a-z0-9]+/', ' ', $s );
	return ' ' . trim( $s ) . ' ';
}

/** Wartość z tekstu (nazwa, kategoria, atrybut) albo ''. Kolejność: dzieci > junior > damskie > unisex > męskie. */
function fx_dla_from_text( $text ) {
	$t = fx_dla_norm( $text );
	if ( '  ' === $t ) {
		return '';
	}
	$rules = apply_filters(
		'fx_dla_rules',
		array(
			'dzieciece' => '/ (kids?|toddlers?|infants?|baby|dzieci|dzieciec\w*|niemowl\w*|maluch\w*) /',
			'junior'    => '/ (junior|jr|youth|yth|mlodziez\w*|girls?|boys?|chlopi\w*|chlopc\w*|dziewcz\w*) /',
			'damskie'   => '/ (lady|ladies|women|womens|woman|wmn|wms|damsk\w*|kobiet\w*) /',
			'unisex'    => '/ (unisex) /',
			'meskie'    => '/ (men|mens|man|mesk\w*|mezczyzn\w*) /',
		)
	);
	// „Damskie|Męskie” (np. parametr z importera) = dla obu płci.
	if ( preg_match( $rules['damskie'], $t ) && preg_match( $rules['meskie'], $t ) && ! preg_match( $rules['junior'], $t ) && ! preg_match( $rules['dzieciece'], $t ) ) {
		return 'unisex';
	}
	foreach ( $rules as $slug => $re ) {
		if ( preg_match( $re, $t ) ) {
			return $slug;
		}
	}
	return '';
}

/** Taksonomie atrybutów z „płeć / gender / dla kogo” w nazwie. */
function fx_dla_attr_taxes() {
	static $out = null;
	if ( null !== $out ) {
		return $out;
	}
	$out = array();
	foreach ( function_exists( 'wc_get_attribute_taxonomies' ) ? wc_get_attribute_taxonomies() : array() as $a ) {
		// Także atrybut „Rodzaj” z importera (Damskie / Męskie / Dziecięce / Damskie|Męskie).
		if ( preg_match( '/(plec|płeć|gender|dla-?kogo|sex)/iu', $a->attribute_name . ' ' . $a->attribute_label ) || preg_match( '/^rodzaj$/iu', trim( $a->attribute_name ) ) || preg_match( '/^rodzaj$/iu', trim( $a->attribute_label ) ) ) {
			$out[] = wc_attribute_taxonomy_name( $a->attribute_name );
		}
	}
	return $out;
}

/** Rozmiary produktu (warianty i przypisane atrybuty rozmiaru). */
function fx_dla_sizes( $product ) {
	$taxes = function_exists( 'fx_filter_taxonomies' ) ? fx_filter_taxonomies()['size'] : array();
	$vals  = array();
	if ( $product->is_type( 'variable' ) ) {
		foreach ( $product->get_children() as $vid ) {
			foreach ( $taxes as $tax ) {
				$v = get_post_meta( $vid, 'attribute_' . $tax, true );
				if ( '' !== $v ) {
					$term   = get_term_by( 'slug', $v, $tax );
					$vals[] = $term ? $term->name : $v;
				}
			}
		}
	} else {
		foreach ( $taxes as $tax ) {
			foreach ( (array) wc_get_product_terms( $product->get_id(), $tax, array( 'fields' => 'names' ) ) as $n ) {
				$vals[] = $n;
			}
		}
	}
	return array_values( array_unique( array_filter( array_map( 'trim', $vals ) ) ) );
}

/** Same rozmiary młodzieżowe → junior, same dziecięce → dzieciece, inaczej ''. */
function fx_dla_from_sizes( $sizes ) {
	if ( ! $sizes ) {
		return '';
	}
	$kids  = 0;
	$youth = 0;
	foreach ( $sizes as $s ) {
		$n = strtoupper( str_replace( array( ' ', '-' ), '', function_exists( 'fx_size_norm' ) ? fx_size_norm( $s ) : $s ) );
		if ( preg_match( '/^(K(XXS|XS|S|M|L|XL)|\d{1,2}T|KOS|KIDS?\w*)$/', $n ) ) {
			$kids++;
		} elseif ( preg_match( '/^(Y(XXS|XS|S|M|L|XL|OS)|YOUTH\w*|JUNIOR\w*|J(XS|S|M|L|XL))$/', $n ) ) {
			$youth++;
		} else {
			return ''; // choć jeden rozmiar dorosły – rozmiary nie rozstrzygają
		}
	}
	return $kids && ! $youth ? 'dzieciece' : 'junior';
}

/** Słowa produktów „dla każdego” (Narzędzia sklepu). */
function fx_dla_unisex_re() {
	$words = array_filter( array_map( 'trim', explode( ',', (string) fx_tool( 'dla_unisex' ) ) ) );
	if ( ! $words ) {
		return '';
	}
	$parts = array();
	foreach ( $words as $w ) {
		$w = trim( fx_dla_norm( $w ) );
		if ( '' !== $w ) {
			$parts[] = preg_quote( $w, '/' ) . '\w*';
		}
	}
	return $parts ? '/ (' . implode( '|', $parts ) . ') /' : '';
}

/**
 * Wartość dla produktu: [ slug, skąd ].
 */
function fx_dla_detect( $product ) {
	$product = $product instanceof WC_Product ? $product : wc_get_product( $product );
	if ( ! $product ) {
		return array( '', '' );
	}
	$labels = fx_dla_labels();
	$manual = (string) $product->get_meta( '_fx_dla' );
	if ( isset( $labels[ $manual ] ) ) {
		return array( $manual, 'ręcznie' );
	}
	foreach ( fx_dla_attr_taxes() as $tax ) {
		$names = wc_get_product_terms( $product->get_id(), $tax, array( 'fields' => 'names' ) );
		$v     = fx_dla_from_text( implode( ' ', (array) $names ) );
		if ( $v ) {
			return array( $v, 'atrybut' );
		}
	}
	$v = fx_dla_from_text( $product->get_name() );
	if ( $v ) {
		return array( $v, 'nazwa' );
	}
	// Kategorie z nadrzędnymi – najpierw najgłębsze (najdokładniejsze).
	$cats = array();
	foreach ( (array) wc_get_product_terms( $product->get_id(), 'product_cat' ) as $c ) {
		$depth = count( get_ancestors( $c->term_id, 'product_cat' ) );
		$cats[] = array( $depth, $c->name . ' ' . str_replace( '-', ' ', $c->slug ) );
		foreach ( get_ancestors( $c->term_id, 'product_cat' ) as $i => $aid ) {
			$a = get_term( $aid, 'product_cat' );
			if ( $a && ! is_wp_error( $a ) ) {
				$cats[] = array( $depth - $i - 1, $a->name . ' ' . str_replace( '-', ' ', $a->slug ) );
			}
		}
	}
	usort( $cats, function ( $a, $b ) {
		return $b[0] <=> $a[0];
	} );
	foreach ( $cats as $c ) {
		$v = fx_dla_from_text( $c[1] );
		if ( $v ) {
			return array( $v, 'kategoria' );
		}
	}
	$v = fx_dla_from_sizes( fx_dla_sizes( $product ) );
	if ( $v ) {
		return array( $v, 'rozmiary' );
	}
	$re = fx_dla_unisex_re();
	if ( $re ) {
		$hay = fx_dla_norm( $product->get_name() . ' ' . implode( ' ', wp_list_pluck( $cats, 1 ) ) );
		if ( preg_match( $re, $hay ) ) {
			return array( 'unisex', 'rodzaj produktu' );
		}
	}
	return array( 'meskie', 'domyślnie' );
}

/** Zapis wartości w taksonomii. */
function fx_dla_assign( $product_id ) {
	$p = wc_get_product( $product_id );
	if ( ! $p || $p->is_type( 'variation' ) ) {
		return;
	}
	list( $slug ) = fx_dla_detect( $p );
	if ( ! $slug ) {
		return;
	}
	$cur = wp_get_object_terms( $p->get_id(), 'fx_dla', array( 'fields' => 'slugs' ) );
	if ( is_wp_error( $cur ) || array( $slug ) !== $cur ) {
		wp_set_object_terms( $p->get_id(), $slug, 'fx_dla', false );
	}
}

foreach ( array( 'woocommerce_update_product', 'woocommerce_new_product' ) as $fx_hook ) {
	add_action( $fx_hook, function ( $id ) {
		fx_dla_assign( $id );
	}, 30 );
}

/* ---------- Przypisanie wszystkich produktów w tle (po wgraniu motywu i po zmianie ustawień) ---------- */

function fx_dla_sig() {
	return md5( FX_DLA_VER . '|' . fx_tool( 'dla_unisex' ) . '|' . implode( ',', fx_dla_attr_taxes() ) );
}

add_action( 'admin_init', function () {
	if ( get_option( 'fx_dla_sig' ) !== fx_dla_sig() && ! wp_next_scheduled( 'fx_dla_seed' ) ) {
		update_option( 'fx_dla_sig', fx_dla_sig(), false );
		update_option( 'fx_dla_cursor', 0, false );
		wp_schedule_single_event( time() + 5, 'fx_dla_seed' );
	}
} );
add_action( 'customize_save_after', function () {
	if ( get_option( 'fx_dla_sig' ) !== fx_dla_sig() ) {
		delete_option( 'fx_dla_sig' ); // admin_init uruchomi ponowne przypisanie
	}
} );

add_action( 'fx_dla_seed', 'fx_dla_seed' );
function fx_dla_seed() {
	global $wpdb;
	$cursor = (int) get_option( 'fx_dla_cursor', 0 );
	$ids    = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status IN ('publish','private','draft') AND ID > %d ORDER BY ID ASC LIMIT 400", $cursor ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$start  = microtime( true );
	foreach ( $ids as $id ) {
		fx_dla_assign( (int) $id );
		$cursor = (int) $id;
		if ( microtime( true ) - $start > 20 ) {
			break;
		}
	}
	update_option( 'fx_dla_cursor', $cursor, false );
	if ( $ids ) {
		wp_schedule_single_event( time() + 30, 'fx_dla_seed' );
	} else {
		update_option( 'fx_dla_done', time(), false );
		if ( function_exists( 'fx_filters_flush' ) ) {
			fx_filters_flush();
		}
	}
}

/* ---------- Filtr ---------- */

/** Wartości obecne w kategorii (lub wynikach wyszukiwania): [ [ slug, name, id ] ]. */
function fx_dla_options( $term ) {
	$key   = 'fx_flt_dla_' . md5( 'v1|' . $term->term_id );
	$cache = get_transient( $key );
	if ( is_array( $cache ) ) {
		return $cache;
	}
	$prod = array_flip( fx_filter_cat_products( $term ) );
	$out  = array();
	if ( $prod ) {
		foreach ( fx_dla_labels() as $slug => $name ) {
			$t = get_term_by( 'slug', $slug, 'fx_dla' );
			if ( ! $t ) {
				continue;
			}
			$n = 0;
			foreach ( (array) get_objects_in_term( (int) $t->term_id, 'fx_dla' ) as $id ) {
				if ( isset( $prod[ (int) $id ] ) ) {
					$n++;
				}
			}
			if ( $n ) {
				$out[] = array( 'slug' => $slug, 'name' => $name, 'id' => (int) $t->term_id, 'n' => $n );
			}
		}
	}
	set_transient( $key, $out, isset( $term->ids ) ? 2 * HOUR_IN_SECONDS : 12 * HOUR_IN_SECONDS );
	return $out;
}

/** Produkty z wybranymi wartościami (Męskie/Damskie z Unisex, jeśli tak ustawiono). */
function fx_dla_match_ids( $slugs ) {
	$slugs = array_values( array_intersect( $slugs, array_keys( fx_dla_labels() ) ) );
	if ( $slugs && fx_tool( 'dla_unisex_in' ) && array_intersect( $slugs, array( 'meskie', 'damskie' ) ) ) {
		$slugs[] = 'unisex';
	}
	$ids = array();
	foreach ( array_unique( $slugs ) as $slug ) {
		$t = get_term_by( 'slug', $slug, 'fx_dla' );
		if ( $t ) {
			$ids = array_merge( $ids, array_map( 'intval', (array) get_objects_in_term( (int) $t->term_id, 'fx_dla' ) ) );
		}
	}
	return array_values( array_unique( $ids ) );
}

/* ---------- Edycja produktu: „Dla kogo” ---------- */

add_action( 'woocommerce_product_options_general_product_data', function () {
	global $product_object;
	if ( ! $product_object instanceof WC_Product ) {
		return;
	}
	$saved          = (string) $product_object->get_meta( '_fx_dla' );
	$product_object->delete_meta_data( '_fx_dla' ); // podgląd automatu bez ręcznej wartości
	list( $auto, $src ) = $product_object->get_id() ? fx_dla_detect( $product_object ) : array( '', '' );
	if ( '' !== $saved ) {
		$product_object->update_meta_data( '_fx_dla', $saved );
	}
	$labels = fx_dla_labels();
	$opts   = array( '' => 'Automatycznie' . ( $auto ? ' (' . $labels[ $auto ] . ' – ' . $src . ')' : '' ) ) + $labels;
	echo '<div class="options_group">';
	woocommerce_wp_select(
		array(
			'id'          => '_fx_dla',
			'label'       => 'Dla kogo',
			'options'     => $opts,
			'value'       => $saved,
			'desc_tip'    => true,
			'description' => 'Filtr „Dla kogo” w kategoriach. „Automatycznie” rozpoznaje z nazwy (lady, junior, kids…), kategorii i rozmiarów; zmień tylko, gdy automat się myli.',
		)
	);
	echo '</div>';
} );

add_action( 'woocommerce_admin_process_product_object', function ( $product ) {
	if ( ! isset( $_POST['_fx_dla'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$v = sanitize_key( wp_unslash( $_POST['_fx_dla'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
	if ( isset( fx_dla_labels()[ $v ] ) ) {
		$product->update_meta_data( '_fx_dla', $v );
	} else {
		$product->delete_meta_data( '_fx_dla' );
	}
} );

/* ---------- Lista produktów w panelu: kolumna i filtr „Dla kogo” (do sprawdzenia automatu) ---------- */

add_filter( 'manage_edit-product_columns', function ( $cols ) {
	$out = array();
	foreach ( $cols as $k => $v ) {
		$out[ $k ] = $v;
		if ( 'product_cat' === $k ) {
			$out['fx_dla'] = 'Dla kogo';
		}
	}
	if ( ! isset( $out['fx_dla'] ) ) {
		$out['fx_dla'] = 'Dla kogo';
	}
	return $out;
}, 20 );

add_action( 'manage_product_posts_custom_column', function ( $col, $id ) {
	if ( 'fx_dla' !== $col ) {
		return;
	}
	$t = wp_get_object_terms( $id, 'fx_dla', array( 'fields' => 'slugs' ) );
	$l = fx_dla_labels();
	echo esc_html( ! is_wp_error( $t ) && $t && isset( $l[ $t[0] ] ) ? $l[ $t[0] ] : '–' );
	if ( get_post_meta( $id, '_fx_dla', true ) ) {
		echo ' <small title="Ustawione ręcznie">✎</small>';
	}
}, 10, 2 );

add_action( 'restrict_manage_posts', function ( $type ) {
	if ( 'product' !== $type ) {
		return;
	}
	$cur = isset( $_GET['fx_dla_f'] ) ? sanitize_key( wp_unslash( $_GET['fx_dla_f'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	echo '<select name="fx_dla_f"><option value="">Dla kogo: wszystkie</option>';
	foreach ( fx_dla_labels() as $slug => $name ) {
		echo '<option value="' . esc_attr( $slug ) . '"' . selected( $cur, $slug, false ) . '>' . esc_html( $name ) . '</option>';
	}
	echo '</select>';
} );

add_action( 'pre_get_posts', function ( $q ) {
	if ( ! is_admin() || ! $q->is_main_query() || empty( $_GET['fx_dla_f'] ) || 'product' !== $q->get( 'post_type' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$tq   = (array) $q->get( 'tax_query' );
	$tq[] = array( 'taxonomy' => 'fx_dla', 'field' => 'slug', 'terms' => sanitize_key( wp_unslash( $_GET['fx_dla_f'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
	$q->set( 'tax_query', $tq );
} );
