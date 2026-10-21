<?php
/**
 * Menu główne budowane automatycznie z kategorii produktów.
 *
 * Zasada: pasek górny (kolejność i nazwy: CASUAL, MOTO, ROWER, PORADNIKI…) ustawiasz jak dotąd
 * w Wygląd → Menu. Pozycja prowadząca do kategorii produktów dostaje rozwijany panel zbudowany SAM
 * z podkategorii – tylko tych, w których jest coś do kupienia. Pozycje, które nie są kategoriami
 * (Poradniki, strony), zostają bez zmian.
 *
 * - kolumny = podkategorie 2. poziomu, pod nimi do 8 linków 3. poziomu („Więcej →” gdy jest ich więcej),
 *   4. poziom (np. Proframe, Rampage) tylko gdy jest ich mało,
 * - kolejność = kolejność kategorii z Produkty → Kategorie (przeciągnij i upuść), potem nazwa,
 * - nazwy w panelu skracane z powtórzeń („Koszulki rowerowe FOX” w ROWER → „Koszulki”),
 *   można je nadpisać w edycji kategorii („Nazwa w menu”) albo ukryć kategorię („Ukryj w menu”),
 * - pusta kategoria (same wyprzedane / ukryte produkty) znika – także z paska górnego,
 * - wynik w pamięci, odświeżany razem z pamięcią motywu (zmiany produktów / kategorii / menu).
 *
 * Włączenie: Dostosuj → FOX.SKLEP.PL → Menu główne. Podgląd przed włączeniem (tylko administrator):
 * dowolna strona z ?fx_menu=auto.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

/** Czy automatyczne menu działa na tej stronie. */
function fx_am_on() {
	if ( isset( $_GET['fx_menu'] ) && current_user_can( 'edit_theme_options' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return 'auto' === $_GET['fx_menu']; // phpcs:ignore WordPress.Security.NonceVerification
	}
	return (bool) fx_mod( 'menu_auto' );
}

/**
 * Liczba produktów do kupienia w każdej kategorii (z podkategoriami): [ term_id => n ].
 * Jedno zapytanie do bazy + sumowanie w górę drzewa.
 */
function fx_am_counts() {
	static $counts = null;
	if ( null !== $counts ) {
		return $counts;
	}
	$counts = get_transient( 'fx_am_counts' );
	if ( is_array( $counts ) ) {
		return $counts;
	}
	global $wpdb;
	// Liczą się tylko produkty do kupienia: widoczne w katalogu i dostępne (wyprzedane nie trzymają kategorii w menu).
	$hide = array( 'exclude-from-catalog' );
	if ( apply_filters( 'fx_am_skip_outofstock', true ) ) {
		$hide[] = 'outofstock';
	}
	$hidden_tt = array();
	foreach ( $hide as $slug ) {
		$t = get_term_by( 'slug', $slug, 'product_visibility' );
		if ( $t ) {
			$hidden_tt[] = (int) $t->term_taxonomy_id;
		}
	}
	$not = $hidden_tt ? 'AND p.ID NOT IN ( SELECT object_id FROM ' . $wpdb->term_relationships . ' WHERE term_taxonomy_id IN (' . implode( ',', $hidden_tt ) . ') )' : '';
	$rows = $wpdb->get_results( "SELECT tt.term_id, COUNT(DISTINCT p.ID) n FROM {$wpdb->posts} p JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat' WHERE p.post_type = 'product' AND p.post_status = 'publish' {$not} GROUP BY tt.term_id" ); // phpcs:ignore
	$own    = array();
	foreach ( (array) $rows as $r ) {
		$own[ (int) $r->term_id ] = (int) $r->n;
	}
	$counts = array();
	foreach ( $own as $tid => $n ) {
		$counts[ $tid ] = ( $counts[ $tid ] ?? 0 ) + $n;
		foreach ( get_ancestors( $tid, 'product_cat', 'taxonomy' ) as $a ) {
			$counts[ (int) $a ] = ( $counts[ (int) $a ] ?? 0 ) + $n;
		}
	}
	set_transient( 'fx_am_counts', $counts, 12 * HOUR_IN_SECONDS );
	return $counts;
}

/** Widoczne dzieci kategorii, w kolejności z panelu (Produkty → Kategorie). */
function fx_am_children( $parent ) {
	static $all = null;
	if ( null === $all ) {
		$all   = array();
		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'menu_order', 'order' => 'ASC' ) );
		foreach ( is_wp_error( $terms ) ? array() : $terms as $t ) {
			$all[ (int) $t->parent ][] = $t;
		}
	}
	$counts = fx_am_counts();
	$out    = array();
	foreach ( $all[ (int) $parent ] ?? array() as $t ) {
		if ( empty( $counts[ $t->term_id ] ) || get_term_meta( $t->term_id, 'fx_menu_hide', true ) ) {
			continue;
		}
		$out[] = $t;
	}
	return $out;
}

