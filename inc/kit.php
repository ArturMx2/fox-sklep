<?php
/**
 * Moduł „Zbuduj komplet”.
 *
 * W edycji produktu (zakładka „Produkty podlinkowane”) wybierasz części kompletu,
 * np. do koszulki: spodnie i rękawice z tej samej grafiki. Moduł pokazuje się
 * na karcie produktu, który ma komplet, i na kartach jego części.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

/* ---------- Panel administracyjny ---------- */

/* Osobne pole „Komplet” w edycji produktu (pod opisem), zamiast ukrytego w zakładce „Produkty podlinkowane”. */
add_action( 'add_meta_boxes_product', function () {
	add_meta_box( 'fx_kit_box', 'Komplet (zestaw)', 'fx_kit_box', 'product', 'normal', 'high' );
} );

function fx_kit_box( $post ) {
	$ids     = fx_kit_get_ids( $post->ID );
	$is_page = fx_kit_is_page( $post->ID );
	$flag    = get_post_meta( $post->ID, '_fx_is_kit', true );
	$auto    = fx_kit_resolve( $post->ID );
	?>
	<div class="fx-kit-admin" style="display:grid;gap:14px;max-width:900px">
		<p style="margin:0">
			<label><input type="checkbox" name="_fx_is_kit" value="yes" <?php checked( $is_page ); ?>> <strong>To jest strona kompletu</strong> (np. „Strój na crossa FOX 180 Frame Red”)</label><br>
			<span class="description">Klient nie kupuje tego produktu, tylko wybrane części z rozmiarami – trafiają do koszyka jako osobne produkty. Cena wyliczy się sama z części<?php echo fx_kit_discount() ? ' (z rabatem ' . esc_html( wc_format_localized_decimal( fx_kit_discount() ) ) . '% za komplet)' : ''; ?>. Adres, opis i zdjęcia tej strony zostają bez zmian.</span>
			<input type="hidden" name="_fx_is_kit_sent" value="1">
		</p>
		<div>
			<label for="fx_kit_ids"><strong>Części kompletu</strong></label><br>
			<select class="wc-product-search" multiple="multiple" style="width:100%" id="fx_kit_ids" name="fx_kit_ids[]" data-placeholder="Wpisz nazwę lub SKU, np. „180 Frame Red”…" data-action="woocommerce_json_search_products" data-exclude="<?php echo (int) $post->ID; ?>">
				<?php
				foreach ( $ids as $id ) {
					$p = wc_get_product( $id );
					if ( $p ) {
						echo '<option value="' . (int) $id . '" selected="selected">' . esc_html( wp_strip_all_tags( $p->get_formatted_name() ) ) . '</option>';
					}
				}
				?>
			</select>
			<p class="description" style="margin-top:6px">
				<?php if ( $is_page ) : ?>
					Wybierz produkty, z których składa się komplet (koszulka, spodnie, rękawice…), w kolejności wyświetlania. Klient wybierze rozmiar każdej części.
				<?php else : ?>
					Opcjonalnie: produkty, które razem z tym tworzą komplet (ten produkt dodaje się sam jako pierwszy). Sekcja „Zbuduj komplet” pokaże się na kartach wszystkich części.
				<?php endif; ?>
				Puste pole = części dobierane automatycznie po nazwie.
			</p>
			<?php if ( $auto['parts'] ) : ?>
				<?php
				$names = array();
				foreach ( $auto['parts'] as $aid ) {
					$names[] = '<a href="' . esc_url( get_edit_post_link( $aid ) ) . '">' . esc_html( get_the_title( $aid ) ) . '</a>';
				}
				$src = array( 'manual' => 'wybrane ręcznie', 'wooco' => 'ze starej wtyczki kompletów – wybierz części powyżej, żeby je zastąpić', 'auto' => 'dobrane automatycznie po nazwie' );
				?>
				<p style="margin:8px 0 0;padding:10px 12px;background:#f6f7f7;border-left:4px solid #2271b1">Teraz w komplecie (<?php echo esc_html( $src[ $auto['source'] ] ); ?>):<br><?php echo implode( ' + ', $names ); // phpcs:ignore ?></p>
			<?php elseif ( $is_page ) : ?>
				<p style="margin:8px 0 0;padding:10px 12px;background:#fcf0f1;border-left:4px solid #d63638">Komplet potrzebuje co najmniej 2 części – wybierz je powyżej (albo zostaw puste, jeśli mają się dobrać automatycznie po nazwie).</p>
			<?php endif; ?>
		</div>
		<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
			<p style="margin:0"><label for="_fx_kit_title"><strong>Tytuł sekcji</strong> (na kartach części)</label><br><input type="text" class="widefat" id="_fx_kit_title" name="_fx_kit_title" value="<?php echo esc_attr( get_post_meta( $post->ID, '_fx_kit_title', true ) ); ?>" placeholder="<?php echo $is_page ? esc_attr( get_the_title( $post ) ) : 'Cała grafika jednym kliknięciem'; ?>"></p>
			<p style="margin:0"><label for="_fx_kit_img"><strong>Zdjęcie całego kompletu</strong> (adres z biblioteki mediów)</label><br><input type="url" class="widefat" id="_fx_kit_img" name="_fx_kit_img" value="<?php echo esc_attr( get_post_meta( $post->ID, '_fx_kit_img', true ) ); ?>" placeholder="puste = zdjęcie główne"></p>
		</div>
	</div>
	<?php
}

add_action( 'woocommerce_admin_process_product_object', function ( $product ) {
	// phpcs:disable WordPress.Security.NonceVerification -- WooCommerce weryfikuje nonce zapisu produktu.
	$ids = isset( $_POST['fx_kit_ids'] ) ? array_filter( array_map( 'absint', (array) wp_unslash( $_POST['fx_kit_ids'] ) ) ) : array();
	$ids = array_values( array_diff( array_unique( $ids ), array( $product->get_id() ) ) );
	if ( $ids ) {
		// Zapis ",12,34," pozwala szybko znaleźć komplet z poziomu jego części.
		$product->update_meta_data( '_fx_kit_ids', ',' . implode( ',', $ids ) . ',' );
	} else {
		$product->delete_meta_data( '_fx_kit_ids' );
	}
	if ( isset( $_POST['_fx_is_kit_sent'] ) ) {
		$product->update_meta_data( '_fx_is_kit', isset( $_POST['_fx_is_kit'] ) ? 'yes' : 'no' );
		delete_transient( 'fx_kit_index_v3' );
	}
	if ( isset( $_POST['_fx_kit_title'] ) ) {
		$product->update_meta_data( '_fx_kit_title', sanitize_text_field( wp_unslash( $_POST['_fx_kit_title'] ) ) );
	}
	if ( isset( $_POST['_fx_kit_img'] ) ) {
		$product->update_meta_data( '_fx_kit_img', esc_url_raw( wp_unslash( $_POST['_fx_kit_img'] ) ) );
	}
	// phpcs:enable
} );

