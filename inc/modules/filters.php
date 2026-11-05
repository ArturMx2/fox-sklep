<?php
/**
 * Filtry kategorii: rozmiar, kolor, promocje.
 *
 * Własny mechanizm motywu (nie wbudowany filtr WooCommerce), bo filtr Woo przy wariantach
 * zależy od tabeli „lookup” atrybutów, której importy (BaseLinker) często nie uzupełniają –
 * wtedy wybór rozmiaru dawał pustą listę. Tutaj:
 * - rozmiar: produkt pasuje, gdy ma DOSTĘPNY NA STANIE wariant w tym rozmiarze
 *   (wartość wariantu porównywana ze slugiem i nazwą rozmiaru – odporne na różne zapisy),
 *   a produkty proste – gdy mają ten rozmiar przypisany,
 * - kolor: atrybut przypisany do produktu (albo wariant w tym kolorze),
 * - kilka wartości w jednej grupie = LUB (np. M lub L), różne grupy = I (rozmiar M i kolor czarny),
 * - rozmiar może pochodzić z kilku atrybutów (np. osobny atrybut dla butów) – wszystkie atrybuty
 *   z „rozmiar / size / blvariant” w nazwie są brane pod uwagę.
 * Adresy: ?rozmiar=m,l&kolor=czarny&promocja=1 – strony z filtrami mają noindex.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

/** Parametry w adresie: rola => parametr (dodatkowe grupy z modułu parametrów – filtr fx_filter_extra). */
function fx_filter_params() {
	$out = array( 'gender' => 'dla', 'size' => 'rozmiar', 'color' => 'kolor' );
	foreach ( fx_filter_extra() as $role => $x ) {
		$out[ $role ] = $x['param'];
	}
	return $out;
}

/**
 * Dodatkowe grupy filtrów z atrybutów (np. parametry z importera):
 * [ rola => [ 'tax' => 'pa_przeznaczenie', 'label' => 'Przeznaczenie', 'param' => 'przeznaczenie' ] ].
 */
function fx_filter_extra() {
	static $out = null;
	if ( null === $out ) {
		$out = array();
		foreach ( (array) apply_filters( 'fx_filter_extra', array() ) as $role => $x ) {
			if ( ! empty( $x['tax'] ) && taxonomy_exists( $x['tax'] ) && ! empty( $x['param'] ) ) {
				$out[ $role ] = $x;
			}
		}
	}
	return $out;
}

/** Wszystkie parametry filtrów w adresie (do noindex i przenoszenia między filtrami). */
function fx_filter_url_params() {
	$base = array_merge( array_values( fx_filter_params() ), array( 'promocja', 'wysylka24', 'wsklepie', 'przedsprzedaz' ) );
	return array_values( array_unique( (array) apply_filters( 'fx_filter_url_params', $base ) ) );
}

/** Ile produktów kategorii (0–1) ma jakąkolwiek wartość atrybutu. Cache 12 h. */
function fx_filter_coverage( $tax, $term ) {
	$key   = 'fx_flt_cov_' . md5( $tax . '|' . $term->term_id );
	$cache = get_transient( $key );
	if ( false !== $cache ) {
		return (float) $cache;
	}
	$prod = fx_filter_cat_products( $term );
	$cov  = 0;
	if ( $prod ) {
		$ids = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false, 'fields' => 'ids' ) );
		$obj = $ids && ! is_wp_error( $ids ) ? get_objects_in_term( $ids, $tax ) : array();
		$obj = is_wp_error( $obj ) ? array() : array_flip( array_map( 'intval', $obj ) );
		$n   = 0;
		foreach ( $prod as $id ) {
			if ( isset( $obj[ $id ] ) ) {
				$n++;
			}
		}
		$cov = $n / count( $prod );
	}
	set_transient( $key, (string) $cov, isset( $term->ids ) ? 2 * HOUR_IN_SECONDS : 12 * HOUR_IN_SECONDS );
	return $cov;
}

