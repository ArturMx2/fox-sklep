<?php
/**
 * Termin wysyłki – właściwy kod (ładowany z delivery.php tylko wtedy, gdy nie działa już fragment/wtyczka z tym samym kodem).
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

define('FOX_BL_DELIVERY_VERSION', '1.2.1');
define('FOX_BL_DELIVERY_OPTION', 'fox_bl_delivery_settings_v1');
define('FOX_BL_DELIVERY_SETTINGS_GROUP', 'fox_bl_delivery_settings_group_v1');
define('FOX_BL_DELIVERY_TIMEZONE', 'Europe/Warsaw');


// Przy pierwszej aktywacji spróbuj przenieść ustawienia ze starego snippetu 26.2.
add_action('init', function () { if (get_option(FOX_BL_DELIVERY_OPTION, null) === null) { fox_bl_delivery_activate(); } });

function fox_bl_delivery_activate() {
    $existing = get_option(FOX_BL_DELIVERY_OPTION, null);

    if (is_array($existing) && !empty($existing)) {
        return;
    }

    $settings = fox_bl_delivery_defaults();
    $legacy = get_option('bl_cart_js_final_settings_v26', []);

    if (is_array($legacy) && !empty($legacy)) {
        $settings['api_token'] = sanitize_text_field($legacy['api_token'] ?? '');
        $settings['inventory_id'] = sanitize_text_field($legacy['inventory_id'] ?? '');

        $legacy_hour = isset($legacy['cutoff_hour']) ? (int) $legacy['cutoff_hour'] : 15;
        $legacy_hour = max(0, min(23, $legacy_hour));
        $settings['cutoff_time'] = sprintf('%02d:00', $legacy_hour);

        $settings['shipping_delay'] = max(0, min(30, (int) ($legacy['shipping_delay'] ?? 0)));
    }

    add_option(FOX_BL_DELIVERY_OPTION, $settings);
}

// -----------------------------------------------------------------------------
// 1. USTAWIENIA
// -----------------------------------------------------------------------------

function fox_bl_delivery_defaults() {
    return [
        'api_token'        => '',
        'inventory_id'     => '',
        'cutoff_time'      => '15:00',
        'shipping_delay'   => 0,
        'fallback_days'    => -1,
        'cache_minutes'    => 5,
        'warehouse_map'    => "bl_33043=0\nwarehouse_4011644=0\nbl_36406=0\nbl_43318=1\nbl_47903=7",
        'blocked_dates'    => '',
        'show_note'        => 1,
        'show_product_cta' => 1,
        'days_local'       => 0, // towar w sklepie stacjonarnym (lista z synchronizacji Base)
        'days_other'       => 1, // pozostałe produkty
    ];
}

function fox_bl_delivery_get_settings() {
    $saved = get_option(FOX_BL_DELIVERY_OPTION, []);
    return wp_parse_args(is_array($saved) ? $saved : [], fox_bl_delivery_defaults());
}

add_action('admin_menu', function () {
    add_options_page(
        'Termin wysyłki FOX',
        'Termin wysyłki FOX',
        'manage_options',
        'fox-bl-delivery-settings',
        'fox_bl_delivery_settings_page'
    );
});

add_action('admin_init', function () {
    register_setting(
        FOX_BL_DELIVERY_SETTINGS_GROUP,
        FOX_BL_DELIVERY_OPTION,
        [
            'type'              => 'array',
            'sanitize_callback' => 'fox_bl_delivery_sanitize_settings',
            'default'           => fox_bl_delivery_defaults(),
        ]
    );
});

function fox_bl_delivery_sanitize_settings($input) {
    $defaults = fox_bl_delivery_defaults();
    $input = is_array($input) ? $input : [];

    $cutoff = sanitize_text_field($input['cutoff_time'] ?? $defaults['cutoff_time']);
    if (!preg_match('/^(?:[01]?\d|2[0-3]):[0-5]\d$/', $cutoff)) {
        $cutoff = $defaults['cutoff_time'];
    }

    $fallback_days = isset($input['fallback_days']) ? (int) $input['fallback_days'] : $defaults['fallback_days'];
    $fallback_days = max(-1, min(30, $fallback_days));

    return [
        'api_token'       => sanitize_text_field($input['api_token'] ?? ''),
        'inventory_id'    => sanitize_text_field($input['inventory_id'] ?? ''),
        'cutoff_time'     => $cutoff,
        'shipping_delay'  => max(0, min(30, (int) ($input['shipping_delay'] ?? 0))),
        'fallback_days'   => $fallback_days,
        'cache_minutes'   => max(1, min(60, (int) ($input['cache_minutes'] ?? 5))),
        'warehouse_map'   => sanitize_textarea_field($input['warehouse_map'] ?? $defaults['warehouse_map']),
        'blocked_dates'   => sanitize_textarea_field($input['blocked_dates'] ?? ''),
        'show_note'       => !empty($input['show_note']) ? 1 : 0,
        'show_product_cta' => !empty($input['show_product_cta']) ? 1 : 0,
        'days_local'      => max(0, min(30, (int) ($input['days_local'] ?? $defaults['days_local']))),
        'days_other'      => max(0, min(30, (int) ($input['days_other'] ?? $defaults['days_other']))),
    ];
}

function fox_bl_delivery_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $settings = fox_bl_delivery_get_settings();
    ?>
    <div class="wrap">
        <h1>Termin wysyłki i dostawy – FOX.SKLEP.PL</h1>
        <p>Termin całego koszyka liczony z listy towaru w sklepie stacjonarnym, którą sklep pobiera z BaseLinkera kilka razy dziennie (WooCommerce → <a href="<?php echo esc_url(admin_url('admin.php?page=fx-local-stock')); ?>">Wysyłka dziś / sklep</a>). Klient nie czeka na BaseLinkera – nic nie jest odpytywane przy przeglądaniu ani w koszyku.</p>

        <form method="post" action="options.php">
            <?php settings_fields(FOX_BL_DELIVERY_SETTINGS_GROUP); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="fox_api_token">Token API BaseLinker</label></th>
                    <td>
                        <input type="password" id="fox_api_token"
                               name="<?php echo esc_attr(FOX_BL_DELIVERY_OPTION); ?>[api_token]"
                               value="<?php echo esc_attr($settings['api_token']); ?>"
                               class="regular-text" autocomplete="off" required>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="fox_inventory_id">ID katalogu BaseLinker</label></th>
                    <td>
                        <input type="number" id="fox_inventory_id"
                               name="<?php echo esc_attr(FOX_BL_DELIVERY_OPTION); ?>[inventory_id]"
                               value="<?php echo esc_attr($settings['inventory_id']); ?>"
                               min="1" required>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="fox_cutoff_time">Godzina graniczna</label></th>
                    <td>
                        <input type="time" id="fox_cutoff_time"
                               name="<?php echo esc_attr(FOX_BL_DELIVERY_OPTION); ?>[cutoff_time]"
                               value="<?php echo esc_attr($settings['cutoff_time']); ?>" required>
                        <p class="description">Dla FOX ustaw 15:00.</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="fox_shipping_delay">Dodatkowe opóźnienie</label></th>
                    <td>
                        <input type="number" id="fox_shipping_delay"
                               name="<?php echo esc_attr(FOX_BL_DELIVERY_OPTION); ?>[shipping_delay]"
                               value="<?php echo esc_attr($settings['shipping_delay']); ?>"
                               min="0" max="30"> dni roboczych
                        <p class="description">Tymczasowy bufor, np. podczas inwentaryzacji lub okresu świątecznego.</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="fox_days_local">Towar w sklepie stacjonarnym</label></th>
                    <td>
                        wysyłka po <input type="number" id="fox_days_local"
                               name="<?php echo esc_attr(FOX_BL_DELIVERY_OPTION); ?>[days_local]"
                               value="<?php echo esc_attr($settings['days_local']); ?>"
                               min="0" max="30" style="width:70px"> dniach roboczych
                        <p class="description">0 = tego samego dnia (zamówienie przed godziną graniczną). Magazyn sklepu ustawiasz w „Wysyłka dziś / sklep” (teraz: <code><?php echo esc_html(function_exists('fx_ls_settings') ? fx_ls_settings()['store_wh'] : ''); ?></code>).</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="fox_days_other">Pozostałe produkty</label></th>
                    <td>
                        wysyłka po <input type="number" id="fox_days_other"
                               name="<?php echo esc_attr(FOX_BL_DELIVERY_OPTION); ?>[days_other]"
                               value="<?php echo esc_attr($settings['days_other']); ?>"
                               min="0" max="30" style="width:70px"> dniach roboczych
                        <p class="description">1 = następnego dnia roboczego (zamówienie przed godziną graniczną). Koszyk z towarem z obu miejsc = termin dłuższego.</p>
                    </td>
                </tr>

                <input type="hidden" name="<?php echo esc_attr(FOX_BL_DELIVERY_OPTION); ?>[fallback_days]" value="<?php echo esc_attr($settings['fallback_days']); ?>">
                <input type="hidden" name="<?php echo esc_attr(FOX_BL_DELIVERY_OPTION); ?>[cache_minutes]" value="<?php echo esc_attr($settings['cache_minutes']); ?>">
                <input type="hidden" name="<?php echo esc_attr(FOX_BL_DELIVERY_OPTION); ?>[warehouse_map]" value="<?php echo esc_attr($settings['warehouse_map']); ?>">

                <tr>
                    <th scope="row"><label for="fox_blocked_dates">Dni bez wysyłki</label></th>
                    <td>
                        <textarea id="fox_blocked_dates"
                                  name="<?php echo esc_attr(FOX_BL_DELIVERY_OPTION); ?>[blocked_dates]"
                                  rows="5" cols="55" class="large-text code"><?php echo esc_textarea($settings['blocked_dates']); ?></textarea>
                        <p class="description">Opcjonalnie: daty w formacie RRRR-MM-DD, oddzielone przecinkiem lub nową linią.</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">Informacja dodatkowa</th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="<?php echo esc_attr(FOX_BL_DELIVERY_OPTION); ?>[show_note]"
                                   value="1" <?php checked(!empty($settings['show_note'])); ?>>
                            Pokaż dopisek, że termin jest szacunkowy i zależy od przewoźnika.
                        </label>
                    </td>
                </tr>

                <tr>
                    <th scope="row">Karta produktu</th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="<?php echo esc_attr(FOX_BL_DELIVERY_OPTION); ?>[show_product_cta]"
                                   value="1" <?php checked(!empty($settings['show_product_cta'])); ?>>
                            Pokaż nad przyciskiem „Dodaj do koszyka” termin wysyłki i dostawy.
                        </label>
                        <p class="description">Konkretny dzień wysyłki dla wybranego rozmiaru (z listy towaru w sklepie), z licznikiem do godziny granicznej.</p>
                    </td>
                </tr>
            </table>

            <p class="description">Polskie święta ustawowe (także Wielkanoc, Boże Ciało i Wigilia) motyw dolicza do dni bez wysyłki automatycznie.</p>
            <?php submit_button('Zapisz ustawienia'); ?>
        </form>
    </div>
    <?php
}

// -----------------------------------------------------------------------------
// 2. PARSOWANIE USTAWIEŃ
// -----------------------------------------------------------------------------

function fox_bl_delivery_parse_warehouses($raw) {
    $result = [];
    $lines = preg_split('/[\r\n,;]+/', (string) $raw);

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        if (preg_match('/^([^=:\s]+)\s*[=:]\s*(\d+)$/', $line, $matches)) {
            $result[sanitize_key($matches[1])] = max(0, (int) $matches[2]);
        }
    }

    asort($result, SORT_NUMERIC);
    return $result;
}

function fox_bl_delivery_parse_blocked_dates($raw) {
    $dates = [];
    $parts = preg_split('/[\s,;]+/', (string) $raw);

    foreach ($parts as $part) {
        $part = trim($part);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $part)) {
            $dates[$part] = true;
        }
    }

    return $dates;
}


/** Dni bez wysyłki: z ustawień + polskie święta (bieżący i następny rok). */
function fox_bl_delivery_blocked($settings) {
    $dates = fox_bl_delivery_parse_blocked_dates($settings['blocked_dates'] ?? '');
    if (function_exists('fx_pl_holidays')) {
        $y = (int) wp_date('Y');
        foreach (array_merge(fx_pl_holidays($y), fx_pl_holidays($y + 1)) as $d) {
            $dates[$d] = true;
        }
    }
    return $dates;
}