function fx_kit_get_ids( $product_id ) {
	$raw = get_post_meta( $product_id, '_fx_kit_ids', true );
	if ( ! $raw ) {
		return array();
	}
	return array_values( array_filter( array_map( 'absint', explode( ',', trim( $raw, ',' ) ) ) ) );
}

/* =====================================================================
 * AUTOMATYCZNE KOMPLETY PO NAZWIE
 * „KOSZULKA MOTOCYKLOWA FOX 180 FRAME RED”, „SPODNIE … FOX 180 FRAME RED”, „RĘKAWICE FOX 180 FRAME RED”
 * → wspólny klucz „180 frame red”. Strona „Strój na crossa FOX 180 Frame Red” ma ten sam klucz.
 * Rok (2026), ukośniki i wielkość liter nie mają znaczenia. JUNIOR/KIDS/LADY tworzą osobne komplety.
 * ===================================================================== */

/** Części kompletu w kolejności wyświetlania: typ => początki słów w nazwie przed „FOX”. */
function fx_kit_part_types() {
	return apply_filters(
		'fx_kit_part_types',
		array(
			'koszulka' => array( 'koszul', 'jersey', 'bluz' ), // „Bluza motocyklowa” = góra stroju na crossa.
			'spodnie'  => array( 'spodn', 'pant' ),
			'rekawice' => array( 'rękaw', 'rekaw', 'glove' ),
		)
	);
}

/** Dodatki do stroju (nie są wymagane do rabatu): gogle i kask w tej samej grafice. */
function fx_kit_extra_types() {
	return apply_filters(
		'fx_kit_extra_types',
		array(
			'gogle' => array( 'gogl', 'goggle' ),
			'kask'  => array( 'kask', 'helmet' ),
		)
	);
}

/** Słowa oznaczające stronę kompletu. */
function fx_kit_page_words() {
	return apply_filters( 'fx_kit_page_words', array( 'strój', 'stroj', 'komplet', 'zestaw' ) );
}

/**
 * [ typ, klucz ] z nazwy produktu; typ = slug części, 'kit' dla strony kompletu albo ''.
 */
function fx_kit_parse_name( $name ) {
	$name = mb_strtolower( wp_strip_all_tags( (string) $name ), 'UTF-8' );
	if ( ! preg_match( '/^(.*?)\bfox\b(.*)$/u', $name, $m ) ) {
		return array( '', '' );
	}
	$prefix = preg_split( '/\s+/u', trim( $m[1] ), -1, PREG_SPLIT_NO_EMPTY );
	$type   = '';
	$mods   = array();
	foreach ( $prefix as $w ) {
		if ( preg_match( '/^(junior|kids|kid|youth|lady|ladies|women|damsk|dziec)/u', $w, $mm ) ) {
			$mods[] = in_array( $mm[1], array( 'kid', 'kids', 'youth', 'dziec' ), true ) ? 'kids' : ( 'junior' === $mm[1] ? 'junior' : 'lady' );
		}
		if ( '' !== $type ) {
			continue;
		}
		foreach ( fx_kit_page_words() as $kw ) {
			if ( 0 === mb_strpos( $w, $kw ) ) {
				$type = 'kit';
				continue 2;
			}
		}
		foreach ( array_merge( fx_kit_part_types(), fx_kit_extra_types() ) as $slug => $stems ) {
			foreach ( $stems as $st ) {
				if ( 0 === mb_strpos( $w, $st ) ) {
					$type = $slug;
					continue 3;
				}
			}
		}
	}
	$rest   = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $m[2] );
	$tokens = array();
	foreach ( preg_split( '/\s+/u', trim( $rest ), -1, PREG_SPLIT_NO_EMPTY ) as $t ) {
		if ( preg_match( '/^20\d\d$/', $t ) ) {
			continue;
		}
		if ( preg_match( '/^(junior|kids|youth|lady|women)$/u', $t, $mm ) ) {
			$mods[] = in_array( $t, array( 'kids', 'youth' ), true ) ? 'kids' : ( 'junior' === $t ? 'junior' : 'lady' );
			continue;
		}
		$tokens[] = $t;
	}
	// Dopisek sezonu na końcu („… FRAME BLUE 26”) – ta sama grafika co bez niego.
	if ( count( $tokens ) > 2 && preg_match( '/^2[3-9]$/', end( $tokens ) ) ) {
		array_pop( $tokens );
	}
	if ( count( $tokens ) < 2 ) {
		return array( $type, '' ); // samo „FOX V1” to za mało, żeby łączyć
	}
	$mods = array_unique( $mods );
	sort( $mods );
	return array( $type, trim( implode( ' ', $mods ) . ' ' . implode( ' ', $tokens ) ) );
}