/** Czy jest włączony jakikolwiek filtr (rozmiar, kolor, promocje, także stare filter_…). */
function fx_filters_active() {
	foreach ( array_keys( $_GET ) as $k ) { // phpcs:ignore WordPress.Security.NonceVerification
		if ( in_array( $k, array_merge( fx_filter_url_params(), array( 'min_price', 'max_price', 'rating_filter' ) ), true ) || 0 === strpos( $k, 'filter_' ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Taksonomie atrybutów: [ 'size' => [ 'pa_blvariant', 'pa_rozmiar-butow' ], 'color' => [ 'pa_kolor' ] ].
 */
function fx_filter_taxonomies() {
	static $out = null;
	if ( null !== $out ) {
		return $out;
	}
	$out = array( 'size' => array(), 'color' => array() );
	$extra = array();
	foreach ( fx_filter_extra() as $role => $x ) {
		$out[ $role ] = array( $x['tax'] );
		$extra[]      = $x['tax'];
	}
	$extra = array_merge( $extra, (array) apply_filters( 'fx_filter_not_size_color', array() ) ); // np. wszystkie atrybuty z importera
	$all = function_exists( 'wc_get_attribute_taxonomies' ) ? wc_get_attribute_taxonomies() : array();
	foreach ( array( 'size' => 'filter_size_attr', 'color' => 'filter_color_attr' ) as $role => $setting ) {
		$chosen = trim( (string) fx_tool( $setting ) );
		if ( $chosen && taxonomy_exists( wc_attribute_taxonomy_name( $chosen ) ) ) {
			$out[ $role ][] = wc_attribute_taxonomy_name( $chosen );
		}
		$pattern = 'size' === $role ? '/(rozmiar|size|blvariant|wielko)/i' : '/(kolor|color|colour)/i';
		foreach ( $all as $a ) {
			$tax = wc_attribute_taxonomy_name( $a->attribute_name );
			if ( preg_match( $pattern, $a->attribute_name . ' ' . $a->attribute_label ) && taxonomy_exists( $tax ) && ! in_array( $tax, $out[ $role ], true ) && ! in_array( $tax, $extra, true ) ) {
				$out[ $role ][] = $tax;
			}
		}
	}
	return $out;
}

/**
 * Strona wyników wyszukiwania (moduł search.php) jako „kategoria” dla filtrów:
 * obiekt z listą ID wyników zamiast terminu. Null poza wynikami wyszukiwania.
 */
function fx_filter_search_term() {
	static $t = false;
	if ( false === $t ) {
		$t = null;
		if ( function_exists( 'fx_srch_is_results' ) && fx_srch_is_results() ) {
			$ids = fx_srch_current()['ids'];
			$t   = (object) array( 'term_id' => 's' . md5( implode( ',', $ids ) ), 'ids' => $ids, 'fx_search' => true );
		}
	}
	return $t;
}

/** Adres „bez filtrów” – kategoria albo wyniki wyszukiwania. */
function fx_filter_clear_url( $term ) {
	if ( ! empty( $term->fx_search ) ) {
		return add_query_arg( array( 's' => rawurlencode( fx_srch_current_q() ), 'post_type' => 'product' ), home_url( '/' ) );
	}
	return get_term_link( $term );
}

/** ID produktów w kategorii (z podkategoriami), opublikowanych. */
function fx_filter_cat_products( $term ) {
	if ( isset( $term->ids ) ) {
		return array_map( 'intval', (array) $term->ids );
	}
	static $cache = array();
	if ( isset( $cache[ $term->term_id ] ) ) {
		return $cache[ $term->term_id ];
	}
	return $cache[ $term->term_id ] = array_map(
		'intval',
		get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'tax_query'      => fx_filter_visible_tax( array( array( 'taxonomy' => 'product_cat', 'terms' => $term->term_id, 'include_children' => true ) ) ), // phpcs:ignore
			)
		)
	);
}

/**
 * Warunek „produkt widoczny na liście”: bez ukrytych w katalogu i – gdy sklep ukrywa
 * niedostępne – bez wyprzedanych. Ukryte produkty nie mogą dokładać rozmiarów do filtra.
 */
function fx_filter_visible_tax( $tq ) {
	$hide = array( 'exclude-from-catalog' );
	if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) ) {
		$hide[] = 'outofstock';
	}
	$tq[] = array( 'taxonomy' => 'product_visibility', 'field' => 'name', 'terms' => $hide, 'operator' => 'NOT IN' );
	return $tq;
}

/**
 * Spośród podanych ID tylko produkty proste. Produkt z wariantami, których rozmiary nie są
 * ustawione (albo są wyłączone), ma często przypisaną całą listę rozmiarów (import) – pomijamy go.
 */
function fx_filter_only_simple( $ids ) {
	static $simple = null;
	if ( ! $ids ) {
		return array();
	}
	if ( null === $simple ) {
		$t      = get_term_by( 'slug', 'simple', 'product_type' );
		$o      = $t ? get_objects_in_term( (int) $t->term_id, 'product_type' ) : array();
		$simple = is_wp_error( $o ) ? array() : array_flip( array_map( 'intval', $o ) );
	}
	return array_values( array_filter( array_map( 'intval', $ids ), function ( $id ) use ( $simple ) { return isset( $simple[ $id ] ); } ) );
}