/** Nazwa w menu: „Nazwa w menu” z kategorii albo nazwa skrócona z powtórzeń. */
function fx_am_label( $t, $root_slug = '', $depth = 1 ) {
	$own = trim( (string) get_term_meta( $t->term_id, 'fx_menu_label', true ) );
	if ( '' !== $own ) {
		return $own;
	}
	$n = trim( html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ) );
	$n = trim( preg_replace( '/\s+fox\b/iu', '', $n ) );
	if ( 1 === $depth ) {
		// W kolumnach panelu słowa oczywiste z działu są zbędne: „Kaski rowerowe” w ROWER → „Kaski”.
		$ctx = array(
			'rowerowy' => '/\s+(rowerowe|rowerowa|rowerowy|na rower|mtb)$/iu',
			'off-road' => '/\s+(off\s*road|mx|motocross)$/iu',
			'wyprzedaz'=> '/\s*wyprzeda[żz]\w*$/iu',
		);
		if ( isset( $ctx[ $root_slug ] ) ) {
			$short = trim( preg_replace( $ctx[ $root_slug ], '', $n ) );
			$n     = '' !== $short ? $short : $n;
		}
	}
	// NAZWY WIELKIMI LITERAMI → zwykły zapis (poza krótkimi skrótami jak MTB, VUE).
	if ( mb_strlen( $n ) > 4 && mb_strtoupper( $n ) === $n ) {
		$n = mb_strtoupper( mb_substr( $n, 0, 1 ) ) . mb_strtolower( mb_substr( $n, 1 ) );
	}
	return $n;
}

/** Wirtualna pozycja menu (obiekt, który rozumie Walker WordPressa). */
function fx_am_item( $id, $parent, $title, $url, $term_id = 0, $classes = array() ) {
	$i                   = new stdClass();
	$i->ID               = $id;
	$i->db_id            = $id;
	$i->menu_item_parent = (string) $parent;
	$i->object_id        = (string) $term_id;
	$i->object           = $term_id ? 'product_cat' : 'custom';
	$i->type             = $term_id ? 'taxonomy' : 'custom';
	$i->type_label       = '';
	$i->title            = $title;
	$i->url              = $url;
	$i->target           = '';
	$i->attr_title       = '';
	$i->description      = '';
	$i->xfn              = '';
	$i->classes          = array_merge( array( 'menu-item', $term_id ? 'menu-item-type-taxonomy' : 'menu-item-type-custom', $term_id ? 'menu-item-object-product_cat' : 'menu-item-object-custom' ), $classes );
	$i->current          = false;
	$i->menu_order       = 0;
	$i->post_type        = 'nav_menu_item';
	$i->post_parent      = 0;
	$i->post_status      = 'publish';
	$i->post_title       = $title;
	$i->post_name        = '';
	$i->post_content     = '';
	$i->post_excerpt     = '';
	return $i;
}