/** Indeks: klucz => [ 'parts' => [ typ => [id…] ], 'kits' => [id…] ]. */
function fx_kit_index() {
	static $idx = null;
	if ( null !== $idx ) {
		return $idx;
	}
	$idx = get_transient( 'fx_kit_index_v3' );
	if ( is_array( $idx ) ) {
		return $idx;
	}
	global $wpdb;
	$rows = $wpdb->get_results( "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish' ORDER BY ID DESC" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$idx  = array();
	foreach ( (array) $rows as $r ) {
		list( $type, $key ) = fx_kit_parse_name( $r->post_title );
		if ( '' === $type || '' === $key ) {
			continue;
		}
		if ( 'kit' === $type ) {
			$idx[ $key ]['kits'][] = (int) $r->ID;
		} else {
			$idx[ $key ]['parts'][ $type ][] = (int) $r->ID;
		}
	}
	set_transient( 'fx_kit_index_v3', $idx, DAY_IN_SECONDS );
	return $idx;
}
foreach ( array( 'woocommerce_update_product', 'woocommerce_new_product', 'trashed_post', 'deleted_post', 'untrashed_post' ) as $fx_hook ) {
	add_action( $fx_hook, 'fx_cache_touch' );
}

/** Czy produkt jest stroną kompletu (dawny „Smart Composite” albo nazwa „Strój…/Komplet…”). */
function fx_kit_is_page( $product ) {
	$product = $product instanceof WC_Product ? $product : wc_get_product( $product );
	if ( ! $product ) {
		return false;
	}
	$flag = get_post_meta( $product->get_id(), '_fx_is_kit', true );
	if ( 'yes' === $flag ) {
		return true;
	}
	if ( 'no' === $flag ) {
		return false;
	}
	if ( has_term( 'composite', 'product_type', $product->get_id() ) ) {
		return true;
	}
	list( $type ) = fx_kit_parse_name( $product->get_name() );
	return 'kit' === $type;
}

/** Części zapisane przez wtyczkę WPC Composite (stare komplety) – domyślny produkt każdego składnika. */
function fx_kit_wooco_parts( $product_id ) {
	$comps = get_post_meta( $product_id, 'wooco_components', true );
	if ( ! is_array( $comps ) ) {
		return array();
	}
	$ids = array();
	foreach ( $comps as $c ) {
		if ( ! is_array( $c ) ) {
			continue;
		}
		$id = ! empty( $c['default'] ) ? absint( $c['default'] ) : 0;
		if ( ! $id && ! empty( $c['products'] ) && ( empty( $c['type'] ) || 'products' === $c['type'] ) ) {
			$list = is_array( $c['products'] ) ? $c['products'] : explode( ',', (string) $c['products'] );
			$id   = absint( reset( $list ) );
		}
		if ( $id ) {
			$p = wc_get_product( $id );
			if ( $p && $p->is_type( 'variation' ) ) {
				$id = $p->get_parent_id();
			}
			$ids[] = $id;
		}
	}
	return array_values( array_unique( array_filter( $ids ) ) );
}

/** Jedna część danego typu – najpierw dostępna na stanie, potem najnowsza. */
function fx_kit_pick( $ids ) {
	foreach ( $ids as $id ) {
		$p = wc_get_product( $id );
		if ( $p && $p->is_visible() && $p->is_in_stock() ) {
			return $id;
		}
	}
	return $ids ? $ids[0] : 0;
}

/**
 * Komplet dla produktu: [ 'parts' => [id…], 'page' => id strony kompletu|0, 'title' => '', 'source' => manual|wooco|auto ].
 * Kolejność: ręcznie wybrane części > części ze starej wtyczki > automatycznie po nazwie.
 */
function fx_kit_resolve( $product_id ) {
	static $cache = array();
	$product_id = (int) $product_id;
	if ( isset( $cache[ $product_id ] ) ) {
		return $cache[ $product_id ];
	}
	$p     = wc_get_product( $product_id );
	$empty = array( 'parts' => array(), 'page' => 0, 'title' => '', 'source' => '' );
	if ( ! $p ) {
		return $cache[ $product_id ] = $empty;
	}
	$is_page = fx_kit_is_page( $p );
	list( , $key ) = fx_kit_parse_name( $p->get_name() );
	$idx   = fx_kit_index();
	$out   = $empty;

	// 1. Ręcznie: ten produkt ma listę części albo jest częścią czyjejś listy.
	$host = fx_kit_get_ids( $product_id ) ? $product_id : fx_kit_host( $product_id );
	if ( $host ) {
		$parts = fx_kit_get_ids( $host );
		if ( ! fx_kit_is_page( $host ) ) {
			array_unshift( $parts, $host );
		}
		$out = array( 'parts' => $parts, 'page' => fx_kit_is_page( $host ) ? $host : 0, 'title' => (string) get_post_meta( $host, '_fx_kit_title', true ), 'source' => 'manual' );
	} elseif ( $is_page && ( $w = fx_kit_wooco_parts( $product_id ) ) ) {
		// 2. Stara wtyczka kompletów.
		$out = array( 'parts' => $w, 'page' => $product_id, 'title' => '', 'source' => 'wooco' );
	} elseif ( '' !== $key && isset( $idx[ $key ]['parts'] ) ) {
		// 3. Automatycznie po nazwie.
		$parts = array();
		foreach ( array_keys( fx_kit_part_types() ) as $type ) {
			if ( ! empty( $idx[ $key ]['parts'][ $type ] ) ) {
				$ids = $idx[ $key ]['parts'][ $type ];
				// Bieżący produkt zawsze jako część swojego typu.
				$parts[] = in_array( $product_id, $ids, true ) ? $product_id : fx_kit_pick( $ids );
			}
		}
		$page = $is_page ? $product_id : ( ! empty( $idx[ $key ]['kits'] ) ? (int) $idx[ $key ]['kits'][0] : 0 );
		$out  = array( 'parts' => $parts, 'page' => $page, 'title' => '', 'source' => 'auto' );
	}
	$out['parts'] = array_values(
		array_filter(
			array_unique( array_map( 'intval', $out['parts'] ) ),
			function ( $id ) {
				$x = wc_get_product( $id );
				return $x && 'publish' === $x->get_status() && ! fx_kit_is_page( $x );
			}
		)
	);
	if ( count( $out['parts'] ) < 2 ) {
		$out = $empty;
	}
	// Podstawa kompletu (cena „od” i rabat) to góra stroju + spodnie; reszta (rękawice, buty, gogle, kask) to dodatki.
	$out['core']  = $out['parts'];
	$out['extra'] = array();
	$core         = array();
	foreach ( $out['parts'] as $pid ) {
		if ( in_array( fx_kit_parse_name( get_the_title( $pid ) )[0], array( 'koszulka', 'spodnie' ), true ) ) {
			$core[] = $pid;
		}
	}
	if ( count( $core ) >= 2 ) {
		$out['core']  = $core;
		$out['extra'] = array_values( array_diff( $out['parts'], $core ) );
	}
	// Strój bez rękawic: dobieramy rękawice FOX Dirtpaw w kolorze stroju.
	if ( $out['parts'] && in_array( $out['source'], array( 'auto', 'wooco' ), true ) && apply_filters( 'fx_kit_add_gloves', true, $product_id ) ) {
		$has_gloves = false;
		foreach ( $out['parts'] as $pid ) {
			list( $t ) = fx_kit_parse_name( get_the_title( $pid ) );
			if ( 'rekawice' === $t ) {
				$has_gloves = true;
				break;
			}
		}
		if ( ! $has_gloves ) {
			$g = fx_kit_gloves_for( '' !== $key ? $key : fx_kit_parse_name( get_the_title( $out['parts'][0] ) )[1] );
			if ( $g && ! in_array( $g, $out['parts'], true ) ) {
				$out['parts'][] = $g;
				$out['extra'][] = $g;
			}
		}
	}
	// Gogle i kask w tej samej grafice (np. „Thorn”, „Nitro”, „Frame”) – tylko przy wyraźnym dopasowaniu nazwy.
	if ( $out['parts'] && apply_filters( 'fx_kit_add_gear', true, $product_id ) ) {
		$kkey = '' !== $key ? $key : fx_kit_parse_name( get_the_title( $out['parts'][0] ) )[1];
		foreach ( array_keys( fx_kit_extra_types() ) as $xt ) {
			$have = false;
			foreach ( $out['parts'] as $pid ) {
				if ( fx_kit_parse_name( get_the_title( $pid ) )[0] === $xt ) {
					$have = true;
					break;
				}
			}
			$x = $have ? 0 : fx_kit_gear_for( $xt, $kkey );
			// Strój moto – kask i gogle moto; rowerowy – rowerowe.
			if ( $x && function_exists( 'fx_hg_same_world' ) && ! fx_hg_same_world( $out['parts'][0], $x ) ) {
				$x = 0;
			}
			if ( $x && ! in_array( $x, $out['parts'], true ) ) {
				$out['parts'][] = $x;
				$out['extra'][] = $x;
			}
		}
	}
	return $cache[ $product_id ] = $out;
}

/** Słowa kolorów (dopasowanie samego koloru nie wystarcza do gogli/kasku). */
function fx_kit_color_words() {
	return array( 'black', 'white', 'blue', 'red', 'pink', 'purple', 'grey', 'gray', 'green', 'yellow', 'orange', 'fluo', 'fluorescent', 'camo', 'navy', 'lava', 'teal', 'gold', 'silver', 'brown', 'tan', 'olive', 'cobalt', 'steel', 'mint', 'flo', 'dark', 'light', 'multi', 'chrome', 'matte', 'ice', 'citrus', 'burgundy', 'maroon', 'blk', 'wht' );
}

/**
 * Gogle albo kask do stroju: produkt tego typu, którego nazwa ma tę samą grafikę (słowo spoza kolorów i numerów
 * modelu, np. „thorn”, „nitro”, „frame”) – im więcej wspólnych słów (grafika + kolor), tym lepiej.
 * Bez wspólnej grafiki nic nie dobieramy. Strój junior/dziecięcy – tylko produkty junior/dziecięce.
 */
function fx_kit_gear_for( $type, $key ) {
	$key = trim( (string) $key );
	if ( '' === $key ) {
		return 0;
	}
	$mods_all = array( 'junior', 'kids', 'lady' );
	$tokens   = preg_split( '/\s+/u', $key );
	$mods     = array_values( array_intersect( $tokens, $mods_all ) );
	$words    = array_values( array_diff( $tokens, $mods_all ) );
	$colors   = fx_kit_color_words();
	$best     = array();
	foreach ( fx_kit_index() as $k => $row ) {
		if ( empty( $row['parts'][ $type ] ) ) {
			continue;
		}
		$kt    = preg_split( '/\s+/u', $k );
		$kmods = array_values( array_intersect( $kt, $mods_all ) );
		if ( $kmods !== $mods ) {
			continue;
		}
		$common  = array_intersect( array_diff( $kt, $mods_all ), $words );
		$graphic = array_filter(
			$common,
			function ( $t ) use ( $colors ) {
				return ! in_array( $t, $colors, true ) && ! preg_match( '/^\d+$/', $t ) && mb_strlen( $t ) > 2;
			}
		);
		if ( ! $graphic ) {
			continue;
		}
		$id = fx_kit_pick( $row['parts'][ $type ] );
		$p  = $id ? wc_get_product( $id ) : null;
		if ( ! $p || ! $p->is_visible() || ! $p->is_in_stock() ) {
			continue;
		}
		$rank = array( count( $graphic ), count( $common ), -count( $kt ) );
		if ( ! $best || $rank > $best[0] ) {
			$best = array( $rank, $id );
		}
	}
	return $best ? (int) $best[1] : 0;
}

/**
 * Rękawice FOX Dirtpaw pasujące kolorem do stroju (po słowach koloru w nazwie), np. „180 Frame Blue” → „Dirtpaw … Blue”.
 * Strój junior/dziecięcy – tylko rękawice junior/dziecięce. Brak dopasowania koloru – czarne.
 */
function fx_kit_gloves_for( $key ) {
	$key = trim( (string) $key );
	if ( '' === $key ) {
		return 0;
	}
	$mods_all = array( 'junior', 'kids', 'lady' );
	$tokens   = preg_split( '/\s+/u', $key );
	$mods     = array_values( array_intersect( $tokens, $mods_all ) );
	$words    = array_values( array_diff( $tokens, $mods_all ) );
	$best     = array();
	foreach ( fx_kit_index() as $k => $row ) {
		if ( empty( $row['parts']['rekawice'] ) || false === strpos( $k, 'dirtpaw' ) ) {
			continue;
		}
		$kt    = preg_split( '/\s+/u', $k );
		$kmods = array_values( array_intersect( $kt, $mods_all ) );
		if ( $kmods !== $mods ) {
			continue;
		}
		$colors = array_values( array_diff( $kt, $mods_all, array( 'dirtpaw', 'race', 'ce', 'mx', 'rekawice', 'rękawice' ) ) );
		$score  = count( array_intersect( $colors, $words ) );
		$black  = in_array( 'black', $colors, true ) ? 1 : 0;
		$id     = fx_kit_pick( $row['parts']['rekawice'] );
		$p      = $id ? wc_get_product( $id ) : null;
		if ( ! $p || ! $p->is_visible() || ! $p->is_in_stock() ) {
			continue;
		}
		$rank = array( $score, $black, -count( $colors ) );
		if ( ! $best || $rank > $best[0] ) {
			$best = array( $rank, $id );
		}
	}
	return $best ? (int) $best[1] : 0;
}

/**
 * Produkt-„gospodarz” ręcznego kompletu: sam produkt albo pierwszy produkt, którego komplet zawiera ten produkt.
 */
function fx_kit_host( $product_id ) {
	if ( fx_kit_get_ids( $product_id ) ) {
		return $product_id;
	}
	$found = get_posts(
		array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'key'     => '_fx_kit_ids',
					'value'   => ',' . (int) $product_id . ',',
					'compare' => 'LIKE',
				),
			),
		)
	);
	return $found ? (int) $found[0] : 0;
}

