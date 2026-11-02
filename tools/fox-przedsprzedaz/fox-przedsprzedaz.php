<?php
/**
 * Plugin Name: FOX – Przedsprzedaż Powerbike
 * Description: Produkty bez stanu, które mają w Powerbike datę dostawy, wracają do sklepu jako przedsprzedaż („Na zamówienie”): widoczne, do kupienia i indeksowane w Google. Daty z importera Powerbike na airoh.sklep.pl (ten sam adres i klucz co „Przeceny Powerbike”). Gdy data minie albo towar przyjdzie – przedsprzedaż sama się wyłącza.
 * Version: 1.0.0
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * WC requires at least: 7.0
 * Text Domain: fox-przedsprzedaz
 */

defined( 'ABSPATH' ) || exit;

define( 'FXPO_VERSION', '1.0.0' );

final class FXPO {

	const OPT    = 'fxpo_settings';
	const ACTIVE = 'fxpo_active';   // [ id => [ 'date' => Y-m-d, 'parent' => id, 'sku' => …, 'via' => … ] ]
	const QUEUE  = 'fxpo_queue';
	const LOG    = 'fxpo_log';
	const STATS  = 'fxpo_stats';
	const EVENT  = 'fxpo_event';
	const META   = '_fxpo_date';    // data dostawy do nas (Y-m-d) – znacznik „to nasza przedsprzedaż”
	const PREV   = '_fxpo_prev';    // ustawienia sprzed przedsprzedaży (do przywrócenia)

	/* =====================================================================
	 * Ustawienia
	 * ===================================================================== */

	public static function defaults() {
		$pbs = get_option( 'pbs_settings', array() ); // „Przeceny Powerbike” – ten sam importer i klucz
		return array(
			'on'         => 0,
			'source_url' => is_array( $pbs ) && ! empty( $pbs['source_url'] ) ? $pbs['source_url'] : 'https://www.airoh.sklep.pl/wp-json/pbi/v1/products',
			'key'        => is_array( $pbs ) && ! empty( $pbs['key'] ) ? $pbs['key'] : '',
			'producers'  => 'FOX',
			'max_days'   => 90,   // dalsze dostawy nie trafiają do przedsprzedaży
			'ship_days'  => 2,    // wysyłka do klienta: tyle dni roboczych po dostawie do nas
			'times'      => '6:30, 13:30',
			'label'      => 'Przedsprzedaż',
			'consent'    => 1,
			'ean_metas'  => '_woosea_ean, _global_unique_id',
		);
	}

	public static function get( $key = null ) {
		$s = wp_parse_args( (array) get_option( self::OPT, array() ), self::defaults() );
		// Puste adres / klucz = z „Przeceny Powerbike”.
		$d = self::defaults();
		foreach ( array( 'source_url', 'key' ) as $k ) {
			if ( '' === trim( (string) $s[ $k ] ) ) {
				$s[ $k ] = $d[ $k ];
			}
		}
		return null === $key ? $s : ( $s[ $key ] ?? null );
	}

	public static function log( $msg ) {
		$log   = (array) get_option( self::LOG, array() );
		$log[] = array( time(), (string) $msg );
		update_option( self::LOG, array_slice( $log, -300 ), false );
	}

	/* =====================================================================
	 * Daty
	 * ===================================================================== */

	public static function tz() {
		return new DateTimeZone( 'Europe/Warsaw' );
	}

	/** Dni wolne: święta z motywu (jeśli jest) + weekendy. */
	public static function is_work( DateTimeImmutable $d ) {
		if ( (int) $d->format( 'N' ) >= 6 ) {
			return false;
		}
		static $hol = null;
		if ( null === $hol ) {
			$hol = array();
			if ( function_exists( 'fox_bl_delivery_get_settings' ) && function_exists( 'fox_bl_delivery_blocked' ) ) {
				$hol = fox_bl_delivery_blocked( fox_bl_delivery_get_settings() );
			} elseif ( function_exists( 'fx_pl_holidays' ) ) {
				$y = (int) wp_date( 'Y' );
				foreach ( array_merge( fx_pl_holidays( $y ), fx_pl_holidays( $y + 1 ) ) as $x ) {
					$hol[ $x ] = true;
				}
			}
		}
		return empty( $hol[ $d->format( 'Y-m-d' ) ] );
	}

	/** Planowana wysyłka do klienta: data dostawy do nas + X dni roboczych (najwcześniej jutro). */
	public static function ship_date( $delivery ) {
		$d    = new DateTimeImmutable( $delivery . ' 12:00:00', self::tz() );
		$n    = max( 0, (int) self::get( 'ship_days' ) );
		while ( ! self::is_work( $d ) ) {
			$d = $d->modify( '+1 day' );
		}
		while ( $n > 0 ) {
			$d = $d->modify( '+1 day' );
			if ( self::is_work( $d ) ) {
				$n--;
			}
		}
		return $d->format( 'Y-m-d' );
	}

	public static function pl_date( $ymd, $year = false ) {
		$d = DateTimeImmutable::createFromFormat( 'Y-m-d', $ymd, self::tz() );
		return $d ? $d->format( $year ? 'd.m.Y' : 'd.m' ) : $ymd;
	}

