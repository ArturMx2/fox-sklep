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

/** Parametry w adresie. */
function fx_filter_params() {
	return array( 'size' => 'rozmiar', 'color' => 'kolor' );
}

/** Czy jest włączony jakikolwiek filtr (rozmiar, kolor, promocje, także stare filter_…). */
function fx_filters_active() {
	foreach ( array_keys( $_GET ) as $k ) { // phpcs:ignore WordPress.Security.NonceVerification
		if ( in_array( $k, array( 'rozmiar', 'kolor', 'promocja', 'min_price', 'max_price', 'rating_filter' ), true ) || 0 === strpos( $k, 'filter_' ) ) {
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
	$all = function_exists( 'wc_get_attribute_taxonomies' ) ? wc_get_attribute_taxonomies() : array();
	foreach ( array( 'size' => 'filter_size_attr', 'color' => 'filter_color_attr' ) as $role => $setting ) {
		$chosen = trim( (string) fx_tool( $setting ) );
		if ( $chosen && taxonomy_exists( wc_attribute_taxonomy_name( $chosen ) ) ) {
			$out[ $role ][] = wc_attribute_taxonomy_name( $chosen );
		}
		$pattern = 'size' === $role ? '/(rozmiar|size|blvariant|wielko)/i' : '/(kolor|color|colour)/i';
		foreach ( $all as $a ) {
			$tax = wc_attribute_taxonomy_name( $a->attribute_name );
			if ( preg_match( $pattern, $a->attribute_name . ' ' . $a->attribute_label ) && taxonomy_exists( $tax ) && ! in_array( $tax, $out[ $role ], true ) ) {
				$out[ $role ][] = $tax;
			}
		}
	}
	return $out;
}

/** ID produktów w kategorii (z podkategoriami), opublikowanych. */
function fx_filter_cat_products( $term ) {
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
				'tax_query'      => array( array( 'taxonomy' => 'product_cat', 'terms' => $term->term_id, 'include_children' => true ) ), // phpcs:ignore
			)
		)
	);
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
	$key   = 'fx_flt_' . md5( $role . '|' . $term->term_id . '|' . implode( ',', fx_filter_taxonomies()[ $role ] ) );
	$cache = get_transient( $key );
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
			$simple = array_values( array_filter( $prod, function ( $id ) use ( $vp ) { return ! isset( $vp[ $id ] ); } ) );
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
				if ( ( isset( $found[ $t->slug ] ) || isset( $found[ sanitize_title( $t->name ) ] ) ) && ! isset( $out[ $t->slug ] ) ) {
					$out[ $t->slug ] = array( 'slug' => $t->slug, 'name' => $t->name, 'id' => $t->term_id );
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
	set_transient( $key, $out, 12 * HOUR_IN_SECONDS );
	return $out;
}

