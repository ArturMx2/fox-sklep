<?php
/**
 * Kolory jednego modelu w listingu – „rodziny kolorów”.
 *
 * Każdy kolor to osobny produkt (własny adres, opis, zdjęcia – bez zmian dla SEO).
 * Produkty jednego modelu łączymy w rodzinę:
 * - automatycznie po SKU FOX: model-kolor (np. 31324-170 i 31324-002 → model 31324),
 * - ręcznie polem „Rodzina kolorów” w produkcie (ten sam tekst = jedna rodzina, „-” = wyłącz).
 *
 * W listingu karta dostaje miniatury kolorów: najechanie (lub dotknięcie na telefonie) podmienia
 * zdjęcie, nazwę, cenę, etykiety i rozmiary; kliknięcie prowadzi do produktu w tym kolorze.
 * Opcjonalnie (domyślnie włączone) w kategorii pokazujemy jedną kartę na model.
 * Na karcie produktu: „Kolor: …” z miniaturami pozostałych kolorów.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

/* ---------- Ustawienie: jedna karta na model ---------- */
add_action( 'customize_register', function ( $wp ) {
	$wp->add_setting( 'fx_t_cf_collapse', array( 'default' => false, 'sanitize_callback' => 'fx_sanitize_bool' ) );
	$wp->add_control(
		'fx_t_cf_collapse',
		array(
			'section'     => 'fx_tools',
			'label'       => 'Kolory: jedna karta na model w kategorii (pozostałe kolory jako miniatury)',
			'description' => 'Uwaga SEO: zmniejsza liczbę stron listy (/page/2/, /page/3/… znane Google) i przesuwa produkty między stronami. Na start zostaw wyłączone. Przy filtrach i w wyszukiwarce i tak widać każdy kolor osobno.',
			'type'        => 'checkbox',
		)
	);
}, 30 );

function fx_cf_collapse_on() {
	return (bool) get_theme_mod( 'fx_t_cf_collapse', false );
}

/* ---------- Klucz rodziny ---------- */
function fx_cf_key_from( $sku, $manual ) {
	$manual = trim( (string) $manual );
	if ( '-' === $manual ) {
		return '';
	}
	if ( '' !== $manual ) {
		return 'm:' . sanitize_title( $manual );
	}
	if ( preg_match( '/^(\d{4,6})-[A-Za-z0-9]{2,4}(?:-|$)/', trim( (string) $sku ), $m ) ) {
		return 's:' . $m[1];
	}
	return '';
}

/**
 * Mapa rodzin: [ 'groups' => [ klucz => [id, ...] ], 'of' => [ id => klucz ], 'stock' => [ id => bool ] ].
 * Tylko rodziny z co najmniej 2 opublikowanymi produktami. Cache do zmiany produktu.
 */
function fx_cf_map() {
	static $map = null;
	if ( null !== $map ) {
		return $map;
	}
	$map = get_transient( 'fx_cf_map' );
	if ( is_array( $map ) ) {
		return $map;
	}
	global $wpdb;
	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		"SELECT p.ID, sku.meta_value AS sku, man.meta_value AS man, st.meta_value AS stock
		 FROM {$wpdb->posts} p
		 LEFT JOIN {$wpdb->postmeta} sku ON sku.post_id = p.ID AND sku.meta_key = '_sku'
		 LEFT JOIN {$wpdb->postmeta} man ON man.post_id = p.ID AND man.meta_key = '_fx_color_group'
		 LEFT JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = '_stock_status'
		 WHERE p.post_type = 'product' AND p.post_status = 'publish'
		 ORDER BY p.menu_order ASC, p.ID ASC"
	);
	$groups = array();
	$stock  = array();
	foreach ( (array) $rows as $r ) {
		$k = fx_cf_key_from( $r->sku, $r->man );
		if ( '' === $k ) {
			continue;
		}
		$groups[ $k ][]           = (int) $r->ID;
		$stock[ (int) $r->ID ]    = ( 'outofstock' !== $r->stock );
	}
	$groups = array_filter(
		$groups,
		function ( $ids ) {
			return count( $ids ) > 1;
		}
	);
	$of = array();
	foreach ( $groups as $k => $ids ) {
		foreach ( $ids as $id ) {
			$of[ $id ] = $k;
		}
	}
	$map = array( 'groups' => $groups, 'of' => $of, 'stock' => array_intersect_key( $stock, $of ) );
	set_transient( 'fx_cf_map', $map, DAY_IN_SECONDS );
	return $map;
}

