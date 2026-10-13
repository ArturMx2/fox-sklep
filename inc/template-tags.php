<?php
/**
 * Funkcje pomocnicze.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ustawienie z Personalizacji z wartością domyślną z rejestru.
 */
function fx_mod( $key, $default = null ) {
	if ( null === $default ) {
		$defaults = fx_defaults();
		$default  = isset( $defaults[ $key ] ) ? $defaults[ $key ] : '';
	}
	return get_theme_mod( 'fx_' . $key, $default );
}

/**
 * Obraz z biblioteki mediów (ID) albo pusty blok-zastępnik.
 */
function fx_media( $id, $size = 'large', $class = 'fx-cover', $alt = '' ) {
	$id = absint( $id );
	if ( $id && wp_attachment_is_image( $id ) ) {
		$attr = array( 'class' => $class, 'loading' => 'lazy', 'decoding' => 'async' );
		if ( '' !== $alt ) {
			$attr['alt'] = $alt;
		}
		return wp_get_attachment_image( $id, $size, false, $attr );
	}
	return '<span class="' . esc_attr( $class ) . ' fx-ph" aria-hidden="true"></span>';
}

/**
 * Linie "Etykieta | wartość" na tablicę par.
 */
function fx_lines( $text ) {
	$out = array();
	foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
		$line = trim( $line );
		if ( '' === $line ) {
			continue;
		}
		$parts = array_map( 'trim', explode( '|', $line, 2 ) );
		$out[] = array( $parts[0], isset( $parts[1] ) ? $parts[1] : '' );
	}
	return $out;
}

/**
 * Ikony (obrys, currentColor).
 */
function fx_icon( $name, $size = 22 ) {
	$paths = array(
		'menu'    => '<path d="M4 7h16M4 12h16M4 17h16"/>',
		'close'   => '<path d="M6 6l12 12M18 6 6 18"/>',
		'search'  => '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
		'user'    => '<circle cx="12" cy="12" r="9.5"/><circle cx="12" cy="10" r="3.2"/><path d="M6.5 18.5c1.4-2.2 3.3-3.3 5.5-3.3s4.1 1.1 5.5 3.3"/>',
		'bag'     => '<path d="M6 8h12l-1 12H7z"/><path d="M9 8a3 3 0 0 1 6 0"/>',
		'chevron' => '<path d="m6 9 6 6 6-6"/>',
		'filter'  => '<path d="M4 6h16M7 12h10M10 18h4"/>',
		'truck'   => '<path d="M3 7h11v9H3z"/><path d="M14 10h4l3 3v3h-7z"/><circle cx="7" cy="18" r="2"/><circle cx="17" cy="18" r="2"/>',
		'pin'     => '<path d="M12 21s-7-6-7-11a7 7 0 0 1 14 0c0 5-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/>',
		'return'  => '<path d="M4 12a8 8 0 1 0 3-6.2"/><path d="M4 4v4h4"/>',
		'phone'   => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/>',
	);
	if ( ! isset( $paths[ $name ] ) ) {
		return '';
	}
	return '<svg width="' . (int) $size . '" height="' . (int) $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths[ $name ] . '</svg>';
}

/**
 * Bezpieczne wyświetlenie HTML z treści sklepu.
 */
function fx_rich( $html ) {
	return wpautop( do_shortcode( wp_kses_post( $html ) ) );
}

/**
 * Okruszki: Rank Math / Yoast, jeśli są włączone, inaczej WooCommerce.
 * Dzięki temu zostaje ten sam schemat danych strukturalnych co obecnie.
 */
function fx_breadcrumb() {
	if ( function_exists( 'rank_math_the_breadcrumbs' ) && class_exists( '\\RankMath\\Helper' ) && method_exists( '\\RankMath\\Helper', 'get_settings' ) && \RankMath\Helper::get_settings( 'general.breadcrumbs' ) ) {
		rank_math_the_breadcrumbs();
		return;
	}
	if ( function_exists( 'yoast_breadcrumb' ) ) {
		$opts = get_option( 'wpseo_titles' );
		if ( is_array( $opts ) && ! empty( $opts['breadcrumbs-enable'] ) ) {
			yoast_breadcrumb( '<nav class="fx-breadcrumb" aria-label="Okruszki">', '</nav>' );
			return;
		}
	}
	if ( function_exists( 'woocommerce_breadcrumb' ) ) {
		woocommerce_breadcrumb(
			array(
				'delimiter'   => ' <span aria-hidden="true">/</span> ',
				'wrap_before' => '<nav class="woocommerce-breadcrumb" aria-label="Okruszki">',
				'wrap_after'  => '</nav>',
				'home'        => 'Strona główna',
			)
		);
	}
}

/**
 * Menu zastępcze, zanim przypiszesz menu do lokalizacji „Menu główne”:
 * główne kategorie produktów (te same adresy co dotychczas).
 */
function fx_fallback_menu( $class ) {
	$items = array();
	if ( taxonomy_exists( 'product_cat' ) ) {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'parent'     => 0,
				'hide_empty' => true,
				'exclude'    => array( (int) get_option( 'default_product_cat' ) ),
			)
		);
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $t ) {
				$items[] = array( $t->name, get_term_link( $t ) );
			}
		}
	}
	if ( ! $items ) {
		return;
	}
	echo '<nav class="' . esc_attr( $class ) . '" aria-label="Menu główne"><ul>';
	foreach ( $items as $it ) {
		echo '<li><a href="' . esc_url( $it[1] ) . '">' . esc_html( $it[0] ) . '</a></li>';
	}
	echo '</ul></nav>';
}

/**
 * Czy strona jest zbudowana w Elementorze (wtedy nie dokładamy własnego H1).
 */
function fx_is_elementor( $post_id = 0 ) {
	$post_id = $post_id ? $post_id : get_the_ID();
	return $post_id && 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true ) && did_action( 'elementor/loaded' );
}