/** Rabat za komplet w procentach (0 = brak). */
function fx_kit_discount() {
	return max( 0, min( 50, (float) fx_tool( 'kit_discount' ) ) );
}

/**
 * Opcje rozmiaru dla produktu (warianty dostępne w magazynie).
 */
function fx_kit_options( $p ) {
	$opts = array();
	if ( $p->is_type( 'variable' ) ) {
		foreach ( $p->get_children() as $vid ) {
			$v = wc_get_product( $vid );
			if ( ! $v || ! $v->exists() || ! $v->is_purchasable() || ! $v->is_in_stock() || ! $v->variation_is_visible() ) {
				continue;
			}
			$labels = array();
			$skip   = false;
			foreach ( $v->get_variation_attributes() as $attr => $value ) {
				if ( '' === $value ) { // Wariant „dowolny” – wymagałby osobnego wyboru, pomijamy.
					$skip = true;
					break;
				}
				$tax = str_replace( 'attribute_', '', $attr );
				if ( taxonomy_exists( $tax ) ) {
					$term     = get_term_by( 'slug', $value, $tax );
					$labels[] = $term ? $term->name : $value;
				} else {
					$labels[] = $value;
				}
			}
			if ( $skip ) {
				continue;
			}
			$opts[] = array(
				'id'    => $vid,
				'label' => fx_size_label( implode( ' / ', $labels ) ),
				'price' => (float) wc_get_price_to_display( $v ),
				'reg'   => (float) wc_get_price_to_display( $v, array( 'price' => $v->get_regular_price() ? $v->get_regular_price() : $v->get_price() ) ),
				'sku'   => $v->get_sku(),
			);
		}
	} elseif ( $p->is_purchasable() && $p->is_in_stock() ) {
		$opts[] = array(
			'id'    => 0,
			'label' => '',
			'price' => (float) wc_get_price_to_display( $p ),
			'reg'   => (float) wc_get_price_to_display( $p, array( 'price' => $p->get_regular_price() ? $p->get_regular_price() : $p->get_price() ) ),
			'sku'   => $p->get_sku(),
		);
	}
	return $opts;
}