/** Produkty-rodzice, których warianty używają danego atrybutu (cache na żądanie). */
function fx_filter_var_parents( $tax ) {
	static $cache = array();
	if ( isset( $cache[ $tax ] ) ) {
		return $cache[ $tax ];
	}
	global $wpdb;
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT v.post_parent FROM {$wpdb->posts} v JOIN {$wpdb->postmeta} a ON a.post_id = v.ID AND a.meta_key = %s WHERE v.post_type = 'product_variation' AND v.post_status = 'publish' AND a.meta_value <> ''", 'attribute_' . $tax ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return $cache[ $tax ] = array_flip( array_map( 'intval', $ids ) );
}

/**
 * Wartości filtra w kategorii: [ slug => [ 'slug', 'name', 'id' ] ] w kolejności z atrybutu.
 * Rozmiary: tylko dostępne na stanie warianty. Cache 12 h (czyszczony przy zmianie produktu).
 */
function fx_filter_options( $role, $term ) {
	$key   = 'fx_flt_' . md5( 'v3|' . $role . '|' . $term->term_id . '|' . implode( ',', fx_filter_taxonomies()[ $role ] ) );
	$cache = get_transient( $key ); // wyniki wyszukiwania: klucz z listy ID, krótszy czas
	if ( is_array( $cache ) ) {
		return $cache;
	}
	global $wpdb;
	$prod = fx_filter_cat_products( $term );
	$out  = array();
	if ( $prod ) {
		$in = implode( ',', array_map( 'intval', $prod ) );
		foreach ( fx_filter_taxonomies()[ $role ] as $tax ) {
			$found = array();
			// Wartości z wariantów na stanie.
			$vals = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT a.meta_value FROM {$wpdb->posts} v JOIN {$wpdb->postmeta} a ON a.post_id = v.ID AND a.meta_key = %s JOIN {$wpdb->postmeta} st ON st.post_id = v.ID AND st.meta_key = '_stock_status' AND st.meta_value IN ('instock','onbackorder') WHERE v.post_type = 'product_variation' AND v.post_status = 'publish' AND v.post_parent IN ($in) AND a.meta_value <> ''", 'attribute_' . $tax ) ); // phpcs:ignore
			foreach ( $vals as $v ) {
				$found[ sanitize_title( $v ) ] = true;
			}
			// Produkty bez wariantów tego atrybutu – przypisane wartości.
			$vp     = fx_filter_var_parents( $tax );
			$simple = array_filter( $prod, function ( $id ) use ( $vp ) { return ! isset( $vp[ $id ] ); } );
			// Rozmiar przypisany „na sztywno” liczy się tylko w produktach prostych (import dokleja całą listę
			// rozmiarów do produktów z wariantami). Kolor przypisany do produktu z wariantami (rozmiarami) jest prawdziwy.
			$simple = 'size' === $role ? fx_filter_only_simple( $simple ) : array_values( $simple );
			if ( $simple ) {
				foreach ( (array) wp_get_object_terms( $simple, $tax, array( 'fields' => 'slugs' ) ) as $s ) {
					if ( is_string( $s ) ) {
						$found[ $s ] = true;
					}
				}
			}
			if ( ! $found ) {
				continue;
			}
			$terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false, 'orderby' => 'menu_order' ) );
			foreach ( is_wp_error( $terms ) ? array() : $terms as $t ) {
				if ( ! isset( $found[ $t->slug ] ) && ! isset( $found[ sanitize_title( $t->name ) ] ) ) {
					continue;
				}
				if ( 'size' === $role ) {
					// „XL - DOSTAWA 24H” i „XL” to ten sam rozmiar w filtrze; długie/techniczne wartości pomijamy.
					$label = fx_size_label( fx_size_norm( $t->name ) );
					if ( ! fx_size_filterable( $label ) ) {
						continue;
					}
					$slug = sanitize_title( fx_size_norm( $t->name ) );
					if ( ! isset( $out[ $slug ] ) ) {
						$out[ $slug ] = array( 'slug' => $slug, 'name' => $label, 'id' => $t->term_id );
					}
					continue;
				}
				if ( ! isset( $out[ $t->slug ] ) ) {
					$out[ $t->slug ] = array( 'slug' => $t->slug, 'name' => fx_size_label( $t->name ), 'id' => $t->term_id );
				}
			}
		}
	}
	$out = array_values( $out );
	if ( 'size' === $role ) {
		usort(
			$out,
			function ( $a, $b ) {
				return fx_size_rank( $a['name'] ) <=> fx_size_rank( $b['name'] );
			}
		);
	}
	set_transient( $key, $out, isset( $term->ids ) ? 2 * HOUR_IN_SECONDS : 12 * HOUR_IN_SECONDS );
	return $out;
}