/* Zmiana produktów, stanów lub atrybutów czyści listy filtrów. */
foreach ( array( 'woocommerce_update_product', 'woocommerce_new_product', 'woocommerce_product_set_stock_status', 'woocommerce_variation_set_stock_status', 'edited_term', 'created_term' ) as $fx_hook ) {
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
	$url = get_term_link( get_queried_object() );
	foreach ( array( 'rozmiar', 'kolor', 'promocja', 'orderby' ) as $k ) {
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
 */
function fx_filter_match_ids( $role, $slugs ) {
	global $wpdb;
	$ids = array();
	foreach ( fx_filter_taxonomies()[ $role ] as $tax ) {
		$terms = get_terms( array( 'taxonomy' => $tax, 'slug' => $slugs, 'hide_empty' => false ) );
		$terms = is_wp_error( $terms ) ? array() : $terms;
		$vals  = $slugs;
		foreach ( $terms as $t ) {
			$vals[] = $t->name;
		}
		$vals = array_values( array_unique( $vals ) );
		if ( $vals ) {
			$ph  = implode( ',', array_fill( 0, count( $vals ), '%s' ) );
			$sql = "SELECT DISTINCT v.post_parent FROM {$wpdb->posts} v JOIN {$wpdb->postmeta} a ON a.post_id = v.ID AND a.meta_key = %s JOIN {$wpdb->postmeta} st ON st.post_id = v.ID AND st.meta_key = '_stock_status' AND st.meta_value IN ('instock','onbackorder') WHERE v.post_type = 'product_variation' AND v.post_status = 'publish' AND a.meta_value IN ($ph)";
			$ids = array_merge( $ids, array_map( 'intval', $wpdb->get_col( $wpdb->prepare( $sql, array_merge( array( 'attribute_' . $tax ), $vals ) ) ) ) ); // phpcs:ignore
		}
		if ( $terms ) {
			$vp  = fx_filter_var_parents( $tax );
			$obj = get_objects_in_term( wp_list_pluck( $terms, 'term_id' ), $tax );
			foreach ( is_wp_error( $obj ) ? array() : $obj as $id ) {
				if ( ! isset( $vp[ (int) $id ] ) ) {
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
			$sets[] = fx_filter_match_ids( $role, $sel );
		}
	}
	if ( fx_filter_sale_on() ) {
		$sets[] = array_map( 'intval', wc_get_product_ids_on_sale() );
	}
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

/* ---------- Pasek filtrów ---------- */
add_action( 'woocommerce_before_shop_loop', 'fx_filters_bar', 16 );
function fx_filters_bar() {
	if ( ! is_product_category() ) {
		return;
	}
	$term   = get_queried_object();
	$groups = array();
	foreach ( array( 'size' => 'Rozmiar', 'color' => 'Kolor' ) as $role => $label ) {
		if ( empty( fx_filter_taxonomies()[ $role ] ) ) {
			continue;
		}
		$opts = fx_filter_options( $role, $term );
		if ( count( $opts ) > 1 || fx_filter_selected( $role ) ) {
			$groups[] = array( $role, $label, $opts );
		}
	}
	$sale_on   = fx_filter_sale_on();
	$sale_have = $sale_on || fx_filter_sale_in_cat( $term );
	if ( ! $groups && ! $sale_have ) {
		return;
	}
	$active = array();
	echo '<div class="fx-filters" role="group" aria-label="Filtry">';
	if ( $sale_have ) {
		echo '<a rel="nofollow" class="fx-chip fx-switch' . ( $sale_on ? ' is-on' : '' ) . '" role="switch" aria-checked="' . ( $sale_on ? 'true' : 'false' ) . '" href="' . esc_url( fx_filter_sale_url() ) . '"><span class="fx-switch__ui" aria-hidden="true"></span>Promocje</a>';
	}
	foreach ( $groups as $g ) {
		list( $role, $label, $opts ) = $g;
		$sel = fx_filter_selected( $role );
		echo '<details class="fx-filter fx-filter--' . esc_attr( $role ) . '"><summary class="fx-chip' . ( $sel ? ' is-active' : '' ) . '">' . esc_html( $label ) . ( $sel ? ' (' . count( $sel ) . ')' : '' ) . fx_icon( 'chevron', 16 ) . '</summary><div class="fx-filter__panel">'; // phpcs:ignore
		foreach ( $opts as $t ) {
			$on    = in_array( $t['slug'], $sel, true );
			$color = 'color' === $role ? get_term_meta( $t['id'], 'fx_color', true ) : '';
			$style = '';
			if ( $color ) {
				$c     = explode( ',', $color );
				$style = ' style="--fx-sw:' . esc_attr( count( $c ) > 1 ? 'linear-gradient(135deg,' . $c[0] . ' 50%,' . $c[1] . ' 50%)' : $c[0] ) . '"';
			}
			echo '<a rel="nofollow" class="fx-filter__opt' . ( $on ? ' is-on' : '' ) . ( $color ? ' has-swatch' : '' ) . '" href="' . esc_url( fx_filter_toggle_url( $role, $t['slug'] ) ) . '" aria-pressed="' . ( $on ? 'true' : 'false' ) . '"' . $style . '>' . esc_html( $t['name'] ) . '</a>'; // phpcs:ignore
			if ( $on ) {
				$active[] = array( $t['name'], fx_filter_toggle_url( $role, $t['slug'] ) );
			}
		}
		echo '</div></details>';
	}
	if ( $active || $sale_on ) {
		echo '<div class="fx-filter__active">';
		foreach ( $active as $a ) {
			echo '<a rel="nofollow" class="fx-chip is-active" href="' . esc_url( $a[1] ) . '" aria-label="Usuń filtr ' . esc_attr( $a[0] ) . '">' . esc_html( $a[0] ) . ' ×</a>';
		}
		echo '<a rel="nofollow" class="fx-filter__clear" href="' . esc_url( get_term_link( $term ) ) . '">Wyczyść filtry</a></div>';
	}
	echo '</div>';
}

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
	$cache = get_transient( $key );
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
	set_transient( $key, $has, HOUR_IN_SECONDS );
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