// -----------------------------------------------------------------------------
// 3. BASELINKER API
// -----------------------------------------------------------------------------

function fox_bl_delivery_api_call($method, array $parameters, $api_token) {
    $response = wp_remote_post(
        'https://api.baselinker.com/connector.php',
        [
            'timeout' => 15,
            'headers' => [
                'X-BLToken' => $api_token,
            ],
            'body' => [
                'method'     => $method,
                'parameters' => wp_json_encode($parameters),
            ],
        ]
    );

    if (is_wp_error($response)) {
        return $response;
    }

    if ((int) wp_remote_retrieve_response_code($response) !== 200) {
        return new WP_Error('fox_bl_http_error', 'BaseLinker zwrócił nieprawidłowy kod HTTP.');
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);

    if (!is_array($data)) {
        return new WP_Error('fox_bl_invalid_json', 'BaseLinker zwrócił nieprawidłową odpowiedź.');
    }

    if (($data['status'] ?? '') !== 'SUCCESS') {
        return new WP_Error(
            'fox_bl_api_error',
            sanitize_text_field($data['error_message'] ?? 'Nieznany błąd BaseLinkera.')
        );
    }

    return $data;
}

// -----------------------------------------------------------------------------
// 4. OBLICZANIE CZASU DLA KOSZYKA (z listy towaru w sklepie – bez zapytań do BaseLinkera)
// -----------------------------------------------------------------------------