function fx_cf_flush() {
	delete_transient( 'fx_cf_map' );
	if ( function_exists( 'fx_filters_flush' ) ) {
		fx_filters_flush(); // także fx_flt_cg_* (karty na model w kategoriach)
	}
}
foreach ( array( 'woocommerce_update_product', 'woocommerce_new_product', 'woocommerce_product_set_stock_status', 'trashed_post', 'untrashed_post', 'deleted_post' ) as $fx_hook ) {
	add_action( $fx_hook, 'fx_cf_flush' );
}

/** Pozostałe produkty rodziny (z bieżącym), w stałej kolejności. */
function fx_cf_members( $product_id ) {
	$map = fx_cf_map();
	if ( empty( $map['of'][ $product_id ] ) ) {
		return array();
	}
	return $map['groups'][ $map['of'][ $product_id ] ];
}

/* ---------- Nazwa koloru: z końca nazwy (część, której nie mają wspólnej) ---------- */
function fx_cf_labels( $products ) {
	$names = array();
	foreach ( $products as $p ) {
		$names[ $p->get_id() ] = preg_split( '/\s+/u', trim( $p->get_name() ) );
	}
	$common = null;
	foreach ( $names as $w ) {
		if ( null === $common ) {
			$common = $w;
			continue;
		}
		$i = 0;
		while ( $i < count( $common ) && $i < count( $w ) && mb_strtolower( $common[ $i ] ) === mb_strtolower( $w[ $i ] ) ) {
			$i++;
		}
		$common = array_slice( $common, 0, $i );
	}
	$n   = $common ? count( $common ) : 0;
	$out = array();
	foreach ( $products as $p ) {
		$rest  = implode( ' ', array_slice( $names[ $p->get_id() ], $n ) );
		$color = $p->get_attribute( 'pa_kolor' );
		$label = '' !== $rest ? $rest : ( $color ? $color : $p->get_name() );
		$out[ $p->get_id() ] = mb_convert_case( mb_strtolower( $label ), MB_CASE_TITLE, 'UTF-8' );
	}
	return $out;
}

/**
 * Dane rodziny do wyświetlenia (tylko widoczne w katalogu).
 */
function fx_cf_data( $product_id ) {
	static $cache = array();
	$members = fx_cf_members( $product_id );
	if ( ! $members ) {
		return array();
	}
	$key = implode( ',', $members );
	if ( isset( $cache[ $key ] ) ) {
		return $cache[ $key ];
	}
	$products = array();
	foreach ( $members as $id ) {
		$p = wc_get_product( $id );
		if ( $p && $p->is_visible() ) {
			$products[] = $p;
		}
	}
	if ( count( $products ) < 2 ) {
		return $cache[ $key ] = array();
	}
	$labels = fx_cf_labels( $products );
	$out    = array();
	foreach ( $products as $p ) {
		$img_id = $p->get_image_id();
		$img    = $img_id ? wp_get_attachment_image_src( $img_id, 'woocommerce_thumbnail' ) : false;
		$thumb  = $img_id ? wp_get_attachment_image_src( $img_id, 'woocommerce_gallery_thumbnail' ) : false;
		$sizes  = function_exists( 'fx_available_sizes' ) ? fx_available_sizes( $p ) : array();
		$out[]  = array(
			'id'     => $p->get_id(),
			'url'    => $p->get_permalink(),
			'name'   => $p->get_name(),
			'label'  => $labels[ $p->get_id() ],
			'img'    => $img ? $img[0] : wc_placeholder_img_src( 'woocommerce_thumbnail' ),
			'srcset' => $img_id ? (string) wp_get_attachment_image_srcset( $img_id, 'woocommerce_thumbnail' ) : '',
			'thumb'  => $thumb ? $thumb[0] : wc_placeholder_img_src( 'woocommerce_gallery_thumbnail' ),
			'price'  => $p->get_price_html(),
			'badges' => function_exists( 'fx_badges_html' ) ? fx_badges_html( $p ) : '',
			'sizes'  => $sizes,
			'stock'  => $p->is_in_stock(),
		);
	}
	return $cache[ $key ] = $out;
}