/* ---------- Sekcja na karcie produktu ---------- */

/** Typ części do etykiety nad nazwą: „Koszulka”, „Spodnie”, „Gogle”… */
function fx_kit_part_label( $p ) {
	list( $type ) = fx_kit_parse_name( $p->get_name() );
	$labels       = array( 'koszulka' => 'Koszulka', 'spodnie' => 'Spodnie', 'rekawice' => 'Rękawice', 'gogle' => 'Gogle', 'kask' => 'Kask' );
	if ( 'koszulka' === $type && preg_match( '/^\s*bluz/iu', $p->get_name() ) ) {
		return 'Bluza';
	}
	if ( isset( $labels[ $type ] ) ) {
		return $labels[ $type ];
	}
	if ( preg_match( '/^(.*?)\bfox\b/iu', $p->get_name(), $m ) && trim( $m[1] ) ) {
		$w = preg_split( '/\s+/u', trim( $m[1] ) );
		return mb_convert_case( mb_strtolower( $w[0] ), MB_CASE_TITLE, 'UTF-8' );
	}
	$cats = wc_get_product_terms( $p->get_id(), 'product_cat', array( 'fields' => 'names' ) );
	return $cats ? $cats[0] : '';
}

/**
 * Sekcja kompletu: duże kafle części (jak Fox UK) + wybór rozmiaru i dodanie całości do koszyka.
 *
 * @param WC_Product $product Produkt strony.
 * @param bool       $inline  Wersja w kolumnie zakupowej (strona kompletu) – bez dużego zdjęcia.
 */
