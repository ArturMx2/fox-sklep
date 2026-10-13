<?php
/**
 * Przyciski wariantów.
 *
 * Wszystkie atrybuty wariantów pokazują się jako przyciski zamiast listy rozwijanej.
 * Atrybuty z ustawionym kolorem (Produkty → Atrybuty → np. Kolor → edycja wartości → „Kolor próbki”)
 * pokazują się jako kółka w danym kolorze. Lista rozwijana Woo zostaje ukryta w kodzie,
 * więc logika wariantów WooCommerce działa bez zmian.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

/* ---------- Pole koloru przy wartościach atrybutów ---------- */
add_action( 'admin_init', function () {
	if ( ! function_exists( 'wc_get_attribute_taxonomy_names' ) ) {
		return;
	}
	foreach ( wc_get_attribute_taxonomy_names() as $tax ) {
		add_action( "{$tax}_add_form_fields", 'fx_swatch_add_field' );
		add_action( "{$tax}_edit_form_fields", 'fx_swatch_edit_field' );
		add_action( "created_{$tax}", 'fx_swatch_save' );
		add_action( "edited_{$tax}", 'fx_swatch_save' );
	}
} );

function fx_swatch_add_field() {
	echo '<div class="form-field"><label for="fx_color">Kolor próbki</label><input type="text" name="fx_color" id="fx_color" placeholder="' . esc_attr( fx_color_from_name( $term->name ) ? 'automatycznie: ' . fx_color_from_name( $term->name ) : '#000000 albo #000000,#ff0000' ) . '"><p>Opcjonalnie. Jeden kolor lub dwa po przecinku (dwukolorowe kółko). Puste = kolor rozpoznany z nazwy (np. „Czarny”, „Fluo Yellow”, „Czarno-biały”), a gdy nazwy nie da się rozpoznać – przycisk z nazwą.</p></div>';
}

function fx_swatch_edit_field( $term ) {
	$val = get_term_meta( $term->term_id, 'fx_color', true );
	echo '<tr class="form-field"><th scope="row"><label for="fx_color">Kolor próbki</label></th><td><input type="text" name="fx_color" id="fx_color" value="' . esc_attr( (string) $val ) . '" placeholder="#000000 albo #000000,#ff0000"><p class="description">Jeden kolor lub dwa po przecinku. Puste = kolor rozpoznany z nazwy (np. „Czarny”, „Fluo Yellow”, „Czarno-biały”), a gdy nazwy nie da się rozpoznać – przycisk z nazwą.</p></td></tr>';
}

function fx_swatch_save( $term_id ) {
	if ( ! isset( $_POST['fx_color'] ) || ! current_user_can( 'manage_product_terms' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$colors = array_filter( array_map( 'sanitize_hex_color', array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( $_POST['fx_color'] ) ) ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
	if ( $colors ) {
		update_term_meta( $term_id, 'fx_color', implode( ',', array_slice( $colors, 0, 2 ) ) );
	} else {
		delete_term_meta( $term_id, 'fx_color' );
	}
}

/* ---------- Dane kolorów dla skryptu na karcie produktu ---------- */
add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_product() ) {
		return;
	}
	$product = wc_get_product( get_queried_object_id() );
	if ( ! $product || ! $product->is_type( 'variable' ) ) {
		return;
	}
	$map = array();
	foreach ( $product->get_variation_attributes() as $tax => $values ) {
		if ( ! taxonomy_exists( $tax ) ) {
			continue;
		}
		foreach ( wc_get_product_terms( $product->get_id(), $tax, array( 'fields' => 'all' ) ) as $t ) {
			$c = fx_term_color( $t );
			if ( $c ) {
				$map[ 'attribute_' . sanitize_title( $tax ) ][ $t->slug ] = explode( ',', $c );
			}
		}
	}
	wp_localize_script( 'fx-theme', 'fxSwatches', array( 'colors' => (object) $map ) );
}, 20 );

/* =====================================================================
 * Kolor próbki z nazwy (gdy nie wpisano go ręcznie)
 * „Czarny”, „Black”, „Fluo Yellow”, „Czarno-biały”, „Black/White” → kolor albo dwa kolory (pół na pół).
 * Ręcznie wpisany kolor w atrybucie zawsze ma pierwszeństwo.
 * ===================================================================== */
