<?php
/**
 * Strona 404.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<section class="fx-404">
	<h1>Nie ma takiej strony</h1>
	<p>Produkt mógł zostać wyprzedany albo adres się zmienił.</p>
	<div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin-top:24px">
		<a class="fx-btn" href="<?php echo esc_url( home_url( '/' ) ); ?>">Strona główna</a>
		<?php if ( function_exists( 'wc_get_page_permalink' ) ) : ?>
			<a class="fx-btn fx-btn--outline" href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>">Wszystkie produkty</a>
		<?php endif; ?>
	</div>
</section>
<?php
get_footer();