function fox_bl_delivery_is_business_day(DateTimeImmutable $date, array $blocked_dates) {
    $weekday = (int) $date->format('N');

    if ($weekday >= 6) {
        return false;
    }

    return empty($blocked_dates[$date->format('Y-m-d')]);
}

function fox_bl_delivery_next_business_day(DateTimeImmutable $date, array $blocked_dates) {
    $candidate = $date;

    do {
        $candidate = $candidate->modify('+1 day');
    } while (!fox_bl_delivery_is_business_day($candidate, $blocked_dates));

    return $candidate;
}

function fox_bl_delivery_add_business_days(DateTimeImmutable $date, $days, array $blocked_dates) {
    $days = max(0, (int) $days);
    $result = $date;

    while ($days > 0) {
        $result = $result->modify('+1 day');

        if (fox_bl_delivery_is_business_day($result, $blocked_dates)) {
            $days--;
        }
    }

    return $result;
}

function fox_bl_delivery_get_cart_lines() {
    $lines = [];

    if (!function_exists('WC') || !WC()->cart) {
        return $lines;
    }

    foreach (WC()->cart->get_cart() as $cart_item) {
        $product = $cart_item['data'] ?? null;

        if (!$product instanceof WC_Product) {
            continue;
        }

        // Bez wysyłki (bon na e-mail, produkt wirtualny) – nie wpływa na termin.
        if (!$product->needs_shipping() || (function_exists('fx_ship_is_voucher') && fx_ship_is_voucher((int) ($cart_item['product_id'] ?? 0)))) {
            continue;
        }

        $quantity = max(1, (int) ($cart_item['quantity'] ?? 1));
        $variant_sku = '';
        $main_sku = (string) $product->get_sku();

        if ($product->is_type('variation')) {
            $variant_sku = (string) $product->get_sku();
            $parent = wc_get_product($product->get_parent_id());
            $parent_sku = $parent instanceof WC_Product ? (string) $parent->get_sku() : '';
            $main_sku = $parent_sku !== '' ? $parent_sku : $variant_sku;
        }

        $line_key = 'p' . (int) $product->get_id(); // po ID – produkty bez SKU się nie zlewają

        if (!isset($lines[$line_key])) {
            $lines[$line_key] = [
                'main_sku'    => $main_sku,
                'variant_sku' => $variant_sku,
                'quantity'    => 0,
            ];
        }

        $lines[$line_key]['quantity'] += $quantity;
        $lines[$line_key]['id'] = (int) $product->get_id();
    }

    return array_values($lines);
}