/** Panel dla jednej kategorii głównej: lista wirtualnych pozycji pod $parent_id. */
function fx_am_panel( $root, $parent_id, &$next ) {
	$items  = array();
	$max3   = max( 3, (int) ( fx_mod( 'menu_auto_l3' ) ? fx_mod( 'menu_auto_l3' ) : 8 ) );
	$counts = fx_am_counts();
	foreach ( fx_am_children( $root->term_id ) as $c2 ) {
		$id2   = $next++;
		$kids2 = fx_am_children( $c2->term_id );
		$items[] = fx_am_item( $id2, $parent_id, fx_am_label( $c2, $root->slug, 1 ), get_term_link( $c2 ), $c2->term_id, $kids2 ? array( 'menu-item-has-children' ) : array() );
		$shown = 0;
		// Najpierw najliczniejsze, gdy trzeba skracać listę.
		if ( count( $kids2 ) > $max3 ) {
			usort( $kids2, function ( $a, $b ) use ( $counts ) {
				return ( $counts[ $b->term_id ] ?? 0 ) <=> ( $counts[ $a->term_id ] ?? 0 );
			} );
		}
		foreach ( $kids2 as $c3 ) {
			if ( $shown >= $max3 ) {
				$items[] = fx_am_item( $next++, $id2, 'Więcej →', get_term_link( $c2 ), 0, array( 'fx-mega__more' ) );
				break;
			}
			$id3   = $next++;
			$kids3 = fx_am_children( $c3->term_id );
			$show4 = $kids3 && count( $kids3 ) <= 6; // np. Proframe / Proframe RS / Rampage
			$items[] = fx_am_item( $id3, $id2, fx_am_label( $c3, $root->slug, 2 ), get_term_link( $c3 ), $c3->term_id, $show4 ? array( 'menu-item-has-children' ) : array() );
			if ( $show4 ) {
				foreach ( $kids3 as $c4 ) {
					$items[] = fx_am_item( $next++, $id3, fx_am_label( $c4, $root->slug, 3 ), get_term_link( $c4 ), $c4->term_id );
				}
			}
			$shown++;
		}
	}
	return $items;
}

/**
 * Podmiana pozycji menu „primary”: kategorie dostają automatyczne panele, puste kategorie znikają.
 */
add_filter( 'wp_nav_menu_objects', function ( $items, $args ) {
	if ( is_admin() || empty( $args->theme_location ) || 'primary' !== $args->theme_location || ! fx_am_on() || ! taxonomy_exists( 'product_cat' ) ) {
		return $items;
	}
	$counts = fx_am_counts();
	$out    = array();
	$next   = 900000000;
	$roots  = array();
	foreach ( $items as $it ) {
		if ( (int) $it->menu_item_parent ) {
			continue; // podmenu zbudujemy od nowa (dla kategorii) albo dołożymy niżej (dla zwykłych pozycji)
		}
		if ( 'taxonomy' === $it->type && 'product_cat' === $it->object ) {
			$term = get_term( (int) $it->object_id, 'product_cat' );
			if ( ! $term || is_wp_error( $term ) || empty( $counts[ $term->term_id ] ) ) {
				continue; // kategoria bez produktów – bez pozycji w pasku
			}
			$roots[ (int) $term->term_id ] = true;
			$panel = fx_am_panel( $term, $it->db_id, $next );
			$it->classes = array_values( array_diff( (array) $it->classes, array( 'menu-item-has-children' ) ) );
			if ( $panel ) {
				$it->classes[] = 'menu-item-has-children';
			}
			$out[] = $it;
			foreach ( $panel as $p ) {
				$out[] = $p;
			}
			continue;
		}
		// Zwykła pozycja (Poradniki, strona) – zostaje razem z ręcznym podmenu.
		$out[] = $it;
		foreach ( fx_am_descendants( $items, $it->db_id ) as $d ) {
			$out[] = $d;
		}
	}
	// Opcjonalnie: nowe kategorie główne, których nie ma w pasku, dopisywane na końcu.
	if ( fx_mod( 'menu_auto_append' ) ) {
		$skip = array_filter( array_map( 'trim', explode( ',', (string) fx_mod( 'menu_auto_skip' ) ) ) );
		foreach ( fx_am_children( 0 ) as $t ) {
			if ( isset( $roots[ $t->term_id ] ) || in_array( $t->slug, $skip, true ) || (int) get_option( 'default_product_cat' ) === $t->term_id ) {
				continue;
			}
			$id    = $next++;
			$panel = fx_am_panel( $t, $id, $next );
			$out[] = fx_am_item( $id, 0, mb_strtoupper( fx_am_label( $t, '', 0 ) ), get_term_link( $t ), $t->term_id, $panel ? array( 'menu-item-has-children' ) : array() );
			foreach ( $panel as $p ) {
				$out[] = $p;
			}
		}
	}
	// Podświetlenie bieżącej kategorii i jej przodków.
	if ( is_product_category() || is_product() ) {
		$cur = is_product_category() ? array( get_queried_object_id() ) : wc_get_product_cat_ids( get_queried_object_id() );
		if ( is_product() && function_exists( 'fx_main_category' ) && fx_main_category( get_queried_object_id() ) ) {
			$cur = array( fx_main_category( get_queried_object_id() )->term_id ); // podświetl tylko dział produktu, nie Prezenty
		}
		$anc = array();
		foreach ( $cur as $c ) {
			$anc = array_merge( $anc, get_ancestors( $c, 'product_cat', 'taxonomy' ) );
		}
		foreach ( $out as $o ) {
			if ( 'product_cat' !== $o->object ) {
				continue;
			}
			if ( in_array( (int) $o->object_id, array_map( 'intval', $cur ), true ) && is_product_category() ) {
				$o->classes[] = 'current-menu-item';
			} elseif ( in_array( (int) $o->object_id, array_map( 'intval', $anc ), true ) || ( is_product() && in_array( (int) $o->object_id, array_map( 'intval', $cur ), true ) ) ) {
				$o->classes[] = 'current-menu-ancestor';
			}
		}
	}
	return $out;
}, 20, 2 );