/* Zmiana produktów, stanów lub atrybutów czyści listy filtrów. */
foreach ( array( 'woocommerce_update_product', 'woocommerce_new_product', 'woocommerce_product_set_stock_status', 'woocommerce_variation_set_stock_status' ) as $fx_hook ) {
	add_action( $fx_hook, 'fx_cache_touch' ); // zbiorczo, nie po każdym produkcie (import, wtyczki w tle)
}
foreach ( array( 'edited_term', 'created_term' ) as $fx_hook ) {
	add_action( $fx_hook, 'fx_filters_flush' );
}
function fx_filters_flush() {
	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_fx\\_flt\\_%' OR option_name LIKE '\\_transient\\_timeout\\_fx\\_flt\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

function fx_filter_selected( $role ) {
	$p = fx_filter_params()[ $role ];
	return isset( $_GET[ $p ] ) ? array_values( array_filter( array_map( 'sanitize_title', explode( ',', wp_unslash( $_GET[ $p ] ) ) ) ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification
}

/** Adres kategorii z bieżącymi filtrami i sortowaniem (bez numeru strony). */
function fx_filter_base_url() {
	$st  = fx_filter_search_term();
	$url = $st ? fx_filter_clear_url( $st ) : get_term_link( get_queried_object() );
	foreach ( array_merge( fx_filter_url_params(), array( 'orderby' ) ) as $k ) {
		if ( isset( $_GET[ $k ] ) && '' !== $_GET[ $k ] ) { // phpcs:ignore WordPress.Security.NonceVerification
			$url = add_query_arg( $k, rawurlencode( sanitize_text_field( wp_unslash( $_GET[ $k ] ) ) ), $url ); // phpcs:ignore WordPress.Security.NonceVerification
		}
	}
	return $url;
}

function fx_filter_toggle_url( $role, $slug ) {
	$sel = fx_filter_selected( $role );
	$sel = in_array( $slug, $sel, true ) ? array_values( array_diff( $sel, array( $slug ) ) ) : array_merge( $sel, array( $slug ) );
	$p   = fx_filter_params()[ $role ];
	$url = remove_query_arg( $p, fx_filter_base_url() );
	return $sel ? add_query_arg( $p, implode( ',', $sel ), $url ) : $url;
}

/**
 * Produkty pasujące do wybranych wartości (dowolnej z nich) w danej grupie.
 * $only: opcjonalnie [ id_wariantu_lub_produktu => true ] – liczą się tylko te warianty / produkty
 * (np. „rozmiar L, który leży w sklepie stacjonarnym”).
 */
function fx_filter_match_ids( $role, $slugs, $only = null ) {
	global $wpdb;
	$ids = array();
	foreach ( fx_filter_taxonomies()[ $role ] as $tax ) {
		if ( 'size' === $role ) {
			// Wybrany „xl” obejmuje też „XL - DOSTAWA 24H” itp.
			$want = array_map( 'fx_size_norm_slug', $slugs );
			$all  = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false ) );
			foreach ( is_wp_error( $all ) ? array() : $all as $t ) {
				if ( in_array( sanitize_title( fx_size_norm( $t->name ) ), $want, true ) ) {
					$slugs[] = $t->slug;
				}
			}
			$slugs = array_values( array_unique( $slugs ) );
		}
		$terms = get_terms( array( 'taxonomy' => $tax, 'slug' => $slugs, 'hide_empty' => false ) );
		$terms = is_wp_error( $terms ) ? array() : $terms;
		$vals  = $slugs;
		foreach ( $terms as $t ) {
			$vals[] = $t->name;
		}
		$vals = array_values( array_unique( $vals ) );
		if ( $vals ) {
			$ph  = implode( ',', array_fill( 0, count( $vals ), '%s' ) );
			$sql = "SELECT DISTINCT v.ID, v.post_parent FROM {$wpdb->posts} v JOIN {$wpdb->postmeta} a ON a.post_id = v.ID AND a.meta_key = %s JOIN {$wpdb->postmeta} st ON st.post_id = v.ID AND st.meta_key = '_stock_status' AND st.meta_value IN ('instock','onbackorder') WHERE v.post_type = 'product_variation' AND v.post_status = 'publish' AND a.meta_value IN ($ph)";
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( 'attribute_' . $tax ), $vals ) ) ) as $row ) { // phpcs:ignore
				if ( null === $only || isset( $only[ (int) $row->ID ] ) ) {
					$ids[] = (int) $row->post_parent;
				}
			}
		}
		if ( $terms ) {
			$vp  = fx_filter_var_parents( $tax );
			$obj = get_objects_in_term( wp_list_pluck( $terms, 'term_id' ), $tax );
			$obj = is_wp_error( $obj ) ? array() : $obj;
			foreach ( 'size' === $role ? fx_filter_only_simple( $obj ) : $obj as $id ) {
				if ( ! isset( $vp[ (int) $id ] ) && ( null === $only || isset( $only[ (int) $id ] ) ) ) {
					$ids[] = (int) $id;
				}
			}
		}
	}
	return array_values( array_unique( $ids ) );
}