function fox_bl_delivery_get_cart_estimate() {
    static $request_cache = [];

    if (!function_exists('WC') || !WC()->cart || WC()->cart->is_empty()) {
        return null;
    }

    $settings = fox_bl_delivery_get_settings();
    $cart_hash = method_exists(WC()->cart, 'get_cart_hash') ? WC()->cart->get_cart_hash() : md5(wp_json_encode(WC()->cart->get_cart()));
    $settings_hash = md5(wp_json_encode($settings));
    $cache_key = $cart_hash . '|' . $settings_hash;

    if (isset($request_cache[$cache_key])) {
        return $request_cache[$cache_key];
    }

    $blocked_dates = fox_bl_delivery_blocked($settings);

    // Bez zapytań do BaseLinkera: towar w sklepie stacjonarnym (lista z synchronizacji) = days_local,
    // reszta = days_other. Ilość ma znaczenie: 3 szt. w koszyku, 1 w sklepie → termin „pozostałe”.
    $local = function_exists('fx_ls_data') ? (array) (fx_ls_data()['store'] ?? []) : [];
    $have_sync = function_exists('fx_ls_data') && !empty(fx_ls_data()['at']);
    $worst_days = 0;
    $used_fallback = !$have_sync || !empty(fx_ls_data()['stale']);

    $lines = fox_bl_delivery_get_cart_lines();
    if (!$lines) {
        $request_cache[$cache_key] = null; // same bony / produkty bez wysyłki – bez terminu
        return null;
    }

    $fixed = []; // pozycje z własną datą wysyłki (np. przedsprzedaż – wtyczka przez filtr niżej)
    foreach ($lines as $line) {
        /**
         * Własna data wysyłki pozycji (Y-m-d) albo null = zasady sklepu.
         * Używa jej np. wtyczka „FOX – Przedsprzedaż Powerbike”.
         */
        $own = apply_filters('fox_bl_delivery_line_dispatch', null, (int) ($line['id'] ?? 0), (int) $line['quantity']);
        if (is_string($own) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $own)) {
            $fixed[] = $own;
            continue;
        }
        $in_store = (int) ($local[(int) ($line['id'] ?? 0)] ?? 0);
        $item_days = $in_store >= (int) $line['quantity'] ? (int) $settings['days_local'] : (int) $settings['days_other'];
        $worst_days = max($worst_days, $item_days);
    }

    $timezone = new DateTimeZone(FOX_BL_DELIVERY_TIMEZONE);
    $now = new DateTimeImmutable('now', $timezone);
    $today = $now->setTime(12, 0, 0);

    [$cutoff_hour, $cutoff_minute] = array_map('intval', explode(':', $settings['cutoff_time']));
    $cutoff_today = $now->setTime($cutoff_hour, $cutoff_minute, 0);

    $can_ship_today = fox_bl_delivery_is_business_day($today, $blocked_dates) && $now < $cutoff_today;

    if ($can_ship_today) {
        $processing_base = $today;
    } else {
        $processing_base = fox_bl_delivery_next_business_day($today, $blocked_dates);
    }

    $dispatch_date = fox_bl_delivery_add_business_days(
        $processing_base,
        $worst_days + max(0, (int) $settings['shipping_delay']),
        $blocked_dates
    );

    $preorder = false;
    if ($fixed) {
        $latest = new DateTimeImmutable(max($fixed) . ' 12:00:00', new DateTimeZone(FOX_BL_DELIVERY_TIMEZONE));
        if ($latest > $dispatch_date) {
            $dispatch_date = $latest;
            $preorder = true;
            $can_ship_today = false; // licznik do 15:00 nic tu nie zmienia
        }
    }

    $delivery_date = fox_bl_delivery_add_business_days($dispatch_date, 1, $blocked_dates);

    $request_cache[$cache_key] = [
        'preorder'         => $preorder,
        'status'           => 'ready',
        'dispatch_days'    => $worst_days,
        'dispatch_date'    => $dispatch_date,
        'delivery_date'    => $delivery_date,
        'ships_today'      => $dispatch_date->format('Y-m-d') === $today->format('Y-m-d'),
        'cutoff_timestamp' => $can_ship_today ? $cutoff_today->getTimestamp() : 0,
        'used_fallback'    => $used_fallback,
    ];

    return $request_cache[$cache_key];
}

