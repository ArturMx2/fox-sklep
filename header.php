<?php
/**
 * Nagłówek.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="skip-link screen-reader-text" href="#fx-main">Przejdź do treści</a>

<header class="fx-header">
	<div class="fx-header__in fx-wrap">
		<div class="fx-header__left">
			<button class="fx-icon-btn" type="button" data-fx-open="fx-menu-drawer" aria-controls="fx-menu-drawer" aria-expanded="false" aria-label="Otwórz menu" style="margin-left:-10px"><?php echo fx_icon( 'menu' ); // phpcs:ignore ?></button>
			<?php
			if ( has_nav_menu( 'primary' ) ) {
				wp_nav_menu(
					array(
						'theme_location'  => 'primary',
						'container'       => 'nav',
						'container_class' => 'fx-nav',
						'container_aria_label' => 'Menu główne',
						'depth'           => 3,
						'fallback_cb'     => false,
					)
				);
			} else {
				fx_fallback_menu( 'fx-nav' );
			}
			?>
		</div>

		<div class="fx-logo">
			<?php
			if ( has_custom_logo() ) {
				the_custom_logo();
			} else {
				echo '<a href="' . esc_url( home_url( '/' ) ) . '" rel="home">' . esc_html( get_bloginfo( 'name' ) ) . '</a>';
			}
			?>
		</div>

		<div class="fx-header__right">
			<?php if ( class_exists( 'WooCommerce' ) ) : ?>
				<form role="search" method="get" class="fx-search" action="<?php echo esc_url( home_url( '/' ) ); ?>">
					<label class="screen-reader-text" for="fx-s">Szukaj produktów</label>
					<input type="search" id="fx-s" name="s" placeholder="Czego szukasz?" value="<?php echo esc_attr( get_search_query() ); ?>">
					<input type="hidden" name="post_type" value="product">
					<button type="submit" aria-label="Szukaj"><?php echo fx_icon( 'search', 18 ); // phpcs:ignore ?></button>
				</form>
				<button class="fx-icon-btn fx-only-mobile" type="button" data-fx-open="fx-search-drawer" aria-controls="fx-search-drawer" aria-expanded="false" aria-label="Szukaj"><?php echo fx_icon( 'search' ); // phpcs:ignore ?></button>
				<?php if ( function_exists( 'fx_wishlist_page_id' ) && fx_wishlist_page_id() ) : ?>
					<a class="fx-icon-btn" href="<?php echo esc_url( get_permalink( fx_wishlist_page_id() ) ); ?>" aria-label="Ulubione"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10z"/></svg><span class="fx-count fx-wish-count" data-count="0"></span></a>
				<?php endif; ?>
				<a class="fx-icon-btn" href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>" aria-label="Moje konto"><?php echo fx_icon( 'user', 24 ); // phpcs:ignore ?></a>
				<a class="fx-icon-btn" href="<?php echo esc_url( wc_get_cart_url() ); ?>" aria-label="Koszyk"><?php echo fx_icon( 'bag', 24 ); // phpcs:ignore ?><?php fx_cart_count(); ?></a>
			<?php else : ?>
				<?php get_search_form(); ?>
			<?php endif; ?>
		</div>
	</div>
</header>

<?php
$fx_announce = fx_mod( 'announce' );
if ( $fx_announce ) {
	echo '<div class="fx-announce">' . wp_kses_post( $fx_announce ) . '</div>';
}
?>

<div class="fx-drawer" id="fx-menu-drawer" aria-hidden="true">
	<div class="fx-drawer__bg" data-fx-close></div>
	<div class="fx-drawer__panel" role="dialog" aria-modal="true" aria-label="Menu">
		<div class="fx-drawer__head">
			<span class="fx-drawer__title">Menu</span>
			<button class="fx-icon-btn" type="button" data-fx-close aria-label="Zamknij menu"><?php echo fx_icon( 'close' ); // phpcs:ignore ?></button>
		</div>
		<?php
		if ( has_nav_menu( 'primary' ) ) {
			wp_nav_menu(
				array(
					'theme_location'  => 'primary',
					'container'       => 'nav',
					'container_class' => 'fx-mnav',
					'container_aria_label' => 'Menu mobilne',
					'depth'           => 3,
					'fallback_cb'     => false,
				)
			);
		} else {
			fx_fallback_menu( 'fx-mnav' );
		}
		?>
	</div>
</div>

<?php if ( class_exists( 'WooCommerce' ) ) : ?>
<div class="fx-drawer fx-drawer--right" id="fx-search-drawer" aria-hidden="true">
	<div class="fx-drawer__bg" data-fx-close></div>
	<div class="fx-drawer__panel" role="dialog" aria-modal="true" aria-label="Szukaj">
		<div class="fx-drawer__head">
			<span class="fx-drawer__title">Szukaj</span>
			<button class="fx-icon-btn" type="button" data-fx-close aria-label="Zamknij"><?php echo fx_icon( 'close' ); // phpcs:ignore ?></button>
		</div>
		<form role="search" method="get" class="fx-search" style="display:flex" action="<?php echo esc_url( home_url( '/' ) ); ?>">
			<label class="screen-reader-text" for="fx-s-m">Szukaj produktów</label>
			<input type="search" id="fx-s-m" name="s" placeholder="Czego szukasz?">
			<input type="hidden" name="post_type" value="product">
			<button type="submit" aria-label="Szukaj"><?php echo fx_icon( 'search', 18 ); // phpcs:ignore ?></button>
		</form>
	</div>
</div>
<?php endif; ?>

<main id="fx-main">