/* ---------- Zawężenie listy produktów ---------- */
add_action( 'woocommerce_product_query', function ( $q ) {
	$sets = array();
	foreach ( array_keys( fx_filter_params() ) as $role ) {
		$sel = fx_filter_selected( $role );
		if ( $sel ) {
			$sets[] = 'gender' === $role ? fx_dla_match_ids( $sel ) : fx_filter_match_ids( $role, $sel );
		}
	}
	if ( fx_filter_sale_on() ) {
		$sets[] = array_map( 'intval', wc_get_product_ids_on_sale() );
	}
	$sets = apply_filters( 'fx_filter_sets', $sets );
	if ( ! $sets ) {
		return;
	}
	$ids = array_shift( $sets );
	foreach ( $sets as $s ) {
		$ids = array_intersect( $ids, $s );
	}
	$cur = array_filter( (array) $q->get( 'post__in' ) );
	if ( $cur ) {
		$ids = array_intersect( $ids, array_map( 'intval', $cur ) );
	}
	$ids = array_values( $ids );
	$q->set( 'post__in', $ids ? $ids : array( 0 ) );
}, 20 );

/* ---------- Filtry w kolumnie po lewej (telefon: panel „Filtry”) ---------- */

/** Dane filtrów dla bieżącej kategorii albo null, gdy nie ma czego filtrować. */
function fx_filters_data() {
	static $data = false;
	if ( false !== $data ) {
		return $data;
	}
	$data = null;
	$term = fx_filter_search_term();
	if ( ! $term && ! is_product_category() ) {
		return $data;
	}
	$term   = $term ? $term : get_queried_object();
	$groups = array();
	// „Dla kogo” – gdy w kategorii są co najmniej dwie grupy (np. męskie i damskie).
	if ( function_exists( 'fx_dla_options' ) ) {
		$opts = fx_dla_options( $term );
		if ( count( $opts ) > 1 || fx_filter_selected( 'gender' ) ) {
			$groups[] = array( 'gender', 'Dla kogo', $opts );
		}
	}
	foreach ( array( 'size' => 'Rozmiar', 'color' => 'Kolor' ) as $role => $label ) {
		if ( empty( fx_filter_taxonomies()[ $role ] ) ) {
			continue;
		}
		$opts = fx_filter_options( $role, $term );
		if ( count( $opts ) > 1 || fx_filter_selected( $role ) ) {
			$groups[] = array( $role, $label, $opts );
		}
	}
	// Dodatkowe atrybuty (np. Przeznaczenie, Szybka) – tylko gdy ma je duża część produktów kategorii.
	$min_cov = (float) apply_filters( 'fx_filter_extra_min_coverage', 0.4 );
	foreach ( fx_filter_extra() as $role => $x ) {
		$sel = fx_filter_selected( $role );
		if ( ! $sel && fx_filter_coverage( $x['tax'], $term ) < $min_cov ) {
			continue;
		}
		$opts = fx_filter_options( $role, $term );
		if ( count( $opts ) > 1 || $sel ) {
			$groups[] = array( $role, $x['label'], $opts );
		}
	}
	$sale_on   = fx_filter_sale_on();
	$sale_have = $sale_on || fx_filter_sale_in_cat( $term );
	$switches  = apply_filters( 'fx_filter_switches', array(), $term ); // np. „Wysyłka dziś”, „W sklepie stacjonarnym”
	if ( ! $groups && ! $sale_have && ! $switches ) {
		return $data;
	}
	$active = array();
	foreach ( $groups as $g ) {
		foreach ( $g[2] as $t ) {
			if ( in_array( $t['slug'], fx_filter_selected( $g[0] ), true ) ) {
				$active[] = array( $t['name'], fx_filter_toggle_url( $g[0], $t['slug'] ) );
			}
		}
	}
	if ( $sale_on ) {
		$active[] = array( 'Promocje', fx_filter_sale_url() );
	}
	foreach ( $switches as $sw ) {
		if ( $sw['on'] ) {
			$active[] = array( $sw['short'], $sw['url'] );
		}
	}
	$data = array( 'term' => $term, 'groups' => $groups, 'sale_have' => $sale_have, 'sale_on' => $sale_on, 'active' => $active, 'switches' => $switches );
	return $data;
}