// -----------------------------------------------------------------------------
// 5. WYŚWIETLANIE ALERTU
// -----------------------------------------------------------------------------

function fox_bl_delivery_polish_date(DateTimeImmutable $date) {
    $days = [
        1 => 'poniedziałek',
        2 => 'wtorek',
        3 => 'środa',
        4 => 'czwartek',
        5 => 'piątek',
        6 => 'sobota',
        7 => 'niedziela',
    ];

    return $days[(int) $date->format('N')] . ', ' . $date->format('d.m.Y');
}

function fox_bl_delivery_icon_svg($type = 'delivery') {
    if ($type === 'clock') {
        return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" aria-hidden="true" focusable="false">'
            . '<circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="1.8"/>'
            . '<path d="M12 7.5V12l3 1.8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>'
            . '</svg>';
    }

    return '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" aria-hidden="true" focusable="false">'
        . '<path d="M3.5 6.5h10v9h-10z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/>'
        . '<path d="M13.5 9h3.4l3.1 3.2v3.3h-6.5z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/>'
        . '<circle cx="7" cy="17.3" r="1.7" stroke="currentColor" stroke-width="1.7"/>'
        . '<circle cx="17" cy="17.3" r="1.7" stroke="currentColor" stroke-width="1.7"/>'
        . '<path d="M5.3 17.3H3.5v-1.8M13.5 17.3H8.7M20 15.5v1.8h-1.3" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>'
        . '</svg>';
}