function fx_color_names() {
	return apply_filters(
		'fx_color_names',
		array(
			// fluo przed zwykłymi
			'fluo yellow' => '#D7F700', 'fluo żółt' => '#D7F700', 'fluo zolt' => '#D7F700', 'flo yellow' => '#D7F700', 'neon yellow' => '#D7F700',
			'fluo orange' => '#FF5F1F', 'fluo pomarańcz' => '#FF5F1F', 'fluorescent orange' => '#FF5F1F', 'flo orange' => '#FF5F1F',
			'fluo red' => '#FF2A3D', 'fluo czerwon' => '#FF2A3D', 'flo red' => '#FF2A3D',
			'fluo pink' => '#FF3FA4', 'fluo róż' => '#FF3FA4', 'flo pink' => '#FF3FA4',
			'fluo green' => '#39FF14', 'fluo zielon' => '#39FF14',
			'light blue' => '#7CC4F2', 'jasnoniebiesk' => '#7CC4F2', 'błękit' => '#7CC4F2', 'blekit' => '#7CC4F2', 'sky' => '#7CC4F2',
			'dark grey' => '#4A4A4A', 'dark gray' => '#4A4A4A', 'ciemnoszar' => '#4A4A4A', 'grafit' => '#3A3A3A', 'charcoal' => '#3A3A3A', 'steel' => '#71797E',
			'light grey' => '#C8C8C8', 'light gray' => '#C8C8C8', 'jasnoszar' => '#C8C8C8',
			'dark blue' => '#1F2A44', 'ciemnoniebiesk' => '#1F2A44', 'granat' => '#1F2A44', 'navy' => '#1F2A44', 'midnight' => '#1F2A44',
			'czarn' => '#111111', 'black' => '#111111', 'blk' => '#111111',
			'biał' => '#FFFFFF', 'bial' => '#FFFFFF', 'white' => '#FFFFFF', 'wht' => '#FFFFFF',
			'czerwon' => '#D32F2F', 'red' => '#D32F2F',
			'bordow' => '#7B1F2B', 'maroon' => '#7B1F2B', 'burgundy' => '#7B1F2B', 'wine' => '#7B1F2B',
			'niebiesk' => '#1E5BD8', 'blue' => '#1E5BD8', 'cobalt' => '#1E5BD8',
			'turkus' => '#00A3A1', 'teal' => '#00897B', 'aqua' => '#00B5C8', 'cyan' => '#00B5C8',
			'zielon' => '#2E7D32', 'green' => '#2E7D32',
			'oliwk' => '#6B6B2A', 'olive' => '#6B6B2A', 'khaki' => '#8A8456', 'army' => '#4B5320', 'moss' => '#5A6B2F', 'sage' => '#9CAF88',
			'limonk' => '#9CCC00', 'lime' => '#9CCC00', 'mięt' => '#98E2C6', 'miet' => '#98E2C6', 'mint' => '#98E2C6',
			'żółt' => '#F5D000', 'zolt' => '#F5D000', 'yellow' => '#F5D000',
			'złot' => '#C9A227', 'zlot' => '#C9A227', 'gold' => '#C9A227',
			'pomarańcz' => '#FF7A00', 'pomarancz' => '#FF7A00', 'orange' => '#FF7A00', 'tangerine' => '#FF8C1A', 'rust' => '#B7410E', 'copper' => '#B87333',
			'róż' => '#E85D9E', 'roz' => '#E85D9E', 'pink' => '#E85D9E', 'magenta' => '#D1007A', 'fuksj' => '#D1007A',
			'fiolet' => '#7B3FB0', 'purple' => '#7B3FB0', 'purpur' => '#7B3FB0', 'lila' => '#B39DDB', 'lilac' => '#B39DDB', 'lawend' => '#B39DDB', 'lavender' => '#B39DDB', 'lava' => '#7B3FB0',
			'szar' => '#8A8A8A', 'grey' => '#8A8A8A', 'gray' => '#8A8A8A', 'heather' => '#9A9A9A',
			'srebr' => '#C0C0C0', 'silver' => '#C0C0C0', 'chrome' => '#C0C0C0',
			'brąz' => '#7A4A2A', 'braz' => '#7A4A2A', 'brown' => '#7A4A2A', 'chocolate' => '#5A3825', 'tan' => '#C29B6C',
			'beż' => '#D9C7A6', 'bez' => '#D9C7A6', 'beige' => '#D9C7A6', 'sand' => '#D2B48C', 'piask' => '#D2B48C', 'krem' => '#F3E9D2', 'cream' => '#F3E9D2', 'bone' => '#E3DAC9', 'natural' => '#E8DCC4', 'ecru' => '#E8DCC4',
			'moro' => '#5B5E3C', 'camo' => '#5B5E3C',
		)
	);
}