function fx_kit_section( $product, $inline = false ) {
	if ( ! $product ) {
		return;
	}
	$kit = fx_kit_resolve( $product->get_id() );
	if ( ! $kit['parts'] ) {
		return;
	}
	$items = array();
	foreach ( $kit['parts'] as $id ) {
		$p = wc_get_product( $id );
		if ( $p ) {
			$items[] = array( $p, fx_kit_options( $p ) );
		}
	}
	if ( count( $items ) < 2 ) {
		return;
	}
	$page     = $kit['page'] ? wc_get_product( $kit['page'] ) : null;
	$title    = $kit['title'] ? $kit['title'] : ( $page && $page->get_id() !== $product->get_id() ? $page->get_name() : 'Cała grafika jednym kliknięciem' );
	$pct      = fx_kit_discount();
	$photo_id = $page && $page->get_image_id() ? $page->get_image_id() : $items[0][0]->get_image_id();
	$custom   = get_post_meta( $kit['page'] ? $kit['page'] : $items[0][0]->get_id(), '_fx_kit_img', true );
	// Największa możliwa oszczędność (promocje części + rabat za komplet) do etykiety „do −X%”.
	$reg = 0;
	$now = 0;
	foreach ( $items as $row ) {
		if ( $row[1] ) {
			$reg += max( wp_list_pluck( $row[1], 'reg' ) );
			$now += min( wp_list_pluck( $row[1], 'price' ) );
		}
	}
	$deal  = $now * ( 1 - $pct / 100 );
	$upto  = $reg > 0 ? (int) floor( ( 1 - $deal / $reg ) * 100 ) : 0;
	?>
	<section class="fx-kit<?php echo $inline ? ' fx-kit--inline' : ''; ?>" id="fx-kit" data-fx-kit data-parts="<?php echo count( $items ); ?>" data-pct="<?php echo esc_attr( $pct ); ?>">
		<div class="fx-kit__head">
			<div>
				<?php if ( ! $inline ) : ?><div class="fx-kicker">Zbuduj komplet</div><?php endif; ?>
				<h2><?php echo $inline ? 'Co jest w zestawie?' : esc_html( $title ); ?></h2>
			</div>
			<?php if ( $upto >= 5 ) : ?>
				<span class="fx-kit__badge">Do −<?php echo (int) $upto; ?>% w zestawie</span>
			<?php endif; ?>
		</div>
		<?php if ( ! $inline && $page && $page->get_id() !== $product->get_id() ) : ?>
			<p class="fx-kit__lead">Wybierz rozmiary i dodaj całość do koszyka<?php echo $pct ? ' – w zestawie płacisz ' . esc_html( wc_format_localized_decimal( $pct ) ) . '% mniej' : ''; ?>. <a href="<?php echo esc_url( $page->get_permalink() ); ?>">Zobacz cały komplet</a></p>
		<?php endif; ?>
		<div class="fx-kit__grid">
			<?php if ( ! $inline ) : ?>
			<div class="fx-kit__photo">
				<?php
				if ( $custom ) {
					echo '<img class="fx-cover" src="' . esc_url( $custom ) . '" alt="' . esc_attr( $title ) . '" loading="lazy">';
				} elseif ( $photo_id ) {
					echo wp_get_attachment_image( $photo_id, 'large', false, array( 'class' => 'fx-cover', 'loading' => 'lazy' ) );
				}
				?>
			</div>
			<?php endif; ?>
			<div class="fx-kit__list">
				<div class="fx-kit__tiles">
				<?php foreach ( $items as $row ) : ?>
					<?php
					list( $p, $opts ) = $row;
					$is_current       = ( $p->get_id() === $product->get_id() );
					$fid              = 'fx-kit-' . $p->get_id();
					$cats             = wc_get_product_terms( $p->get_id(), 'product_cat', array( 'fields' => 'names' ) );
					$avail            = (bool) $opts;
					$variable         = $p->is_type( 'variable' );
					$single           = $avail && 1 === count( $opts );
					// Zaznaczone od razu: bluza, spodnie i rękawice. Buty, gogle, kask – propozycja, klient zaznacza sam.
					$is_gear          = ! in_array( $p->get_id(), isset( $kit['core'] ) ? $kit['core'] : $kit['parts'], true ) && 'rekawice' !== fx_kit_parse_name( $p->get_name() )[0];
					?>
					<div class="fx-kit__item<?php echo $avail ? '' : ' is-out'; ?>" data-core="<?php echo in_array( $p->get_id(), isset( $kit['core'] ) ? $kit['core'] : $kit['parts'], true ) ? '1' : '0'; ?>" data-product-id="<?php echo (int) $p->get_id(); ?>" data-name="<?php echo esc_attr( $p->get_name() ); ?>" data-category="<?php echo esc_attr( $cats ? $cats[0] : '' ); ?>">
						<label class="fx-kit__check" title="Dodaj do zestawu">
							<input type="checkbox" id="<?php echo esc_attr( $fid ); ?>-on" <?php checked( $avail && ! $is_gear ); ?> <?php disabled( ! $avail ); ?>>
							<span class="screen-reader-text">W zestawie: <?php echo esc_html( $p->get_name() ); ?></span>
						</label>
						<a class="fx-kit__img" href="<?php echo esc_url( $p->get_permalink() ); ?>"<?php echo $is_current ? ' aria-current="page"' : ''; ?>><?php echo $p->get_image( 'woocommerce_thumbnail' ); // phpcs:ignore ?></a>
						<div class="fx-kit__body">
							<span class="fx-kit__type"><?php echo esc_html( fx_kit_part_label( $p ) ); ?><?php echo $is_current ? ' · ten produkt' : ''; ?></span>
							<a class="fx-kit__name" href="<?php echo esc_url( $p->get_permalink() ); ?>"><?php echo esc_html( $p->get_name() ); ?></a>
							<span class="fx-kit__price"><?php echo $avail ? wp_kses_post( $p->get_price_html() ) : 'Chwilowo niedostępne'; ?></span>
							<?php if ( $avail ) : ?>
								<input type="hidden" data-fx-kit-size data-variable="<?php echo $variable ? 1 : 0; ?>" value="<?php echo $single ? (int) $opts[0]['id'] : ( $variable ? '' : '0' ); ?>" data-price="<?php echo esc_attr( $single || ! $variable ? $opts[0]['price'] : 0 ); ?>" data-reg="<?php echo esc_attr( $single || ! $variable ? $opts[0]['reg'] : 0 ); ?>">
								<?php if ( $variable && ! $single ) : ?>
									<div class="fx-kit__sizes" role="group" aria-label="Rozmiar: <?php echo esc_attr( $p->get_name() ); ?>">
										<?php foreach ( $opts as $o ) : ?>
											<button type="button" class="fx-kit__sz" data-vid="<?php echo (int) $o['id']; ?>" data-price="<?php echo esc_attr( $o['price'] ); ?>" data-reg="<?php echo esc_attr( $o['reg'] ); ?>" aria-pressed="false"><?php echo esc_html( $o['label'] ); ?></button>
										<?php endforeach; ?>
									</div>
								<?php elseif ( $variable && $single ) : ?>
									<span class="fx-kit__one">Rozmiar: <?php echo esc_html( $opts[0]['label'] ); ?></span>
								<?php endif; ?>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
				</div>

				<div class="fx-kit__foot">
					<div class="fx-kit__sum">
						<span class="fx-kit__sumlabel">Razem</span>
						<div class="fx-kit__total" data-fx-kit-total aria-live="polite"></div>
						<div class="fx-kit__summary" data-fx-kit-summary></div>
					</div>
					<button type="button" class="fx-btn fx-kit__add" data-fx-kit-add>Dodaj zestaw do koszyka</button>
				</div>
				<div class="fx-kit__msg" data-fx-kit-msg role="status" aria-live="polite"></div>
			</div>
		</div>
	</section>
	<?php
}

/* ---------- Strona kompletu („Strój na crossa …”) ---------- */

/* Zamiast zwykłego „Dodaj do koszyka”: wybór części i rozmiarów w kolumnie zakupowej. */
add_action( 'woocommerce_single_product_summary', function () {
	global $product;
	if ( ! $product instanceof WC_Product || ! fx_kit_is_page( $product ) ) {
		return;
	}
	remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );
	if ( fx_kit_resolve( $product->get_id() )['parts'] ) {
		fx_kit_section( $product, true );
	}
}, 29 );

/* Strony kompletu nie trafiają do koszyka jako osobny produkt – kupuje się części. */
add_filter( 'woocommerce_is_purchasable', function ( $ok, $product ) {
	return ( $product instanceof WC_Product && ! $product->is_type( 'variation' ) && fx_kit_is_page( $product ) ) ? false : $ok;
}, 20, 2 );

