<?php
/**
 * Etykiety (badge) na zdjęciach produktów.
 *
 * Automatyczne: Promocja / −X%, Nowość, Ostatnie sztuki, Wyprzedane.
 * Ręczne: pole „Etykiety” w produkcie i w kategorii – jedna etykieta na linię,
 * „!” na początku = etykieta wyróżniona (czarna), np. „!Ocieplane”.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

/* Domyślne kółko „Promocja!” WooCommerce zastępujemy naszymi etykietami. */
remove_action( 'woocommerce_before_shop_loop_item_title', 'woocommerce_show_product_loop_sale_flash', 10 );
remove_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_sale_flash', 10 );

add_action( 'woocommerce_before_shop_loop_item_title', function () {
	global $product;
	echo fx_badges_html( $product ); // phpcs:ignore
}, 9 );

add_action( 'woocommerce_before_single_product_summary', function () {
	global $product;
	echo fx_badges_html( $product, 'fx-badges--single' ); // phpcs:ignore
}, 15 );

/**
 * Lista etykiet produktu: [ [tekst, wariant], ... ].
 */
function fx_product_badges( $product ) {
	if ( ! $product instanceof WC_Product ) {
		return array();
	}
	$out = array();

	if ( ! $product->is_in_stock() ) {
		return array( array( 'Wyprzedane', 'muted' ) );
	}

	if ( $product->is_on_sale() ) {
		$sb = fx_sale_badge( $product );
		if ( $sb ) {
			$out[] = $sb;
		}
	}

	$days    = (int) fx_tool( 'badge_new_days' );
	$created = $product->get_date_created();
	if ( $days > 0 && $created && ( time() - $created->getTimestamp() ) < $days * DAY_IN_SECONDS ) {
		$out[] = array( 'Nowość', 'default' );
	}

	// Ręczne etykiety: produkt, a jeśli brak – jego kategorie.
	$manual = trim( (string) get_post_meta( $product->get_id(), '_fx_badges', true ) );
	if ( '' === $manual ) {
		foreach ( $product->get_category_ids() as $cat_id ) {
			$cat_badges = trim( (string) get_term_meta( $cat_id, 'fx_badges', true ) );
			if ( '' !== $cat_badges ) {
				$manual = $cat_badges;
				break;
			}
		}
	}
	foreach ( preg_split( '/\r\n|\r|\n/', $manual ) as $line ) {
		$line = trim( $line );
		if ( '' === $line ) {
			continue;
		}
		$strong = ( '!' === $line[0] );
		$out[]  = array( ltrim( $line, '!' ), $strong ? 'strong' : 'default' );
	}

	$low = (int) fx_tool( 'badge_low_stock' );
	if ( $low > 0 && fx_low_stock( $product, $low ) ) {
		$out[] = array( 'Ostatnie sztuki', 'default' );
	}

	return apply_filters( 'fx_product_badges', array_slice( $out, 0, 3 ), $product );
}

/**
 * Rabat jednej pozycji (produkt prosty / wariant): procent w dół od podstawy (Omnibus albo regularna)
 * i segment kampanii z modułu Przeceny ('shelf', 'pb', 'rest' albo '' – zwykła promocja).
 *
 * @return array|null [ pct, seg ]
 */
function fx_sale_item( WC_Product $p ) {
	if ( ! $p->is_on_sale() ) {
		return null;
	}
	$base = function_exists( 'fx_omni_base' ) ? fx_omni_base( $p ) : (float) $p->get_regular_price();
	$sale = (float) $p->get_price();
	if ( $base <= 0 || $sale <= 0 ) {
		return null;
	}
	$pct = (int) floor( ( 1 - $sale / $base ) * 100 + 0.0001 );
	$seg = '';
	if ( function_exists( 'fx_promo_rule' ) && fx_promo_active() ) {
		$r = fx_promo_rule( $p );
		if ( $r[0] > 0 ) { // produkt objęty kampanią (także gdy ma własną, równą albo głębszą promocję)
			$seg = (string) $r[1];
		} elseif ( fx_tool( 'badge_bw_all' ) ) {
			$seg = 'all'; // każdy przeceniony produkt w trakcie kampanii – etykieta i filtr kampanii
		}
	}
	return array( max( 0, $pct ), $seg );
}

