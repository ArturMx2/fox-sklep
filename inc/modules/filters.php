<?php
/**
 * Filtry kategorii: rozmiar i kolor.
 *
 * Korzysta z wbudowanego filtrowania WooCommerce po atrybutach (?filter_rozmiar=m,l),
 * więc nie potrzebuje wtyczki ani dodatkowych zapytań przy wyświetlaniu produktów.
 * Pokazujemy tylko wartości, które występują w danej kategorii (lista w pamięci podręcznej na 12 h).
 * Strony z aktywnymi filtrami dostają noindex, żeby nie mnożyć adresów w Google.
 *
 * Atrybuty: Wygląd → Dostosuj → FOX.SKLEP.PL → Narzędzia sklepu (domyślnie wykrywane po nazwie).
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

/**
 * Taksonomie atrybutów do filtrowania: [ 'size' => 'pa_rozmiar', 'color' => 'pa_kolor' ].
 */
function fx_filter_taxonomies() {
	static $out = null;
	if ( null !== $out ) {
		return $out;
	}
	$out = array();
	$all = function_exists( 'wc_get_attribute_taxonomies' ) ? wc_get_attribute_taxonomies() : array();
	foreach ( array( 'size' => 'filter_size_attr', 'color' => 'filter_color_attr' ) as $role => $setting ) {
		$chosen = trim( (string) fx_tool( $setting ) );
		if ( $chosen && taxonomy_exists( wc_attribute_taxonomy_name( $chosen ) ) ) {
			$out[ $role ] = wc_attribute_taxonomy_name( $chosen );
			continue;
		}
		$pattern = 'size' === $role ? '/(rozmiar|size)/i' : '/(kolor|color|colour)/i';
		foreach ( $all as $a ) {
			if ( preg_match( $pattern, $a->attribute_name . ' ' . $a->attribute_label ) ) {
				$out[ $role ] = wc_attribute_taxonomy_name( $a->attribute_name );
				break;
			}
		}
	}
	return $out;
}

/**
 * Wartości atrybutu występujące w kategorii (z podkategoriami), w kolejności z atrybutu.
 */
function fx_filter_terms_in_cat( $tax, $term ) {
	$key   = 'fx_flt_' . md5( $tax . '|' . $term->term_id );
	$cache = get_transient( $key );
	if ( false !== $cache ) {
		return $cache;
	}
	$ids = wc_get_products(
		array(
			'status'     => 'publish',
			'visibility' => 'catalog',
			'limit'      => 2000,
			'category'   => array( $term->slug ),
			'return'     => 'ids',
		)
	);
	$found = $ids ? wp_get_object_terms( $ids, $tax, array( 'fields' => 'ids' ) ) : array();
	$terms = array();
	if ( $found && ! is_wp_error( $found ) ) {
		$terms = get_terms(
			array(
				'taxonomy'   => $tax,
				'include'    => array_unique( array_map( 'intval', $found ) ),
				'hide_empty' => false,
				'orderby'    => 'menu_order',
			)
		);
		$terms = is_wp_error( $terms ) ? array() : array_map(
			function ( $t ) {
				return array( 'slug' => $t->slug, 'name' => $t->name, 'id' => $t->term_id );
			},
			$terms
		);
	}
	set_transient( $key, $terms, 12 * HOUR_IN_SECONDS );
	return $terms;
}