function fx_filters_sidebar() {
	$d = fx_filters_data();
	if ( ! $d ) {
		return;
	}
	echo '<aside class="fx-sidebar" id="fx-filters" aria-label="Filtry"><div class="fx-sidebar__head"><span class="fx-sidebar__title">Filtry</span><button type="button" class="fx-icon-btn fx-sidebar__close" data-fx-filters-close aria-label="Zamknij filtry">' . fx_icon( 'close' ) . '</button></div><div class="fx-sidebar__body">'; // phpcs:ignore
	if ( $d['sale_have'] ) {
		echo '<div class="fx-fgroup fx-fgroup--sale"><a rel="nofollow" class="fx-switch fx-switch--side' . ( $d['sale_on'] ? ' is-on' : '' ) . '" role="switch" aria-checked="' . ( $d['sale_on'] ? 'true' : 'false' ) . '" href="' . esc_url( fx_filter_sale_url() ) . '"><span class="fx-switch__ui" aria-hidden="true"></span>Tylko promocje</a></div>';
	}
	foreach ( (array) ( $d['switches'] ?? array() ) as $sw ) {
		echo '<div class="fx-fgroup fx-fgroup--sale fx-fgroup--' . esc_attr( $sw['key'] ) . '"><a rel="nofollow" class="fx-switch fx-switch--side' . ( $sw['on'] ? ' is-on' : '' ) . '" role="switch" aria-checked="' . ( $sw['on'] ? 'true' : 'false' ) . '" href="' . esc_url( $sw['url'] ) . '"><span class="fx-switch__ui" aria-hidden="true"></span><span><span class="fx-switch__label"' . ( 'wysylka24' === $sw['key'] ? ' data-fx-shipday="' . esc_attr( $sw['label'] ) . '"' : '' ) . '>' . esc_html( $sw['label'] ) . '</span>' . ( ! empty( $sw['note'] ) ? '<small class="fx-switch__note"' . ( 'wysylka24' === $sw['key'] ? ' data-fx-shipnote' : '' ) . '>' . esc_html( $sw['note'] ) . '</small>' : '' ) . '</span></a></div>';
	}
	foreach ( $d['groups'] as $g ) {
		list( $role, $label, $opts ) = $g;
		$sel = fx_filter_selected( $role );
		echo '<details class="fx-fgroup fx-fgroup--' . esc_attr( $role ) . '" open><summary>' . esc_html( $label ) . ( $sel ? ' <span class="fx-fgroup__n">' . count( $sel ) . '</span>' : '' ) . fx_icon( 'chevron', 16 ) . '</summary><div class="fx-fgroup__opts">'; // phpcs:ignore
		foreach ( $opts as $t ) {
			$on    = in_array( $t['slug'], $sel, true );
			$color = 'color' === $role ? fx_term_color( get_term( $t['id'] ) ) : '';
			$style = '';
			if ( $color ) {
				$c     = explode( ',', $color );
				$style = ' style="--fx-sw:' . esc_attr( count( $c ) > 1 ? 'linear-gradient(135deg,' . $c[0] . ' 50%,' . $c[1] . ' 50%)' : $c[0] ) . '"';
			}
			echo '<a rel="nofollow" class="fx-filter__opt' . ( $on ? ' is-on' : '' ) . ( 'color' === $role ? ' is-color' : '' ) . ( $color ? ' has-swatch' : '' ) . '" href="' . esc_url( fx_filter_toggle_url( $role, $t['slug'] ) ) . '" aria-pressed="' . ( $on ? 'true' : 'false' ) . '"' . $style . '>' . esc_html( $t['name'] ) . '</a>'; // phpcs:ignore
		}
		echo '</div></details>';
	}
	if ( $d['active'] ) {
		echo '<a rel="nofollow" class="fx-sidebar__clear" href="' . esc_url( fx_filter_clear_url( $d['term'] ) ) . '">Wyczyść wszystkie filtry</a>';
	}
	echo '</div></aside><div class="fx-sidebar__bg" data-fx-filters-close></div>';
}