/** Potomkowie pozycji ręcznego menu (dla pozycji, które nie są kategoriami). */
function fx_am_descendants( $items, $parent_id ) {
	$out = array();
	foreach ( $items as $it ) {
		if ( (int) $it->menu_item_parent === (int) $parent_id ) {
			$out[] = $it;
			$out   = array_merge( $out, fx_am_descendants( $items, $it->db_id ) );
		}
	}
	return $out;
}

/* Odświeżanie: razem z pamięcią motywu i przy zmianach kategorii / menu. */
add_action( 'fx_flush_caches', function () {
	delete_transient( 'fx_am_counts' );
}, 20 );
foreach ( array( 'created_product_cat', 'edited_product_cat', 'delete_product_cat', 'wp_update_nav_menu' ) as $fx_hook ) {
	add_action( $fx_hook, function () {
		delete_transient( 'fx_am_counts' );
	} );
}

/* Pola w edycji kategorii: „Nazwa w menu”, „Ukryj w menu”. */
add_action( 'product_cat_edit_form_fields', function ( $term ) {
	?>
	<tr class="form-field">
		<th scope="row"><label for="fx_menu_label">Nazwa w menu (motyw FOX)</label></th>
		<td><input type="text" id="fx_menu_label" name="fx_menu_label" value="<?php echo esc_attr( get_term_meta( $term->term_id, 'fx_menu_label', true ) ); ?>" placeholder="<?php echo esc_attr( fx_am_label( $term, '', 2 ) ); ?>">
		<p class="description">Krótsza nazwa w automatycznym menu głównym. Puste = nazwa kategorii (bez „FOX” i powtórzeń działu).</p>
		<label style="display:block;margin-top:8px"><input type="checkbox" name="fx_menu_hide" value="1" <?php checked( (bool) get_term_meta( $term->term_id, 'fx_menu_hide', true ) ); ?>> Ukryj w menu głównym (kategoria zostaje w sklepie)</label></td>
	</tr>
	<?php
}, 20 );
add_action( 'edited_product_cat', function ( $term_id ) {
	if ( ! isset( $_POST['fx_menu_label'] ) || ! current_user_can( 'manage_product_terms' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$label = sanitize_text_field( wp_unslash( $_POST['fx_menu_label'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
	if ( '' === $label ) {
		delete_term_meta( $term_id, 'fx_menu_label' );
	} else {
		update_term_meta( $term_id, 'fx_menu_label', $label );
	}
	if ( ! empty( $_POST['fx_menu_hide'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		update_term_meta( $term_id, 'fx_menu_hide', 1 );
	} else {
		delete_term_meta( $term_id, 'fx_menu_hide' );
	}
} );