/** Cena kompletu: suma najniższych cen części (z rabatem za komplet). [ pełna, po rabacie ] */
function fx_kit_page_prices( $product_id ) {
	$kit = fx_kit_resolve( $product_id );
	if ( ! $kit['parts'] ) {
		return array( 0, 0 );
	}
	// Tylko góra stroju + spodnie, najtańszy dostępny rozmiar każdej z nich. Gdy którejś nie ma – brak ceny „od”.
	$sum   = 0;
	$found = array();
	foreach ( $kit['parts'] as $id ) {
		$type = fx_kit_parse_name( get_the_title( $id ) )[0];
		if ( ! in_array( $type, array( 'koszulka', 'spodnie' ), true ) || isset( $found[ $type ] ) ) {
			continue;
		}
		$p = wc_get_product( $id );
		if ( ! $p || ! $p->is_in_stock() ) {
			continue;
		}
		$min = 0;
		if ( $p->is_type( 'variable' ) ) {
			foreach ( $p->get_available_variations( 'objects' ) as $v ) {
				if ( $v && $v->is_in_stock() && $v->is_purchasable() ) {
					$price = (float) wc_get_price_to_display( $v );
					if ( $price > 0 && ( ! $min || $price < $min ) ) {
						$min = $price;
					}
				}
			}
		} else {
			$min = (float) wc_get_price_to_display( $p );
		}
		if ( $min > 0 ) {
			$found[ $type ] = $min;
		}
	}
	if ( count( $found ) < 2 ) {
		return array( 0, 0 );
	}
	$sum = array_sum( $found );
	return array( $sum, $sum * ( 1 - fx_kit_discount() / 100 ) );
}

add_filter( 'woocommerce_get_price_html', function ( $html, $product ) {
	if ( ! $product instanceof WC_Product || $product->is_type( 'variation' ) || ! fx_kit_is_page( $product ) ) {
		return $html;
	}
	list( $full, $deal ) = fx_kit_page_prices( $product->get_id() );
	if ( $full <= 0 ) {
		return '<span class="fx-kit-from">Sprawdź dostępne części</span>';
	}
	$out = '<span class="fx-kit-from">Komplet od</span> ';
	if ( $deal < $full ) {
		$out .= '<del aria-hidden="true">' . wc_price( $full ) . '</del> <ins>' . wc_price( $deal ) . '</ins>';
	} else {
		$out .= wc_price( $full );
	}
	return $out;
}, 30, 2 );

/* =====================================================================
 * RABAT ZA KOMPLET W KOSZYKU
 * Gdy w koszyku są wszystkie części kompletu, ich ceny spadają o ustawiony procent
 * (tyle sztuk, ile pełnych kompletów). Ceny pozycji są obniżone – faktura i BaseLinker widzą prawdziwe kwoty.
 * ===================================================================== */
add_action( 'woocommerce_before_calculate_totals', 'fx_kit_cart_discount', 20 );
function fx_kit_cart_discount( $cart ) {
	if ( ( is_admin() && ! wp_doing_ajax() ) || ! $cart ) {
		return;
	}
	$GLOBALS['fx_kit_saved'] = 0;
	$pct = fx_kit_discount();
	if ( $pct <= 0 ) {
		return;
	}
	$qty = array();
	foreach ( $cart->get_cart() as $k => $item ) {
		$pid         = (int) $item['product_id'];
		$qty[ $pid ] = ( isset( $qty[ $pid ] ) ? $qty[ $pid ] : 0 ) + (int) $item['quantity'];
	}
	// Pełne komplety w koszyku: ile sztuk każdej części objąć rabatem.
	$units = array();
	$seen  = array();
	foreach ( array_keys( $qty ) as $pid ) {
		$kit   = fx_kit_resolve( $pid );
		$parts = isset( $kit['core'] ) ? $kit['core'] : $kit['parts'];
		if ( count( $parts ) < 2 ) {
			continue;
		}
		$sig = implode( ',', $parts );
		if ( isset( $seen[ $sig ] ) ) {
			continue;
		}
		$seen[ $sig ] = true;
		$n            = PHP_INT_MAX;
		foreach ( $parts as $part ) {
			$left = ( isset( $qty[ $part ] ) ? $qty[ $part ] : 0 ) - ( isset( $units[ $part ] ) ? $units[ $part ] : 0 );
			$n    = min( $n, $left );
		}
		if ( $n > 0 && PHP_INT_MAX !== $n ) {
			foreach ( $parts as $part ) {
				$units[ $part ] = ( isset( $units[ $part ] ) ? $units[ $part ] : 0 ) + $n;
			}
			// Dobrane rękawice też z rabatem, jeśli są w koszyku.
			foreach ( ( isset( $kit['extra'] ) ? $kit['extra'] : array() ) as $ex ) {
				$left = ( isset( $qty[ $ex ] ) ? $qty[ $ex ] : 0 ) - ( isset( $units[ $ex ] ) ? $units[ $ex ] : 0 );
				if ( $left > 0 ) {
					$units[ $ex ] = ( isset( $units[ $ex ] ) ? $units[ $ex ] : 0 ) + min( $n, $left );
				}
			}
		}
	}
	foreach ( $cart->get_cart() as $k => $item ) {
		$pid  = (int) $item['product_id'];
		$line = wc_get_product( $item['variation_id'] ? $item['variation_id'] : $pid );
		if ( ! $line ) {
			continue;
		}
		$orig = (float) $line->get_price();
		$cart->cart_contents[ $k ]['fx_kit_orig'] = $orig;
		$cart->cart_contents[ $k ]['fx_kit_units'] = 0;
		if ( empty( $units[ $pid ] ) || $orig <= 0 ) {
			$item['data']->set_price( $orig );
			continue;
		}
		$du = min( $units[ $pid ], (int) $item['quantity'] );
		$units[ $pid ] -= $du;
		$new = $orig * ( 1 - ( $pct / 100 ) * $du / max( 1, (int) $item['quantity'] ) );
		$item['data']->set_price( round( $new, wc_get_price_decimals() ) );
		$cart->cart_contents[ $k ]['fx_kit_units'] = $du;
		$GLOBALS['fx_kit_saved'] += ( $orig - $new ) * (int) $item['quantity'];
	}
}

add_filter( 'woocommerce_cart_item_price', function ( $price, $item ) {
	if ( empty( $item['fx_kit_units'] ) || empty( $item['fx_kit_orig'] ) ) {
		return $price;
	}
	return '<del>' . wc_price( $item['fx_kit_orig'] ) . '</del> ' . $price . '<br><small class="fx-kit-badge">w komplecie −' . esc_html( wc_format_localized_decimal( fx_kit_discount() ) ) . '%</small>';
}, 20, 2 );

