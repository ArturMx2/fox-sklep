<?php
/**
 * Ustawienia narzędzi sklepu: Wygląd → Dostosuj → FOX.SKLEP.PL → Narzędzia sklepu.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wartości domyślne narzędzi.
 */
function fx_tool_defaults() {
	return array(
		'badge_new_days'   => 30,
		'badge_sale_pct'   => true,
		'badge_low_stock'  => 2,
		'helmet_cats'      => 'kaski, kaski-odziez-mtb',
		'helmet_ranges'    => '',
		'stock_alerts'     => true,
		'stock_alert_text' => 'Zapisując się, zgadzasz się na jednorazową wiadomość e-mail o dostępności produktu.',
	);
}

function fx_tool( $key ) {
	$d = fx_tool_defaults();
	return get_theme_mod( 'fx_t_' . $key, isset( $d[ $key ] ) ? $d[ $key ] : '' );
}

add_action( 'customize_register', function ( $wp ) {
	$d = fx_tool_defaults();
	$wp->add_section(
		'fx_tools',
		array(
			'title' => 'Narzędzia sklepu',
			'panel' => 'fx_panel',
		)
	);
	$fields = array(
		'badge_new_days'   => array( 'number', 'Etykieta „Nowość” – przez ile dni od dodania produktu (0 = wyłączona)', 'absint' ),
		'badge_sale_pct'   => array( 'checkbox', 'Etykieta promocji z procentem rabatu (np. −20%)', 'fx_sanitize_bool' ),
		'badge_low_stock'  => array( 'number', 'Etykieta „Ostatnie sztuki”, gdy na stanie jest najwyżej tyle sztuk (0 = wyłączona)', 'absint' ),
		'helmet_cats'      => array( 'text', 'Kategorie kasków (slugi po przecinku, z podkategoriami) – wymagany obwód głowy przy zamówieniu', 'sanitize_text_field' ),
		'helmet_ranges'    => array( 'textarea', 'Zakresy rozmiarów kasków w cm, jedna linia na rozmiar, np. „M | 57-58”. Puste = bez sprawdzania zgodności.', 'sanitize_textarea_field' ),
		'stock_alerts'     => array( 'checkbox', 'Formularz „Powiadom mnie o dostępności” przy niedostępnych rozmiarach', 'fx_sanitize_bool' ),
		'stock_alert_text' => array( 'textarea', 'Informacja pod formularzem powiadomienia (RODO)', 'sanitize_textarea_field' ),
	);
	foreach ( $fields as $key => $f ) {
		$wp->add_setting(
			'fx_t_' . $key,
			array(
				'default'           => $d[ $key ],
				'sanitize_callback' => $f[2],
			)
		);
		$wp->add_control(
			'fx_t_' . $key,
			array(
				'section' => 'fx_tools',
				'label'   => $f[1],
				'type'    => $f[0],
			)
		);
	}
}, 20 );
