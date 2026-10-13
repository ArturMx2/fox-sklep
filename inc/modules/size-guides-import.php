<?php
/**
 * Import tabel rozmiarów z CommerceKit (i podobnych wtyczek) do tabel motywu.
 *
 * Narzędzia → Import tabel rozmiarów. Najpierw podgląd (nic nie zmienia), potem „Importuj”.
 * Działa także po wyłączeniu CommerceKit – dane wtyczki zostają w bazie.
 * Przypisania rozpoznajemy po zawartości pól: listy ID kategorii → kategorie, listy ID produktów → produkty.
 * Ponowny import aktualizuje wcześniej zaimportowane tabele (bez duplikatów).
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', function () {
	add_management_page( 'Import tabel rozmiarów', 'Import tabel rozmiarów', 'manage_woocommerce', 'fx-sg-import', 'fx_sg_import_page' );
} );

/** Typy wpisów, w których wtyczki trzymają tabele rozmiarów. */
function fx_sg_source_types() {
	global $wpdb;
	$types = $wpdb->get_col( "SELECT DISTINCT post_type FROM {$wpdb->posts} WHERE ( post_type LIKE '%size%' OR post_type LIKE 'ckit%' OR post_type LIKE '%sizeguide%' ) AND post_type <> 'fx_size_guide'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return array_values( array_filter( (array) $types ) );
}

/** Liczby całkowite z wartości pola (tablica, serializacja, JSON, lista po przecinku). */
function fx_sg_ints( $value ) {
	$value = maybe_unserialize( $value );
	if ( is_string( $value ) && '' !== $value && ( '[' === $value[0] || '{' === $value[0] ) ) {
		$j = json_decode( $value, true );
		if ( null !== $j ) {
			$value = $j;
		}
	}
	$out = array();
	if ( is_array( $value ) ) {
		array_walk_recursive(
			$value,
			function ( $v ) use ( &$out ) {
				$out = array_merge( $out, fx_sg_ints( $v ) );
			}
		);
	} elseif ( is_scalar( $value ) && preg_match_all( '/\b\d+\b/', (string) $value, $m ) ) {
		$out = array_map( 'intval', $m[0] );
	}
	return array_values( array_unique( array_filter( $out ) ) );
}

/** Wpisy w ustawieniach wtyczek (np. CommerceKit), które wskazują tę tabelę – do diagnostyki. */
function fx_sg_option_refs( $id ) {
	static $opts = null;
	if ( null === $opts ) {
		global $wpdb;
		$opts = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE '%commercekit%' OR option_name LIKE '%ckit%' OR option_name LIKE '%size_guide%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
	$out  = array();
	$walk = function ( $val, $path ) use ( &$walk, &$out, $id ) {
		if ( is_array( $val ) ) {
			foreach ( $val as $k => $v ) {
				$walk( $v, $path . '[' . $k . ']' );
			}
		} elseif ( is_scalar( $val ) && preg_match( '/(^|[^0-9])' . (int) $id . '([^0-9]|$)/', (string) $val ) && preg_match( '/size|guide|sg/i', $path ) ) {
			$out[] = 'opcja ' . $path . ' = ' . mb_substr( (string) $val, 0, 160 );
		}
	};
	foreach ( (array) $opts as $o ) {
		$walk( maybe_unserialize( $o->option_value ), $o->option_name );
	}
	return array_slice( $out, 0, 8 );
}

/**
 * Plan importu: [ [ 'src' => WP_Post, 'cats' => [], 'products' => [], 'existing' => id|0 ], … ].
 */
function fx_sg_import_plan() {
	global $wpdb;
	$plan = array();
	foreach ( fx_sg_source_types() as $type ) {
		$posts = get_posts( array( 'post_type' => $type, 'post_status' => array( 'publish', 'private', 'draft' ), 'numberposts' => 500 ) );
		foreach ( $posts as $src ) {
			$cats  = array();
			$prods = array();
			foreach ( get_post_meta( $src->ID ) as $key => $vals ) {
				if ( 0 === strpos( $key, '_edit' ) || '_wp_old_slug' === $key || '_thumbnail_id' === $key ) {
					continue;
				}
				$ints = array();
				foreach ( $vals as $v ) {
					$ints = array_merge( $ints, fx_sg_ints( $v ) );
				}
				if ( ! $ints ) {
					continue;
				}
				$is_cat  = 0;
				$is_prod = 0;
				foreach ( $ints as $i ) {
					$t = get_term( $i, 'product_cat' );
					if ( $t instanceof WP_Term ) {
						$is_cat++;
					}
					$pt = get_post_type( $i );
					if ( in_array( $pt, array( 'product', 'product_variation' ), true ) ) {
						$is_prod++;
					}
				}
				$hint_cat  = (bool) preg_match( '/cat|term|tax/i', $key );
				$hint_prod = (bool) preg_match( '/prod|item/i', $key );
				if ( $is_cat && ( $hint_cat || ( ! $hint_prod && $is_cat >= $is_prod && $is_cat >= count( $ints ) / 2 ) ) ) {
					$cats = array_merge( $cats, array_filter( $ints, function ( $i ) { return get_term( $i, 'product_cat' ) instanceof WP_Term; } ) );
				} elseif ( $is_prod && ( $hint_prod || $is_prod >= count( $ints ) / 2 ) ) {
					foreach ( $ints as $i ) {
						$pt = get_post_type( $i );
						if ( 'product_variation' === $pt ) {
							$i = wp_get_post_parent_id( $i );
						}
						if ( 'product' === get_post_type( $i ) ) {
							$prods[] = $i;
						}
					}
				}
			}
			// Produkty, które same wskazują tę tabelę w swoim polu (np. „size guide” w edycji produktu).
			$linked = $wpdb->get_col( $wpdb->prepare( "SELECT pm.post_id FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'product' WHERE pm.meta_value = %s AND ( pm.meta_key LIKE %s OR pm.meta_key LIKE %s OR pm.meta_key LIKE %s ) AND pm.meta_key <> '_fx_size_guide'", (string) $src->ID, '%size%', '%sg%', '%ckit%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$prods  = array_merge( $prods, array_map( 'intval', $linked ) );

			$existing = get_posts( array( 'post_type' => 'fx_size_guide', 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_fx_imported_from', 'meta_value' => $src->ID ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
			$plan[]   = array(
				'src'      => $src,
				'cats'     => array_values( array_unique( array_map( 'intval', $cats ) ) ),
				'products' => array_values( array_unique( array_map( 'intval', $prods ) ) ),
				'existing' => $existing ? (int) $existing[0] : 0,
			);
		}
	}
	return $plan;
}

function fx_sg_import_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	$done = null;
	if ( isset( $_POST['fx_sg_import'] ) && check_admin_referer( 'fx_sg_import' ) ) {
		$done = 0;
		foreach ( fx_sg_import_plan() as $row ) {
			$src  = $row['src'];
			$data = array(
				'post_type'    => 'fx_size_guide',
				'post_status'  => 'publish' === $src->post_status ? 'publish' : 'draft',
				'post_title'   => $src->post_title ? $src->post_title : 'Tabela rozmiarów ' . $src->ID,
				'post_content' => $src->post_content,
				'post_name'    => $src->post_name, // ten sam adres: /ckit_size_guide/nazwa/
			);
			if ( $row['existing'] ) {
				$data['ID'] = $row['existing'];
				$gid        = wp_update_post( $data );
			} else {
				$gid = wp_insert_post( $data );
			}
			if ( ! $gid || is_wp_error( $gid ) ) {
				continue;
			}
			update_post_meta( $gid, '_fx_imported_from', $src->ID );
			// Tytuł i opis SEO (Yoast / Rank Math) przenosimy razem z tabelą.
			foreach ( get_post_meta( $src->ID ) as $mk => $mv ) {
				if ( preg_match( '/^_(yoast_wpseo_|rank_math_)/', $mk ) && isset( $mv[0] ) ) {
					update_post_meta( $gid, $mk, maybe_unserialize( $mv[0] ) );
				}
			}
			update_post_meta( $gid, '_fx_cats', $row['cats'] );
			update_post_meta( $gid, '_fx_products', $row['products'] );
			$done++;
		}
		delete_transient( 'fx_sg_map' );
	}
	$plan = fx_sg_import_plan();
	echo '<div class="wrap"><h1>Import tabel rozmiarów</h1>';
	if ( null !== $done ) {
		echo '<div class="notice notice-success"><p>Zaimportowano tabel: ' . (int) $done . '. Lista: <a href="' . esc_url( admin_url( 'edit.php?post_type=fx_size_guide' ) ) . '">Produkty → Tabele rozmiarów' . ( post_type_exists( 'fx_size_guide' ) && ! function_exists( 'fx_size_guide_for' ) ? ' (nowy motyw)' : '' ) . '</a>. CommerceKit wyłącz dopiero po włączeniu nowego motywu.</p></div>';
	}
	echo '<p>Narzędzie przenosi tabele rozmiarów z CommerceKit do motywu: treść tabeli, przypisane kategorie i produkty. Oryginały zostają nietknięte. Możesz importować wielokrotnie – wcześniej zaimportowane tabele zostaną zaktualizowane.</p>';
	if ( ! $plan ) {
		echo '<p><strong>Nie znaleziono tabel do importu.</strong> Typy wpisów sprawdzone: ' . esc_html( implode( ', ', fx_sg_source_types() ) ? implode( ', ', fx_sg_source_types() ) : 'brak' ) . '.</p></div>';
		return;
	}
	echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>Tabela (źródło)</th><th>Typ</th><th>Kategorie</th><th>Produkty</th><th>Status</th></tr></thead><tbody>';
	foreach ( $plan as $row ) {
		$cn = array();
		foreach ( $row['cats'] as $c ) {
			$t = get_term( $c, 'product_cat' );
			if ( $t instanceof WP_Term ) {
				$cn[] = $t->name;
			}
		}
		$pn = array();
		foreach ( array_slice( $row['products'], 0, 6 ) as $pid ) {
			$pn[] = get_the_title( $pid );
		}
		$more = count( $row['products'] ) > 6 ? ' … (+' . ( count( $row['products'] ) - 6 ) . ')' : '';
		// Brak przypisań: pokazujemy, gdzie wtyczka mogła je zapisać (pola tabeli i wpisy w ustawieniach wtyczki).
		$diag = '';
		if ( ! $row['cats'] && ! $row['products'] ) {
			$lines = array();
			foreach ( get_post_meta( $row['src']->ID ) as $mk => $mv ) {
				if ( 0 === strpos( $mk, '_edit' ) ) {
					continue;
				}
				$lines[] = $mk . ' = ' . mb_substr( (string) ( isset( $mv[0] ) ? $mv[0] : '' ), 0, 160 );
			}
			foreach ( fx_sg_option_refs( $row['src']->ID ) as $ref ) {
				$lines[] = $ref;
			}
			$diag = '<details><summary>Brak przypisań – szczegóły</summary><code style="white-space:pre-wrap;font-size:11px">' . esc_html( $lines ? implode( "\n", $lines ) : 'brak pól' ) . '</code></details>';
		}
		echo '<tr><td><strong>' . esc_html( $row['src']->post_title ) . '</strong>' . $diag . '</td><td><code>' . esc_html( $row['src']->post_type ) . '</code></td><td>' . esc_html( $cn ? implode( ', ', $cn ) : '—' ) . '</td><td>' . esc_html( $pn ? implode( ', ', $pn ) . $more : '—' ) . '</td><td>' . ( $row['existing'] ? 'zaimportowana – zostanie zaktualizowana' : 'nowa' ) . '</td></tr>';
	}
	echo '</tbody></table><form method="post" style="margin-top:16px">';
	wp_nonce_field( 'fx_sg_import' );
	echo '<button class="button button-primary" name="fx_sg_import" value="1">Importuj ' . count( $plan ) . ' tabel</button></form></div>';
}
