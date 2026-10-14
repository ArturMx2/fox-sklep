<?php
/**
 * Plugin Name: FOX Smart Color GMC
 * Description: Inteligentne wykrywanie kolorów produktów WooCommerce z priorytetem jawnego koloru producenta. Zapisuje brakujący kolor jako globalny atrybut pa_kolor, z bezpieczną polityką kategorii dla Części i Akcesoria.
 * Version: 2.1.0
 * Author: ENDURO.SKLEP.PL
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * Requires Plugins: woocommerce
 * Text Domain: fox-smart-color-gmc
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class FOX_Smart_Color_GMC {
    const VERSION       = '2.1.0';
    const OPTION_IMPORT = 'foxsc_import_at';
    const DB_VERSION    = '1.2.0';
    const OPTION_DB     = 'foxsc_db_version';
    const OPTION_CONFIG = 'foxsc_settings';
    const OPTION_WORKER = 'foxsc_worker_scheduled';
    const OPTION_PROGRESS = 'foxsc_progress_state';
    const OPTION_PAUSED   = 'foxsc_processing_paused';
    const OPTION_LOG_CLEANUP = 'foxsc_last_log_cleanup';
    const TAXONOMY      = 'pa_kolor';
    const ACTION_HOOK   = 'foxsc_process_queue';
    const RESET_HOOK    = 'foxsc_reset_colors';
    const OPTION_RESET  = 'foxsc_reset_state';
    const ACTION_GROUP  = 'fox-smart-color-gmc';
    const ALG_VERSION   = 'explicit-color-marker-v4-final';
    const PARTS_ROOT_SLUG = 'czesci-i-akcesoria';
    const PARTS_ALLOWED_SUBTREE_SLUG = 'nadwozie';

    private static $internal_save = false;

    public static function init() {
        add_action( 'plugins_loaded', array( __CLASS__, 'maybe_upgrade' ), 20 );
        add_action( 'init', array( __CLASS__, 'maybe_ensure_settings' ), 30 );
        // Import z BaseLinkera (REST API WooCommerce) – rozpoznanie, żeby nie pracować w tym samym czasie.
        add_filter( 'rest_pre_dispatch', array( __CLASS__, 'detect_import' ), 10, 3 );
        add_action( 'woocommerce_api_request', array( __CLASS__, 'mark_import' ) );
        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
        add_action( 'add_meta_boxes_product', array( __CLASS__, 'add_product_metabox' ) );
        add_action( 'admin_post_foxsc_admin_action', array( __CLASS__, 'handle_admin_action' ) );
        add_action( 'wp_ajax_foxsc_progress_status', array( __CLASS__, 'ajax_progress_status' ) );
        add_action( self::ACTION_HOOK, array( __CLASS__, 'run_worker' ) );
        add_action( self::RESET_HOOK, array( __CLASS__, 'run_reset_worker' ) );
        add_action( 'woocommerce_new_product', array( __CLASS__, 'maybe_auto_enqueue' ), 50, 1 );
        add_action( 'woocommerce_update_product', array( __CLASS__, 'maybe_auto_enqueue' ), 50, 1 );
        add_filter( 'woocommerce_structured_data_product', array( __CLASS__, 'add_color_to_schema' ), 20, 2 );
        add_action( 'woocommerce_product_meta_end', array( __CLASS__, 'display_color_on_product' ), 15 );
    }

    public static function activate() {
        self::install_tables();
        self::ensure_settings();
    }

    public static function maybe_upgrade() {
        $installed = (string) get_option( self::OPTION_DB, '0.0.0' );
        if ( $installed !== self::DB_VERSION ) {
            self::install_tables();
        }
        self::ensure_settings();
    }

    /**
     * Ustawienia/kategorie sprawdzamy tylko w panelu, cronie i WP-CLI – nie przy każdej wizycie
     * klienta (wcześniej: 2 zapytania o kategorie na każdej stronie sklepu).
     */
    public static function maybe_ensure_settings() {
        if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
            self::ensure_settings();
        }
    }

    public static function mark_import() {
        $last = (int) get_option( self::OPTION_IMPORT, 0 );
        if ( time() - $last > 60 ) { // zapis najwyżej raz na minutę
            update_option( self::OPTION_IMPORT, time(), false );
        }
    }

    /** Zapis produktów przez REST API z zewnątrz (BaseLinker) = trwa import. */
    public static function detect_import( $result, $server, $request ) {
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST && 'GET' !== $request->get_method() && preg_match( '#^/?wc/v[1-3]/#', $request->get_route() ) ) {
            self::mark_import();
        }
        return $result;
    }

    /** Sekundy do końca przerwy po imporcie (10 min od ostatniego zapisu; uwzględnia też wtyczkę SEO zdjęć). */
    public static function import_busy() {
        $last = max( (int) get_option( self::OPTION_IMPORT, 0 ), (int) get_option( 'fxi_import_at', 0 ) );
        return max( 0, $last + 10 * MINUTE_IN_SECONDS - time() );
    }

    private static function queue_table() {
        global $wpdb;
        return $wpdb->prefix . 'foxsc_queue';
    }

    private static function logs_table() {
        global $wpdb;
        return $wpdb->prefix . 'foxsc_logs';
    }

    private static function install_tables() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $queue   = self::queue_table();
        $logs    = self::logs_table();

        $sql_queue = "CREATE TABLE {$queue} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            product_id bigint(20) unsigned NOT NULL,
            run_id varchar(32) NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            attempts smallint(5) unsigned NOT NULL DEFAULT 0,
            locked_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            last_error text NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY product_id (product_id),
            KEY status_id (status,id),
            KEY run_status (run_id,status),
            KEY locked_at (locked_at)
        ) {$charset};";

        $sql_logs = "CREATE TABLE {$logs} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            processed_at datetime NOT NULL,
            product_id bigint(20) unsigned NULL,
            sku varchar(190) NULL,
            product_name text NULL,
            detected_colors varchar(255) NULL,
            final_color varchar(255) NULL,
            confidence decimal(5,4) NULL,
            source varchar(32) NULL,
            status varchar(32) NOT NULL,
            message text NULL,
            details longtext NULL,
            PRIMARY KEY  (id),
            KEY processed_at (processed_at),
            KEY product_id (product_id),
            KEY status (status)
        ) {$charset};";

        dbDelta( $sql_queue );
        dbDelta( $sql_logs );
        update_option( self::OPTION_DB, self::DB_VERSION, false );
    }

    private static function defaults() {
        return array(
            'batch_size'         => 4,
            'worker_delay'       => 10,
            'min_confidence'     => 0.80,
            'ignore_background'  => 1,
            'max_colors'         => 2,
            'display_on_product' => 0,
            'auto_enqueue'       => 1,
            'parts_root_id'      => 0,
            'allowed_subtree_id' => 0,
        );
    }

    public static function ensure_settings() {
        $current = get_option( self::OPTION_CONFIG, array() );
        if ( ! is_array( $current ) ) {
            $current = array();
        }
        $merged = wp_parse_args( $current, self::defaults() );

        if ( empty( $merged['parts_root_id'] ) && taxonomy_exists( 'product_cat' ) ) {
            $root = get_term_by( 'slug', self::PARTS_ROOT_SLUG, 'product_cat' );
            if ( $root && ! is_wp_error( $root ) ) {
                $merged['parts_root_id'] = (int) $root->term_id;
            }
        }
        if ( empty( $merged['allowed_subtree_id'] ) && taxonomy_exists( 'product_cat' ) ) {
            $allowed = get_term_by( 'slug', self::PARTS_ALLOWED_SUBTREE_SLUG, 'product_cat' );
            if ( $allowed && ! is_wp_error( $allowed ) ) {
                $merged['allowed_subtree_id'] = (int) $allowed->term_id;
            }
        }

        if ( $merged !== $current ) {
            update_option( self::OPTION_CONFIG, $merged, false );
        }
    }

    private static function settings() {
        return wp_parse_args( get_option( self::OPTION_CONFIG, array() ), self::defaults() );
    }

    /**
     * Czytelny licznik postępu dla aktualnie uruchomionej partii.
     * Każde masowe uruchomienie dostaje własny run_id, dzięki czemu stare
     * rekordy kolejki nie fałszują licznika X / Y.
     */
    private static function is_paused() {
        return (bool) get_option( self::OPTION_PAUSED, false );
    }

    /**
     * Każde masowe uruchomienie dostaje własny run_id, dzięki czemu stare
     * rekordy kolejki nie fałszują licznika X / Y.
     */
    private static function begin_progress_run( $label ) {
        $run_id = str_replace( '-', '', wp_generate_uuid4() );
        delete_option( self::OPTION_PAUSED );
        update_option(
            self::OPTION_PROGRESS,
            array(
                'run_id'        => $run_id,
                'label'         => sanitize_text_field( $label ),
                'started_at'    => current_time( 'mysql' ),
                'started_ts'    => time(),
                'paused_at'     => 0,
                'paused_total'  => 0,
            ),
            false
        );
        return $run_id;
    }

    private static function pause_processing() {
        update_option( self::OPTION_PAUSED, 1, false );
        $state = get_option( self::OPTION_PROGRESS, array() );
        if ( ! is_array( $state ) ) {
            $state = array();
        }
        if ( empty( $state['paused_at'] ) ) {
            $state['paused_at'] = time();
            update_option( self::OPTION_PROGRESS, $state, false );
        }
        self::unschedule_worker();
    }

    private static function resume_processing() {
        $state = get_option( self::OPTION_PROGRESS, array() );
        if ( ! is_array( $state ) ) {
            $state = array();
        }
        if ( ! empty( $state['paused_at'] ) ) {
            $state['paused_total'] = (int) ( $state['paused_total'] ?? 0 ) + max( 0, time() - (int) $state['paused_at'] );
            $state['paused_at'] = 0;
            update_option( self::OPTION_PROGRESS, $state, false );
        }
        delete_option( self::OPTION_PAUSED );
        self::schedule_worker( 1 );
    }

    private static function adopt_active_queue_as_run( $label ) {
        global $wpdb;
        $run_id = self::begin_progress_run( $label );
        $queue  = self::queue_table();
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$queue} SET run_id=%s WHERE status IN ('pending','processing')",
                $run_id
            )
        );
        return $run_id;
    }

    private static function get_progress_data() {
        global $wpdb;
        $queue = self::queue_table();
        $state = get_option( self::OPTION_PROGRESS, array() );
        if ( ! is_array( $state ) ) {
            $state = array();
        }

        $counts = array_fill_keys( array( 'pending', 'processing', 'done', 'error', 'skipped' ), 0 );
        $run_id = ! empty( $state['run_id'] ) ? sanitize_text_field( $state['run_id'] ) : '';

        if ( $run_id ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT status, COUNT(*) total FROM {$queue} WHERE run_id=%s GROUP BY status",
                    $run_id
                )
            );
        } else {
            $rows = $wpdb->get_results( "SELECT status, COUNT(*) total FROM {$queue} GROUP BY status" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }

        foreach ( (array) $rows as $row ) {
            if ( isset( $counts[ $row->status ] ) ) {
                $counts[ $row->status ] = (int) $row->total;
            }
        }

        $total      = array_sum( $counts );
        $processed  = $counts['done'] + $counts['skipped'] + $counts['error'];
        $remaining  = $counts['pending'] + $counts['processing'];
        $percent    = $total > 0 ? round( ( $processed / $total ) * 100, 1 ) : 0.0;
        $paused     = self::is_paused();
        $status     = $remaining > 0 ? ( $paused ? 'paused' : 'running' ) : ( $total > 0 ? 'finished' : 'idle' );
        $label      = ! empty( $state['label'] ) ? (string) $state['label'] : 'Bieżąca kolejka';
        $started    = ! empty( $state['started_at'] ) ? (string) $state['started_at'] : '';
        $started_ts = ! empty( $state['started_ts'] ) ? (int) $state['started_ts'] : 0;
        if ( ! $started_ts && $started ) {
            $started_ts = (int) strtotime( $started );
        }

        $elapsed_seconds = 0;
        $eta_seconds     = null;
        if ( $started_ts > 0 ) {
            $paused_total = max( 0, (int) ( $state['paused_total'] ?? 0 ) );
            $current_pause = ( $paused && ! empty( $state['paused_at'] ) ) ? max( 0, time() - (int) $state['paused_at'] ) : 0;
            $elapsed_seconds = max( 0, time() - $started_ts - $paused_total - $current_pause );
            if ( ! $paused && $processed > 0 && $remaining > 0 && $elapsed_seconds > 0 ) {
                $rate = $processed / $elapsed_seconds;
                if ( $rate > 0 ) {
                    $eta_seconds = (int) round( $remaining / $rate );
                }
            }
        }

        return array(
            'label'       => $label,
            'status'      => $status,
            'paused'      => $paused,
            'total'       => (int) $total,
            'processed'   => (int) $processed,
            'remaining'   => (int) $remaining,
            'percent'     => (float) $percent,
            'pending'     => (int) $counts['pending'],
            'processing'  => (int) $counts['processing'],
            'done'        => (int) $counts['done'],
            'skipped'     => (int) $counts['skipped'],
            'error'       => (int) $counts['error'],
            'started_at'  => $started,
            'elapsed'     => self::format_duration( $elapsed_seconds ),
            'eta'         => $paused ? 'wstrzymano' : ( null === $eta_seconds ? '—' : self::format_duration( $eta_seconds ) ),
        );
    }

    private static function get_reset_progress_data() {
        $state = get_option( self::OPTION_RESET, array() );
        if ( ! is_array( $state ) ) {
            $state = array();
        }
        $processed = (int) ( $state['processed'] ?? 0 );
        $total     = (int) ( $state['total'] ?? 0 );
        $percent   = $total > 0 ? min( 100, round( ( $processed / $total ) * 100, 1 ) ) : 0.0;
        return array(
            'status'            => (string) ( $state['status'] ?? 'idle' ),
            'processed'         => $processed,
            'total'             => $total,
            'percent'           => $percent,
            'removed'           => (int) ( $state['removed'] ?? 0 ),
            'skipped_variation' => (int) ( $state['skipped_variation'] ?? 0 ),
        );
    }

    private static function format_duration( $seconds ) {
        $seconds = max( 0, (int) $seconds );
        if ( $seconds < 60 ) {
            return $seconds . ' s';
        }
        $minutes = (int) floor( $seconds / 60 );
        if ( $minutes < 60 ) {
            return $minutes . ' min';
        }
        $hours = (int) floor( $minutes / 60 );
        $mins  = $minutes % 60;
        return $hours . ' h ' . $mins . ' min';
    }

    public static function ajax_progress_status() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => 'Brak uprawnień.' ), 403 );
        }
        check_ajax_referer( 'foxsc_progress', 'nonce' );
        wp_send_json_success(
            array(
                'queue' => self::get_progress_data(),
                'reset' => self::get_reset_progress_data(),
            )
        );
    }

    public static function admin_menu() {
        add_submenu_page(
            'woocommerce',
            'FOX Smart Color GMC',
            'Smart Color GMC',
            'manage_woocommerce',
            'fox-smart-color-gmc',
            array( __CLASS__, 'render_panel' )
        );
    }

    public static function add_product_metabox() {
        add_meta_box(
            'foxsc-product-status',
            'FOX Smart Color GMC',
            array( __CLASS__, 'render_product_metabox' ),
            'product',
            'side',
            'default'
        );
    }

    public static function render_product_metabox( $post ) {
        if ( ! current_user_can( 'edit_post', $post->ID ) || ! function_exists( 'wc_get_product' ) ) {
            return;
        }
        $product = wc_get_product( $post->ID );
        if ( ! $product ) {
            return;
        }

        global $wpdb;
        $colors     = self::existing_color_names( $product );
        $gmc_color  = (string) get_post_meta( $post->ID, '_foxsc_gmc_color', true );
        $confidence = get_post_meta( $post->ID, '_foxsc_confidence', true );
        if ( '' === (string) $confidence ) {
            $confidence = get_post_meta( $post->ID, '_foxsc_color_confidence', true ); // migracja 1.x
        }
        $source = (string) get_post_meta( $post->ID, '_foxsc_source', true );
        if ( ! $source ) {
            $source = (string) get_post_meta( $post->ID, '_foxsc_color_source', true ); // migracja 1.x
        }
        $processed = (string) get_post_meta( $post->ID, '_foxsc_processed_at', true );
        $policy    = self::category_policy( $product );
        $last_log  = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT final_color,confidence,source,status,message,processed_at FROM ' . self::logs_table() . ' WHERE product_id=%d ORDER BY id DESC LIMIT 1',
                (int) $post->ID
            )
        );

        echo '<p><strong>Atrybut WooCommerce:</strong><br>' . ( $colors ? esc_html( implode( ' / ', $colors ) ) : '<em>brak</em>' ) . '</p>';
        echo '<p><strong>Pole GMC:</strong><br>' . ( $gmc_color ? '<code>' . esc_html( $gmc_color ) . '</code>' : '<em>brak</em>' ) . '</p>';
        if ( '' !== (string) $confidence ) {
            echo '<p><strong>Pewność:</strong> ' . esc_html( number_format_i18n( (float) $confidence * 100, 1 ) ) . '%</p>';
        }
        if ( $source ) {
            echo '<p><strong>Źródło:</strong> ' . esc_html( $source ) . '</p>';
        }
        if ( $processed ) {
            echo '<p><strong>Ostatni zapis:</strong> ' . esc_html( $processed ) . '</p>';
        }
        if ( $last_log ) {
            echo '<hr><p><strong>Ostatnia analiza:</strong><br>' . esc_html( $last_log->status ) . ' — ' . esc_html( $last_log->message ) . '</p>';
        }
        echo '<p><strong>Zakres:</strong><br>' . esc_html( $policy['message'] ) . '</p>';

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'foxsc_admin_action' );
        echo '<input type="hidden" name="action" value="foxsc_admin_action">';
        echo '<input type="hidden" name="foxsc_op" value="analyze_single">';
        echo '<input type="hidden" name="product_id" value="' . (int) $post->ID . '">';
        echo '<button class="button" type="submit">Przeanalizuj ponownie</button>';
        echo '</form>';
    }

    private static function category_label( $term_id ) {
        $term = $term_id ? get_term( (int) $term_id, 'product_cat' ) : null;
        return ( $term && ! is_wp_error( $term ) ) ? $term->name . ' (#' . (int) $term->term_id . ')' : 'nie ustawiono';
    }

    private static function category_select( $name, $selected ) {
        $terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC' ) );
        echo '<select name="' . esc_attr( $name ) . '" style="min-width:320px">';
        echo '<option value="0">— wybierz kategorię —</option>';
        if ( ! is_wp_error( $terms ) ) {
            foreach ( $terms as $term ) {
                $ancestors = array_reverse( get_ancestors( $term->term_id, 'product_cat' ) );
                $depth = count( $ancestors );
                echo '<option value="' . (int) $term->term_id . '" ' . selected( (int) $selected, (int) $term->term_id, false ) . '>' . esc_html( str_repeat( '— ', $depth ) . $term->name ) . '</option>';
            }
        }
        echo '</select>';
    }

    public static function render_panel() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        if ( ! function_exists( 'wc_get_product' ) ) {
            echo '<div class="notice notice-error"><p><strong>FOX Smart Color:</strong> WooCommerce nie jest aktywny.</p></div>';
            return;
        }

        global $wpdb;
        $queue    = self::queue_table();
        $logs     = self::logs_table();
        $settings = self::settings();
        $progress = self::get_progress_data();
        $counts   = array_fill_keys( array( 'pending', 'processing', 'done', 'error', 'skipped' ), 0 );
        $rows     = $wpdb->get_results( "SELECT status, COUNT(*) total FROM {$queue} GROUP BY status" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        foreach ( $rows as $row ) {
            if ( isset( $counts[ $row->status ] ) ) {
                $counts[ $row->status ] = (int) $row->total;
            }
        }

        $notice = isset( $_GET['foxsc_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['foxsc_notice'] ) ) : '';
        $error  = isset( $_GET['foxsc_error'] ) ? sanitize_text_field( wp_unslash( $_GET['foxsc_error'] ) ) : '';

        echo '<div class="wrap"><h1>FOX Smart Color GMC</h1>';
        echo '<p><strong>Cel:</strong> uzupełnianie brakującego globalnego atrybutu WooCommerce <code>pa_kolor</code> na produkcie głównym. Wartości ręczne nigdy nie są nadpisywane.</p>';
        echo '<div class="notice notice-info inline"><p><strong>Polityka kategorii:</strong> cały sklep jest analizowany. W gałęzi <strong>' . esc_html( self::category_label( $settings['parts_root_id'] ) ) . '</strong> pomijamy wszystko poza <strong>' . esc_html( self::category_label( $settings['allowed_subtree_id'] ) ) . '</strong> i wszystkimi jej podkategoriami.</p></div>';

        if ( $notice ) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
        if ( $error ) echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
        if ( ! taxonomy_exists( self::TAXONOMY ) ) {
            echo '<div class="notice notice-error"><p>Nie znaleziono globalnego atrybutu <code>pa_kolor</code>. Utwórz w WooCommerce atrybut „Kolor” ze slugiem <code>kolor</code> przed uruchomieniem analizy.</p></div>';
        }
        $cat_sets = self::category_term_sets();
        if ( ! empty( $settings['parts_root_id'] ) && ! empty( $settings['allowed_subtree_id'] ) && empty( $cat_sets['allowed_ids'] ) ) {
            echo '<div class="notice notice-warning"><p>Wybrany „dozwolony wyjątek” nie znajduje się pod gałęzią ograniczoną. W takim ustawieniu cała gałąź ograniczona będzie pomijana. Ustaw <strong>Części i Akcesoria → Nadwozie</strong>.</p></div>';
        }

        $progress_status_label = 'running' === $progress['status'] ? 'TRWA' : ( 'paused' === $progress['status'] ? 'WSTRZYMANE' : ( 'finished' === $progress['status'] ? 'ZAKOŃCZONE' : 'BRAK AKTYWNEJ KOLEJKI' ) );
        echo '<div id="foxsc-progress-card" style="background:#fff;border:2px solid #2271b1;border-radius:6px;padding:20px;margin:18px 0;max-width:1180px">';
        echo '<div style="display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap">';
        echo '<div><div style="font-size:13px;text-transform:uppercase;font-weight:700;letter-spacing:.04em">Postęp analizy</div>';
        echo '<div id="foxsc-progress-label" style="font-size:18px;font-weight:600;margin-top:4px">' . esc_html( $progress['label'] ) . '</div></div>';
        echo '<div id="foxsc-progress-status" style="font-size:13px;font-weight:700;padding:6px 10px;border:1px solid #ccd0d4;border-radius:4px">' . esc_html( $progress_status_label ) . '</div>';
        echo '</div>';
        echo '<div style="margin:18px 0 8px;font-size:30px;line-height:1.2"><strong>Przetworzono <span id="foxsc-progress-processed">' . (int) $progress['processed'] . '</span> / <span id="foxsc-progress-total">' . (int) $progress['total'] . '</span> produktów</strong></div>';
        echo '<div style="height:28px;background:#e5e7eb;border-radius:5px;overflow:hidden;position:relative">';
        echo '<div id="foxsc-progress-bar" style="height:100%;width:' . esc_attr( $progress['percent'] ) . '%;background:#2271b1;transition:width .35s ease"></div>';
        echo '<div id="foxsc-progress-percent" style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-weight:700;color:#111">' . esc_html( number_format_i18n( $progress['percent'], 1 ) ) . '%</div>';
        echo '</div>';
        echo '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px">';
        echo '<div style="padding:9px 12px;background:#f6f7f7;border-radius:4px"><strong>Pozostało:</strong> <span id="foxsc-progress-remaining">' . (int) $progress['remaining'] . '</span></div>';
        echo '<div style="padding:9px 12px;background:#f6f7f7;border-radius:4px"><strong>OK:</strong> <span id="foxsc-progress-done">' . (int) $progress['done'] . '</span></div>';
        echo '<div style="padding:9px 12px;background:#f6f7f7;border-radius:4px"><strong>Pominięte:</strong> <span id="foxsc-progress-skipped">' . (int) $progress['skipped'] . '</span></div>';
        echo '<div style="padding:9px 12px;background:#f6f7f7;border-radius:4px"><strong>Błędy:</strong> <span id="foxsc-progress-error">' . (int) $progress['error'] . '</span></div>';
        echo '<div style="padding:9px 12px;background:#f6f7f7;border-radius:4px"><strong>W trakcie:</strong> <span id="foxsc-progress-processing">' . (int) $progress['processing'] . '</span></div>';
        echo '<div style="padding:9px 12px;background:#f6f7f7;border-radius:4px"><strong>Oczekuje:</strong> <span id="foxsc-progress-pending">' . (int) $progress['pending'] . '</span></div>';
        echo '</div>';
        echo '<div style="margin-top:12px;color:#646970">Czas: <strong id="foxsc-progress-elapsed">' . esc_html( $progress['elapsed'] ) . '</strong> &nbsp; • &nbsp; Szacowany czas do końca: <strong id="foxsc-progress-eta">' . esc_html( $progress['eta'] ) . '</strong> &nbsp; • &nbsp; Odświeżanie automatyczne co 3 sekundy.</div>';
        echo '</div>';

        echo '<details style="margin:0 0 18px;max-width:1180px"><summary style="cursor:pointer"><strong>Stan techniczny całej tabeli kolejki</strong></summary><div style="display:flex;gap:12px;flex-wrap:wrap;margin:12px 0">';
        foreach ( $counts as $status => $count ) {
            echo '<div style="background:#fff;border:1px solid #ccd0d4;padding:10px 14px;min-width:110px"><strong>' . esc_html( ucfirst( $status ) ) . '</strong><br><span style="font-size:20px">' . (int) $count . '</span></div>';
        }
        echo '</div></details>';

        echo '<h2>Ustawienia</h2>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="background:#fff;border:1px solid #ccd0d4;padding:16px;max-width:980px">';
        wp_nonce_field( 'foxsc_admin_action' );
        echo '<input type="hidden" name="action" value="foxsc_admin_action"><input type="hidden" name="foxsc_op" value="save_settings">';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th>Gałąź ograniczona</th><td>'; self::category_select( 'parts_root_id', $settings['parts_root_id'] ); echo '<p class="description">U Ciebie: Części i Akcesoria. Cała ta gałąź jest pomijana poza wyjątkiem poniżej.</p></td></tr>';
        echo '<tr><th>Dozwolony wyjątek</th><td>'; self::category_select( 'allowed_subtree_id', $settings['allowed_subtree_id'] ); echo '<p class="description">U Ciebie: Nadwozie. Analizowane jest Nadwozie oraz wszystkie podkategorie niżej.</p></td></tr>';
        echo '<tr><th><label for="foxsc_batch_size">Produkty na zadanie</label></th><td><input id="foxsc_batch_size" type="number" min="1" max="20" name="batch_size" value="' . esc_attr( $settings['batch_size'] ) . '"><p class="description">Bezpieczny start: 4.</p></td></tr>';
        echo '<tr><th><label for="foxsc_worker_delay">Przerwa między zadaniami</label></th><td><input id="foxsc_worker_delay" type="number" min="3" max="60" name="worker_delay" value="' . esc_attr( $settings['worker_delay'] ) . '"> s<p class="description">Bezpieczny start: 10 sekund.</p></td></tr>';
        echo '<tr><th><label for="foxsc_min_conf">Minimalna pewność</label></th><td><input id="foxsc_min_conf" type="number" min="0.55" max="0.98" step="0.01" name="min_confidence" value="' . esc_attr( $settings['min_confidence'] ) . '"><p class="description">Domyślnie 0,80. Poniżej progu produkt nie jest zmieniany; wynik diagnostyczny zostaje wyłącznie w logu.</p></td></tr>';
        echo '<tr><th><label for="foxsc_max_colors">Maks. liczba kolorów</label></th><td><input id="foxsc_max_colors" type="number" min="1" max="3" name="max_colors" value="' . esc_attr( $settings['max_colors'] ) . '"><p class="description">Domyślnie 2. Drugi kolor ze zdjęcia jest dodawany tylko, gdy ma istotny udział; jawny kolor z nazwy ma pierwszeństwo.</p></td></tr>';
        echo '<tr><th>Automatyzacja</th><td>';
        echo '<label><input type="checkbox" name="ignore_background" value="1" ' . checked( ! empty( $settings['ignore_background'] ), true, false ) . '> wykrywaj i pomijaj jednolite tło zdjęcia</label><br>';
        echo '<label><input type="checkbox" name="auto_enqueue" value="1" ' . checked( ! empty( $settings['auto_enqueue'] ), true, false ) . '> automatycznie dodawaj nowe/aktualizowane produkty bez koloru do kolejki</label><br>';
        echo '<label><input type="checkbox" name="display_on_product" value="1" ' . checked( ! empty( $settings['display_on_product'] ), true, false ) . '> dodatkowo pokazuj kolor przy metadanych produktu (sam atrybut jest już widoczny w danych produktu)</label>';
        echo '</td></tr>';
        echo '</tbody></table><p><button class="button button-primary">Zapisz ustawienia</button></p></form>';

        echo '<h2>Kolejka</h2>';
        echo '<div style="background:#fff;border:1px solid #ccd0d4;padding:14px;max-width:1180px;margin-bottom:12px">';
        echo '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">';
        if ( self::is_paused() ) {
            self::admin_button( 'resume_queue', '▶ Kontynuuj przetwarzanie', 'button button-primary' );
        } else {
            self::admin_button( 'pause_queue', '⏸ Wstrzymaj przetwarzanie', 'button button-secondary' );
        }
        echo '<span style="color:#646970">Wstrzymanie zachowuje kolejkę i licznik. Po kliknięciu „Kontynuuj” proces rusza od miejsca, w którym został zatrzymany.</span>';
        echo '</div></div>';
        echo '<div style="display:flex;gap:8px;flex-wrap:wrap">';
        self::admin_button( 'add_sample', 'Test: 100 produktów bez koloru', 'button button-primary' );
        self::admin_button( 'add_missing', 'Dodaj wszystkie produkty bez koloru' );
        self::admin_button( 'add_all', 'Sprawdź wszystkie produkty' );
        self::admin_button( 'run_now', 'Uruchom kolejkę teraz' );
        self::admin_button( 'retry_errors', 'Ponów błędy' );
        self::admin_button( 'clear_finished', 'Usuń zakończone z kolejki' );
        self::admin_button( 'clear_queue', 'Wyczyść całą kolejkę', 'button', true );
        self::admin_button( 'clear_logs', 'Wyczyść logi', 'button', true );
        echo '</div>';

        $reset = get_option( self::OPTION_RESET, array() );
        if ( ! is_array( $reset ) ) $reset = array();
        $reset_progress = self::get_reset_progress_data();
        echo '<h2>Reset kolorów</h2>';
        echo '<div style="background:#fff;border:1px solid #ccd0d4;padding:16px;max-width:980px">';
        echo '<p><strong>Nie usuwaj globalnego atrybutu „Kolor” z WooCommerce.</strong> Reset usuwa tylko przypisanie <code>pa_kolor</code> z produktów i zostawia słownik kolorów w Produkty → Atrybuty.</p>';
        echo '<p>Tryb pełny usuwa także kolory wpisane ręcznie, ale <strong>nie rusza produktów, w których Kolor jest atrybutem wariantowym</strong> (np. Black/M, Red/L), żeby nie uszkodzić wariantów.</p>';
        if ( ! empty( $reset['status'] ) ) {
            echo '<div id="foxsc-reset-progress" style="margin:14px 0;padding:14px;background:#f6f7f7;border-radius:4px">';
            echo '<div style="font-size:20px"><strong>Reset: <span id="foxsc-reset-processed">' . (int) $reset_progress['processed'] . '</span> / <span id="foxsc-reset-total">' . (int) $reset_progress['total'] . '</span> produktów</strong> (<span id="foxsc-reset-percent">' . esc_html( number_format_i18n( $reset_progress['percent'], 1 ) ) . '%</span>)</div>';
            echo '<div style="height:18px;background:#dcdcde;border-radius:4px;overflow:hidden;margin:8px 0"><div id="foxsc-reset-bar" style="height:100%;width:' . esc_attr( $reset_progress['percent'] ) . '%;background:#2271b1;transition:width .35s ease"></div></div>';
            echo '<div><strong>Status:</strong> <span id="foxsc-reset-status">' . esc_html( $reset_progress['status'] ) . '</span> &nbsp; • &nbsp; <strong>Usunięto kolor:</strong> <span id="foxsc-reset-removed">' . (int) $reset_progress['removed'] . '</span> &nbsp; • &nbsp; <strong>Pominięto warianty po kolorze:</strong> <span id="foxsc-reset-skipped">' . (int) $reset_progress['skipped_variation'] . '</span></div>';
            echo '</div>';
        }
        echo '<div style="display:flex;gap:8px;flex-wrap:wrap">';
        self::admin_button( 'reset_auto_colors', 'Resetuj tylko kolory nadane przez FOX Smart Color', 'button', true );
        self::admin_button( 'reset_all_nonvariation_colors', 'WYCZYŚĆ wszystkie kolory niewariantowe', 'button button-secondary', true );
        if ( ! empty( $reset['status'] ) && 'running' === $reset['status'] ) {
            self::admin_button( 'cancel_reset', 'Przerwij reset', 'button', true );
        }
        echo '</div>';
        echo '<p class="description">Po zakończeniu pełnego resetu kliknij „Dodaj wszystkie produkty bez koloru”, a następnie „Uruchom kolejkę teraz”.</p>';
        echo '</div>';

        echo '<h2>Feed / GMC</h2>';
        echo '<p><strong>Źródło dla AdTribes / GMC:</strong> <code>_foxsc_gmc_color</code>. Atrybut WooCommerce <code>pa_kolor</code> pozostaje źródłem widocznym na produkcie. Wtyczka przechowuje tylko lekkie meta: <code>_foxsc_gmc_color</code>, <code>_foxsc_confidence</code>, <code>_foxsc_source</code>, <code>_foxsc_processed_at</code>. Szczegóły analizy trafiają wyłącznie do tabeli logów i są automatycznie czyszczone po 30 dniach.</p>';
        echo '<p>'; self::admin_button( 'cleanup_legacy_meta', 'Wyczyść stare pola diagnostyczne 1.x', 'button', true ); echo ' <span class="description">Migruje użyteczne wartości i usuwa stare candidate/JSON/hash/trackery bez kasowania pa_kolor ani _foxsc_gmc_color.</span></p>';

        $log_rows = $wpdb->get_results( "SELECT * FROM {$logs} ORDER BY id DESC LIMIT 100" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        echo '<h2>Ostatnie 100 logów</h2><div style="overflow:auto"><table class="widefat striped"><thead><tr><th>Data</th><th>Produkt</th><th>SKU</th><th>Wykryte</th><th>Wynik</th><th>Pewność</th><th>Źródło</th><th>Status</th><th>Uwagi</th></tr></thead><tbody>';
        if ( $log_rows ) {
            foreach ( $log_rows as $log ) {
                $edit = $log->product_id ? get_edit_post_link( (int) $log->product_id ) : '';
                echo '<tr><td>' . esc_html( $log->processed_at ) . '</td>';
                echo '<td>' . ( $edit ? '<a href="' . esc_url( $edit ) . '">' : '' ) . esc_html( $log->product_name ) . ' (#' . (int) $log->product_id . ')' . ( $edit ? '</a>' : '' ) . '</td>';
                echo '<td>' . esc_html( $log->sku ) . '</td><td>' . esc_html( $log->detected_colors ) . '</td><td>' . esc_html( $log->final_color ) . '</td>';
                echo '<td>' . ( null !== $log->confidence ? esc_html( number_format_i18n( (float) $log->confidence * 100, 1 ) . '%' ) : '' ) . '</td>';
                echo '<td>' . esc_html( $log->source ) . '</td><td>' . esc_html( $log->status ) . '</td><td>' . esc_html( $log->message ) . '</td></tr>';
            }
        } else {
            echo '<tr><td colspan="9">Brak logów.</td></tr>';
        }
        echo '</tbody></table></div>';

        $progress_nonce = wp_create_nonce( 'foxsc_progress' );
        echo '<script>(function(){
'
            . 'var endpoint = (typeof ajaxurl !== "undefined" ? ajaxurl : "' . esc_js( admin_url( 'admin-ajax.php' ) ) . '");
'
            . 'var nonce = "' . esc_js( $progress_nonce ) . '";
'
            . 'function t(id,v){var e=document.getElementById(id);if(e)e.textContent=v;}
'
            . 'function w(id,v){var e=document.getElementById(id);if(e)e.style.width=Math.max(0,Math.min(100,parseFloat(v)||0))+"%";}
'
            . 'function renderQueue(q){if(!q)return;t("foxsc-progress-label",q.label||"Bieżąca kolejka");t("foxsc-progress-processed",q.processed||0);t("foxsc-progress-total",q.total||0);t("foxsc-progress-remaining",q.remaining||0);t("foxsc-progress-done",q.done||0);t("foxsc-progress-skipped",q.skipped||0);t("foxsc-progress-error",q.error||0);t("foxsc-progress-processing",q.processing||0);t("foxsc-progress-pending",q.pending||0);t("foxsc-progress-percent",(parseFloat(q.percent)||0).toLocaleString("pl-PL",{minimumFractionDigits:1,maximumFractionDigits:1})+"%");t("foxsc-progress-elapsed",q.elapsed||"0 s");t("foxsc-progress-eta",q.eta||"—");w("foxsc-progress-bar",q.percent);var st=q.status==="running"?"TRWA":(q.status==="paused"?"WSTRZYMANE":(q.status==="finished"?"ZAKOŃCZONE":"BRAK AKTYWNEJ KOLEJKI"));t("foxsc-progress-status",st);}
'
            . 'function renderReset(r){if(!r)return;t("foxsc-reset-processed",r.processed||0);t("foxsc-reset-total",r.total||0);t("foxsc-reset-percent",(parseFloat(r.percent)||0).toLocaleString("pl-PL",{minimumFractionDigits:1,maximumFractionDigits:1})+"%");t("foxsc-reset-status",r.status||"idle");t("foxsc-reset-removed",r.removed||0);t("foxsc-reset-skipped",r.skipped_variation||0);w("foxsc-reset-bar",r.percent);}
'
            . 'function poll(){var body=new URLSearchParams();body.append("action","foxsc_progress_status");body.append("nonce",nonce);fetch(endpoint,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded; charset=UTF-8"},body:body.toString()}).then(function(r){return r.json();}).then(function(j){if(j&&j.success&&j.data){renderQueue(j.data.queue);renderReset(j.data.reset);}}).catch(function(){});}
'
            . 'poll();setInterval(poll,3000);
'
            . '})();</script>';
        echo '</div>';
    }

    private static function admin_button( $op, $label, $class = 'button', $confirm = false ) {
        $confirm_attr = $confirm ? ' onclick="return confirm(\'Czy na pewno?\')"' : '';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
        wp_nonce_field( 'foxsc_admin_action' );
        echo '<input type="hidden" name="action" value="foxsc_admin_action">';
        echo '<input type="hidden" name="foxsc_op" value="' . esc_attr( $op ) . '">';
        echo '<button class="' . esc_attr( $class ) . '"' . $confirm_attr . '>' . esc_html( $label ) . '</button>';
        echo '</form>';
    }

    public static function handle_admin_action() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Brak uprawnień.' );
        }
        check_admin_referer( 'foxsc_admin_action' );

        $op       = isset( $_POST['foxsc_op'] ) ? sanitize_key( wp_unslash( $_POST['foxsc_op'] ) ) : '';
        $redirect = admin_url( 'admin.php?page=fox-smart-color-gmc' );
        $message  = '';
        $error    = '';

        try {
            switch ( $op ) {
                case 'save_settings':
                    $settings = array(
                        'batch_size'         => min( 20, max( 1, (int) ( $_POST['batch_size'] ?? 4 ) ) ),
                        'worker_delay'       => min( 60, max( 3, (int) ( $_POST['worker_delay'] ?? 10 ) ) ),
                        'min_confidence'     => min( 0.98, max( 0.55, (float) ( $_POST['min_confidence'] ?? 0.80 ) ) ),
                        'ignore_background'  => empty( $_POST['ignore_background'] ) ? 0 : 1,
                        'max_colors'         => min( 3, max( 1, (int) ( $_POST['max_colors'] ?? 2 ) ) ),
                        'display_on_product' => empty( $_POST['display_on_product'] ) ? 0 : 1,
                        'auto_enqueue'       => empty( $_POST['auto_enqueue'] ) ? 0 : 1,
                        'parts_root_id'      => max( 0, (int) ( $_POST['parts_root_id'] ?? 0 ) ),
                        'allowed_subtree_id' => max( 0, (int) ( $_POST['allowed_subtree_id'] ?? 0 ) ),
                    );
                    update_option( self::OPTION_CONFIG, $settings, false );
                    $message = 'Ustawienia zapisane.';
                    break;

                case 'analyze_single':
                    $product_id = max( 0, (int) ( $_POST['product_id'] ?? 0 ) );
                    if ( ! $product_id || ! current_user_can( 'edit_post', $product_id ) ) {
                        throw new Exception( 'Nieprawidłowy produkt.' );
                    }
                    $run_id = self::begin_progress_run( 'Analiza pojedynczego produktu' );
                    self::enqueue_single( $product_id, true, $run_id );
                    self::schedule_worker( 1 );
                    $redirect = get_edit_post_link( $product_id, 'raw' );
                    $message  = 'Produkt dodany do analizy. Wynik pojawi się po przetworzeniu kolejki.';
                    break;

                case 'add_sample':
                    $run_id  = self::begin_progress_run( 'Test: 100 produktów bez koloru' );
                    $count   = self::enqueue_sample( 100, $run_id );
                    $message = 'Dodano/odświeżono próbkę: ' . $count . ' pozycji.';
                    self::schedule_worker( 1 );
                    break;
                case 'add_missing':
                    $run_id  = self::begin_progress_run( 'Wszystkie produkty bez koloru' );
                    $count   = self::enqueue_products( true, $run_id );
                    $message = 'Dodano/odświeżono w kolejce: ' . $count . ' produktów bez koloru.';
                    self::schedule_worker( 1 );
                    break;
                case 'add_all':
                    $run_id  = self::begin_progress_run( 'Ponowna analiza wszystkich produktów' );
                    $count   = self::enqueue_products( false, $run_id );
                    $message = 'Dodano/odświeżono w kolejce: ' . $count . ' produktów.';
                    self::schedule_worker( 1 );
                    break;
                case 'run_now':
                    self::adopt_active_queue_as_run( 'Bieżąca kolejka' );
                    self::unschedule_worker();
                    self::schedule_worker( 1 );
                    $message = 'Uruchomienie kolejki zostało zlecone.';
                    break;
                case 'pause_queue':
                    self::pause_processing();
                    $message = 'Przetwarzanie zostało wstrzymane. Kolejka i postęp zostały zachowane.';
                    break;
                case 'resume_queue':
                    self::resume_processing();
                    $message = 'Przetwarzanie zostało wznowione.';
                    break;
                case 'retry_errors':
                    global $wpdb;
                    $queue  = self::queue_table();
                    $run_id = self::begin_progress_run( 'Ponowienie błędów' );
                    $count = $wpdb->query( $wpdb->prepare( "UPDATE {$queue} SET run_id=%s,status='pending', attempts=0, locked_at=NULL, last_error=NULL, updated_at=NOW() WHERE status='error'", $run_id ) );
                    self::schedule_worker( 1 );
                    $message = 'Ponowiono: ' . (int) $count . ' błędów.';
                    break;
                case 'reset_auto_colors':
                    self::start_reset( 'auto_only' );
                    $message = 'Uruchomiono reset kolorów nadanych przez FOX Smart Color. Proces działa w tle.';
                    break;
                case 'reset_all_nonvariation_colors':
                    self::start_reset( 'all_nonvariation' );
                    $message = 'Uruchomiono pełny reset pa_kolor dla produktów niewariantowych. Produkty z wariantami po kolorze zostaną pominięte.';
                    break;
                case 'cancel_reset':
                    $state = get_option( self::OPTION_RESET, array() );
                    if ( is_array( $state ) ) {
                        $state['status'] = 'cancelled';
                        $state['finished_at'] = current_time( 'mysql' );
                        update_option( self::OPTION_RESET, $state, false );
                    }
                    $message = 'Reset został oznaczony jako przerwany.';
                    break;
                case 'clear_finished':
                    global $wpdb;
                    $queue = self::queue_table();
                    $count = $wpdb->query( "DELETE FROM {$queue} WHERE status IN ('done','skipped')" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    delete_option( self::OPTION_PROGRESS );
                    $message = 'Usunięto zakończone pozycje: ' . (int) $count . '.';
                    break;
                case 'clear_queue':
                    global $wpdb;
                    $wpdb->query( 'TRUNCATE TABLE ' . self::queue_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    delete_option( self::OPTION_PROGRESS );
                    delete_option( self::OPTION_PAUSED );
                    self::unschedule_worker();
                    $message = 'Kolejka wyczyszczona.';
                    break;
                case 'cleanup_legacy_meta':
                    $deleted = self::cleanup_legacy_meta_global();
                    $message = 'Migracja zakończona. Usunięto stare wpisy meta: ' . (int) $deleted . '.';
                    break;
                case 'clear_logs':
                    global $wpdb;
                    $wpdb->query( 'TRUNCATE TABLE ' . self::logs_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    $message = 'Logi wyczyszczone.';
                    break;
                default:
                    $error = 'Nieznana operacja.';
            }
        } catch ( Throwable $e ) {
            $error = $e->getMessage();
        }

        if ( $message ) $redirect = add_query_arg( 'foxsc_notice', $message, $redirect );
        if ( $error ) $redirect = add_query_arg( 'foxsc_error', $error, $redirect );
        wp_safe_redirect( $redirect );
        exit;
    }

    private static function schedule_reset_worker( $delay = 2 ) {
        $state = get_option( self::OPTION_RESET, array() );
        if ( ! is_array( $state ) || empty( $state['status'] ) || 'running' !== $state['status'] ) {
            return;
        }
        if ( function_exists( 'as_schedule_single_action' ) ) {
            as_schedule_single_action( time() + max( 1, (int) $delay ), self::RESET_HOOK, array(), self::ACTION_GROUP, false, 20 );
        } else {
            wp_schedule_single_event( time() + max( 1, (int) $delay ), self::RESET_HOOK );
        }
    }

    private static function start_reset( $mode ) {
        global $wpdb;
        $mode = ( 'auto_only' === $mode ) ? 'auto_only' : 'all_nonvariation';

        // Nie analizujemy równolegle produktów, które właśnie czyścimy.
        $wpdb->query( 'TRUNCATE TABLE ' . self::queue_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        self::unschedule_worker();
        delete_option( self::OPTION_PAUSED );
        delete_option( self::OPTION_PROGRESS );

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type='product' AND post_status NOT IN ('trash','auto-draft')"
        ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $state = array(
            'status'            => 'running',
            'mode'              => $mode,
            'cursor'            => 0,
            'processed'         => 0,
            'total'             => $total,
            'removed'           => 0,
            'skipped_variation' => 0,
            'started_at'        => current_time( 'mysql' ),
            'finished_at'       => '',
        );
        update_option( self::OPTION_RESET, $state, false );
        self::schedule_reset_worker( 1 );
    }

    public static function run_reset_worker() {
        $state = get_option( self::OPTION_RESET, array() );
        if ( ! is_array( $state ) || empty( $state['status'] ) || 'running' !== $state['status'] ) {
            return;
        }

        global $wpdb;
        $lock_name = 'foxsc_reset_' . substr( md5( DB_NAME . '|' . $wpdb->prefix ), 0, 32 );
        $lock = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock_name ) );
        if ( 1 !== $lock ) {
            self::schedule_reset_worker( 5 );
            return;
        }

        try {
            if ( function_exists( 'set_time_limit' ) ) @set_time_limit( 55 );

            $cursor = max( 0, (int) ( $state['cursor'] ?? 0 ) );
            $ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts}
                     WHERE post_type='product'
                       AND ID > %d
                       AND post_status NOT IN ('trash','auto-draft')
                     ORDER BY ID ASC
                     LIMIT 100",
                    $cursor
                )
            );

            if ( ! $ids ) {
                $state['status'] = 'done';
                $state['finished_at'] = current_time( 'mysql' );
                update_option( self::OPTION_RESET, $state, false );
                return;
            }

            foreach ( $ids as $id ) {
                $id = (int) $id;
                $state['cursor'] = $id;
                $state['processed'] = (int) ( $state['processed'] ?? 0 ) + 1;

                $product = wc_get_product( $id );
                if ( ! $product || $product->is_type( 'variation' ) ) continue;

                if ( $product->is_type( 'variable' ) && self::product_uses_color_for_variations( $product ) ) {
                    $state['skipped_variation'] = (int) ( $state['skipped_variation'] ?? 0 ) + 1;
                    continue;
                }

                if ( 'auto_only' === ( $state['mode'] ?? '' ) && ! self::colors_are_auto_managed( $product ) ) {
                    continue;
                }

                if ( self::remove_nonvariation_color( $product ) ) {
                    $state['removed'] = (int) ( $state['removed'] ?? 0 ) + 1;
                }
            }

            update_option( self::OPTION_RESET, $state, false );
            self::schedule_reset_worker( 2 );
        } catch ( Throwable $e ) {
            $state['status'] = 'error';
            $state['error'] = $e->getMessage();
            $state['finished_at'] = current_time( 'mysql' );
            update_option( self::OPTION_RESET, $state, false );
        } finally {
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
        }
    }

    private static function remove_nonvariation_color( $product ) {
        if ( ! is_a( $product, 'WC_Product' ) || $product->is_type( 'variation' ) ) return false;
        if ( $product->is_type( 'variable' ) && self::product_uses_color_for_variations( $product ) ) return false;

        $product_id = (int) $product->get_id();
        $had_terms = self::existing_color_term_ids( $product );
        $attributes = $product->get_attributes();
        $had_attribute = isset( $attributes[ self::TAXONOMY ] );

        if ( $had_attribute ) {
            $attr = $attributes[ self::TAXONOMY ];
            if ( is_a( $attr, 'WC_Product_Attribute' ) && $attr->get_variation() ) return false;
            unset( $attributes[ self::TAXONOMY ] );
            $product->set_attributes( $attributes );
            self::$internal_save = true;
            try {
                $product->save();
            } finally {
                self::$internal_save = false;
            }
        }

        if ( taxonomy_exists( self::TAXONOMY ) ) {
            wp_set_object_terms( $product_id, array(), self::TAXONOMY, false );
        }

        foreach ( array(
            '_foxsc_taxonomy_autoset', '_foxsc_gmc_color', '_foxsc_confidence', '_foxsc_source',
            '_foxsc_color_confidence', '_foxsc_color_source', '_foxsc_color_candidate', '_foxsc_candidate_confidence',
            '_foxsc_candidate_source', '_foxsc_detected_colors_json', '_foxsc_processed_at', '_foxsc_image_hash', '_foxsc_algorithm_version'
        ) as $key ) {
            delete_post_meta( $product_id, $key );
        }

        // Czyścimy też techniczne meta poprzedniego prototypu, jeśli zostały w bazie.
        foreach ( array(
            '_foxak_gmc_color', '_foxak_color_confidence', '_foxak_color_source', '_foxak_color_candidate',
            '_foxak_candidate_confidence', '_foxak_candidate_source', '_foxak_detected_colors_json', '_foxak_processed_at'
        ) as $key ) {
            delete_post_meta( $product_id, $key );
        }

        return $had_attribute || ! empty( $had_terms );
    }

    /**
     * Zapis produktu (także każda synchronizacja stanów z BaseLinkera) – tylko lekkie sprawdzenie.
     * - produkt z kolorem (ręcznym albo nadanym wcześniej) NIE wraca do kolejki,
     * - produkt bez koloru wraca najwyżej raz na 7 dni (import, który czyści atrybuty, nie wywoła
     *   „przepychanki” kolor znika ↔ kolor wraca przy każdej synchronizacji),
     * - pole GMC dla koloru ręcznego jest zapisywane tylko, gdy faktycznie się zmieniło,
     * - zadanie w tle jest zlecane najwyżej raz na minutę i startuje z opóźnieniem.
     */
    public static function maybe_auto_enqueue( $product_id ) {
        if ( self::$internal_save || ! function_exists( 'wc_get_product' ) ) {
            return;
        }
        $product_id = (int) $product_id;
        $settings   = self::settings();
        if ( empty( $settings['auto_enqueue'] ) || 'product' !== get_post_type( $product_id ) || 'publish' !== get_post_status( $product_id ) ) {
            return;
        }
        $term_ids = taxonomy_exists( self::TAXONOMY ) ? wp_get_object_terms( $product_id, self::TAXONOMY, array( 'fields' => 'ids' ) ) : array();
        if ( ! is_wp_error( $term_ids ) && $term_ids ) {
            $product = wc_get_product( $product_id );
            if ( $product && self::has_manual_color( $product ) ) {
                // Ręczny pa_kolor jest nadrzędny – synchronizujemy go do pola GMC, ale tylko przy zmianie.
                $manual = implode( '/', array_slice( self::existing_color_names( $product ), 0, 3 ) );
                if ( $manual && $manual !== (string) get_post_meta( $product_id, '_foxsc_gmc_color', true ) ) {
                    self::publish_result( $product_id, $manual, 1.0, 'manual' );
                }
            }
            return;
        }
        $last = strtotime( (string) get_post_meta( $product_id, '_foxsc_processed_at', true ) );
        if ( $last && current_time( 'timestamp' ) - $last < 7 * DAY_IN_SECONDS ) {
            return;
        }
        global $wpdb;
        $state = $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . self::queue_table() . ' WHERE product_id=%d', $product_id ) );
        if ( in_array( $state, array( 'pending', 'processing' ), true ) ) {
            return;
        }
        $product = wc_get_product( $product_id );
        if ( ! $product || $product->is_type( 'variation' ) ) {
            return;
        }
        $policy = self::category_policy( $product );
        if ( empty( $policy['allowed'] ) ) {
            return;
        }
        self::enqueue_single( $product_id, false );
        if ( false === get_transient( 'foxsc_kick' ) ) {
            set_transient( 'foxsc_kick', 1, MINUTE_IN_SECONDS );
            self::schedule_worker( 60 );
        }
    }

    private static function enqueue_single( $product_id, $force = false, $run_id = '' ) {
        global $wpdb;
        $product = wc_get_product( $product_id );
        if ( ! $product || $product->is_type( 'variation' ) ) {
            return false;
        }
        if ( ! $force && self::has_manual_color( $product ) ) {
            return false;
        }
        $policy = self::category_policy( $product );
        if ( empty( $policy['allowed'] ) ) {
            return false;
        }
        $now    = current_time( 'mysql' );
        $run_id = sanitize_text_field( (string) $run_id );
        return false !== $wpdb->query( $wpdb->prepare(
            "INSERT INTO " . self::queue_table() . " (product_id,run_id,status,attempts,locked_at,created_at,updated_at,last_error)
             VALUES (%d,%s,'pending',0,NULL,%s,%s,NULL)
             ON DUPLICATE KEY UPDATE run_id=%s,status='pending',attempts=0,locked_at=NULL,updated_at=%s,last_error=NULL",
            $product_id, $run_id, $now, $now, $run_id, $now
        ) );
    }

    private static function enqueue_sample( $limit = 100, $run_id = '' ) {
        global $wpdb;
        $queue        = self::queue_table();
        $taxonomy     = self::TAXONOMY;
        $now          = current_time( 'mysql' );
        $run_id       = sanitize_text_field( (string) $run_id );
        $limit        = min( 500, max( 1, (int) $limit ) );
        $category_sql = self::category_sql_clause( 'p.ID' );

        $sql = $wpdb->prepare(
            "INSERT INTO {$queue} (product_id,run_id,status,attempts,locked_at,created_at,updated_at,last_error)
             SELECT p.ID,%s,'pending',0,NULL,%s,%s,NULL
             FROM {$wpdb->posts} p
             WHERE p.post_type='product' AND p.post_status='publish'
               {$category_sql}
               AND NOT EXISTS (
                    SELECT 1 FROM {$wpdb->term_relationships} tr
                    INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id
                    WHERE tr.object_id=p.ID AND tt.taxonomy=%s
               )
             ORDER BY p.ID DESC
             LIMIT %d
             ON DUPLICATE KEY UPDATE run_id=%s,status='pending',attempts=0,locked_at=NULL,updated_at=%s,last_error=NULL",
            $run_id, $now, $now, $taxonomy, $limit, $run_id, $now
        );
        return (int) $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    private static function enqueue_products( $missing_only, $run_id = '' ) {
        global $wpdb;
        $queue        = self::queue_table();
        $taxonomy     = self::TAXONOMY;
        $now          = current_time( 'mysql' );
        $run_id       = sanitize_text_field( (string) $run_id );
        $category_sql = self::category_sql_clause( 'p.ID' );
        $where        = '';

        if ( $missing_only ) {
            $where = $wpdb->prepare(
                " AND NOT EXISTS (
                    SELECT 1 FROM {$wpdb->term_relationships} tr
                    INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id
                    WHERE tr.object_id=p.ID AND tt.taxonomy=%s
                )",
                $taxonomy
            );
        }

        $sql = $wpdb->prepare(
            "INSERT INTO {$queue} (product_id,run_id,status,attempts,locked_at,created_at,updated_at,last_error)
             SELECT p.ID,%s,'pending',0,NULL,%s,%s,NULL
             FROM {$wpdb->posts} p
             WHERE p.post_type='product' AND p.post_status='publish' {$category_sql} {$where}
             ON DUPLICATE KEY UPDATE run_id=%s,status='pending',attempts=0,locked_at=NULL,updated_at=%s,last_error=NULL",
            $run_id, $now, $now, $run_id, $now
        );
        return (int) $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    /**
     * Check only for a PENDING next worker.
     *
     * Important: as_has_scheduled_action() also returns true for the action that is
     * currently IN PROGRESS. Using it here prevents a running worker from scheduling
     * its successor and makes the queue stop after exactly one batch.
     */
    private static function worker_is_scheduled() {
        if ( function_exists( 'as_get_scheduled_actions' ) ) {
            $status = class_exists( 'ActionScheduler_Store' ) ? ActionScheduler_Store::STATUS_PENDING : 'pending';
            $ids = as_get_scheduled_actions(
                array(
                    'hook'     => self::ACTION_HOOK,
                    'group'    => self::ACTION_GROUP,
                    'status'   => $status,
                    'per_page' => 1,
                ),
                'ids'
            );
            return ! empty( $ids );
        }
        if ( function_exists( 'as_next_scheduled_action' ) ) {
            $next = as_next_scheduled_action( self::ACTION_HOOK, array(), self::ACTION_GROUP );
            // Integer timestamp = pending scheduled action. Boolean true may mean
            // async/in-progress, so it must not block chaining the next batch.
            return is_int( $next ) || ( is_numeric( $next ) && ! is_bool( $next ) );
        }
        return false !== wp_next_scheduled( self::ACTION_HOOK );
    }

    private static function unschedule_worker() {
        delete_option( self::OPTION_WORKER ); // sprzątanie po wersjach 1.x
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( self::ACTION_HOOK, array(), self::ACTION_GROUP );
        }
        wp_clear_scheduled_hook( self::ACTION_HOOK );
    }

    private static function schedule_worker( $delay = 3 ) {
        if ( self::is_paused() || self::worker_is_scheduled() ) {
            return;
        }

        $delay = max( 1, (int) $delay );
        if ( function_exists( 'as_schedule_single_action' ) ) {
            as_schedule_single_action(
                time() + $delay,
                self::ACTION_HOOK,
                array(),
                self::ACTION_GROUP,
                false,
                20
            );
        } else {
            wp_schedule_single_event( time() + $delay, self::ACTION_HOOK );
        }
    }

    public static function run_worker() {
        delete_option( self::OPTION_WORKER ); // sprzątanie po wersjach 1.x
        if ( self::is_paused() ) {
            return;
        }

        global $wpdb;
        $lock_name = 'foxsc_' . substr( md5( DB_NAME . '|' . $wpdb->prefix ), 0, 40 );
        $lock      = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock_name ) );
        if ( 1 !== $lock ) {
            if ( ! self::is_paused() ) {
                self::schedule_worker( 5 );
            }
            return;
        }

        try {
            if ( function_exists( 'set_time_limit' ) ) {
                @set_time_limit( 55 );
            }

            // Trwa import z BaseLinkera – nie zapisujemy produktów równolegle, wracamy za 5 min.
            if ( self::import_busy() ) {
                self::schedule_worker( 300 );
                return;
            }

            $started  = microtime( true );
            $queue    = self::queue_table();
            $settings = self::settings();
            $batch    = min( 20, max( 1, (int) $settings['batch_size'] ) );

            $wpdb->query( "UPDATE {$queue} SET status='pending',locked_at=NULL,last_error='Odblokowano stare zadanie',updated_at=NOW() WHERE status='processing' AND locked_at < (NOW() - INTERVAL 20 MINUTE)" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

            $jobs = $wpdb->get_results( $wpdb->prepare( "SELECT id,product_id FROM {$queue} WHERE status='pending' ORDER BY id ASC LIMIT %d", $batch ) );

            foreach ( $jobs as $job ) {
                // Zatrzymanie jest kooperacyjne: bieżący produkt może się dokończyć,
                // ale kolejny nie zostanie rozpoczęty.
                if ( self::is_paused() || self::import_busy() || microtime( true ) - $started > 40 ) {
                    break; // reszta w następnym zadaniu
                }

                $claimed = $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$queue} SET status='processing',attempts=attempts+1,locked_at=NOW(),updated_at=NOW() WHERE id=%d AND status='pending'",
                        (int) $job->id
                    )
                );
                if ( 1 !== (int) $claimed ) {
                    continue;
                }

                try {
                    $result = self::process_product( (int) $job->product_id, $settings );
                    $status = in_array( $result['status'], array( 'done', 'skipped' ), true ) ? $result['status'] : 'error';
                    $wpdb->update(
                        $queue,
                        array(
                            'status'     => $status,
                            'locked_at'  => null,
                            'updated_at' => current_time( 'mysql' ),
                            'last_error' => ( 'error' === $status ) ? $result['message'] : null,
                        ),
                        array( 'id' => (int) $job->id ),
                        array( '%s', '%s', '%s', '%s' ),
                        array( '%d' )
                    );
                } catch ( Throwable $e ) {
                    $wpdb->update(
                        $queue,
                        array(
                            'status'     => 'error',
                            'locked_at'  => null,
                            'updated_at' => current_time( 'mysql' ),
                            'last_error' => $e->getMessage(),
                        ),
                        array( 'id' => (int) $job->id ),
                        array( '%s', '%s', '%s', '%s' ),
                        array( '%d' )
                    );
                    self::log( (int) $job->product_id, '', '', array(), '', null, '', 'error', 'Wyjątek: ' . $e->getMessage(), array() );
                }
            }

            $pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$queue} WHERE status='pending'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            if ( $pending > 0 && ! self::is_paused() ) {
                self::schedule_worker( min( 60, max( 3, (int) $settings['worker_delay'] ) ) );
            }
        } finally {
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
        }
    }

    private static function process_product( $product_id, $settings ) {
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            self::log( $product_id, '', '', array(), '', null, '', 'skipped', 'Produkt nie istnieje.', array() );
            return array( 'status' => 'skipped', 'message' => 'Produkt nie istnieje.' );
        }

        $sku  = (string) $product->get_sku();
        $name = (string) $product->get_name();

        $category = self::category_policy( $product );
        if ( empty( $category['allowed'] ) ) {
            self::log( $product_id, $sku, $name, array(), '', null, 'category', 'skipped', $category['message'], $category );
            return array( 'status' => 'skipped', 'message' => $category['message'] );
        }

        $existing_colors = self::existing_color_names( $product );
        if ( $existing_colors && self::has_manual_color( $product ) ) {
            // Jeżeli użytkownik zmienił wcześniej automatyczny kolor, traktujemy go od teraz jako ręczny.
            delete_post_meta( $product_id, '_foxsc_taxonomy_autoset' );
            $manual_value = implode( '/', array_slice( $existing_colors, 0, 3 ) );
            self::save_meta_result( $product_id, $manual_value, 1.0, 'manual', array( 'manual' => $existing_colors ) );
            self::log( $product_id, $sku, $name, array(), $manual_value, 1.0, 'manual', 'done', 'Zachowano kolor ustawiony ręcznie.', array() );
            return array( 'status' => 'done', 'message' => 'Kolor ręczny.' );
        }
        $previous_auto_colors = $existing_colors && self::colors_are_auto_managed( $product ) ? $existing_colors : array();

        if ( $product->is_type( 'variable' ) && self::product_uses_color_for_variations( $product ) ) {
            self::log( $product_id, $sku, $name, array(), '', null, 'variation', 'skipped', 'Produkt ma warianty po kolorze — nie wnioskujemy jednego pa_kolor ze zdjęcia głównego.', array() );
            return array( 'status' => 'skipped', 'message' => 'Produkt ma warianty po kolorze.' );
        }

        // Najpierw czytamy nazwę/oznaczenia producenta. Jeżeli kolor jest jawnie podany
        // (np. GREY, BLACK/WHITE, FLO RED), jest to silniejszy sygnał niż histogram zdjęcia.
        $text_signal = self::extract_text_color_signal( $product );
        $text_colors = $text_signal['colors'];
        $image_id    = (int) $product->get_image_id();
        $analysis    = null;
        $image       = null;
        $image_error = '';
        $hash        = '';
        $cleanup     = false;

        if ( $product->is_type( 'variation' ) && ! $image_id && ! $text_colors ) {
            self::log( $product_id, $sku, $name, array(), '', null, '', 'skipped', 'Wariant nie ma własnego zdjęcia ani jawnego koloru w nazwie.', array() );
            return array( 'status' => 'skipped', 'message' => 'Wariant bez własnego zdjęcia i koloru tekstowego.' );
        }

        if ( $image_id ) {
            $image = self::resolve_image_file( $image_id );
            if ( is_wp_error( $image ) ) {
                $image_error = $image->get_error_message();
                $image = null;
            } else {
                $cleanup = ! empty( $image['temporary'] );
                try {
                    $hash = self::image_hash( $image_id, $image['path'], $cleanup );
                    $analysis = self::analyze_image( $image['path'], ! empty( $settings['ignore_background'] ), (int) $settings['max_colors'] );
                    if ( is_wp_error( $analysis ) ) {
                        $image_error = $analysis->get_error_message();
                        $analysis = null;
                    }
                } finally {
                    if ( $cleanup && ! empty( $image['path'] ) && file_exists( $image['path'] ) ) {
                        @unlink( $image['path'] );
                    }
                }
            }
        } elseif ( ! $text_colors ) {
            self::log( $product_id, $sku, $name, array(), '', null, '', 'skipped', 'Brak zdjęcia głównego i brak koloru w nazwie/oznaczeniu producenta.', array() );
            return array( 'status' => 'skipped', 'message' => 'Brak danych do rozpoznania koloru.' );
        }

        // Gdy zdjęcie jest chwilowo niedostępne, ale producent podał jawny kolor w nazwie,
        // nadal możemy bezpiecznie uzupełnić pa_kolor. To zwiększa pokrycie katalogu.
        if ( ! $analysis && ! $text_colors ) {
            self::log( $product_id, $sku, $name, array(), '', null, '', 'error', $image_error ?: 'Nie udało się przeanalizować zdjęcia.', array() );
            return array( 'status' => 'error', 'message' => $image_error ?: 'Błąd analizy zdjęcia.' );
        }

        $image_colors     = $analysis ? $analysis['colors'] : array();
        $image_confidence = $analysis ? (float) $analysis['confidence'] : 0.0;
        $decision         = self::combine_signals( $image_colors, $image_confidence, $text_signal );
        $final_colors     = array_slice( $decision['colors'], 0, min( 3, max( 1, (int) $settings['max_colors'] ) ) );
        $final_value      = implode( '/', $final_colors );
        $confidence       = (float) $decision['confidence'];
        $source           = $decision['source'];

        $details = array(
            'image'        => $analysis,
            'image_error'  => $image_error,
            'text_signal'  => $text_signal,
            'decision'     => $decision,
            'image_id'     => $image_id,
            'image_hash'   => $hash,
            'algorithm'    => self::ALG_VERSION,
            'previous_auto_colors' => $previous_auto_colors,
        );

        if ( ! $final_colors ) {
            self::log( $product_id, $sku, $name, $image_colors, '', $confidence, $source, 'error', 'Nie udało się ustalić koloru.', $details );
            return array( 'status' => 'error', 'message' => 'Brak wiarygodnego koloru.' );
        }

        if ( $product->is_type( 'variation' ) ) {
            self::cleanup_product_legacy_meta( $product_id );
            update_post_meta( $product_id, '_foxsc_processed_at', current_time( 'mysql' ) );
            self::log( $product_id, $sku, $name, $image_colors, $final_value, $confidence, $source, 'skipped', 'Wariant nie jest automatycznie modyfikowany; wynik pozostaje wyłącznie w logu.', $details );
            return array( 'status' => 'skipped', 'message' => 'Wariant wymaga ręcznej decyzji.' );
        }

        $threshold = (float) $settings['min_confidence'];
        $status    = 'done';
        $message   = 'Zapisano wynik automatyczny.';

        if ( $confidence < $threshold ) {
            self::cleanup_product_legacy_meta( $product_id );
            update_post_meta( $product_id, '_foxsc_processed_at', current_time( 'mysql' ) );
            $status = 'skipped';
            if ( $previous_auto_colors ) {
                $message = 'Nowy wynik ma zbyt niską pewność — zachowano poprzedni automatyczny kolor produktu.';
            } else {
                $message = 'Zbyt niska pewność — produktu nie zmieniono. Kandydat jest dostępny w logu.';
            }
        } else {
            // Najpierw zapisujemy poprawny atrybut WooCommerce. Dopiero po powodzeniu
            // publikujemy uporządkowane pole GMC, aby oba źródła pozostawały spójne.
            $term_result = self::set_missing_taxonomy_colors( $product_id, $final_colors );
            if ( is_wp_error( $term_result ) ) {
                $status  = 'error';
                $message = 'Nie udało się ustawić pa_kolor: ' . $term_result->get_error_message();
            } elseif ( false === $term_result ) {
                $status  = 'skipped';
                $message = 'Wykryto istniejący kolor ręczny — produktu nie zmieniono.';
            } else {
                self::publish_result( $product_id, $final_value, $confidence, $source );
                $message = 'Uzupełniono atrybut pa_kolor i pole GMC.';
            }
        }

        if ( ! empty( $decision['conflict'] ) ) {
            $message .= ' Zdjęcie nie potwierdziło wprost koloru z nazwy, ale jawny kolor producenta otrzymał priorytet.';
        }

        self::log( $product_id, $sku, $name, $image_colors, $final_value, $confidence, $source, $status, $message, $details );
        return array( 'status' => $status, 'message' => $message );
    }

    /**
     * Polityka kategorii dla enduro.sklep.pl:
     * - wszystko poza „Części i Akcesoria” jest dozwolone,
     * - wewnątrz „Części i Akcesoria” dozwolone jest tylko „Nadwozie” + potomkowie.
     * Wariant dziedziczy kategorię produktu nadrzędnego.
     */
    private static function category_policy( $product ) {
        $object_id = $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : (int) $product->get_id();
        if ( ! $object_id || ! taxonomy_exists( 'product_cat' ) ) {
            return array( 'allowed' => true, 'message' => 'Brak kategorii do oceny.' );
        }

        $sets = self::category_term_sets();
        if ( empty( $sets['root_id'] ) ) {
            // Fail-open poza sytuacją, gdy kategoria została usunięta/zmieniono slug.
            return array( 'allowed' => true, 'message' => 'Nie ustawiono gałęzi ograniczonej — polityka kategorii nie została zastosowana.' );
        }

        $term_ids = wp_get_post_terms( $object_id, 'product_cat', array( 'fields' => 'ids' ) );
        if ( is_wp_error( $term_ids ) || ! $term_ids ) {
            return array( 'allowed' => true, 'message' => 'Produkt nie należy do wykluczonej gałęzi.' );
        }

        $in_parts   = false;
        $in_allowed = false;
        foreach ( array_map( 'intval', $term_ids ) as $term_id ) {
            if ( in_array( $term_id, $sets['root_ids'], true ) ) {
                $in_parts = true;
            }
            if ( in_array( $term_id, $sets['allowed_ids'], true ) ) {
                $in_allowed = true;
            }
        }

        if ( $in_allowed ) {
            return array(
                'allowed' => true,
                'message' => 'Dozwolony wyjątek kategorii lub jego podkategoria.',
                'scope'   => 'nadwozie',
            );
        }

        if ( $in_parts ) {
            return array(
                'allowed' => false,
                'message' => 'Pominięto: produkt należy do gałęzi ograniczonej, ale nie do dozwolonego wyjątku ani jego podkategorii.',
                'scope'   => 'excluded_parts',
            );
        }

        return array( 'allowed' => true, 'message' => 'Produkt poza gałęzią ograniczoną.', 'scope' => 'normal' );
    }

    private static function category_term_sets() {
        $settings = self::settings();
        $sets = array(
            'root_id'     => (int) $settings['parts_root_id'],
            'allowed_id'  => (int) $settings['allowed_subtree_id'],
            'root_ids'    => array(),
            'allowed_ids' => array(),
            'blocked_ids' => array(),
        );

        if ( ! $sets['root_id'] || ! taxonomy_exists( 'product_cat' ) ) {
            return $sets;
        }
        $root = get_term( $sets['root_id'], 'product_cat' );
        if ( ! $root || is_wp_error( $root ) ) {
            $sets['root_id'] = 0;
            return $sets;
        }

        $root_children    = get_term_children( $sets['root_id'], 'product_cat' );
        $root_children    = is_wp_error( $root_children ) ? array() : array_map( 'intval', $root_children );
        $sets['root_ids'] = array_values( array_unique( array_merge( array( $sets['root_id'] ), $root_children ) ) );

        if ( $sets['allowed_id'] && in_array( $sets['allowed_id'], $sets['root_ids'], true ) ) {
            $allowed_children = get_term_children( $sets['allowed_id'], 'product_cat' );
            $allowed_children = is_wp_error( $allowed_children ) ? array() : array_map( 'intval', $allowed_children );
            $sets['allowed_ids'] = array_values( array_unique( array_merge( array( $sets['allowed_id'] ), $allowed_children ) ) );
        }

        $sets['blocked_ids'] = array_values( array_diff( $sets['root_ids'], $sets['allowed_ids'] ) );
        return $sets;
    }

    /**
     * SQL-owa wersja polityki kategorii używana już podczas budowania kolejki,
     * aby nie wrzucać tysięcy technicznych części tylko po to, by je później pominąć.
     */
    private static function category_sql_clause( $object_id_sql ) {
        global $wpdb;
        $sets = self::category_term_sets();
        if ( empty( $sets['root_id'] ) || empty( $sets['blocked_ids'] ) ) {
            return '';
        }

        $blocked = implode( ',', array_map( 'intval', $sets['blocked_ids'] ) );
        $allowed = implode( ',', array_map( 'intval', $sets['allowed_ids'] ) );

        $blocked_exists = "EXISTS (
            SELECT 1 FROM {$wpdb->term_relationships} foxsc_tr_b
            INNER JOIN {$wpdb->term_taxonomy} foxsc_tt_b ON foxsc_tt_b.term_taxonomy_id=foxsc_tr_b.term_taxonomy_id
            WHERE foxsc_tr_b.object_id={$object_id_sql}
              AND foxsc_tt_b.taxonomy='product_cat'
              AND foxsc_tt_b.term_id IN ({$blocked})
        )";

        if ( $allowed ) {
            $allowed_exists = "EXISTS (
                SELECT 1 FROM {$wpdb->term_relationships} foxsc_tr_a
                INNER JOIN {$wpdb->term_taxonomy} foxsc_tt_a ON foxsc_tt_a.term_taxonomy_id=foxsc_tr_a.term_taxonomy_id
                WHERE foxsc_tr_a.object_id={$object_id_sql}
                  AND foxsc_tt_a.taxonomy='product_cat'
                  AND foxsc_tt_a.term_id IN ({$allowed})
            )";
            return " AND (NOT {$blocked_exists} OR {$allowed_exists}) ";
        }

        return " AND NOT {$blocked_exists} ";
    }

    private static function product_uses_color_for_variations( $product ) {
        if ( ! $product->is_type( 'variable' ) ) {
            return false;
        }

        $attributes = $product->get_attributes();
        if ( isset( $attributes[ self::TAXONOMY ] ) && is_a( $attributes[ self::TAXONOMY ], 'WC_Product_Attribute' ) ) {
            return (bool) $attributes[ self::TAXONOMY ]->get_variation();
        }

        $variation_attributes = $product->get_variation_attributes();
        return isset( $variation_attributes[ self::TAXONOMY ] ) && ! empty( $variation_attributes[ self::TAXONOMY ] );
    }

    /**
     * Migracja 1.x: stare wersje zapisywały ID terminów w _foxsc_taxonomy_autoset.
     * W 2.0 nie tworzymy już tego pola; używamy porównania aktualnego pa_kolor
     * z uporządkowanym _foxsc_gmc_color i źródłem wyniku.
     */
    private static function tracked_auto_color_ids( $product_id ) {
        $raw = (string) get_post_meta( (int) $product_id, '_foxsc_taxonomy_autoset', true );
        if ( '' === trim( $raw ) ) {
            return array();
        }
        $ids = array_values( array_unique( array_filter( array_map( 'absint', preg_split( '/\s*,\s*/', $raw ) ) ) ) );
        sort( $ids, SORT_NUMERIC );
        return $ids;
    }

    private static function existing_color_term_ids( $product ) {
        if ( ! is_a( $product, 'WC_Product' ) || $product->is_type( 'variation' ) || ! taxonomy_exists( self::TAXONOMY ) ) {
            return array();
        }
        $ids = wp_get_object_terms( $product->get_id(), self::TAXONOMY, array( 'fields' => 'ids' ) );
        if ( is_wp_error( $ids ) ) {
            return array();
        }
        $ids = array_values( array_unique( array_map( 'absint', $ids ) ) );
        sort( $ids, SORT_NUMERIC );
        return $ids;
    }

    private static function normalize_color_set( $colors ) {
        $out = array();
        foreach ( (array) $colors as $color ) {
            $key = strtolower( remove_accents( trim( wp_strip_all_tags( (string) $color ) ) ) );
            if ( '' !== $key ) {
                $out[] = $key;
            }
        }
        $out = array_values( array_unique( $out ) );
        sort( $out, SORT_STRING );
        return $out;
    }

    private static function stored_source( $product_id ) {
        $source = (string) get_post_meta( (int) $product_id, '_foxsc_source', true );
        if ( ! $source ) {
            $source = (string) get_post_meta( (int) $product_id, '_foxsc_color_source', true ); // 1.x
        }
        return sanitize_key( $source );
    }

    private static function colors_are_auto_managed( $product ) {
        if ( ! is_a( $product, 'WC_Product' ) || $product->is_type( 'variation' ) ) {
            return false;
        }

        $current_names = self::existing_color_names( $product );
        if ( ! $current_names ) {
            return false;
        }

        $source = self::stored_source( $product->get_id() );
        $gmc    = (string) get_post_meta( $product->get_id(), '_foxsc_gmc_color', true );
        if ( $source && 'manual' !== $source && $gmc ) {
            $gmc_names = preg_split( '#\s*/\s*#u', $gmc, -1, PREG_SPLIT_NO_EMPTY );
            if ( self::normalize_color_set( $current_names ) === self::normalize_color_set( $gmc_names ) ) {
                return true;
            }
        }

        // Fallback migracyjny dla 1.x.
        $current_ids = self::existing_color_term_ids( $product );
        $tracked_ids = self::tracked_auto_color_ids( $product->get_id() );
        return ! empty( $current_ids ) && ! empty( $tracked_ids ) && $current_ids === $tracked_ids;
    }

    private static function has_manual_color( $product ) {
        $colors = self::existing_color_names( $product );
        if ( ! $colors ) {
            return false;
        }
        if ( $product->is_type( 'variation' ) ) {
            return true;
        }
        return ! self::colors_are_auto_managed( $product );
    }

    private static function existing_color_names( $product ) {
        $colors = array();

        if ( $product->is_type( 'variation' ) ) {
            $attrs = $product->get_attributes();
            $value = isset( $attrs[ self::TAXONOMY ] ) ? $attrs[ self::TAXONOMY ] : '';
            if ( $value ) {
                $term = get_term_by( 'slug', $value, self::TAXONOMY );
                $colors[] = $term && ! is_wp_error( $term ) ? $term->name : wc_clean( $value );
            }
        } else {
            if ( taxonomy_exists( self::TAXONOMY ) ) {
                $terms = wc_get_product_terms( $product->get_id(), self::TAXONOMY, array( 'fields' => 'names' ) );
                if ( ! is_wp_error( $terms ) ) {
                    $colors = $terms;
                }
            }
        }

        $colors = array_values( array_unique( array_filter( array_map( 'wc_clean', $colors ) ) ) );
        return array_slice( $colors, 0, 3 );
    }

    private static function resolve_image_file( $attachment_id ) {
        $path = get_attached_file( $attachment_id );
        if ( $path && file_exists( $path ) ) {
            $meta = wp_get_attachment_metadata( $attachment_id );
            if ( is_array( $meta ) && ! empty( $meta['sizes']['medium']['file'] ) ) {
                $candidate = trailingslashit( dirname( $path ) ) . $meta['sizes']['medium']['file'];
                if ( file_exists( $candidate ) ) {
                    $path = $candidate;
                }
            }
            return array( 'path' => $path, 'temporary' => false );
        }

        $url = wp_get_attachment_image_url( $attachment_id, 'medium' );
        if ( ! $url ) {
            $url = wp_get_attachment_url( $attachment_id );
        }
        if ( ! $url ) {
            return new WP_Error( 'foxsc_no_image', 'Brak lokalnego pliku i adresu URL zdjęcia.' );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        $tmp = download_url( $url, 20 );
        if ( is_wp_error( $tmp ) ) {
            return new WP_Error( 'foxsc_download', 'Nie udało się pobrać zdjęcia: ' . $tmp->get_error_message() );
        }
        if ( filesize( $tmp ) > 20 * MB_IN_BYTES ) {
            @unlink( $tmp );
            return new WP_Error( 'foxsc_too_large', 'Pobrany plik przekracza limit 20 MB.' );
        }
        return array( 'path' => $tmp, 'temporary' => true );
    }

    private static function image_hash( $attachment_id, $path, $temporary = false ) {
        $modified = (string) get_post_field( 'post_modified_gmt', $attachment_id );
        $meta     = wp_get_attachment_metadata( $attachment_id );
        $meta_sig = is_array( $meta ) ? wp_json_encode( array(
            'file'   => isset( $meta['file'] ) ? $meta['file'] : '',
            'width'  => isset( $meta['width'] ) ? $meta['width'] : 0,
            'height' => isset( $meta['height'] ) ? $meta['height'] : 0,
        ) ) : '';
        $mtime = ( ! $temporary && file_exists( $path ) ) ? (int) filemtime( $path ) : 0;
        $size  = file_exists( $path ) ? (int) filesize( $path ) : 0;
        return hash( 'sha256', $attachment_id . '|' . $modified . '|' . $meta_sig . '|' . $mtime . '|' . $size . '|' . self::ALG_VERSION );
    }

    private static function analyze_image( $path, $ignore_background, $max_colors ) {
        $im = self::load_as_gd( $path );
        if ( is_wp_error( $im ) ) {
            return $im;
        }

        $w = imagesx( $im );
        $h = imagesy( $im );
        if ( $w < 2 || $h < 2 ) {
            imagedestroy( $im );
            return new WP_Error( 'foxsc_bad_dimensions', 'Nieprawidłowe wymiary obrazu.' );
        }

        $max_side = 180;
        $scale    = min( 1, $max_side / max( $w, $h ) );
        $nw       = max( 1, (int) round( $w * $scale ) );
        $nh       = max( 1, (int) round( $h * $scale ) );
        $thumb    = imagecreatetruecolor( $nw, $nh );
        imagealphablending( $thumb, false );
        imagesavealpha( $thumb, true );
        $transparent = imagecolorallocatealpha( $thumb, 255, 255, 255, 127 );
        imagefill( $thumb, 0, 0, $transparent );
        imagecopyresampled( $thumb, $im, 0, 0, 0, 0, $nw, $nh, $w, $h );
        imagedestroy( $im );

        $background = $ignore_background ? self::detect_border_background( $thumb ) : null;
        $scores     = array();
        $dist_sum   = 0.0;
        $weight_sum = 0.0;
        $total_seen = 0;
        $foreground = 0;
        $step       = max( 1, (int) floor( max( $nw, $nh ) / 120 ) );

        for ( $y = 0; $y < $nh; $y += $step ) {
            for ( $x = 0; $x < $nw; $x += $step ) {
                $rgba = self::gd_pixel( $thumb, $x, $y );
                if ( $rgba['opacity'] < 0.16 ) {
                    continue;
                }
                $total_seen++;
                $lab = self::rgb_to_lab( $rgba['r'], $rgba['g'], $rgba['b'] );

                if ( $background && self::delta_e76( $lab, $background['lab'] ) < $background['threshold'] ) {
                    continue;
                }

                $foreground++;
                $nearest = self::nearest_palette_color( $lab );
                $cx      = abs( ( $x + 0.5 ) / $nw - 0.5 );
                $cy      = abs( ( $y + 0.5 ) / $nh - 0.5 );
                $center  = 1.20 - min( 0.35, ( $cx + $cy ) * 0.35 );
                $weight  = $center * $rgba['opacity'];

                if ( ! isset( $scores[ $nearest['name'] ] ) ) {
                    $scores[ $nearest['name'] ] = 0.0;
                }
                $scores[ $nearest['name'] ] += $weight;
                $dist_sum                 += min( 60, $nearest['distance'] ) * $weight;
                $weight_sum               += $weight;
            }
        }
        imagedestroy( $thumb );

        if ( $weight_sum <= 0 || ! $scores ) {
            return new WP_Error( 'foxsc_empty_foreground', 'Po odjęciu tła nie pozostały piksele produktu.' );
        }

        arsort( $scores );
        $shares = array();
        foreach ( $scores as $color => $score ) {
            $shares[ $color ] = $score / $weight_sum;
        }

        $selected = array();
        $primary  = reset( $shares );
        foreach ( $shares as $color => $share ) {
            if ( ! $selected ) {
                if ( $share >= 0.07 ) {
                    $selected[] = $color;
                }
                continue;
            }
            $minimum = ( count( $selected ) === 1 ) ? max( 0.14, $primary * 0.34 ) : max( 0.11, $primary * 0.28 );
            if ( in_array( $color, array( 'Czarny', 'Biały' ), true ) && $share < 0.18 && ! in_array( $color, $selected, true ) ) {
                continue;
            }
            if ( $share >= $minimum ) {
                $selected[] = $color;
            }
            if ( count( $selected ) >= $max_colors ) {
                break;
            }
        }

        if ( ! $selected ) {
            $selected[] = key( $shares );
        }

        $avg_distance     = $dist_sum / $weight_sum;
        $palette_quality  = max( 0, min( 1, 1 - ( $avg_distance / 48 ) ) );
        $foreground_ratio = $total_seen > 0 ? $foreground / $total_seen : 0;
        $foreground_score = min( 1, $foreground_ratio / 0.28 );
        $primary_score    = min( 1, $primary / 0.40 );
        $bg_score         = $background ? min( 1, $background['confidence'] ) : 0.55;
        $confidence       = ( 0.45 * $palette_quality ) + ( 0.20 * $foreground_score ) + ( 0.20 * $primary_score ) + ( 0.15 * $bg_score );
        $confidence       = max( 0.05, min( 0.99, $confidence ) );

        return array(
            'colors'           => array_values( $selected ),
            'shares'           => array_map( static function( $v ) { return round( $v, 4 ); }, $shares ),
            'confidence'       => round( $confidence, 4 ),
            'foreground_ratio' => round( $foreground_ratio, 4 ),
            'avg_delta_e'      => round( $avg_distance, 2 ),
            'background'       => $background ? array(
                'rgb'        => $background['rgb'],
                'confidence' => round( $background['confidence'], 4 ),
                'threshold'  => $background['threshold'],
            ) : null,
        );
    }

    private static function load_as_gd( $path ) {
        if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
            return new WP_Error( 'foxsc_unreadable', 'Plik obrazu jest niedostępny.' );
        }
        if ( filesize( $path ) > 20 * MB_IN_BYTES ) {
            return new WP_Error( 'foxsc_file_large', 'Plik obrazu przekracza limit 20 MB.' );
        }

        $bytes = @file_get_contents( $path );
        if ( false !== $bytes && function_exists( 'imagecreatefromstring' ) ) {
            $gd = @imagecreatefromstring( $bytes );
            unset( $bytes );
            if ( $gd ) {
                return $gd;
            }
        }

        if ( class_exists( 'Imagick' ) && function_exists( 'imagecreatefromstring' ) ) {
            try {
                $img = new Imagick();
                $img->readImage( $path . '[0]' );
                if ( method_exists( $img, 'autoOrientImage' ) ) {
                    $img->autoOrientImage();
                }
                $img->setImageColorspace( Imagick::COLORSPACE_SRGB );
                $img->thumbnailImage( 240, 240, true, true );
                $img->setImageFormat( 'png' );
                $blob = $img->getImageBlob();
                $img->clear();
                $img->destroy();
                $gd = @imagecreatefromstring( $blob );
                unset( $blob );
                if ( $gd ) {
                    return $gd;
                }
            } catch ( Throwable $e ) {
                return new WP_Error( 'foxsc_imagick', 'Imagick: ' . $e->getMessage() );
            }
        }

        return new WP_Error( 'foxsc_format', 'Nieobsługiwany format obrazu albo brak GD/Imagick.' );
    }

    private static function detect_border_background( $im ) {
        $w      = imagesx( $im );
        $h      = imagesy( $im );
        $margin = max( 2, (int) round( min( $w, $h ) * 0.08 ) );
        $bins   = array();
        $total  = 0;

        for ( $y = 0; $y < $h; $y += 2 ) {
            for ( $x = 0; $x < $w; $x += 2 ) {
                if ( $x >= $margin && $x < ( $w - $margin ) && $y >= $margin && $y < ( $h - $margin ) ) {
                    continue;
                }
                $px = self::gd_pixel( $im, $x, $y );
                if ( $px['opacity'] < 0.20 ) {
                    continue;
                }
                $q   = 24;
                $qr  = min( 255, (int) round( $px['r'] / $q ) * $q );
                $qg  = min( 255, (int) round( $px['g'] / $q ) * $q );
                $qb  = min( 255, (int) round( $px['b'] / $q ) * $q );
                $key = $qr . ':' . $qg . ':' . $qb;
                if ( ! isset( $bins[ $key ] ) ) {
                    $bins[ $key ] = array( 'count' => 0, 'r' => 0, 'g' => 0, 'b' => 0 );
                }
                $bins[ $key ]['count']++;
                $bins[ $key ]['r'] += $px['r'];
                $bins[ $key ]['g'] += $px['g'];
                $bins[ $key ]['b'] += $px['b'];
                $total++;
            }
        }

        if ( ! $bins || $total < 20 ) {
            return null;
        }
        uasort( $bins, static function( $a, $b ) { return $b['count'] <=> $a['count']; } );
        $dominant = reset( $bins );
        $share    = $dominant['count'] / $total;
        if ( $share < 0.48 ) {
            return null;
        }

        $rgb = array(
            (int) round( $dominant['r'] / $dominant['count'] ),
            (int) round( $dominant['g'] / $dominant['count'] ),
            (int) round( $dominant['b'] / $dominant['count'] ),
        );
        $lab = self::rgb_to_lab( $rgb[0], $rgb[1], $rgb[2] );

        $center_total = 0;
        $center_near  = 0;
        $x1 = (int) round( $w * 0.25 );
        $x2 = (int) round( $w * 0.75 );
        $y1 = (int) round( $h * 0.25 );
        $y2 = (int) round( $h * 0.75 );
        for ( $y = $y1; $y < $y2; $y += 3 ) {
            for ( $x = $x1; $x < $x2; $x += 3 ) {
                $px = self::gd_pixel( $im, $x, $y );
                if ( $px['opacity'] < 0.20 ) {
                    continue;
                }
                $center_total++;
                if ( self::delta_e76( self::rgb_to_lab( $px['r'], $px['g'], $px['b'] ), $lab ) < 11 ) {
                    $center_near++;
                }
            }
        }
        $center_share = $center_total ? $center_near / $center_total : 0;
        if ( $center_share > max( 0.62, $share - 0.08 ) ) {
            return null;
        }

        return array(
            'rgb'        => $rgb,
            'lab'        => $lab,
            'confidence' => min( 1, max( 0, ( $share - $center_share ) / 0.65 ) ),
            'threshold'  => $lab[0] > 85 ? 13 : 10,
        );
    }

    private static function gd_pixel( $im, $x, $y ) {
        $index = imagecolorat( $im, $x, $y );
        $rgba  = imagecolorsforindex( $im, $index );
        $alpha = isset( $rgba['alpha'] ) ? (int) $rgba['alpha'] : 0;
        return array(
            'r'       => (int) $rgba['red'],
            'g'       => (int) $rgba['green'],
            'b'       => (int) $rgba['blue'],
            'opacity' => 1 - min( 127, max( 0, $alpha ) ) / 127,
        );
    }

    private static function palette() {
        static $palette = null;
        if ( null !== $palette ) {
            return $palette;
        }

        $anchors = array(
            'Czarny'       => array( '#050505', '#151515', '#252525' ),
            'Biały'        => array( '#FFFFFF', '#F6F6F2', '#EDEDED' ),
            'Szary'        => array( '#808080', '#60656B', '#8A8F94', '#42474D' ),
            'Srebrny'      => array( '#C0C0C0', '#D1D5D8', '#B5BBC0', '#E0E2E3' ),
            'Brązowy'      => array( '#5C4033', '#7B3F00', '#8B4513', '#6F4E37' ),
            'Beżowy'       => array( '#F5F5DC', '#D8C3A5', '#C8AD7F', '#E4D5B7' ),
            'Czerwony'     => array( '#E60012', '#FF0000', '#C1121F', '#B00020' ),
            'Bordowy'      => array( '#800020', '#6D071A', '#7F1734' ),
            'Pomarańczowy' => array( '#FF7A00', '#FF8C00', '#F05A00' ),
            'Żółty'        => array( '#FFD400', '#FFFF00', '#F2C300' ),
            'Złoty'        => array( '#D4AF37', '#C9A227', '#B8860B', '#E0B84B' ),
            'Zielony'      => array( '#00A651', '#32CD32', '#008A45', '#00C853' ),
            'Oliwkowy'     => array( '#808000', '#6B7B3E', '#556B2F' ),
            'Niebieski'    => array( '#0067C5', '#0070FF', '#1E88E5', '#0057B8' ),
            'Granatowy'    => array( '#000080', '#001F54', '#0B1F3A', '#14213D' ),
            'Turkusowy'    => array( '#00A6A6', '#00CED1', '#00B8A9', '#008C95' ),
            'Fioletowy'    => array( '#800080', '#6A0DAD', '#7B2CBF', '#5A189A' ),
            'Różowy'       => array( '#FF69B4', '#FF4FA3', '#E83E8C', '#FFC0CB' ),
        );

        $palette = array();
        foreach ( $anchors as $name => $hexes ) {
            foreach ( $hexes as $hex ) {
                $rgb       = self::hex_to_rgb( $hex );
                $palette[] = array( 'name' => $name, 'lab' => self::rgb_to_lab( $rgb[0], $rgb[1], $rgb[2] ) );
            }
        }
        return $palette;
    }

    private static function nearest_palette_color( $lab ) {
        $best = array( 'name' => 'Inny', 'distance' => PHP_FLOAT_MAX );
        foreach ( self::palette() as $anchor ) {
            $d = self::delta_e76( $lab, $anchor['lab'] );
            if ( $d < $best['distance'] ) {
                $best = array( 'name' => $anchor['name'], 'distance' => $d );
            }
        }
        return $best;
    }

    private static function rgb_to_lab( $r, $g, $b ) {
        $r = $r / 255;
        $g = $g / 255;
        $b = $b / 255;
        $r = $r > 0.04045 ? pow( ( $r + 0.055 ) / 1.055, 2.4 ) : $r / 12.92;
        $g = $g > 0.04045 ? pow( ( $g + 0.055 ) / 1.055, 2.4 ) : $g / 12.92;
        $b = $b > 0.04045 ? pow( ( $b + 0.055 ) / 1.055, 2.4 ) : $b / 12.92;

        $x = ( $r * 0.4124564 + $g * 0.3575761 + $b * 0.1804375 ) / 0.95047;
        $y = ( $r * 0.2126729 + $g * 0.7151522 + $b * 0.0721750 ) / 1.00000;
        $z = ( $r * 0.0193339 + $g * 0.1191920 + $b * 0.9503041 ) / 1.08883;

        $f = static function( $t ) {
            return $t > 0.008856 ? pow( $t, 1 / 3 ) : ( 7.787 * $t ) + ( 16 / 116 );
        };
        $fx = $f( $x );
        $fy = $f( $y );
        $fz = $f( $z );

        return array(
            ( 116 * $fy ) - 16,
            500 * ( $fx - $fy ),
            200 * ( $fy - $fz ),
        );
    }

    private static function delta_e76( $a, $b ) {
        return sqrt( pow( $a[0] - $b[0], 2 ) + pow( $a[1] - $b[1], 2 ) + pow( $a[2] - $b[2], 2 ) );
    }

    private static function hex_to_rgb( $hex ) {
        $hex = ltrim( $hex, '#' );
        return array(
            hexdec( substr( $hex, 0, 2 ) ),
            hexdec( substr( $hex, 2, 2 ) ),
            hexdec( substr( $hex, 4, 2 ) ),
        );
    }

    /**
     * Wydobywa jawne oznaczenia kolorów z nazwy produktu i pól wariantu producenta.
     * Najdłuższe frazy mają pierwszeństwo, więc np. "midnight blue" nie daje jednocześnie
     * Granatowego i Niebieskiego, a "dark shadow" nie jest rozbijane na przypadkowe tokeny.
     */
    private static function extract_text_color_signal( $product ) {
        $title = wp_strip_all_tags( (string) $product->get_name() );
        $variant_parts = array();
        foreach ( array( 'pa_kolor-producenta', 'pa_blvariant', 'pa_wariant', 'pa_variant' ) as $tax ) {
            $value = $product->get_attribute( $tax );
            if ( $value ) {
                $variant_parts[] = wp_strip_all_tags( (string) $value );
            }
        }

        $short_description = method_exists( $product, 'get_short_description' ) ? wp_strip_all_tags( (string) $product->get_short_description() ) : '';
        $description       = method_exists( $product, 'get_description' ) ? wp_strip_all_tags( (string) $product->get_description() ) : '';

        // Alias -> standardowy kolor pa_kolor. Złożone frazy (np. NEON YELLOW)
        // są rozpoznawane jako całość i nie dublują prostszego YELLOW.
        $aliases = array(
            'fluorescent yellow' => 'Żółty', 'fluo yellow' => 'Żółty', 'flo yellow' => 'Żółty', 'neon yellow' => 'Żółty',
            'fluorescent red' => 'Czerwony', 'fluo red' => 'Czerwony', 'flo red' => 'Czerwony', 'neon red' => 'Czerwony',
            'fluorescent orange' => 'Pomarańczowy', 'fluo orange' => 'Pomarańczowy', 'flo orange' => 'Pomarańczowy', 'neon orange' => 'Pomarańczowy',
            'fluorescent green' => 'Zielony', 'fluo green' => 'Zielony', 'flo green' => 'Zielony', 'neon green' => 'Zielony',
            'fluorescent pink' => 'Różowy', 'fluo pink' => 'Różowy', 'flo pink' => 'Różowy', 'neon pink' => 'Różowy',
            'midnight blue' => 'Granatowy', 'dark navy' => 'Granatowy', 'navy blue' => 'Granatowy',
            'royal blue' => 'Niebieski', 'light blue' => 'Niebieski', 'sky blue' => 'Niebieski', 'ice blue' => 'Niebieski',
            'dark shadow' => 'Szary', 'steel grey' => 'Szary', 'steel gray' => 'Szary', 'dark grey' => 'Szary', 'dark gray' => 'Szary',
            'light grey' => 'Szary', 'light gray' => 'Szary', 'heather grey' => 'Szary', 'heather gray' => 'Szary',
            'matte black' => 'Czarny', 'matt black' => 'Czarny', 'blackout' => 'Czarny', 'stealth black' => 'Czarny',
            'off white' => 'Biały', 'optic white' => 'Biały', 'cream white' => 'Biały',
            'army green' => 'Zielony', 'military green' => 'Zielony', 'forest green' => 'Zielony', 'moss green' => 'Zielony',
            'burnt orange' => 'Pomarańczowy', 'atomic orange' => 'Pomarańczowy',
            'hot pink' => 'Różowy', 'dusty pink' => 'Różowy',
            'deep purple' => 'Fioletowy', 'dark purple' => 'Fioletowy',
            'rose gold' => 'Złoty', 'champagne gold' => 'Złoty',

            'anthracite' => 'Szary', 'antracyt' => 'Szary', 'graphite' => 'Szary', 'grafit' => 'Szary',
            'gunmetal' => 'Szary', 'pewter' => 'Szary', 'charcoal' => 'Szary', 'slate' => 'Szary',
            'grey' => 'Szary', 'gray' => 'Szary', 'szary' => 'Szary', 'szara' => 'Szary', 'szare' => 'Szary',
            'silver' => 'Srebrny', 'srebrny' => 'Srebrny', 'srebrna' => 'Srebrny', 'srebrne' => 'Srebrny',
            'black' => 'Czarny', 'czarny' => 'Czarny', 'czarna' => 'Czarny', 'czarne' => 'Czarny',
            'white' => 'Biały', 'biały' => 'Biały', 'bialy' => 'Biały', 'biała' => 'Biały', 'biala' => 'Biały', 'białe' => 'Biały', 'biale' => 'Biały',
            'burgundy' => 'Bordowy', 'maroon' => 'Bordowy', 'bordowy' => 'Bordowy', 'bordowa' => 'Bordowy',
            'crimson' => 'Czerwony', 'scarlet' => 'Czerwony', 'red' => 'Czerwony', 'czerwony' => 'Czerwony', 'czerwona' => 'Czerwony', 'czerwone' => 'Czerwony',
            'orange' => 'Pomarańczowy', 'pomarańczowy' => 'Pomarańczowy', 'pomarańczowa' => 'Pomarańczowy', 'pomarańczowe' => 'Pomarańczowy',
            'yellow' => 'Żółty', 'żółty' => 'Żółty', 'zolty' => 'Żółty', 'żółta' => 'Żółty', 'zolta' => 'Żółty', 'żółte' => 'Żółty', 'zolte' => 'Żółty',
            'olive' => 'Oliwkowy', 'oliwkowy' => 'Oliwkowy', 'oliwkowa' => 'Oliwkowy',
            'lime' => 'Zielony', 'sage' => 'Zielony', 'moss' => 'Zielony', 'emerald' => 'Zielony', 'mint' => 'Zielony',
            'green' => 'Zielony', 'zielony' => 'Zielony', 'zielona' => 'Zielony', 'zielone' => 'Zielony',
            'navy' => 'Granatowy', 'granatowy' => 'Granatowy', 'granatowa' => 'Granatowy',
            'cobalt' => 'Niebieski', 'azure' => 'Niebieski', 'blue' => 'Niebieski', 'niebieski' => 'Niebieski', 'niebieska' => 'Niebieski', 'niebieskie' => 'Niebieski',
            'turquoise' => 'Turkusowy', 'turkusowy' => 'Turkusowy', 'teal' => 'Turkusowy', 'aqua' => 'Turkusowy', 'cyan' => 'Turkusowy',
            'violet' => 'Fioletowy', 'purple' => 'Fioletowy', 'lavender' => 'Fioletowy', 'lilac' => 'Fioletowy', 'fioletowy' => 'Fioletowy', 'fioletowa' => 'Fioletowy',
            'magenta' => 'Różowy', 'pink' => 'Różowy', 'różowy' => 'Różowy', 'rozowy' => 'Różowy', 'różowa' => 'Różowy', 'rozowa' => 'Różowy',
            'golden' => 'Złoty', 'gold' => 'Złoty', 'złoty' => 'Złoty', 'zloty' => 'Złoty', 'złota' => 'Złoty', 'zlota' => 'Złoty',
            'bronze' => 'Brązowy', 'brown' => 'Brązowy', 'chocolate' => 'Brązowy', 'mocha' => 'Brązowy', 'brązowy' => 'Brązowy', 'brazowy' => 'Brązowy',
            'taupe' => 'Beżowy', 'beige' => 'Beżowy', 'sand' => 'Beżowy', 'tan' => 'Beżowy', 'khaki' => 'Beżowy', 'cream' => 'Beżowy', 'beżowy' => 'Beżowy', 'bezowy' => 'Beżowy', 'beżowa' => 'Beżowy', 'bezowa' => 'Beżowy',
        );

        // 1) Najmocniejszy sygnał: jawnie oznaczone pole KOLOR/COLOR.
        //    Ignorujemy etykiety dotyczące szybki/soczewki, bo to nie zawsze jest kolor produktu.
        $explicit_sources = array(
            array( 'source' => 'title-explicit', 'text' => $title ),
        );
        foreach ( $variant_parts as $variant_text ) {
            $explicit_sources[] = array( 'source' => 'variant-explicit', 'text' => $variant_text );
        }
        if ( $short_description ) {
            $explicit_sources[] = array( 'source' => 'short-description-explicit', 'text' => $short_description );
        }
        if ( $description ) {
            $explicit_sources[] = array( 'source' => 'description-explicit', 'text' => $description );
        }

        foreach ( $explicit_sources as $source_row ) {
            $segments = self::explicit_color_segments( $source_row['text'] );
            foreach ( $segments as $segment ) {
                $parsed = self::match_color_aliases( $segment, $aliases, true );
                if ( ! empty( $parsed['colors'] ) ) {
                    return array(
                        'colors'       => array_slice( $parsed['colors'], 0, 3 ),
                        'matches'      => $parsed['matches'],
                        'text'         => $title,
                        'signal_type'  => 'explicit',
                        'signal_source'=> $source_row['source'],
                        'segment'      => $segment,
                    );
                }
            }
        }

        // 2) Dedykowane pole producenta/wariantu ma wyższy priorytet niż luźne słowa w tytule.
        foreach ( $variant_parts as $variant_text ) {
            $parsed = self::match_color_aliases( $variant_text, $aliases, true );
            if ( ! empty( $parsed['colors'] ) ) {
                return array(
                    'colors'        => array_slice( $parsed['colors'], 0, 3 ),
                    'matches'       => $parsed['matches'],
                    'text'          => $variant_text,
                    'signal_type'   => 'variant',
                    'signal_source' => 'manufacturer-variant',
                    'segment'       => $variant_text,
                );
            }
        }

        // 3) Fallback: nazwa produktu. Tutaj wyrażenia jednoznaczne (np. NEON YELLOW)
        //    wygrywają z nazwami odcieni, które bywają nazwą kolekcji/modelu (np. SAND).
        $parsed = self::match_color_aliases( $title, $aliases, false );

        return array(
            'colors'        => array_slice( $parsed['colors'], 0, 3 ),
            'matches'       => $parsed['matches'],
            'text'          => $title,
            'signal_type'   => ! empty( $parsed['colors'] ) ? 'title' : 'none',
            'signal_source' => ! empty( $parsed['colors'] ) ? 'title' : '',
            'segment'       => '',
        );
    }

    /**
     * Zwraca fragmenty po etykietach typu "KOLOR", "Kolor oprawy", "COLOR".
     * Celowo nie traktujemy "kolor szybki/soczewki/lens" jako koloru całego produktu.
     */
    private static function explicit_color_segments( $text ) {
        $text = wp_strip_all_tags( (string) $text );
        if ( '' === trim( $text ) ) {
            return array();
        }

        $pattern = '/(?:^|[\s|;,\-])(?:kolor(?!\s+(?:szybki|szyby|soczewki|wizjera)\b)(?:\s+(?:produktu|oprawy|ramy|obudowy))?|colou?r(?!\s+(?:lens|visor)\b)(?:\s+(?:product|frame|body))?)\s*[:\-]?\s*(.{1,90}?)(?=(?:\s+(?:szybka|soczewka|wizjer|lens|visor|rozmiar|size|model|wariant|variant|kod|sku|ean|gtin|mpn|stan|condition)\b)|(?:[\r\n]|\s+[|;]\s*)|$)/iu';
        if ( ! preg_match_all( $pattern, $text, $matches ) || empty( $matches[1] ) ) {
            return array();
        }

        $segments = array();
        foreach ( $matches[1] as $segment ) {
            $segment = trim( preg_replace( '/\s+/u', ' ', (string) $segment ), " \t\n\r\0\x0B-–—:;,." );
            if ( '' !== $segment ) {
                $segments[] = $segment;
            }
        }
        return array_values( array_unique( $segments ) );
    }

    /**
     * Rozpoznaje aliasy kolorów. W trybie jawnego pola zachowujemy kolejność zapisu.
     * W trybie tytułu podnosimy rangę precyzyjnych fraz, a obniżamy wieloznaczne odcienie
     * takie jak SAND/TAN/SLATE, żeby nazwa modelu nie wypchnęła właściwego koloru.
     */
    private static function match_color_aliases( $text, $aliases, $explicit = false ) {
        $text = wp_strip_all_tags( (string) $text );
        $text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );

        uksort( $aliases, static function( $a, $b ) {
            $la = function_exists( 'mb_strlen' ) ? mb_strlen( $a, 'UTF-8' ) : strlen( $a );
            $lb = function_exists( 'mb_strlen' ) ? mb_strlen( $b, 'UTF-8' ) : strlen( $b );
            return $lb <=> $la;
        } );

        $ambiguous = array( 'sand', 'tan', 'khaki', 'cream', 'taupe', 'slate', 'charcoal', 'pewter', 'gunmetal', 'sage', 'moss', 'mint' );
        $strong_phrases = array( 'neon ', 'fluo ', 'flo ', 'fluorescent ', 'midnight ', 'navy blue', 'dark ', 'light ', 'royal ', 'steel ', 'matte ', 'matt ', 'off white', 'rose gold', 'champagne gold' );

        $matches = array();
        foreach ( $aliases as $alias => $color ) {
            $pattern = '/(?<![\p{L}\p{N}])' . preg_quote( $alias, '/' ) . '(?![\p{L}\p{N}])/iu';
            if ( preg_match_all( $pattern, $text, $m, PREG_OFFSET_CAPTURE ) ) {
                foreach ( $m[0] as $hit ) {
                    $score = 100;
                    if ( in_array( $alias, $ambiguous, true ) ) {
                        $score = 55;
                    }
                    foreach ( $strong_phrases as $prefix ) {
                        if ( false !== strpos( $alias, $prefix ) ) {
                            $score = 140;
                            break;
                        }
                    }
                    if ( false !== strpos( $alias, ' ' ) ) {
                        $score += 15;
                    }
                    $matches[] = array(
                        'alias'  => $alias,
                        'color'  => $color,
                        'offset' => (int) $hit[1],
                        'length' => strlen( $hit[0] ),
                        'score'  => $score,
                    );
                }
            }
        }

        // Najpierw odrzucamy nakładające się krótsze aliasy (NEON YELLOW > YELLOW).
        usort( $matches, static function( $a, $b ) {
            if ( $a['offset'] === $b['offset'] ) {
                if ( $a['length'] === $b['length'] ) {
                    return $b['score'] <=> $a['score'];
                }
                return $b['length'] <=> $a['length'];
            }
            return $a['offset'] <=> $b['offset'];
        } );

        $accepted = array();
        foreach ( $matches as $match ) {
            $start = $match['offset'];
            $end   = $start + $match['length'];
            $overlap = false;
            foreach ( $accepted as $used ) {
                $used_start = $used['offset'];
                $used_end   = $used_start + $used['length'];
                if ( $start < $used_end && $end > $used_start ) {
                    $overlap = true;
                    break;
                }
            }
            if ( ! $overlap ) {
                $accepted[] = $match;
            }
        }

        if ( ! $explicit ) {
            usort( $accepted, static function( $a, $b ) {
                if ( $a['score'] === $b['score'] ) {
                    return $a['offset'] <=> $b['offset'];
                }
                return $b['score'] <=> $a['score'];
            } );
        } else {
            usort( $accepted, static function( $a, $b ) {
                return $a['offset'] <=> $b['offset'];
            } );
        }

        $colors = array();
        $used_matches = array();
        foreach ( $accepted as $match ) {
            if ( ! in_array( $match['color'], $colors, true ) ) {
                $colors[] = $match['color'];
                $used_matches[] = $match;
            }
            if ( count( $colors ) >= 3 ) {
                break;
            }
        }

        return array( 'colors' => $colors, 'matches' => $used_matches );
    }

    private static function colors_compatible( $a, $b ) {
        if ( $a === $b ) {
            return true;
        }
        $compatible = array(
            'Srebrny'   => array( 'Szary', 'Biały' ),
            'Szary'     => array( 'Srebrny', 'Czarny' ),
            'Złoty'     => array( 'Żółty', 'Brązowy', 'Beżowy' ),
            'Granatowy' => array( 'Niebieski', 'Czarny' ),
            'Bordowy'   => array( 'Czerwony', 'Brązowy' ),
            'Beżowy'    => array( 'Brązowy', 'Biały' ),
            'Oliwkowy'  => array( 'Zielony', 'Brązowy' ),
            'Turkusowy' => array( 'Niebieski', 'Zielony' ),
        );
        return in_array( $b, $compatible[ $a ] ?? array(), true ) || in_array( $a, $compatible[ $b ] ?? array(), true );
    }

    /**
     * Hierarchia sygnałów:
     * 1. jawny kolor w nazwie/oznaczeniu producenta — źródło nadrzędne,
     * 2. zdjęcie — walidacja i fallback, nigdy nie dopisuje dodatkowego koloru do jawnej nazwy,
     * 3. tylko zdjęcie — ostrożny wynik z progiem pewności.
     */
    private static function combine_signals( $image_colors, $image_confidence, $text_signal ) {
        $image_colors = array_values( array_unique( array_filter( $image_colors ) ) );
        $text_colors  = array_values( array_unique( array_filter( $text_signal['colors'] ?? array() ) ) );
        $signal_type  = (string) ( $text_signal['signal_type'] ?? 'none' );

        if ( $text_colors ) {
            $agreement_count = 0;
            foreach ( $text_colors as $text_color ) {
                foreach ( $image_colors as $image_color ) {
                    if ( self::colors_compatible( $text_color, $image_color ) ) {
                        $agreement_count++;
                        break;
                    }
                }
            }

            $all_confirmed = $image_colors && $agreement_count >= count( $text_colors );

            // Jawne "KOLOR/COLOR" jest traktowane jak dane producenta, a nie luźna fraza SEO.
            // Obraz może potwierdzić wynik, ale nie może zastąpić ŻÓŁTY/CZARNY np. słowem SAND.
            if ( 'explicit' === $signal_type ) {
                return array(
                    'colors'     => $text_colors,
                    'confidence' => $all_confirmed ? 0.995 : 0.985,
                    'source'     => $all_confirmed ? 'explicit+image' : 'explicit',
                    'conflict'   => (bool) $image_colors && ! $all_confirmed,
                );
            }

            if ( 'variant' === $signal_type ) {
                return array(
                    'colors'     => $text_colors,
                    'confidence' => $all_confirmed ? 0.99 : 0.97,
                    'source'     => $all_confirmed ? 'variant+image' : 'variant',
                    'conflict'   => (bool) $image_colors && ! $all_confirmed,
                );
            }

            if ( ! $image_colors ) {
                return array(
                    'colors'     => $text_colors,
                    'confidence' => count( $text_colors ) === 1 ? 0.96 : 0.94,
                    'source'     => 'title',
                    'conflict'   => false,
                );
            }

            return array(
                'colors'     => $text_colors,
                'confidence' => $all_confirmed ? 0.985 : ( count( $text_colors ) === 1 ? 0.94 : 0.91 ),
                'source'     => $all_confirmed ? 'title+image' : 'title',
                'conflict'   => ! $all_confirmed,
            );
        }

        return array(
            'colors'     => $image_colors,
            'confidence' => $image_confidence,
            'source'     => 'image',
            'conflict'   => false,
        );
    }

    private static function set_missing_taxonomy_colors( $product_id, $colors ) {
        if ( ! taxonomy_exists( self::TAXONOMY ) ) {
            return new WP_Error( 'foxsc_no_taxonomy', 'Taksonomia ' . self::TAXONOMY . ' nie istnieje.' );
        }

        $existing = wp_get_object_terms( $product_id, self::TAXONOMY, array( 'fields' => 'ids' ) );
        if ( is_wp_error( $existing ) ) {
            return $existing;
        }

        // Kolor ręczny jest nietykalny. Kolor zapisany wcześniej przez FOX Smart Color
        // może być natomiast poprawiony po zmianie algorytmu lub ponownej analizie.
        if ( $existing ) {
            $existing_product = wc_get_product( $product_id );
            if ( ! $existing_product || self::has_manual_color( $existing_product ) ) {
                return false;
            }
        }

        $term_ids = array();
        foreach ( $colors as $color ) {
            $term = term_exists( $color, self::TAXONOMY );
            if ( ! $term ) {
                $term = wp_insert_term( $color, self::TAXONOMY );
            }
            if ( is_wp_error( $term ) ) {
                return $term;
            }
            $term_ids[] = (int) ( is_array( $term ) ? $term['term_id'] : $term );
        }

        $set = wp_set_object_terms( $product_id, $term_ids, self::TAXONOMY, false );
        if ( is_wp_error( $set ) ) {
            return $set;
        }

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return new WP_Error( 'foxsc_product_reload', 'Nie udało się ponownie wczytać produktu.' );
        }
        $attribute_id = function_exists( 'wc_attribute_taxonomy_id_by_name' ) ? wc_attribute_taxonomy_id_by_name( self::TAXONOMY ) : 0;
        if ( ! $attribute_id || ! class_exists( 'WC_Product_Attribute' ) ) {
            return new WP_Error( 'foxsc_attribute_id', 'Nie udało się odczytać definicji globalnego atrybutu koloru.' );
        }

        $attributes = $product->get_attributes();
        if ( isset( $attributes[ self::TAXONOMY ] ) && is_a( $attributes[ self::TAXONOMY ], 'WC_Product_Attribute' ) ) {
            $attribute = $attributes[ self::TAXONOMY ];
        } else {
            $attribute = new WC_Product_Attribute();
            $attribute->set_id( $attribute_id );
            $attribute->set_name( self::TAXONOMY );
            $attribute->set_position( count( $attributes ) );
        }
        $attribute->set_options( $term_ids );
        $attribute->set_visible( true );
        $attribute->set_variation( false );
        $attributes[ self::TAXONOMY ] = $attribute;
        $product->set_attributes( $attributes );
        self::$internal_save = true;
        try {
            $product->save();
        } finally {
            self::$internal_save = false;
        }
        delete_post_meta( $product_id, '_foxsc_taxonomy_autoset' ); // 2.0 nie potrzebuje osobnego trackera terminów.

        return true;
    }

    private static function cleanup_legacy_meta_global() {
        global $wpdb;
        $pm = $wpdb->postmeta;

        // Najpierw migrujemy wartości, które nadal są użyteczne.
        $migrations = array(
            '_foxsc_color_confidence' => '_foxsc_confidence',
            '_foxsc_color_source'     => '_foxsc_source',
            '_foxak_gmc_color'        => '_foxsc_gmc_color',
            '_foxak_color_confidence' => '_foxsc_confidence',
            '_foxak_color_source'     => '_foxsc_source',
            '_foxak_processed_at'     => '_foxsc_processed_at',
        );
        foreach ( $migrations as $old_key => $new_key ) {
            $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO {$pm} (post_id,meta_key,meta_value)
                     SELECT old.post_id,%s,old.meta_value
                     FROM {$pm} old
                     LEFT JOIN {$pm} newer ON newer.post_id=old.post_id AND newer.meta_key=%s
                     WHERE old.meta_key=%s AND newer.meta_id IS NULL",
                    $new_key,
                    $new_key,
                    $old_key
                )
            );
        }

        $legacy = array(
            '_foxsc_taxonomy_autoset', '_foxsc_color_confidence', '_foxsc_color_source',
            '_foxsc_color_candidate', '_foxsc_candidate_confidence', '_foxsc_candidate_source',
            '_foxsc_detected_colors_json', '_foxsc_image_hash', '_foxsc_algorithm_version',
            '_foxak_gmc_color', '_foxak_color_confidence', '_foxak_color_source', '_foxak_color_candidate',
            '_foxak_candidate_confidence', '_foxak_candidate_source', '_foxak_detected_colors_json', '_foxak_processed_at'
        );
        $placeholders = implode( ',', array_fill( 0, count( $legacy ), '%s' ) );
        $sql = $wpdb->prepare( "DELETE FROM {$pm} WHERE meta_key IN ({$placeholders})", $legacy );
        return (int) $wpdb->query( $sql );
    }

    private static function cleanup_product_legacy_meta( $product_id ) {
        // Jedno zapytanie zamiast 17 osobnych usunięć przy każdym zapisie wyniku.
        global $wpdb;
        $keys = array(
            '_foxsc_taxonomy_autoset',
            '_foxsc_color_confidence', '_foxsc_color_source',
            '_foxsc_color_candidate', '_foxsc_candidate_confidence', '_foxsc_candidate_source',
            '_foxsc_detected_colors_json', '_foxsc_image_hash', '_foxsc_algorithm_version',
            '_foxak_gmc_color', '_foxak_color_confidence', '_foxak_color_source', '_foxak_color_candidate',
            '_foxak_candidate_confidence', '_foxak_candidate_source', '_foxak_detected_colors_json', '_foxak_processed_at',
        );
        $ph  = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
        $del = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key IN ({$ph})", array_merge( array( (int) $product_id ), $keys ) ) ); // phpcs:ignore
        if ( $del ) {
            wp_cache_delete( (int) $product_id, 'post_meta' );
        }
    }

    private static function publish_result( $product_id, $color, $confidence, $source ) {
        self::cleanup_product_legacy_meta( $product_id );
        update_post_meta( $product_id, '_foxsc_gmc_color', sanitize_text_field( $color ) );
        update_post_meta( $product_id, '_foxsc_confidence', round( (float) $confidence, 4 ) );
        update_post_meta( $product_id, '_foxsc_source', sanitize_key( $source ) );
        update_post_meta( $product_id, '_foxsc_processed_at', current_time( 'mysql' ) );
    }

    private static function save_meta_result( $product_id, $color, $confidence, $source, $details = array() ) {
        self::publish_result( $product_id, $color, $confidence, $source );
    }

    private static function published_color_value( $product ) {
        if ( ! is_a( $product, 'WC_Product' ) ) {
            return '';
        }

        // Pole GMC zachowuje kolejność znaczeniową, np. Żółty/Czarny.
        $gmc = (string) get_post_meta( $product->get_id(), '_foxsc_gmc_color', true );
        if ( $gmc ) {
            return $gmc;
        }

        $colors = self::existing_color_names( $product );
        return $colors ? implode( '/', array_slice( $colors, 0, 3 ) ) : '';
    }

    public static function display_color_on_product() {
        $settings = self::settings();
        if ( empty( $settings['display_on_product'] ) ) {
            return;
        }
        global $product;
        if ( ! is_a( $product, 'WC_Product' ) ) {
            return;
        }
        $color = self::published_color_value( $product );
        if ( ! $color ) {
            return;
        }
        echo '<span class="foxsc-product-color" style="display:block"><span class="foxsc-label">' . esc_html( wc_attribute_label( self::TAXONOMY ) ) . ':</span> ' . esc_html( $color ) . '</span>';
    }

    public static function add_color_to_schema( $markup, $product ) {
        if ( ! is_a( $product, 'WC_Product' ) ) {
            return $markup;
        }
        $color = self::published_color_value( $product );
        if ( $color && empty( $markup['color'] ) ) {
            $markup['color'] = $color;
        }
        return $markup;
    }

    private static function log( $product_id, $sku, $name, $detected, $final, $confidence, $source, $status, $message, $details ) {
        global $wpdb;
        $wpdb->insert(
            self::logs_table(),
            array(
                'processed_at'    => current_time( 'mysql' ),
                'product_id'      => $product_id ?: null,
                'sku'             => (string) $sku,
                'product_name'    => (string) $name,
                'detected_colors' => implode( '/', array_slice( (array) $detected, 0, 3 ) ),
                'final_color'     => (string) $final,
                'confidence'      => null === $confidence ? null : round( (float) $confidence, 4 ),
                'source'          => (string) $source,
                'status'          => (string) $status,
                'message'         => (string) $message,
                'details'         => wp_json_encode( $details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
            ),
            array( '%s', '%d', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s' )
        );

        if ( false === get_transient( 'foxsc_log_cleanup' ) ) {
            set_transient( 'foxsc_log_cleanup', 1, 12 * HOUR_IN_SECONDS );
            $wpdb->query( 'DELETE FROM ' . self::logs_table() . ' WHERE processed_at < (NOW() - INTERVAL 30 DAY)' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
    }
}

register_activation_hook( __FILE__, array( 'FOX_Smart_Color_GMC', 'activate' ) );
FOX_Smart_Color_GMC::init();