	/* =====================================================================
	 * Synchronizacja
	 * ===================================================================== */

	/** Pozycje z importera (marka/marki z ustawień). @throws Exception */
	public static function fetch() {
		$s = self::get();
		if ( '' === trim( $s['source_url'] ) || '' === trim( $s['key'] ) ) {
			throw new Exception( 'Brak adresu importera lub klucza (ustaw je tutaj albo w „Przeceny Powerbike”).' );
		}
		$out  = array();
		$page = 1;
		do {
			$url = add_query_arg( array( 'producer' => $s['producers'], 'page' => $page, 'per_page' => 2000, '_' => time() ), trim( $s['source_url'] ) );
			$r   = wp_remote_get( $url, array( 'timeout' => 60, 'headers' => array( 'X-PBI-Key' => trim( $s['key'] ), 'Cache-Control' => 'no-cache' ) ) );
			if ( is_wp_error( $r ) ) {
				throw new Exception( 'Importer: ' . $r->get_error_message() );
			}
			$code = (int) wp_remote_retrieve_response_code( $r );
			$data = json_decode( wp_remote_retrieve_body( $r ), true );
			if ( 200 !== $code || ! is_array( $data ) || ! isset( $data['items'] ) ) {
				throw new Exception( 'Importer: HTTP ' . $code . ' – ' . wp_trim_words( wp_strip_all_tags( wp_remote_retrieve_body( $r ) ), 20 ) );
			}
			$out = array_merge( $out, (array) $data['items'] );
			$page++;
		} while ( count( $data['items'] ) >= (int) $data['per_page'] && $page < 100 );
		return $out;
	}