/** Nad produktami: przycisk „Filtry” (telefon) i wybrane filtry do szybkiego usunięcia. */
function fx_filters_topbar() {
	$d = fx_filters_data();
	if ( ! $d ) {
		return;
	}
	echo '<div class="fx-fbar"><button type="button" class="fx-chip fx-fbar__open" data-fx-filters-open aria-controls="fx-filters">' . fx_icon( 'menu', 16 ) . 'Filtry' . ( $d['active'] ? ' (' . count( $d['active'] ) . ')' : '' ) . '</button>'; // phpcs:ignore
	foreach ( $d['active'] as $a ) {
		$sd = false !== mb_stripos( $a[0], 'dziś' ) ? ' data-fx-shipday="' . esc_attr( $a[0] ) . '"' : '';
		echo '<a rel="nofollow" class="fx-chip is-active" href="' . esc_url( $a[1] ) . '" aria-label="Usuń filtr ' . esc_attr( $a[0] ) . '"><span' . $sd . '>' . esc_html( $a[0] ) . '</span> ×</a>';
	}
	if ( $d['active'] ) {
		echo '<a rel="nofollow" class="fx-filter__clear" href="' . esc_url( fx_filter_clear_url( $d['term'] ) ) . '">Wyczyść</a>';
	}
	echo '</div>';
}

/* Układ: [filtry | produkty]. Otwarcie po kafelkach podkategorii, zamknięcie po paginacji. */
add_action( 'woocommerce_before_shop_loop', 'fx_shop_layout_open', 17 );
add_action( 'woocommerce_after_shop_loop', 'fx_shop_layout_close', 99 );
/* Gdy filtr nic nie znalazł – też z kolumną filtrów (żeby dało się je zmienić). */
add_action( 'woocommerce_no_products_found', function () {
	if ( fx_filters_data() ) {
		fx_shop_layout_open();
	}
}, 5 );
add_action( 'woocommerce_no_products_found', function () {
	if ( fx_filters_data() ) {
		fx_shop_layout_close();
	}
}, 99 );

function fx_shop_layout_open() {
	if ( ! fx_filters_data() ) {
		return;
	}
	$GLOBALS['fx_layout_open'] = true;
	echo '<div class="fx-shop-layout">';
	fx_filters_sidebar();
	echo '<div class="fx-shop-main">';
	fx_filters_topbar();
}
function fx_shop_layout_close() {
	if ( empty( $GLOBALS['fx_layout_open'] ) ) {
		return;
	}
	$GLOBALS['fx_layout_open'] = false;
	echo '</div></div>';
}

/* Kolumna filtrów zostawia na produkty 3 kolumny. */
add_filter( 'loop_shop_columns', function ( $c ) {
	return 3;
}, 30 );

/* ---------- Włącznik „Promocje” (?promocja=1) ---------- */
function fx_filter_sale_on() {
	return ! empty( $_GET['promocja'] ) || ! empty( $_GET['filter_promocja'] ); // phpcs:ignore WordPress.Security.NonceVerification
}

function fx_filter_sale_url() {
	$url = remove_query_arg( array( 'promocja', 'filter_promocja', 'paged' ), fx_filter_base_url() );
	return fx_filter_sale_on() ? $url : add_query_arg( 'promocja', '1', $url );
}

/** Czy w kategorii (z podkategoriami) jest choć jeden produkt w promocji. */
function fx_filter_sale_in_cat( $term ) {
	$key   = 'fx_flt_sale_' . $term->term_id;
	$cache = isset( $term->ids ) ? false : get_transient( $key );
	if ( false !== $cache ) {
		return (bool) $cache;
	}
	$sale = array_flip( array_map( 'intval', wc_get_product_ids_on_sale() ) );
	$has  = 0;
	foreach ( fx_filter_cat_products( $term ) as $id ) {
		if ( isset( $sale[ $id ] ) ) {
			$has = 1;
			break;
		}
	}
	if ( ! isset( $term->ids ) ) {
		set_transient( $key, $has, HOUR_IN_SECONDS );
	}
	return (bool) $has;
}

/* Strony z filtrami nie trafiają do indeksu Google (oryginalna kategoria zostaje bez zmian). */
add_filter( 'wp_robots', function ( $robots ) {
	if ( fx_filters_active() ) {
		$robots['noindex'] = true;
		$robots['follow']  = true;
		unset( $robots['index'] );
	}
	return $robots;
}, 99 );

/* Yoast SEO: to samo dla jego znacznika robots. */
add_filter( 'wpseo_robots', function ( $r ) {
	return fx_filters_active() ? 'noindex, follow' : $r;
}, 99 );