function fox_bl_delivery_render_alert($compact = false) {
    $estimate = fox_bl_delivery_get_cart_estimate();
    $settings = fox_bl_delivery_get_settings();

    if (!$estimate) {
        return '';
    }

    if (($estimate['status'] ?? '') !== 'ready') {
        return '<div class="fox-delivery-alert fox-delivery-alert--unknown">'
            . '<div class="fox-delivery-alert__icon" aria-hidden="true">' . fox_bl_delivery_icon_svg('clock') . '</div>'
            . '<div class="fox-delivery-alert__content">'
            . '<div class="fox-delivery-alert__headline">Termin wysyłki wymaga potwierdzenia</div>'
            . '<div class="fox-delivery-alert__delivery">Jeżeli realizacja potrwa dłużej niż standardowo, skontaktujemy się z Tobą.</div>'
            . '</div></div>';
    }

    $dispatch_date = $estimate['dispatch_date'];
    $delivery_date = $estimate['delivery_date'];
    $ships_today = !empty($estimate['ships_today']);

    if (!empty($estimate['preorder'])) {
        $headline = 'Przedsprzedaż – planowana wysyłka: ' . fox_bl_delivery_polish_date($dispatch_date);
        $class = 'fox-delivery-alert--later';
    } elseif ($ships_today) {
        $headline = 'Wysyłka jeszcze dzisiaj';
        $class = 'fox-delivery-alert--today';
    } else {
        $headline = 'Planowana wysyłka: ' . fox_bl_delivery_polish_date($dispatch_date);
        $class = 'fox-delivery-alert--later';
    }

    $countdown = '';
    if (!empty($estimate['cutoff_timestamp'])) { // także „wyślemy jutro” – po godzinie granicznej termin się przesuwa
        $countdown = '<span class="fox-delivery-countdown" data-cutoff="'
            . esc_attr((string) $estimate['cutoff_timestamp'])
            . '"></span>';
    }

    $note = '';
    if (!$compact && !empty($settings['show_note'])) {
        $note = '<div class="fox-delivery-alert__note">Termin uwzględnia najwolniejszy produkt w koszyku. Doręczenie zależy od przewoźnika.</div>';
    }

    return '<div class="fox-delivery-alert ' . esc_attr($class) . '">'
        . '<div class="fox-delivery-alert__icon" aria-hidden="true">' . fox_bl_delivery_icon_svg('delivery') . '</div>'
        . '<div class="fox-delivery-alert__content">'
        . '<div class="fox-delivery-alert__headline">' . esc_html($headline) . '</div>'
        . '<div class="fox-delivery-alert__delivery">Przewidywana dostawa: <strong>'
        . esc_html(fox_bl_delivery_polish_date($delivery_date))
        . '</strong>' . $countdown . '</div>'
        . $note
        . '</div></div>';
}

add_action('woocommerce_before_cart_totals', function () {
    echo fox_bl_delivery_render_alert(false); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}, 4);

add_action('woocommerce_review_order_before_cart_contents', function () {
    $html = fox_bl_delivery_render_alert(false);

    if ($html !== '') {
        echo '<tr class="fox-delivery-checkout-row"><td colspan="2">'
            . $html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            . '</td></tr>';
    }
}, 5);

add_action('woocommerce_widget_shopping_cart_before_buttons', function () {
    echo fox_bl_delivery_render_alert(true); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}, 5);

// -----------------------------------------------------------------------------
// 6. LICZNIK DO GODZINY GRANICZNEJ (style w style.css motywu)
// -----------------------------------------------------------------------------