/* Zmiana produktów lub atrybutów czyści listy filtrów. */
foreach ( array( 'woocommerce_update_product', 'woocommerce_new_product', 'edited_term', 'created_term' ) as $fx_hook ) {
	add_action( $fx_hook, 'fx_filters_flush' );
}
function fx_filters_flush() {
	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_fx\\_flt\\_%' OR option_name LIKE '\\_transient\\_timeout\\_fx\\_flt\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

function fx_filter_param( $tax ) {
	return 'filter_' . wc_attribute_taxonomy_slug( $tax );
}

function fx_filter_selected( $tax ) {
	$p = fx_filter_param( $tax );
	return isset( $_GET[ $p ] ) ? array_filter( array_map( 'sanitize_title', explode( ',', wp_unslash( $_GET[ $p ] ) ) ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification
}

/**
 * Adres z przełączoną wartością filtra (bez numeru strony).
 */
function fx_filter_toggle_url( $tax, $slug ) {
	$sel = fx_filter_selected( $tax );
	$sel = in_array( $slug, $sel, true ) ? array_values( array_diff( $sel, array( $slug ) ) ) : array_merge( $sel, array( $slug ) );
	$url = remove_query_arg( array( fx_filter_param( $tax ), 'paged', 'query_type_' . wc_attribute_taxonomy_slug( $tax ) ), fx_filter_base_url() );
	return $sel ? add_query_arg( fx_filter_param( $tax ), implode( ',', $sel ), $url ) : $url;
}

function fx_filter_base_url() {
	$url = get_term_link( get_queried_object() );
	foreach ( $_GET as $k => $v ) { // phpcs:ignore WordPress.Security.NonceVerification
		if ( 0 === strpos( $k, 'filter_' ) || 'orderby' === $k ) {
			$url = add_query_arg( sanitize_key( $k ), rawurlencode( sanitize_text_field( wp_unslash( $v ) ) ), $url );
		}
	}
	return $url;
}

add_action( 'woocommerce_before_shop_loop', 'fx_filters_bar', 16 );
function fx_filters_bar() {
	if ( ! is_product_category() ) {
		return;
	}
	$term   = get_queried_object();
	$taxes  = fx_filter_taxonomies();
	$groups = array();
	foreach ( array( 'size' => 'Rozmiar', 'color' => 'Kolor' ) as $role => $label ) {
		if ( empty( $taxes[ $role ] ) ) {
			continue;
		}
		$terms = fx_filter_terms_in_cat( $taxes[ $role ], $term );
		if ( count( $terms ) > 1 || fx_filter_selected( $taxes[ $role ] ) ) {
			$groups[] = array( $role, $label, $taxes[ $role ], $terms );
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
		list( $role, $label, $tax, $terms ) = $g;
		$sel = fx_filter_selected( $tax );
		echo '<details class="fx-filter fx-filter--' . esc_attr( $role ) . '"><summary class="fx-chip' . ( $sel ? ' is-active' : '' ) . '">' . esc_html( $label ) . ( $sel ? ' (' . count( $sel ) . ')' : '' ) . fx_icon( 'chevron', 16 ) . '</summary><div class="fx-filter__panel">'; // phpcs:ignore
		foreach ( $terms as $t ) {
			$on    = in_array( $t['slug'], $sel, true );
			$color = 'color' === $role ? get_term_meta( $t['id'], 'fx_color', true ) : '';
			$style = '';
			if ( $color ) {
				$c     = explode( ',', $color );
				$style = ' style="--fx-sw:' . esc_attr( count( $c ) > 1 ? 'linear-gradient(135deg,' . $c[0] . ' 50%,' . $c[1] . ' 50%)' : $c[0] ) . '"';
			}
			echo '<a rel="nofollow" class="fx-filter__opt' . ( $on ? ' is-on' : '' ) . ( $color ? ' has-swatch' : '' ) . '" href="' . esc_url( fx_filter_toggle_url( $tax, $t['slug'] ) ) . '" aria-pressed="' . ( $on ? 'true' : 'false' ) . '"' . $style . '>' . esc_html( $t['name'] ) . '</a>'; // phpcs:ignore
			if ( $on ) {
				$active[] = array( $t['name'], fx_filter_toggle_url( $tax, $t['slug'] ) );
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

/* =====================================================================
 * Włącznik „Promocje”: ?filter_promocja=1 – tylko produkty w promocji.
 * Nazwa zaczyna się od filter_, więc działa z resztą filtrów, paginacją, nieskończonym
 * przewijaniem i noindex jak pozostałe filtry.
 * ===================================================================== */
function fx_filter_sale_on() {
	return ! empty( $_GET['filter_promocja'] ); // phpcs:ignore WordPress.Security.NonceVerification
}

function fx_filter_sale_url() {
	$url = remove_query_arg( array( 'filter_promocja', 'paged' ), fx_filter_base_url() );
	return fx_filter_sale_on() ? $url : add_query_arg( 'filter_promocja', '1', $url );
}

/** Czy w kategorii (z podkategoriami) jest choć jeden produkt w promocji – żeby nie pokazywać pustego włącznika. */
function fx_filter_sale_in_cat( $term ) {
	$key   = 'fx_flt_sale_' . $term->term_id;
	$cache = get_transient( $key );
	if ( false !== $cache ) {
		return (bool) $cache;
	}
	$sale = wc_get_product_ids_on_sale();
	$has  = 0;
	if ( $sale ) {
		$q   = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'post__in'       => $sale,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'tax_query'      => array( array( 'taxonomy' => 'product_cat', 'terms' => $term->term_id, 'include_children' => true ) ), // phpcs:ignore
			)
		);
		$has = $q->posts ? 1 : 0;
	}
	set_transient( $key, $has, HOUR_IN_SECONDS );
	return (bool) $has;
}

add_action( 'woocommerce_product_query', function ( $q ) {
	if ( ! fx_filter_sale_on() ) {
		return;
	}
	$ids = array_map( 'intval', wc_get_product_ids_on_sale() );
	$cur = (array) $q->get( 'post__in' );
	if ( $cur ) {
		$ids = array_values( array_intersect( $ids, array_map( 'intval', $cur ) ) );
	}
	$q->set( 'post__in', $ids ? $ids : array( 0 ) );
} );

/* Strony z filtrami nie trafiają do indeksu Google (oryginalna kategoria zostaje bez zmian). */
add_filter( 'wp_robots', function ( $robots ) {
	foreach ( array_keys( $_GET ) as $k ) { // phpcs:ignore WordPress.Security.NonceVerification
		if ( 0 === strpos( $k, 'filter_' ) ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
			unset( $robots['index'] );
			break;
		}
	}
	return $robots;
}, 99 );