	/** Dopasowanie pozycji do wariantów/produktów prostych sklepu: po SKU, potem po EAN. */
	public static function resolve( array $items, &$unmatched = 0 ) {
		global $wpdb;
		$skus = array();
		foreach ( $items as $it ) {
			foreach ( (array) ( $it['skus'] ?? array() ) as $sku ) {
				$skus[] = trim( (string) $sku );
			}
		}
		$by_sku = array();
		foreach ( array_chunk( array_values( array_unique( array_filter( $skus, 'strlen' ) ) ), 500 ) as $chunk ) {
			$in = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT l.product_id id, l.sku FROM {$wpdb->wc_product_meta_lookup} l JOIN {$wpdb->posts} p ON p.ID = l.product_id AND p.post_status = 'publish' WHERE l.sku IN ($in)", $chunk ) ) as $r ) { // phpcs:ignore
				$by_sku[ mb_strtoupper( $r->sku ) ] = (int) $r->id;
			}
			// Brakujące – prosto z danych produktu (tabela pomocnicza WooCommerce bywa nieaktualna).
			$miss = array_values( array_filter( $chunk, function ( $x ) use ( $by_sku ) {
				return ! isset( $by_sku[ mb_strtoupper( $x ) ] );
			} ) );
			if ( $miss ) {
				$in = implode( ',', array_fill( 0, count( $miss ), '%s' ) );
				foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT pm.post_id id, pm.meta_value sku FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type IN ('product','product_variation') AND p.post_status = 'publish' WHERE pm.meta_key = '_sku' AND pm.meta_value IN ($in)", $miss ) ) as $r ) { // phpcs:ignore
					$by_sku[ mb_strtoupper( $r->sku ) ] = (int) $r->id;
				}
			}
		}
		$metas = array_filter( array_map( 'trim', explode( ',', (string) self::get( 'ean_metas' ) ) ) );
		$eans  = array();
		foreach ( $items as $it ) {
			$e = preg_replace( '/\D/', '', (string) ( $it['ean'] ?? '' ) );
			if ( strlen( $e ) >= 8 ) {
				$eans[ $e ] = true;
			}
		}
		$by_ean = array();
		if ( $metas && $eans ) {
			$mk = implode( ',', array_fill( 0, count( $metas ), '%s' ) );
			foreach ( array_chunk( array_keys( $eans ), 500 ) as $chunk ) {
				$in = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
				foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT pm.post_id id, pm.meta_value ean FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type IN ('product','product_variation') AND p.post_status = 'publish' WHERE pm.meta_key IN ($mk) AND pm.meta_value IN ($in)", array_merge( $metas, $chunk ) ) ) as $r ) { // phpcs:ignore
					$by_ean[ $r->ean ] = (int) $r->id;
				}
			}
		}
		$out       = array();
		$unmatched = 0;
		foreach ( $items as $it ) {
			$id  = 0;
			$via = '';
			foreach ( (array) ( $it['skus'] ?? array() ) as $sku ) {
				$k = mb_strtoupper( trim( (string) $sku ) );
				if ( isset( $by_sku[ $k ] ) ) {
					$id  = $by_sku[ $k ];
					$via = 'SKU ' . $sku;
					break;
				}
			}
			if ( ! $id ) {
				$e = preg_replace( '/\D/', '', (string) ( $it['ean'] ?? '' ) );
				if ( isset( $by_ean[ $e ] ) ) {
					$id  = $by_ean[ $e ];
					$via = 'EAN ' . $e;
				}
			}
			if ( $id ) {
				$out[ $id ] = array( $it, $via );
			} else {
				$unmatched++;
			}
		}
		return $out;
	}

	/** Pełna synchronizacja: pobranie, dopasowanie, kolejka zmian. Zwraca opis. */
	public static function sync( $who = 'harmonogram' ) {
		global $wpdb;
		$s = self::get();
		self::log( '=== START (' . $who . ')' );
		try {
			$items = self::fetch();
		} catch ( Exception $e ) {
			self::log( 'BŁĄD: ' . $e->getMessage() . ' – nic nie zmieniam.' );
			update_option( self::STATS, array( 'time' => time(), 'error' => $e->getMessage() ) + (array) get_option( self::STATS, array() ), false );
			return $e->getMessage();
		}
		$today = new DateTimeImmutable( 'today', self::tz() );
		$limit = $today->modify( '+' . max( 1, (int) $s['max_days'] ) . ' days' )->format( 'Y-m-d' );
		$cand  = array();
		$dated = 0;
		foreach ( $items as $it ) {
			$date = (string) ( $it['delivery_date'] ?? '' );
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
				continue;
			}
			$dated++;
			if ( $date <= $today->format( 'Y-m-d' ) || $date > $limit || empty( $it['in_offer'] ) || (int) ( $it['availability'] ?? 0 ) > 0 ) {
				continue; // przeszła / za odległa / poza ofertą / Powerbike ma towar (zwykła wysyłka)
			}
			$cand[] = $it;
		}
		$unmatched = 0;
		$found     = self::resolve( $cand, $unmatched );

		// Typ i stan w sklepie (jednym zapytaniem): tylko warianty / proste, tylko bez stanu.
		$info = array();
		foreach ( array_chunk( array_keys( $found ), 500 ) as $chunk ) {
			$in = implode( ',', array_map( 'intval', $chunk ) );
			foreach ( (array) $wpdb->get_results( "SELECT p.ID, p.post_type, p.post_parent, l.stock_status FROM {$wpdb->posts} p LEFT JOIN {$wpdb->wc_product_meta_lookup} l ON l.product_id = p.ID WHERE p.ID IN ($in)" ) as $r ) { // phpcs:ignore
				$info[ (int) $r->ID ] = $r;
			}
		}
		$active = (array) get_option( self::ACTIVE, array() );
		$new    = array();
		foreach ( $found as $id => $f ) {
			$r = $info[ $id ] ?? null;
			if ( ! $r ) {
				continue;
			}
			if ( 'product' === $r->post_type && has_term( 'variable', 'product_type', $id ) ) {
				continue; // trafienie w produkt główny – przedsprzedaż ustawiamy na wariantach
			}
			$ours = isset( $active[ $id ] );
			if ( 'instock' === $r->stock_status || ( 'onbackorder' === $r->stock_status && ! $ours ) ) {
				continue; // jest na stanie albo ktoś sam ustawił „na zamówienie” – nie ruszamy
			}
			$new[ $id ] = array(
				'date'   => $f[0]['delivery_date'],
				'parent' => 'product_variation' === $r->post_type ? (int) $r->post_parent : (int) $id,
				'sku'    => (string) ( $f[0]['skus'][0] ?? '' ),
				'via'    => $f[1],
				'name'   => (string) ( $f[0]['name'] ?? '' ),
			);
		}
		// Bezpiecznik: importer pusty, a u nas dużo przedsprzedaży – nie zdejmuj hurtem.
		if ( ! $items && count( $active ) > 5 ) {
			self::log( 'BEZPIECZNIK: importer zwrócił 0 pozycji – nic nie zdejmuję.' );
			return 'Importer zwrócił 0 pozycji.';
		}
		$queue = array();
		foreach ( $new as $id => $row ) {
			if ( ! isset( $active[ $id ] ) ) {
				$queue[] = array( 'on', $id, $row['date'] );
			} elseif ( $active[ $id ]['date'] !== $row['date'] ) {
				$queue[] = array( 'date', $id, $row['date'] );
			}
		}
		foreach ( $active as $id => $row ) {
			if ( ! isset( $new[ $id ] ) ) {
				$queue[] = array( 'off', (int) $id, '' );
			}
		}
		update_option( self::QUEUE, $queue, false );
		update_option( 'fxpo_next_active', $new, false );
		$stats = array(
			'time'      => time(),
			'items'     => count( $items ),
			'dated'     => $dated,
			'cand'      => count( $cand ),
			'matched'   => count( $found ),
			'unmatched' => $unmatched,
			'preorder'  => count( $new ),
			'on'        => count( wp_list_filter( $queue, array( 0 => 'on' ) ) ),
			'off'       => count( wp_list_filter( $queue, array( 0 => 'off' ) ) ),
			'error'     => '',
		);
		update_option( self::STATS, $stats, false );
		self::log( sprintf( 'Importer: %d pozycji, z datą dostawy %d, w oknie %d dni i bez stanu u Powerbike %d, dopasowane w sklepie %d (bez pary %d). Przedsprzedaż: %d. Zmiany: +%d / −%d / data %d.', $stats['items'], $dated, (int) $s['max_days'], $stats['cand'], $stats['matched'], $unmatched, $stats['preorder'], $stats['on'], $stats['off'], count( $queue ) - $stats['on'] - $stats['off'] ) );
		return '';
	}

	/** Zapis zmian partiami (lekko: zwykły zapis WooCommerce, tylko dla zmienionych pozycji). */
	public static function apply( $seconds = 20 ) {
		$queue  = (array) get_option( self::QUEUE, array() );
		$active = (array) get_option( self::ACTIVE, array() );
		$next   = (array) get_option( 'fxpo_next_active', array() );
		$t0     = microtime( true );
		$parents = array();
		while ( $queue && microtime( true ) - $t0 < $seconds ) {
			list( $what, $id, $date ) = array_shift( $queue );
			$p = wc_get_product( $id );
			if ( ! $p ) {
				unset( $active[ $id ] );
				continue;
			}
			if ( 'on' === $what ) {
				$p->update_meta_data( self::PREV, wp_json_encode( array( 'backorders' => $p->get_backorders(), 'status' => $p->get_stock_status(), 'manage' => $p->get_manage_stock() ) ) );
				$p->update_meta_data( self::META, $date );
				if ( $p->get_manage_stock() ) {
					$p->set_backorders( 'notify' ); // stan 0 + zamówienia dozwolone = „Na zamówienie”
				} else {
					$p->set_stock_status( 'onbackorder' );
				}
				$p->save();
				$active[ $id ] = $next[ $id ] ?? array( 'date' => $date, 'parent' => $p->get_parent_id() ? $p->get_parent_id() : $id );
				self::log( '+ ' . $p->get_name() . ' – dostawa ' . self::pl_date( $date, true ) );
			} elseif ( 'date' === $what ) {
				$p->update_meta_data( self::META, $date );
				$p->save_meta_data();
				$active[ $id ] = $next[ $id ] ?? $active[ $id ];
				self::log( '~ ' . $p->get_name() . ' – nowa data dostawy ' . self::pl_date( $date, true ) );
			} else {
				$prev = json_decode( (string) $p->get_meta( self::PREV ), true );
				$p->delete_meta_data( self::META );
				$p->delete_meta_data( self::PREV );
				if ( $p->get_manage_stock() ) {
					$p->set_backorders( is_array( $prev ) && ! empty( $prev['backorders'] ) && 'notify' !== $prev['backorders'] ? $prev['backorders'] : 'no' );
				} elseif ( 'onbackorder' === $p->get_stock_status() ) {
					$p->set_stock_status( 'outofstock' );
				}
				$p->save();
				unset( $active[ $id ] );
				self::log( '− ' . $p->get_name() . ' – koniec przedsprzedaży' );
			}
			if ( $p->get_parent_id() ) {
				$parents[ $p->get_parent_id() ] = true;
			}
		}
		foreach ( array_keys( $parents ) as $pid ) {
			if ( class_exists( 'WC_Product_Variable' ) ) {
				WC_Product_Variable::sync( $pid );
			}
			wc_delete_product_transients( $pid );
		}
		update_option( self::QUEUE, $queue, false );
		update_option( self::ACTIVE, $active, false );
		if ( ! $queue ) {
			update_option( self::STATS, array( 'applied' => time() ) + (array) get_option( self::STATS, array() ), false );
			if ( function_exists( 'fx_cache_touch' ) ) {
				fx_cache_touch();
			}
		}
		return ! $queue;
	}

	/* =====================================================================
	 * Harmonogram
	 * ===================================================================== */

	public static function next_time() {
		$now  = new DateTimeImmutable( 'now', self::tz() );
		$best = null;
		foreach ( preg_split( '/[\s,;]+/', (string) self::get( 'times' ) ) as $t ) {
			if ( preg_match( '/^(\d{1,2})[:.](\d{2})$/', trim( $t ), $m ) ) {
				$c = $now->setTime( min( 23, (int) $m[1] ), min( 59, (int) $m[2] ) );
				if ( $c <= $now ) {
					$c = $c->modify( '+1 day' );
				}
				if ( ! $best || $c < $best ) {
					$best = $c;
				}
			}
		}
		return $best ? $best->getTimestamp() : 0;
	}

	public static function schedule() {
		wp_clear_scheduled_hook( self::EVENT, array( 'sync' ) );
		if ( self::get( 'on' ) && self::next_time() ) {
			wp_schedule_single_event( self::next_time(), self::EVENT, array( 'sync' ) );
		}
	}

	public static function job( $mode = 'sync' ) {
		if ( 'sync' === $mode ) {
			self::schedule();
			if ( ! self::get( 'on' ) ) {
				return;
			}
			self::sync();
		}
		if ( ! self::apply( 25 ) ) {
			wp_schedule_single_event( time() + 10, self::EVENT, array( 'apply' ) );
		}
	}

	/* =====================================================================
	 * Sklep: produkt, koszyk, zamówienie, Google
	 * ===================================================================== */

	/** Data dostawy (Y-m-d), jeśli wariant/produkt jest teraz w naszej przedsprzedaży. */
	public static function date_of( $id ) {
		static $active = null;
		if ( null === $active ) {
			$active = (array) get_option( self::ACTIVE, array() );
		}
		return isset( $active[ (int) $id ] ) ? (string) $active[ (int) $id ]['date'] : '';
	}

	/** Produkty (nadrzędne/proste) z przedsprzedażą. */
	public static function parent_ids() {
		$out = array();
		foreach ( (array) get_option( self::ACTIVE, array() ) as $id => $r ) {
			$out[ (int) ( $r['parent'] ?? $id ) ] = true;
		}
		return $out;
	}

	public static function override_for( $id ) {
		$date = self::date_of( $id );
		if ( ! $date ) {
			return null;
		}
		$label = (string) self::get( 'label' );
		return array(
			'title' => $label . ' – wyślemy ok. ' . self::pl_date( self::ship_date( $date ) ),
			'desc'  => 'Dostawa do nas planowana na ' . self::pl_date( $date, true ) . ' (termin dystrybutora, może się przesunąć). Zamów teraz – sztuka jest rezerwowana dla Ciebie, a o wysyłce damy znać mailem.',
			'badge' => mb_strtolower( $label ),
		);
	}

	public static function front() {
		// Tekst dostępności zamiast „Na zamówienie”.
		add_filter( 'woocommerce_get_availability_text', function ( $text, $product ) {
			$d = $product instanceof WC_Product ? self::date_of( $product->get_id() ) : '';
			return $d ? self::get( 'label' ) . ' – dostawa do nas ok. ' . self::pl_date( $d, true ) : $text;
		}, 20, 2 );

		// Dane wariantu dla karty produktu (komunikat w ramce terminu wysyłki motywu).
		add_filter( 'woocommerce_available_variation', function ( $data, $parent, $variation ) {
			$o = self::override_for( $variation->get_id() );
			if ( $o ) {
				$data['fx_ship_override'] = $o;
				$data['fxpo_date']        = self::date_of( $variation->get_id() );
			}
			return $data;
		}, 20, 3 );
		add_filter( 'fx_ship_cta_override', function ( $o, $product ) {
			return self::override_for( $product->get_id() ) ?: $o;
		}, 10, 2 );

		// Etykieta na kafelku – gdy produkt jest już tylko w przedsprzedaży (nic na stanie).
		add_filter( 'fx_product_badges', function ( $badges, $product ) {
			if ( $product instanceof WC_Product && 'onbackorder' === $product->get_stock_status() && isset( self::parent_ids()[ $product->get_id() ] ) ) {
				array_unshift( $badges, array( self::get( 'label' ), 'strong' ) );
				$badges = array_slice( $badges, 0, 3 );
			}
			return $badges;
		}, 20, 2 );

		// Termin całego koszyka w motywie (moduł terminu wysyłki).
		add_filter( 'fox_bl_delivery_line_dispatch', function ( $own, $id ) {
			$d = self::date_of( $id );
			return $d ? self::ship_date( $d ) : $own;
		}, 10, 2 );

		// Koszyk: dopisek przy pozycji.
		add_filter( 'woocommerce_get_item_data', function ( $data, $item ) {
			$id = ! empty( $item['variation_id'] ) ? $item['variation_id'] : ( $item['product_id'] ?? 0 );
			$d  = self::date_of( $id );
			if ( $d ) {
				$data[] = array( 'key' => self::get( 'label' ), 'value' => 'wysyłka ok. ' . self::pl_date( self::ship_date( $d ), true ) );
			}
			return $data;
		}, 20, 2 );

		// Zamówienie: widoczna informacja przy pozycji + znacznik dla ramki terminu w panelu.
		add_action( 'woocommerce_checkout_create_order_line_item', function ( $item, $key, $values ) {
			$p = $values['data'] ?? null;
			$d = $p instanceof WC_Product ? self::date_of( $p->get_id() ) : '';
			if ( $d ) {
				$item->add_meta_data( self::get( 'label' ), 'dostawa do nas ok. ' . self::pl_date( $d, true ) . ', wysyłka ok. ' . self::pl_date( self::ship_date( $d ), true ), true );
				$item->add_meta_data( '_fxpo_date', self::pl_date( $d, true ), true );
				$item->update_meta_data( '_fx_ship_src', 'przedsprzedaz' );
			}
		}, 30, 3 );

		// Zgoda na termin przy przedsprzedaży.
		add_action( 'woocommerce_review_order_before_submit', function () {
			if ( ! self::get( 'consent' ) || ! self::cart_dates() ) {
				return;
			}
			$latest = max( self::cart_dates() );
			echo '<p class="form-row fxpo-consent validate-required"><label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox"><input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" name="fxpo_consent" value="1"> <span>Rozumiem, że w zamówieniu jest produkt w przedsprzedaży – wyślemy go po dostawie do nas (ok. ' . esc_html( self::pl_date( self::ship_date( $latest ), true ) ) . '; termin dystrybutora może się przesunąć).</span>&nbsp;<abbr class="required" title="wymagane">*</abbr></label></p>';
		} );
		add_action( 'woocommerce_checkout_process', function () {
			if ( self::get( 'consent' ) && self::cart_dates() && empty( $_POST['fxpo_consent'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				wc_add_notice( 'Zaznacz, że rozumiesz termin wysyłki produktu w przedsprzedaży.', 'error' );
			}
		} );
		add_action( 'woocommerce_checkout_create_order', function ( $order ) {
			if ( self::cart_dates() ) {
				$order->update_meta_data( 'przedsprzedaz', 'tak – wysyłka ok. ' . self::pl_date( self::ship_date( max( self::cart_dates() ) ), true ) );
			}
		}, 30 );

		// Google: dane strukturalne „PreOrder” z datą dostępności.
		add_filter( 'woocommerce_structured_data_product', function ( $markup, $product ) {
			if ( ! $product instanceof WC_Product || 'onbackorder' !== $product->get_stock_status() || ! isset( self::parent_ids()[ $product->get_id() ] ) || empty( $markup['offers'] ) ) {
				return $markup;
			}
			$dates = array();
			foreach ( (array) get_option( self::ACTIVE, array() ) as $r ) {
				if ( (int) ( $r['parent'] ?? 0 ) === $product->get_id() ) {
					$dates[] = $r['date'];
				}
			}
			foreach ( $markup['offers'] as &$o ) {
				$o['availability'] = 'https://schema.org/PreOrder';
				if ( $dates ) {
					$o['availabilityStarts'] = min( $dates );
				}
			}
			return $markup;
		}, 20, 2 );

		// Filtr „Przedsprzedaż” na listach (motyw FOX).
		add_filter( 'fx_filter_switches', function ( $sw, $term ) {
			$on = ! empty( $_GET['przedsprzedaz'] ); // phpcs:ignore WordPress.Security.NonceVerification
			if ( ! $on && function_exists( 'fx_filter_cat_products' ) && ! array_intersect_key( self::parent_ids(), array_flip( fx_filter_cat_products( $term ) ) ) ) {
				return $sw;
			}
			$base = remove_query_arg( array( 'przedsprzedaz', 'paged' ), fx_filter_base_url() );
			$sw[] = array(
				'key'   => 'przedsprzedaz',
				'label' => self::get( 'label' ) . ' / nowa kolekcja',
				'short' => self::get( 'label' ),
				'note'  => 'zamów przed dostawą do sklepu',
				'on'    => $on,
				'url'   => $on ? $base : add_query_arg( 'przedsprzedaz', '1', $base ),
			);
			return $sw;
		}, 20, 2 );
		add_filter( 'fx_filter_sets', function ( $sets ) {
			if ( ! empty( $_GET['przedsprzedaz'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				$sets[] = array_keys( self::parent_ids() );
			}
			return $sets;
		} );
	}

	/** Daty dostaw pozycji w koszyku. */
	public static function cart_dates() {
		$out = array();
		if ( function_exists( 'WC' ) && WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $item ) {
				$d = self::date_of( ! empty( $item['variation_id'] ) ? $item['variation_id'] : $item['product_id'] );
				if ( $d ) {
					$out[] = $d;
				}
			}
		}
		return $out;
	}

	/* =====================================================================
	 * Panel: WooCommerce → Przedsprzedaż
	 * ===================================================================== */

	public static function admin() {
		add_action( 'admin_menu', function () {
			add_submenu_page( 'woocommerce', 'Przedsprzedaż Powerbike', 'Przedsprzedaż', 'manage_woocommerce', 'fxpo', array( __CLASS__, 'page' ) );
		}, 70 );
		add_action( 'admin_post_fxpo_save', function () {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( 'Brak uprawnień.' );
			}
			check_admin_referer( 'fxpo_save' );
			$in = wp_unslash( $_POST );
			update_option( self::OPT, array(
				'on'         => empty( $in['on'] ) ? 0 : 1,
				'source_url' => esc_url_raw( trim( $in['source_url'] ?? '' ) ),
				'key'        => sanitize_text_field( $in['key'] ?? '' ),
				'producers'  => sanitize_text_field( $in['producers'] ?? 'FOX' ),
				'max_days'   => max( 1, min( 365, (int) ( $in['max_days'] ?? 90 ) ) ),
				'ship_days'  => max( 0, min( 30, (int) ( $in['ship_days'] ?? 2 ) ) ),
				'times'      => sanitize_text_field( $in['times'] ?? '' ),
				'label'      => sanitize_text_field( $in['label'] ?? 'Przedsprzedaż' ) ?: 'Przedsprzedaż',
				'consent'    => empty( $in['consent'] ) ? 0 : 1,
				'ean_metas'  => sanitize_text_field( $in['ean_metas'] ?? '' ),
			), false );
			self::schedule();
			if ( ! empty( $in['sync_now'] ) ) {
				self::sync( 'ręcznie' );
				set_transient( 'fxpo_run', 1, 10 * MINUTE_IN_SECONDS );
			}
			if ( ! empty( $in['off_all'] ) ) {
				$q = array();
				foreach ( array_keys( (array) get_option( self::ACTIVE, array() ) ) as $id ) {
					$q[] = array( 'off', (int) $id, '' );
				}
				update_option( self::QUEUE, $q, false );
				self::log( '=== Zdejmuję całą przedsprzedaż (ręcznie): ' . count( $q ) . ' pozycji' );
				set_transient( 'fxpo_run', 1, 10 * MINUTE_IN_SECONDS );
			}
			wp_safe_redirect( admin_url( 'admin.php?page=fxpo' ) );
			exit;
		} );
		add_action( 'wp_ajax_fxpo_apply', function () {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_send_json_error();
			}
			check_ajax_referer( 'fxpo_apply' );
			$done = self::apply( 20 );
			if ( $done ) {
				delete_transient( 'fxpo_run' );
			}
			wp_send_json_success( array( 'done' => $done, 'left' => count( (array) get_option( self::QUEUE, array() ) ) ) );
		} );
	}

	public static function page() {
		$s      = self::get();
		$st     = (array) get_option( self::STATS, array() );
		$active = (array) get_option( self::ACTIVE, array() );
		$queue  = (array) get_option( self::QUEUE, array() );
		$tz     = self::tz();
		$fmt    = function ( $t ) use ( $tz ) {
			return $t ? wp_date( 'd.m.Y H:i', $t, $tz ) : '—';
		};
		$log = array();
		foreach ( array_slice( (array) get_option( self::LOG, array() ), -120 ) as $r ) {
			$log[] = wp_date( 'd.m H:i:s', (int) $r[0], $tz ) . '  ' . $r[1];
		}
		$raw = (array) get_option( self::OPT, array() );
		uasort( $active, function ( $a, $b ) {
			return strcmp( $a['date'], $b['date'] );
		} );
		?>
		<div class="wrap">
			<h1>Przedsprzedaż Powerbike</h1>
			<p>Produkty bez stanu z datą dostawy w Powerbike (z importera na airoh) dostają w sklepie status „Na zamówienie”: są widoczne, można je kupić, Google je indeksuje. Wysyłka do klienta = data dostawy + <?php echo (int) $s['ship_days']; ?> dni robocze. Po dostawie (stan &gt; 0), po minięciu daty albo gdy pozycja zniknie z importera – przedsprzedaż wyłącza się sama.</p>
			<?php if ( ! $s['on'] ) : ?><div class="notice notice-warning"><p>Automat jest wyłączony – zaznacz „Włączone” i zapisz, albo użyj „Synchronizuj teraz”, żeby sprawdzić wynik.</p></div><?php endif; ?>
			<table class="widefat striped" style="max-width:900px">
				<tr><th>Ostatnia synchronizacja</th><td><?php echo esc_html( $fmt( $st['time'] ?? 0 ) ); ?><?php echo ! empty( $st['error'] ) ? ' – <strong style="color:#b32d2e">' . esc_html( $st['error'] ) . '</strong>' : ''; ?></td></tr>
				<tr><th>Następna</th><td><?php echo esc_html( $fmt( wp_next_scheduled( self::EVENT, array( 'sync' ) ) ) ); ?> (czas polski)</td></tr>
				<tr><th>Z importera</th><td><?php echo (int) ( $st['items'] ?? 0 ); ?> pozycji marki <?php echo esc_html( $s['producers'] ); ?>; z datą dostawy: <?php echo (int) ( $st['dated'] ?? 0 ); ?>; w oknie <?php echo (int) $s['max_days']; ?> dni i bez stanu u Powerbike: <?php echo (int) ( $st['cand'] ?? 0 ); ?></td></tr>
				<tr><th>Dopasowane w sklepie</th><td><?php echo (int) ( $st['matched'] ?? 0 ); ?> (bez pary w sklepie: <?php echo (int) ( $st['unmatched'] ?? 0 ); ?>)</td></tr>
				<tr><th>Teraz w przedsprzedaży</th><td><strong><?php echo count( $active ); ?></strong> wariantów / produktów<?php echo $queue ? ' – do zapisania: <strong>' . count( $queue ) . '</strong>' : ''; ?></td></tr>
			</table>

			<?php if ( $queue ) : ?>
				<p id="fxpo-run"><strong>Zapisuję zmiany…</strong> zostało <span><?php echo count( $queue ); ?></span> – nie zamykaj strony.</p>
				<script>
				(function(){var el=document.querySelector('#fxpo-run span');function go(){var f=new FormData();f.append('action','fxpo_apply');f.append('_ajax_nonce','<?php echo esc_js( wp_create_nonce( 'fxpo_apply' ) ); ?>');
				fetch(ajaxurl,{method:'POST',body:f,credentials:'same-origin'}).then(function(r){return r.json()}).then(function(j){if(j&&j.success){el.textContent=j.data.left;if(j.data.done){location.reload()}else{setTimeout(go,300)}}else{setTimeout(go,5000)}}).catch(function(){setTimeout(go,8000)})}go();})();
				</script>
			<?php endif; ?>

			<h2>Produkty w przedsprzedaży</h2>
			<?php if ( $active ) : ?>
				<table class="widefat striped" style="max-width:1100px">
					<thead><tr><th>Produkt</th><th>SKU</th><th>Dostawa do nas</th><th>Wysyłka do klienta</th><th>Dopasowanie</th></tr></thead>
					<tbody>
					<?php foreach ( array_slice( $active, 0, 300, true ) as $id => $r ) : ?>
						<tr><td><a href="<?php echo esc_url( get_edit_post_link( (int) ( $r['parent'] ?? $id ) ) ); ?>"><?php echo esc_html( get_the_title( $id ) ); ?></a></td><td><code><?php echo esc_html( $r['sku'] ?? '' ); ?></code></td><td><?php echo esc_html( self::pl_date( $r['date'], true ) ); ?></td><td><?php echo esc_html( self::pl_date( self::ship_date( $r['date'] ), true ) ); ?></td><td><?php echo esc_html( $r['via'] ?? '' ); ?></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p>Brak.</p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'fxpo_save' ); ?><input type="hidden" name="action" value="fxpo_save">
				<h2>Ustawienia</h2>
				<table class="form-table" role="presentation">
					<tr><th>Włączone</th><td><label><input type="checkbox" name="on" value="1" <?php checked( $s['on'] ); ?>> Automatyczna synchronizacja</label></td></tr>
					<tr><th><label for="fxpo_url">Adres importera</label></th><td><input id="fxpo_url" class="large-text code" name="source_url" value="<?php echo esc_attr( $raw['source_url'] ?? '' ); ?>" placeholder="<?php echo esc_attr( self::defaults()['source_url'] ); ?>"><p class="description">Puste = z „Przeceny Powerbike” (teraz: <code><?php echo esc_html( $s['source_url'] ); ?></code>).</p></td></tr>
					<tr><th><label for="fxpo_key">Klucz</label></th><td><input id="fxpo_key" type="password" class="regular-text" name="key" value="<?php echo esc_attr( $raw['key'] ?? '' ); ?>" autocomplete="off"><p class="description">Puste = z „Przeceny Powerbike” (<?php echo $s['key'] ? 'jest' : '<strong>brak</strong>'; ?>).</p></td></tr>
					<tr><th><label for="fxpo_prod">Marki</label></th><td><input id="fxpo_prod" class="regular-text" name="producers" value="<?php echo esc_attr( $s['producers'] ); ?>"></td></tr>
					<tr><th><label for="fxpo_max">Okno przedsprzedaży</label></th><td><input id="fxpo_max" type="number" min="1" max="365" name="max_days" value="<?php echo (int) $s['max_days']; ?>" style="width:80px"> dni od dziś <p class="description">Dostawy dalsze niż tyle dni nie trafiają do przedsprzedaży.</p></td></tr>
					<tr><th><label for="fxpo_ship">Wysyłka po dostawie</label></th><td><input id="fxpo_ship" type="number" min="0" max="30" name="ship_days" value="<?php echo (int) $s['ship_days']; ?>" style="width:80px"> dni roboczych</td></tr>
					<tr><th><label for="fxpo_times">Godziny synchronizacji</label></th><td><input id="fxpo_times" class="regular-text" name="times" value="<?php echo esc_attr( $s['times'] ); ?>"><p class="description">Czas polski. Importer na airoh odświeża daty w nocy, więc wystarczą 1–2 razy dziennie.</p></td></tr>
					<tr><th><label for="fxpo_label">Nazwa</label></th><td><input id="fxpo_label" class="regular-text" name="label" value="<?php echo esc_attr( $s['label'] ); ?>"></td></tr>
					<tr><th>Zgoda w zamówieniu</th><td><label><input type="checkbox" name="consent" value="1" <?php checked( $s['consent'] ); ?>> Gdy w koszyku jest przedsprzedaż – wymagany checkbox „Rozumiem termin wysyłki…”</label></td></tr>
					<tr><th><label for="fxpo_ean">Pola EAN w sklepie</label></th><td><input id="fxpo_ean" class="regular-text code" name="ean_metas" value="<?php echo esc_attr( $s['ean_metas'] ); ?>"></td></tr>
				</table>
				<p>
					<button class="button button-primary" name="save" value="1">Zapisz</button>
					<button class="button" name="sync_now" value="1">Zapisz i synchronizuj teraz</button>
					<?php if ( $active ) : ?><button class="button" name="off_all" value="1" onclick="return confirm('Zdjąć przedsprzedaż ze wszystkich produktów?')">Zdejmij całą przedsprzedaż</button><?php endif; ?>
				</p>
			</form>

			<h2>Dziennik</h2>
			<pre style="max-width:1100px;max-height:380px;overflow:auto;background:#1d2327;color:#e5e5e5;padding:12px 14px;font-size:12px;white-space:pre-wrap"><?php echo esc_html( implode( "\n", $log ) ); ?></pre>
		</div>
		<?php
	}
}

add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}
	add_action( FXPO::EVENT, array( 'FXPO', 'job' ) );
	add_action( 'init', function () {
		if ( FXPO::get( 'on' ) && ! wp_next_scheduled( FXPO::EVENT, array( 'sync' ) ) ) {
			FXPO::schedule();
		}
	} );
	FXPO::front();
	if ( is_admin() ) {
		FXPO::admin();
	}
} );

register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( FXPO::EVENT, array( 'sync' ) );
	wp_clear_scheduled_hook( FXPO::EVENT, array( 'apply' ) );
} );
