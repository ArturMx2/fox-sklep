<?php
/**
 * Stopka.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;
?>
</main>

<footer class="fx-footer fx-wrap">
	<div class="fx-footer__cols">
		<?php
		for ( $fx_i = 1; $fx_i <= 4; $fx_i++ ) {
			$fx_loc = 'footer-' . $fx_i;
			if ( ! has_nav_menu( $fx_loc ) ) {
				continue;
			}
			$fx_locations = get_nav_menu_locations();
			$fx_menu      = isset( $fx_locations[ $fx_loc ] ) ? wp_get_nav_menu_object( $fx_locations[ $fx_loc ] ) : null;
			echo '<div>';
			if ( $fx_menu ) {
				echo '<h2>' . esc_html( $fx_menu->name ) . '</h2>';
			}
			wp_nav_menu(
				array(
					'theme_location' => $fx_loc,
					'container'      => 'nav',
					'container_aria_label' => $fx_menu ? $fx_menu->name : 'Stopka',
					'depth'          => 1,
					'fallback_cb'    => false,
				)
			);
			echo '</div>';
		}
		// Każdy widżet stopki to osobna kolumna siatki.
		if ( is_active_sidebar( 'footer-newsletter' ) ) {
			dynamic_sidebar( 'footer-newsletter' );
		}
		?>
	</div>
	<div class="fx-footer__bottom">
		<span>© <?php echo esc_html( gmdate( 'Y' ) ); ?> <?php echo esc_html( get_bloginfo( 'name' ) ); ?><?php echo fx_mod( 'footer_note' ) ? ' · ' . esc_html( fx_mod( 'footer_note' ) ) : ''; ?></span>
	</div>
</footer>

<?php wp_footer(); ?>
</body>
</html>