/* ---------- Listing: miniatury kolorów pod kartą ---------- */
add_action( 'woocommerce_after_shop_loop_item', 'fx_cf_loop_swatches', 7 );
function fx_cf_loop_swatches() {
	global $product;
	if ( ! $product instanceof WC_Product ) {
		return;
	}
	$data = fx_cf_data( $product->get_id() );
	if ( ! $data ) {
		return;
	}
	$max   = 5;
	$json  = wp_json_encode( $data );
	$cur   = $product->get_id();
	echo '<div class="fx-cf" data-fx-cf="' . esc_attr( $json ) . '"><span class="screen-reader-text">Dostępne kolory:</span>';
	// Bieżący kolor pierwszy, potem pozostałe w stałej kolejności.
	usort(
		$data,
		function ( $a, $b ) use ( $cur ) {
			return ( $b['id'] === $cur ) - ( $a['id'] === $cur );
		}
	);
	foreach ( array_slice( $data, 0, $max ) as $d ) {
		$on = $d['id'] === $cur;
		echo '<a class="fx-cf__sw' . ( $on ? ' is-on' : '' ) . ( $d['stock'] ? '' : ' is-out' ) . '" href="' . esc_url( $d['url'] ) . '" data-id="' . (int) $d['id'] . '" title="' . esc_attr( $d['label'] ) . '" aria-label="' . esc_attr( 'Kolor: ' . $d['label'] ) . '"' . ( $on ? ' aria-current="true"' : '' ) . '><img src="' . esc_url( $d['thumb'] ) . '" alt="' . esc_attr( $product->get_name() . ' – ' . $d['label'] ) . '" width="40" height="40" loading="lazy" decoding="async"></a>';
	}
	if ( count( $data ) > $max ) {
		echo '<a class="fx-cf__more" href="' . esc_url( $product->get_permalink() ) . '#fx-colors" aria-label="Więcej kolorów">+' . ( count( $data ) - $max ) . '</a>';
	}
	echo '</div>';
}

/* ---------- Karta produktu: „Kolor: …” i pozostałe kolory ---------- */
add_action( 'woocommerce_single_product_summary', 'fx_cf_single', 25 );
function fx_cf_single() {
	global $product;
	if ( ! $product instanceof WC_Product ) {
		return;
	}
	$data = fx_cf_data( $product->get_id() );
	if ( ! $data ) {
		return;
	}
	$label = '';
	foreach ( $data as $d ) {
		if ( $d['id'] === $product->get_id() ) {
			$label = $d['label'];
		}
	}
	echo '<div class="fx-colors" id="fx-colors"><div class="fx-colors__label">Kolor: <strong>' . esc_html( $label ) . '</strong> <span>(' . count( $data ) . ' ' . esc_html( fx_pl( count( $data ), 'kolor', 'kolory', 'kolorów' ) ) . ')</span></div><div class="fx-colors__list">';
	foreach ( $data as $d ) {
		$on = $d['id'] === $product->get_id();
		echo '<a class="fx-colors__sw' . ( $on ? ' is-on' : '' ) . ( $d['stock'] ? '' : ' is-out' ) . '" href="' . esc_url( $d['url'] ) . '" title="' . esc_attr( $d['label'] . ( $d['stock'] ? '' : ' – niedostępny' ) ) . '"' . ( $on ? ' aria-current="page"' : '' ) . '><img src="' . esc_url( $d['thumb'] ) . '" alt="' . esc_attr( $d['name'] ) . '" width="64" height="64" loading="lazy" decoding="async"></a>';
	}
	echo '</div></div>';
}