/** Rabaty produktu: [ max, min, shelf (bool), campaign (bool) ] albo null. */
function fx_sale_info( WC_Product $product ) {
	static $memo = array();
	$id = $product->get_id();
	if ( isset( $memo[ $id ] ) ) {
		return $memo[ $id ];
	}
	// Kafelki: gotowe wartości z tła (bez wczytywania rozmiarów i historii cen).
	$row = function_exists( 'fx_pl_row' ) && ! $product->is_type( 'variation' ) ? fx_pl_row( $id ) : false;
	if ( false !== $row ) {
		return $memo[ $id ] = $row ? array( (int) $row[0], (int) $row[1], false, ! empty( $row[2] ) ) : null;
	}
	$items = array();
	if ( $product->is_type( 'variable' ) ) {
		foreach ( $product->get_visible_children() as $vid ) {
			$v = wc_get_product( $vid );
			if ( $v && $v->is_in_stock() && $v->is_purchasable() && ( $i = fx_sale_item( $v ) ) ) {
				$items[] = $i;
			}
		}
	} elseif ( $i = fx_sale_item( $product ) ) {
		$items[] = $i;
	}
	if ( ! $items ) {
		return $memo[ $id ] = null;
	}
	$pcts  = wp_list_pluck( $items, 0 );
	$segs  = wp_list_pluck( $items, 1 );
	return $memo[ $id ] = array( max( $pcts ), min( $pcts ), in_array( 'shelf', $segs, true ), (bool) array_filter( $segs ) );
}

/** Tekst etykiety z szablonu: {rabat} = „−20%” albo „DO −30%”, {pct} = liczba. */
function fx_sale_badge_text( $tpl, $max, $min ) {
	$rabat = ( $max !== $min ? 'DO ' : '' ) . '−' . $max . '%';
	return trim( str_replace( array( '{rabat}', '{pct}' ), array( $rabat, $max ), $tpl ) );
}

/**
 * Etykieta cenowa (jedna): kampania → zwykła promocja.
 * Poniżej progu (domyślnie 5% od najniższej ceny z 30 dni) – bez etykiety cenowej.
 */
function fx_sale_badge( WC_Product $product ) {
	if ( get_post_meta( $product->get_id(), '_fx_hide_sale_badge', true ) ) {
		return null;
	}
	$info = fx_sale_info( $product );
	if ( ! $info ) {
		return null;
	}
	list( $max, $min, $shelf, $campaign ) = $info;
	if ( ! fx_tool( 'badge_sale_pct' ) ) {
		return array( 'Promocja', 'sale' );
	}
	if ( $max < max( 1, (int) fx_tool( 'badge_sale_min' ) ) ) {
		return null;
	}
	if ( $campaign && fx_tool( 'badge_bw_on' ) ) {
		return array( fx_sale_badge_text( fx_tool( 'badge_bw' ), $max, $min ), 'bw' ); // jedna etykieta kampanii – bez osobnej „z półki”
	}
	return array( fx_sale_badge_text( fx_tool( 'badge_sale_tpl' ), $max, $min ), 'sale' );
}

/* Wariant: etykieta po wybraniu rozmiaru (karta produktu podmienia tekst). */
add_filter( 'woocommerce_available_variation', function ( $data, $product, $variation ) {
	if ( get_post_meta( $product->get_id(), '_fx_hide_sale_badge', true ) || ! fx_tool( 'badge_sale_pct' ) ) {
		return $data;
	}
	$i = fx_sale_item( $variation );
	if ( ! $i || $i[0] < max( 1, (int) fx_tool( 'badge_sale_min' ) ) ) {
		$data['fx_badge'] = array( '', '' );
		return $data;
	}
	if ( $i[1] && fx_tool( 'badge_bw_on' ) ) {
		$data['fx_badge'] = array( fx_sale_badge_text( fx_tool( 'badge_bw' ), $i[0], $i[0] ), 'bw' );
	} else {
		$data['fx_badge'] = array( fx_sale_badge_text( fx_tool( 'badge_sale_tpl' ), $i[0], $i[0] ), 'sale' );
	}
	if ( ! empty( $data['fx_badge'][0] ) ) {
		$data['fx_badge'][2] = fx_badge_inner( $data['fx_badge'][0], $data['fx_badge'][1] );
	}
	return $data;
}, 30, 3 );

function fx_sale_percent( $product ) {
	$info = $product instanceof WC_Product ? fx_sale_info( $product ) : null;
	return $info ? (int) $info[0] : 0;
}