add_action('wp_footer', function () {
    if (!function_exists('is_woocommerce')) {
        return;
    }
    ?>
    <script>
    (function ($) {
        if (!$) { return; }
        var refreshTriggered = false;
        function updateCountdowns() {
            $('.fox-delivery-countdown[data-cutoff]').each(function () {
                var $c = $(this), cutoff = parseInt($c.attr('data-cutoff'), 10) * 1000, remaining = cutoff - Date.now();
                if (!cutoff || remaining <= 0) {
                    $c.text('');
                    if (!refreshTriggered && $c.closest('.fx-added').length === 0) {
                        refreshTriggered = true;
                        if ($('form.checkout').length) { $(document.body).trigger('update_checkout'); setTimeout(function () { refreshTriggered = false; }, 5000); }
                        else if ($('.woocommerce-cart-form').length) { window.location.reload(); }
                    }
                    return;
                }
                var total = Math.max(0, Math.floor(remaining / 60000)), h = Math.floor(total / 60), m = total % 60;
                $c.text(h > 0 ? 'Zamów w ciągu ' + h + ' godz. ' + m + ' min.' : 'Zamów w ciągu ' + m + ' min.');
            });
        }
        $(function () { updateCountdowns(); setInterval(updateCountdowns, 30000); });
        $(document.body).on('updated_cart_totals updated_checkout wc_fragments_refreshed added_to_cart', function () { setTimeout(updateCountdowns, 100); });
        document.addEventListener('click', function () { setTimeout(updateCountdowns, 50); });
    })(window.jQuery);
    </script>
    <?php
}, 40);

// -----------------------------------------------------------------------------
// 8. ZAPIS TERMINU DO ZAMÓWIENIA / BASELINKERA
// -----------------------------------------------------------------------------

add_action('woocommerce_checkout_create_order', function ($order, $data) {
    if (!$order instanceof WC_Order) {
        return;
    }

    $estimate = fox_bl_delivery_get_cart_estimate();

    if (!$estimate || ($estimate['status'] ?? '') !== 'ready') {
        $order->update_meta_data('szacowana_wysylka', 'Termin wymaga potwierdzenia');
        $order->update_meta_data('szacowana_dostawa', 'Termin wymaga potwierdzenia');
        return;
    }

    $dispatch = fox_bl_delivery_polish_date($estimate['dispatch_date']);
    $delivery = fox_bl_delivery_polish_date($estimate['delivery_date']);

    $order->update_meta_data('szacowana_wysylka', $dispatch);
    $order->update_meta_data('szacowana_dostawa', $delivery);

    // Pola techniczne – ukryte w standardowym widoku pól własnych.
    $order->update_meta_data('_fox_dispatch_date', $estimate['dispatch_date']->format('Y-m-d'));
    $order->update_meta_data('_fox_delivery_date', $estimate['delivery_date']->format('Y-m-d'));

    // Deklaracja dla klienta – dokładnie to, co widział w koszyku/zamówieniu, i kiedy.
    $now = new DateTimeImmutable('now', new DateTimeZone(FOX_BL_DELIVERY_TIMEZONE));
    $headline = !empty($estimate['preorder']) ? 'Przedsprzedaż – planowana wysyłka: ' . $dispatch : (!empty($estimate['ships_today']) ? 'Wysyłka jeszcze dzisiaj' : 'Planowana wysyłka: ' . $dispatch);
    $order->update_meta_data('termin_deklaracja', $headline . ' · Przewidywana dostawa: ' . $delivery);
    $order->update_meta_data('_fox_declared_at', $now->format('Y-m-d H:i'));
    $order->update_meta_data('_fox_declared_rule', !empty($estimate['cutoff_timestamp']) ? 'przed godziną graniczną' : 'po godzinie granicznej / dzień wolny');
    if (!empty($estimate['used_fallback'])) {
        $order->update_meta_data('_fox_declared_note', 'Brak synchronizacji ze sklepem stacjonarnym – wszystko liczone jako „pozostałe”.');
    }
}, 20, 2);

/* Każda pozycja: skąd termin (towar w sklepie stacjonarnym czy pozostałe). */
add_action('woocommerce_checkout_create_order_line_item', function ($item, $cart_item_key, $values, $order) {
    $product = $values['data'] ?? null;
    if (!$product instanceof WC_Product) {
        return;
    }
    $local = function_exists('fx_ls_data') ? (int) (fx_ls_data()['store'][$product->get_id()] ?? 0) : 0;
    $item->add_meta_data('_fx_ship_src', $local >= (int) $item->get_quantity() ? 'sklep' : 'pozostale', true);
}, 20, 4);

