<?php
/**
 * Wzmocnienia bezpieczeństwa (lekkie, bez wpływu na sklep, płatności i BaseLinkera).
 *
 * - nie zdradzamy wersji WordPressa w kodzie strony,
 * - blokujemy podglądanie loginów użytkowników (?author=1 i /wp-json/wp/v2/users dla niezalogowanych),
 * - nagłówki bezpieczeństwa przeglądarki (nosniff, ochrona przed osadzaniem strony w ramce obcej witryny, referrer).
 * Wyłączenie: Wygląd → Dostosuj → FOX.SKLEP.PL → Narzędzia sklepu → „Wzmocnienia bezpieczeństwa”.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

add_action( 'customize_register', function ( $wp ) {
	$wp->add_setting( 'fx_security', array( 'default' => true, 'sanitize_callback' => 'fx_sanitize_bool' ) );
	$wp->add_control(
		'fx_security',
		array(
			'section'     => 'fx_tools',
			'label'       => 'Wzmocnienia bezpieczeństwa (ukrycie wersji WordPressa i loginów, nagłówki bezpieczeństwa)',
			'type'        => 'checkbox',
		)
	);
}, 40 );

if ( ! get_theme_mod( 'fx_security', true ) ) {
	return;
}

/* Wersja WordPressa niewidoczna w HTML i kanałach RSS. */
remove_action( 'wp_head', 'wp_generator' );
add_filter( 'the_generator', '__return_empty_string' );

/* ?author=1 → przekierowanie na stronę główną (to najprostszy sposób na wyciągnięcie loginu admina). */
add_action( 'template_redirect', function () {
	if ( ! is_user_logged_in() && isset( $_GET['author'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		wp_safe_redirect( home_url( '/' ), 301 );
		exit;
	}
}, 1 );

/* Lista użytkowników w REST API tylko dla uprawnionych (klucze API WooCommerce / BaseLinker działają dalej). */
add_filter( 'rest_request_before_callbacks', function ( $response, $handler, $request ) {
	if ( 0 === strpos( $request->get_route(), '/wp/v2/users' ) && ! current_user_can( 'edit_posts' ) ) {
		return new WP_Error( 'rest_forbidden', 'Brak dostępu.', array( 'status' => 401 ) );
	}
	return $response;
}, 10, 3 );

/* Loginy autorów nie wyciekają też przez oEmbed. */
add_filter( 'oembed_response_data', function ( $data ) {
	unset( $data['author_name'], $data['author_url'] );
	return $data;
} );

/* Nagłówki bezpieczeństwa – tylko na stronie sklepu (panel WP bez zmian). */
add_action( 'send_headers', function () {
	if ( is_admin() || headers_sent() ) {
		return;
	}
	header( 'X-Content-Type-Options: nosniff' );
	header( 'X-Frame-Options: SAMEORIGIN' );
	header( 'Referrer-Policy: strict-origin-when-cross-origin' );
	header( 'Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()' );
} );