/** Kolejność rozmiarów: dziecięce, literowe (XS…4XL), potem liczbowe rosnąco (28, 30, 42, 43…). */
/** Rozmiar bez dopisków magazynowych: „XL - DOSTAWA 24H - DOSTAWA 24H” → „XL”. */
function fx_size_norm( $name ) {
	$n = trim( (string) $name );
	$n = preg_replace( '/(\s*[-–—]\s*dostawa\s*\d+\s*h)+\s*$/iu', '', $n );
	return trim( $n );
}

function fx_size_norm_slug( $slug ) {
	return preg_replace( '/(-dostawa-\d+h)+$/', '', (string) $slug );
}

/**
 * Czy rozmiar pokazać w filtrze: krótkie (do 5 znaków) oraz Junior / Uniwersalny.
 * Pomijamy listy typu „10|11|12”, kody „002” i długie zestawy („XS/SM/MD”).
 */
function fx_size_filterable( $label ) {
	$label = trim( (string) $label );
	if ( '' === $label ) {
		return false;
	}
	if ( in_array( mb_strtolower( $label ), array( 'junior', 'uniwersalny' ), true ) ) {
		return true;
	}
	if ( false !== strpos( $label, '|' ) || preg_match( '/^0\d+$/', $label ) ) {
		return false;
	}
	return (bool) apply_filters( 'fx_size_filterable', mb_strlen( $label ) <= 5, $label );
}

function fx_size_rank( $name ) {
	$n = strtoupper( trim( (string) $name ) );
	$n = str_replace( array( ' ', '-' ), '', $n );
	$letters = array( 'XXXS', 'XXS', 'XS', 'S', 'M', 'L', 'XL', 'XXL', '2XL', 'XXXL', '3XL', '4XL', '5XL' );
	$map     = array( '2XL' => 'XXL', 'XXXL' => '3XL' );
	if ( preg_match( '/^(Y|K|J)(XXS|XS|S|M|L|XL)$/', $n, $m ) ) { // młodzieżowe / dziecięce
		return 100 + array_search( $m[2], $letters, true );
	}
	$k = isset( $map[ $n ] ) ? $map[ $n ] : $n;
	$i = array_search( $k, $letters, true );
	if ( false !== $i ) {
		return 200 + $i;
	}
	if ( preg_match( '/^(\d+(?:[.,]\d+)?)/', $n, $m ) ) {
		return 1000 + (float) str_replace( ',', '.', $m[1] );
	}
	return 100000;
}

/*
 * Stare adresy filtrów (wtyczka YITH / filtr WooCommerce), np.
 * /sklep/?product_cat=buty-rowerowe-fox-union&source_id=3249&source_tax=product_cat&filter_blvariant=37
 * → 301 na /kategoria-produktu/…/buty-rowerowe-fox-union/?rozmiar=37
 * Linki z zewnątrz, zakładki klientów i stare reklamy dalej działają.
 */
add_action( 'template_redirect', function () {
	if ( is_admin() || empty( $_GET ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$map   = array();
	$taxes = fx_filter_taxonomies();
	foreach ( array( 'size' => 'rozmiar', 'color' => 'kolor' ) as $role => $param ) {
		foreach ( $taxes[ $role ] as $tax ) {
			$map[ 'filter_' . wc_attribute_taxonomy_slug( $tax ) ] = $param;
		}
	}
	$map['filter_promocja'] = 'promocja';
	$found = array_intersect_key( $_GET, $map ); // phpcs:ignore WordPress.Security.NonceVerification
	if ( ! $found ) {
		return;
	}
	// Adres docelowy: kategoria z parametru product_cat albo bieżąca strona.
	$base = '';
	if ( ! empty( $_GET['product_cat'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		$t = get_term_by( 'slug', sanitize_title( wp_unslash( $_GET['product_cat'] ) ), 'product_cat' ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( $t ) {
			$base = get_term_link( $t );
		}
	}
	if ( ! $base ) {
		$base = is_product_category() ? get_term_link( get_queried_object() ) : ( function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' ) );
	}
	if ( is_wp_error( $base ) ) {
		return;
	}
	$args = array();
	foreach ( $found as $k => $v ) {
		$vals = array_filter( array_map( 'sanitize_title', explode( ',', wp_unslash( $v ) ) ) );
		if ( $vals ) {
			$p          = $map[ $k ];
			$args[ $p ] = isset( $args[ $p ] ) ? $args[ $p ] . ',' . implode( ',', $vals ) : implode( ',', $vals );
		}
	}
	if ( isset( $_GET['orderby'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		$args['orderby'] = sanitize_key( wp_unslash( $_GET['orderby'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
	}
	wp_safe_redirect( add_query_arg( $args, $base ), 301 );
	exit;
}, 5 );