add_action('woocommerce_admin_order_data_after_shipping_address', function ($order) {
    if (!$order instanceof WC_Order) {
        return;
    }

    $dispatch = $order->get_meta('szacowana_wysylka');
    $delivery = $order->get_meta('szacowana_dostawa');

    if (!$dispatch && !$delivery) {
        return;
    }
    echo '<div style="margin-top:12px;padding:10px 12px;background:#f6f7f7;border-left:4px solid #e59b00;">';
    echo '<strong>Termin zadeklarowany klientowi</strong><br>';
    if ($order->get_meta('termin_deklaracja')) {
        echo '<em>„' . esc_html($order->get_meta('termin_deklaracja')) . '”</em><br>';
    }
    if ($dispatch) {
        echo 'Wysyłka: <strong>' . esc_html($dispatch) . '</strong><br>';
    }
    if ($delivery) {
        echo 'Dostawa: ' . esc_html($delivery) . '<br>';
    }
    if ($order->get_meta('_fox_declared_at')) {
        echo '<small>Złożone: ' . esc_html($order->get_meta('_fox_declared_at')) . ' (' . esc_html($order->get_meta('_fox_declared_rule')) . ')</small><br>';
    }
    $rows = [];
    foreach ($order->get_items() as $item) {
        $src = $item->get_meta('_fx_ship_src');
        if ($src) {
            $rows[] = esc_html($item->get_name()) . ' ×' . (int) $item->get_quantity() . ' – ' . ('sklep' === $src ? '<strong>w sklepie stacjonarnym</strong>' : ('przedsprzedaz' === $src ? '<strong>przedsprzedaż</strong>' . ($item->get_meta('_fxpo_date') ? ' (dostawa do nas ok. ' . esc_html($item->get_meta('_fxpo_date')) . ')' : '') : 'pozostałe'));
        }
    }
    if ($rows) {
        echo '<small style="display:block;margin-top:6px">' . implode('<br>', $rows) . '</small>'; // phpcs:ignore WordPress.Security.EscapeOutput
    }
    if ($order->get_meta('_fox_declared_note')) {
        echo '<small style="display:block;margin-top:6px;color:#b32d2e">' . esc_html($order->get_meta('_fox_declared_note')) . '</small>';
    }
    echo '</div>';
});

/* Klient też ma deklarację na piśmie: strona „Dziękujemy” i maile z potwierdzeniem zamówienia. */
function fox_bl_delivery_order_line($order) {
    if (!$order instanceof WC_Order) {
        return '';
    }
    $d = (string) $order->get_meta('termin_deklaracja');
    if ('' === $d && $order->get_meta('szacowana_wysylka') && 'Termin wymaga potwierdzenia' !== $order->get_meta('szacowana_wysylka')) {
        $d = 'Planowana wysyłka: ' . $order->get_meta('szacowana_wysylka') . ' · Przewidywana dostawa: ' . $order->get_meta('szacowana_dostawa');
    }
    return $d;
}

add_action('woocommerce_thankyou', function ($order_id) {
    $d = fox_bl_delivery_order_line(wc_get_order($order_id));
    if (!$d) {
        return;
    }
    $parts = array_map('esc_html', explode(' · ', $d));
    echo '<div class="fox-delivery-alert fox-delivery-alert--later" style="margin:0 0 24px">'
        . '<div class="fox-delivery-alert__icon" aria-hidden="true">' . fox_bl_delivery_icon_svg('delivery') . '</div>'
        . '<div class="fox-delivery-alert__content"><div class="fox-delivery-alert__headline">' . $parts[0] . '</div>'
        . (isset($parts[1]) ? '<div class="fox-delivery-alert__delivery">' . $parts[1] . '</div>' : '')
        . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
}, 5);

add_action('woocommerce_email_order_meta', function ($order, $sent_to_admin, $plain_text) {
    $d = fox_bl_delivery_order_line($order);
    if (!$d) {
        return;
    }
    if ($plain_text) {
        echo "\n" . str_replace(' · ', "\n", $d) . "\n";
        return;
    }
    echo '<p style="margin:0 0 16px;padding:12px 14px;background:#f4f3f0;border-left:4px solid #111"><strong>' . implode('</strong><br>', array_map('esc_html', explode(' · ', $d))) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
}, 15, 3);