function fx_low_stock( $product, $limit ) {
	if ( $product->is_type( 'variable' ) ) {
		// Jedno zapytanie do tabeli stanów WooCommerce zamiast wczytywania każdego rozmiaru.
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT COUNT(l.stock_quantity) AS managed, SUM(CASE WHEN l.stock_quantity > 0 THEN l.stock_quantity ELSE 0 END) AS total
			 FROM {$wpdb->posts} p JOIN {$wpdb->prefix}wc_product_meta_lookup l ON l.product_id = p.ID
			 WHERE p.post_parent = %d AND p.post_type = 'product_variation' AND p.post_status = 'publish'",
			$product->get_id()
		) );
		$total = $row ? (int) $row->total : 0;
		return $row && (int) $row->managed > 0 && $total > 0 && $total <= $limit;
	}
	return $product->managing_stock() && $product->get_stock_quantity() > 0 && $product->get_stock_quantity() <= $limit;
}

/** Treść etykiety; kampania: procent („−20%”, „DO −30%”) w czerwonym polu jak na naklejce. */
function fx_badge_inner( $text, $class ) {
	if ( 'bw' === $class && preg_match( '/^(.*?)\s*((?:DO\s+)?[−-]\d+%)\s*$/u', $text, $m ) && '' !== trim( $m[1] ) ) {
		return esc_html( trim( $m[1] ) ) . ' <b class="fx-bw__pct">' . esc_html( $m[2] ) . '</b>';
	}
	return esc_html( $text );
}

function fx_badges_html( $product, $extra_class = '' ) {
	$badges = fx_product_badges( $product );
	if ( ! $badges ) {
		return '';
	}
	$html = '<span class="fx-badges ' . esc_attr( $extra_class ) . '">';
	foreach ( $badges as $b ) {
		$style = '';
		if ( 'custom' === $b[1] && ! empty( $b[2] ) && function_exists( 'fx_tb_text_color' ) ) {
			$style = ' style="background:' . esc_attr( $b[2] ) . ';color:' . esc_attr( fx_tb_text_color( $b[2] ) ) . ';box-shadow:none"';
		}
		$html .= '<span class="fx-badge fx-badge--' . esc_attr( $b[1] ) . '"' . $style . ( in_array( $b[1], array( 'sale', 'bw', 'bw-shelf' ), true ) ? ' data-fx-pbadge' : '' ) . '>' . fx_badge_inner( $b[0], $b[1] ) . '</span>';
	}
	return $html . '</span>';
}

/* ---------- Pola w panelu ---------- */
add_action( 'woocommerce_product_options_general_product_data', function () {
	echo '<div class="options_group">';
	woocommerce_wp_textarea_input(
		array(
			'id'          => '_fx_badges',
			'label'       => 'Etykiety',
			'placeholder' => "Ocieplane\n!Limitowana",
			'description' => 'Jedna etykieta na linię. „!” na początku = etykieta czarna. Puste = etykiety z kategorii. Promocja, Nowość i Wyprzedane dodają się same.',
			'desc_tip'    => true,
			'rows'        => 2,
		)
	);
	echo '</div>';
} );

add_action( 'woocommerce_product_options_pricing', function () {
	woocommerce_wp_checkbox( array( 'id' => '_fx_hide_sale_badge', 'label' => 'Ukryj etykietę promocyjną', 'description' => 'bez etykiety −X% / Black Week na kafelku i karcie produktu' ) );
} );

add_action( 'woocommerce_admin_process_product_object', function ( $product ) {
	$product->update_meta_data( '_fx_hide_sale_badge', empty( $_POST['_fx_hide_sale_badge'] ) ? '' : 'yes' ); // phpcs:ignore WordPress.Security.NonceVerification
	if ( isset( $_POST['_fx_badges'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		$product->update_meta_data( '_fx_badges', sanitize_textarea_field( wp_unslash( $_POST['_fx_badges'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
	}
} );

add_action( 'product_cat_edit_form_fields', function ( $term ) {
	$val = get_term_meta( $term->term_id, 'fx_badges', true );
	?>
	<tr class="form-field">
		<th scope="row"><label for="fx_badges">Etykiety produktów</label></th>
		<td>
			<textarea name="fx_badges" id="fx_badges" rows="3" class="large-text"><?php echo esc_textarea( (string) $val ); ?></textarea>
			<p class="description">Etykiety dla wszystkich produktów tej kategorii, które nie mają własnych. Jedna na linię, „!” na początku = czarna.</p>
		</td>
	</tr>
	<?php
}, 30 );

add_action( 'edited_product_cat', function ( $term_id ) {
	if ( current_user_can( 'manage_product_terms' ) && isset( $_POST['fx_badges'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		update_term_meta( $term_id, 'fx_badges', sanitize_textarea_field( wp_unslash( $_POST['fx_badges'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
	}
} );