/** Kolor (#hex albo „#a,#b”) z nazwy wartości atrybutu albo ''. */
function fx_color_from_name( $name ) {
	$name = mb_strtolower( trim( wp_strip_all_tags( (string) $name ) ), 'UTF-8' );
	if ( '' === $name ) {
		return '';
	}
	$map = fx_color_names();
	$one = function ( $part ) use ( $map ) {
		$part = trim( $part );
		$best = '';
		$pos  = PHP_INT_MAX;
		$len  = 0;
		foreach ( $map as $k => $hex ) {
			// Nazwa koloru jako początek słowa; wygrywa ta, która stoi najwcześniej (np. „Olive Green” → oliwkowy).
			if ( preg_match( '/(^|[^\p{L}])' . preg_quote( $k, '/' ) . '/u', $part, $m, PREG_OFFSET_CAPTURE ) ) {
				$at = $m[0][1] + strlen( $m[1][0] );
				if ( $at < $pos || ( $at === $pos && strlen( $k ) > $len ) ) {
					$best = $hex;
					$pos  = $at;
					$len  = strlen( $k );
				}
			}
		}
		return $best;
	};
	// Dwa kolory: „czarno-biały”, „black/white”, „czarny i czerwony”, „black & red”.
	$name  = preg_replace( '/\b(czarno|biało|bialo|czerwono|niebiesko|zielono|żółto|zolto|szaro|granatowo|pomarańczowo|różowo|fioletowo)-/u', '$1 / ', $name );
	$name  = str_replace( array( 'czarno', 'biało', 'bialo', 'czerwono', 'niebiesko', 'zielono', 'żółto', 'zolto', 'szaro', 'granatowo', 'pomarańczowo', 'różowo', 'fioletowo' ), array( 'czarny', 'biały', 'biały', 'czerwony', 'niebieski', 'zielony', 'żółty', 'żółty', 'szary', 'granatowy', 'pomarańczowy', 'różowy', 'fioletowy' ), $name );
	$parts = preg_split( '/\s*(?:\/|\\\\|\||&|\+|,|\si\s|\sand\s)\s*/u', $name );
	// Bez separatora, ale każde słowo to kolor („Grey Yellow”, „Black Blue”) – też dwa kolory.
	if ( 1 === count( $parts ) ) {
		$words = preg_split( '/\s+/u', trim( $name ) );
		if ( count( $words ) >= 2 && count( array_filter( array_map( $one, $words ) ) ) === count( $words ) ) {
			$parts = $words;
		}
	}
	$cols  = array();
	foreach ( $parts as $p ) {
		$c = $one( $p );
		if ( $c && ! in_array( $c, $cols, true ) ) {
			$cols[] = $c;
		}
	}
	if ( ! $cols ) {
		$c = $one( $name );
		return $c ? $c : '';
	}
	return implode( ',', array_slice( $cols, 0, 2 ) );
}

/** Kolor próbki wartości atrybutu: ręczny z panelu, a gdy brak – z nazwy (tylko atrybuty kolorów). */
function fx_term_color( $term ) {
	if ( ! $term instanceof WP_Term ) {
		return '';
	}
	$c = get_term_meta( $term->term_id, 'fx_color', true );
	if ( $c ) {
		return $c;
	}
	$color_taxes = function_exists( 'fx_filter_taxonomies' ) ? fx_filter_taxonomies()['color'] : array();
	if ( ! in_array( $term->taxonomy, $color_taxes, true ) ) {
		return '';
	}
	return fx_color_from_name( $term->name );
}
