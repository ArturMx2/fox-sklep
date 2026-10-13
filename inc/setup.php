<?php
/**
 * Konfiguracja motywu, menu, widżety, skrypty.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

add_action( 'after_setup_theme', 'fx_setup' );
function fx_setup() {
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'automatic-feed-links' );
	add_theme_support( 'html5', array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script', 'navigation-widgets' ) );
	add_theme_support( 'custom-logo', array( 'height' => 88, 'width' => 400, 'flex-height' => true, 'flex-width' => true ) );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'align-wide' );
	add_theme_support( 'customize-selective-refresh-widgets' );

	add_theme_support(
		'woocommerce',
		array(
			'thumbnail_image_width' => 600,
			'single_image_width'    => 1200,
			'product_grid'          => array(
				'default_columns' => 3,
				'default_rows'    => 4,
				'min_columns'     => 2,
				'max_columns'     => 4,
			),
		)
	);
	add_theme_support( 'wc-product-gallery-zoom' );
	add_theme_support( 'wc-product-gallery-lightbox' );
	add_theme_support( 'wc-product-gallery-slider' );

	// Nazwa "primary" celowo taka sama jak w wielu motywach – WordPress
	// przy zmianie motywu sam przypisze dotychczasowe menu główne.
	register_nav_menus(
		array(
			'primary'  => 'Menu główne',
			'footer-1' => 'Stopka – kolumna 1',
			'footer-2' => 'Stopka – kolumna 2',
			'footer-3' => 'Stopka – kolumna 3',
			'footer-4' => 'Stopka – kolumna 4',
		)
	);

	add_image_size( 'fx-tile', 1200, 1200, true );
	add_image_size( 'fx-portrait', 640, 900, true );
}

add_action( 'widgets_init', 'fx_widgets' );
function fx_widgets() {
	$common = array(
		'before_widget' => '<div id="%1$s" class="widget %2$s">',
		'after_widget'  => '</div>',
		'before_title'  => '<h2 class="widget-title">',
		'after_title'   => '</h2>',
	);
	register_sidebar(
		array_merge(
			$common,
			array(
				'id'          => 'shop-filters',
				'name'        => 'Filtry sklepu',
				'description' => 'Pokazują się w panelu „Filtry” na stronach kategorii. Wstaw tu bloki filtrów WooCommerce lub widżety wtyczki do filtrowania.',
			)
		)
	);
	register_sidebar(
		array_merge(
			$common,
			array(
				'id'          => 'footer-newsletter',
				'name'        => 'Stopka – kolumny',
				'description' => 'Każdy widżet to osobna kolumna stopki, obok menu ze stopki (np. Kontakt, Pomoc, newsletter).',
			)
		)
	);
}

add_action( 'wp_enqueue_scripts', 'fx_assets' );
function fx_assets() {
	if ( 'google' === fx_mod( 'fonts' ) ) {
		wp_enqueue_style( 'fx-fonts', 'https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Outfit:wght@500;600&display=swap', array(), null );
	}
	$css_ver = FX_VERSION . '.' . filemtime( FX_DIR . '/style.css' );
	wp_enqueue_style( 'fx-style', get_stylesheet_uri(), array(), $css_ver );

	$js_ver = FX_VERSION . '.' . filemtime( FX_DIR . '/assets/js/theme.js' );
	wp_enqueue_script( 'fx-theme', FX_URI . '/assets/js/theme.js', array( 'jquery' ), $js_ver, true );

	if ( class_exists( 'WooCommerce' ) ) {
		wp_enqueue_script( 'wc-cart-fragments' );
	}

	if ( is_singular() && comments_open() && get_option( 'thread_comments' ) ) {
		wp_enqueue_script( 'comment-reply' );
	}
}

/* Preconnect do Google Fonts, jeśli używane. */
add_filter( 'wp_resource_hints', function ( $urls, $relation ) {
	if ( 'preconnect' === $relation && 'google' === fx_mod( 'fonts' ) ) {
		$urls[] = array( 'href' => 'https://fonts.gstatic.com', 'crossorigin' );
	}
	return $urls;
}, 10, 2 );

/* Własny kod w <head> i zaraz po <body> – np. Google Tag Manager. */
add_action( 'wp_head', function () {
	$code = fx_mod( 'code_head' );
	if ( $code ) {
		echo "\n" . $code . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- kod wpisany przez administratora z uprawnieniem unfiltered_html.
	}
}, 1 );

add_action( 'wp_body_open', function () {
	$code = fx_mod( 'code_body' );
	if ( $code ) {
		echo "\n" . $code . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	}
}, 1 );

add_filter( 'body_class', function ( $classes ) {
	$classes[] = 'fx-theme';
	return $classes;
} );

/* Zgodność z WordPressem < 5.2. */
if ( ! function_exists( 'wp_body_open' ) ) {
	function wp_body_open() {
		do_action( 'wp_body_open' );
	}
}

/*
 * Pozostałości po kreatorach stron (WPBakery / Visual Composer).
 * Gdy wtyczka nie jest aktywna, kody typu [vc_row] wyświetlałyby się jako tekst – ukrywamy same znaczniki,
 * treść między nimi zostaje.
 */
add_filter( 'the_content', 'fx_strip_orphan_builder_codes', 12 );
add_filter( 'woocommerce_short_description', 'fx_strip_orphan_builder_codes', 12 );
function fx_strip_orphan_builder_codes( $content ) {
	if ( false === strpos( (string) $content, '[vc_' ) && false === strpos( (string) $content, '[/vc_' ) ) {
		return $content;
	}
	if ( shortcode_exists( 'vc_row' ) ) {
		return $content;
	}
	$content = preg_replace( '/\[\/?vc_[^\]]*\]/', '', $content );
	// Puste akapity po usuniętych znacznikach.
	return preg_replace( '/<p>(\s|&nbsp;|<br\s*\/?>)*<\/p>/i', '', $content );
}

/* Długość zajawek na blogu. */
add_filter( 'excerpt_length', function () {
	return 24;
} );
