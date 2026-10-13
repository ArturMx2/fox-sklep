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
		'filter_size_attr'  => '',
		'filter_color_attr' => '',
		'kit_discount'     => 5,
		'free_ship'        => 300,
		'size_names'       => "OS = Uniwersalny\nYOS = Junior",
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
		'size_names'       => array( 'textarea', 'Nazwy rozmiarów w sklepie (jedna linia: „OS = Uniwersalny”). Zmienia tylko wyświetlanie – w produktach i zamówieniach zostaje oryginał.', 'sanitize_textarea_field' ),
		'free_ship'        => array( 'number', 'Koszyk: próg darmowej dostawy w zł (pasek „Brakuje Ci … do darmowej dostawy”; 0 = ukryty)', 'absint' ),
		'kit_discount'     => array( 'number', 'Rabat za komplet w % (gdy w koszyku są wszystkie części; 0 = bez rabatu)', 'absint' ),
		'stock_alerts'     => array( 'checkbox', 'Formularz „Powiadom mnie o dostępności” przy niedostępnych rozmiarach', 'fx_sanitize_bool' ),
		'stock_alert_text' => array( 'textarea', 'Informacja pod formularzem powiadomienia (RODO)', 'sanitize_textarea_field' ),
	);
	// Atrybuty do filtrów: lista z WooCommerce.
	$attrs = array( '' => 'Wykryj automatycznie po nazwie' );
	if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
		foreach ( wc_get_attribute_taxonomies() as $a ) {
			$attrs[ $a->attribute_name ] = $a->attribute_label;
		}
	}
	foreach ( array( 'filter_size_attr' => 'Filtr „Rozmiar” – atrybut', 'filter_color_attr' => 'Filtr „Kolor” – atrybut' ) as $key => $label ) {
		$wp->add_setting( 'fx_t_' . $key, array( 'default' => '', 'sanitize_callback' => 'sanitize_title' ) );
		$wp->add_control( 'fx_t_' . $key, array( 'section' => 'fx_tools', 'label' => $label, 'type' => 'select', 'choices' => $attrs ) );
	}

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