/* ---------- Jedna karta na model w kategorii ---------- */
add_action( 'woocommerce_product_query', 'fx_cf_collapse_query', 30 );
function fx_cf_collapse_query( $q ) {
	if ( ! fx_cf_collapse_on() || is_search() || ! ( is_product_category() || is_product_tag() || is_shop() ) ) {
		return;
	}
	if ( function_exists( 'fx_filters_active' ) && fx_filters_active() ) {
		return; // przy filtrach pokazujemy każdy pasujący kolor
	}
	$hide = fx_cf_hidden_ids( get_queried_object() );
	if ( $hide ) {
		$q->set( 'post__not_in', array_merge( (array) $q->get( 'post__not_in' ), $hide ) );
	}
}

/**
 * Produkty do ukrycia w danej kategorii: z każdej rodziny zostaje jeden kolor
 * (dostępny na stanie, najnowszy), pozostałe są miniaturami na jego karcie.
 */
function fx_cf_hidden_ids( $obj ) {
	$map = fx_cf_map();
	if ( empty( $map['groups'] ) ) {
		return array();
	}
	$key = 'fx_flt_cg_' . ( $obj instanceof WP_Term ? $obj->term_id : 'shop' );
	$c   = get_transient( $key );
	if ( is_array( $c ) ) {
		return $c;
	}
	$in = null;
	if ( $obj instanceof WP_Term ) {
		$in = array_flip(
			array_map(
				'intval',
				get_posts(
					array(
						'post_type'      => 'product',
						'post_status'    => 'publish',
						'posts_per_page' => -1,
						'fields'         => 'ids',
						'no_found_rows'  => true,
						'tax_query'      => array( array( 'taxonomy' => $obj->taxonomy, 'terms' => $obj->term_id, 'include_children' => true ) ), // phpcs:ignore
					)
				)
			)
		);
	}
	$hide = array();
	foreach ( $map['groups'] as $ids ) {
		$here = null === $in ? $ids : array_values( array_filter( $ids, function ( $id ) use ( $in ) { return isset( $in[ $id ] ); } ) );
		if ( count( $here ) < 2 ) {
			continue;
		}
		// Reprezentant: dostępny na stanie, najnowszy (najwyższe ID).
		usort(
			$here,
			function ( $a, $b ) use ( $map ) {
				$sa = ! empty( $map['stock'][ $a ] );
				$sb = ! empty( $map['stock'][ $b ] );
				return $sa === $sb ? $b - $a : ( $sa ? -1 : 1 );
			}
		);
		$hide = array_merge( $hide, array_slice( $here, 1 ) );
	}
	set_transient( $key, $hide, 6 * HOUR_IN_SECONDS );
	return $hide;
}

/* ---------- Pole w produkcie ---------- */
add_action( 'woocommerce_product_options_sku', function () {
	global $post;
	$sku = get_post_meta( $post->ID, '_sku', true );
	$auto = fx_cf_key_from( $sku, '' );
	woocommerce_wp_text_input(
		array(
			'id'          => '_fx_color_group',
			'label'       => 'Rodzina kolorów',
			'placeholder' => $auto ? 'automatycznie: model ' . substr( $auto, 2 ) : 'brak (SKU bez numeru modelu)',
			'desc_tip'    => true,
			'description' => 'Produkty w różnych kolorach jednego modelu. Puste = automatycznie po SKU (31324-170 i 31324-002 → model 31324). Wpisz ten sam tekst w kilku produktach, żeby je połączyć (np. 31324 przy innym SKU). „-” = nie łącz z innymi.',
		)
	);
} );
add_action( 'woocommerce_admin_process_product_object', function ( $product ) {
	if ( isset( $_POST['_fx_color_group'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		$product->update_meta_data( '_fx_color_group', sanitize_text_field( wp_unslash( $_POST['_fx_color_group'] ) ) ); // phpcs:ignore
	}
} );