add_action( 'woocommerce_cart_totals_before_order_total', 'fx_kit_saved_row' );
add_action( 'woocommerce_review_order_before_order_total', 'fx_kit_saved_row' );
function fx_kit_saved_row() {
	$saved = isset( $GLOBALS['fx_kit_saved'] ) ? (float) $GLOBALS['fx_kit_saved'] : 0;
	if ( $saved <= 0 ) {
		return;
	}
	echo '<tr class="fx-kit-saved"><th>Rabat za komplet</th><td data-title="Rabat za komplet">−' . wc_price( $saved ) . ' <small>(już w cenach)</small></td></tr>'; // phpcs:ignore
}

/* ---------- Dane dla skryptu ---------- */

add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_product() ) {
		return;
	}
	wp_localize_script(
		'fx-theme',
		'fxKit',
		array(
			'url'       => WC_AJAX::get_endpoint( 'fx_kit_add' ),
			'cartUrl'   => wc_get_cart_url(),
			'datalayer' => (bool) fx_mod( 'datalayer' ),
			'currency'  => get_woocommerce_currency(),
			'symbol'    => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
			'pos'       => get_option( 'woocommerce_currency_pos', 'right_space' ),
		'pct'       => fx_kit_discount(),
			'dec'       => wc_get_price_decimals(),
			'ds'        => wc_get_price_decimal_separator(),
			'ts'        => wc_get_price_thousand_separator(),
		)
	);
}, 20 );

/* ---------- Dodanie kompletu do koszyka ---------- */

add_action( 'wc_ajax_fx_kit_add', 'fx_kit_add' );
function fx_kit_add() {
	if ( null === WC()->cart && function_exists( 'wc_load_cart' ) ) {
		wc_load_cart();
	}
	// phpcs:ignore WordPress.Security.NonceVerification -- dodanie do koszyka, jak w WooCommerce, nie wymaga nonce (zgodność z cache stron).
	$raw   = isset( $_POST['items'] ) ? json_decode( wp_unslash( $_POST['items'] ), true ) : array();
	$items = is_array( $raw ) ? array_slice( $raw, 0, 10 ) : array();
	$added = array();

	foreach ( $items as $it ) {
		$pid = isset( $it['product_id'] ) ? absint( $it['product_id'] ) : 0;
		$vid = isset( $it['variation_id'] ) ? absint( $it['variation_id'] ) : 0;
		$p   = wc_get_product( $pid );
		if ( ! $p ) {
			continue;
		}
		$attrs = array();
		if ( $vid ) {
			$v = wc_get_product( $vid );
			if ( ! $v || $v->get_parent_id() !== $pid ) {
				continue;
			}
			$attrs = $v->get_variation_attributes();
			if ( in_array( '', $attrs, true ) ) {
				continue;
			}
		} elseif ( $p->is_type( 'variable' ) ) {
			continue;
		}
		$ok = apply_filters( 'woocommerce_add_to_cart_validation', true, $pid, 1, $vid, $attrs );
		if ( ! $ok ) {
			continue;
		}
		$key = WC()->cart->add_to_cart( $pid, 1, $vid, $attrs );
		if ( $key ) {
			do_action( 'woocommerce_ajax_added_to_cart', $pid );
			$line    = $vid ? wc_get_product( $vid ) : $p;
			$added[] = array(
				'item_id'      => $line->get_sku() ? $line->get_sku() : (string) $line->get_id(),
				'item_name'    => $p->get_name(),
				'item_variant' => $vid ? wc_get_formatted_variation( $line, true, false, false ) : '',
				'price'        => (float) wc_get_price_to_display( $line ),
				'quantity'     => 1,
			);
		}
	}

	$errors = array();
	foreach ( wc_get_notices( 'error' ) as $n ) {
		$errors[] = wp_strip_all_tags( is_array( $n ) ? $n['notice'] : $n );
	}
	wc_clear_notices();

	if ( ! $added ) {
		wp_send_json_error( array( 'message' => $errors ? implode( ' ', $errors ) : 'Nie udało się dodać kompletu. Sprawdź rozmiary i spróbuj ponownie.' ) );
	}

	ob_start();
	woocommerce_mini_cart();
	$mini = ob_get_clean();

	wp_send_json_success(
		array(
			'added'     => $added,
			'errors'    => $errors,
			'fragments' => apply_filters( 'woocommerce_add_to_cart_fragments', array( 'div.widget_shopping_cart_content' => '<div class="widget_shopping_cart_content">' . $mini . '</div>' ) ),
			'cart_hash' => WC()->cart->get_cart_hash(),
		)
	);
}

/* Klasa strony kompletu (szersza kolumna z kaflami). */
add_filter( 'body_class', function ( $c ) {
	if ( function_exists( 'is_product' ) && is_product() && fx_kit_is_page( get_queried_object_id() ) ) {
		$c[] = 'fx-kit-page';
	}
	return $c;
} );

/* ---------- Koszyk: „Dokończ komplet” – brakujące części kompletów z koszyka ---------- */
function fx_kit_cart_missing( $limit = 4 ) {
	if ( ! WC()->cart ) {
		return array();
	}
	$in = array();
	foreach ( WC()->cart->get_cart() as $item ) {
		$in[ (int) $item['product_id'] ] = true;
	}
	$missing = array();
	foreach ( array_keys( $in ) as $pid ) {
		$kit = fx_kit_resolve( $pid );
		if ( count( $kit['parts'] ) < 2 ) {
			continue;
		}
		foreach ( $kit['parts'] as $part ) {
			if ( empty( $in[ $part ] ) && ! isset( $missing[ $part ] ) ) {
				$p = wc_get_product( $part );
				if ( $p && $p->is_visible() && $p->is_in_stock() && $p->is_purchasable() ) {
					$missing[ $part ] = $pid;
				}
			}
		}
	}
	return array_slice( array_keys( $missing ), 0, $limit );
}

add_action( 'woocommerce_after_cart', function () {
	$ids = fx_kit_cart_missing( 4 );
	if ( ! $ids ) {
		return;
	}
	$pct = fx_kit_discount();
	echo '<section class="fx-kit-upsell"><div class="fx-row-head"><h2>Dokończ komplet</h2></div>';
	echo '<p class="fx-kit-upsell__lead">' . ( $pct > 0 ? 'Dobierz brakujące części tej samej grafiki – za pełny komplet w koszyku dostaniesz <strong>−' . esc_html( wc_format_localized_decimal( $pct ) ) . '%</strong> na każdą część.' : 'Dobierz brakujące części tej samej grafiki.' ) . '</p>';
	echo do_shortcode( '[products ids="' . implode( ',', array_map( 'intval', $ids ) ) . '" orderby="post__in" columns="4" limit="4"]' ); // phpcs:ignore
	echo '</section>';
}, 5 );
