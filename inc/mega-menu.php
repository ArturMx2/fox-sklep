<?php
/**
 * Mega menu w nagłówku (desktop).
 *
 * Każda pozycja główna z podmenu otwiera panel na całą szerokość nagłówka.
 * Pozycje 2. poziomu to nagłówki kolumn, 3. poziom to lista linków pod nimi,
 * 4. poziom (np. Rower → Kaski → Kaski fullface → Proframe) to wcięta lista.
 * Kolumny układają się automatycznie (CSS columns), więc nie trzeba nic ustawiać w panelu WP.
 * Na dole panelu link „Zobacz wszystko” do kategorii nadrzędnej.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

class FX_Mega_Walker extends Walker_Nav_Menu {

	/** Stos pozycji, żeby przy zamykaniu panelu znać rodzica. */
	private $parents = array();

	public function start_lvl( &$output, $depth = 0, $args = null ) {
		if ( 0 === $depth ) {
			$output .= '<div class="fx-mega"><div class="fx-mega__in"><ul class="sub-menu fx-mega__cols">';
			return;
		}
		parent::start_lvl( $output, $depth, $args );
	}

	public function end_lvl( &$output, $depth = 0, $args = null ) {
		if ( 0 === $depth ) {
			$parent = end( $this->parents );
			$output .= '</ul>';
			if ( $parent && ! empty( $parent->url ) && '#' !== $parent->url ) {
				$output .= '<a class="fx-mega__all" href="' . esc_url( $parent->url ) . '">Zobacz wszystko: ' . esc_html( wp_strip_all_tags( $parent->title ) ) . ' <span aria-hidden="true">→</span></a>';
			}
			$output .= '</div></div>';
			return;
		}
		parent::end_lvl( $output, $depth, $args );
	}

	public function start_el( &$output, $data_object, $depth = 0, $args = null, $current_object_id = 0 ) {
		$this->parents[ $depth ] = $data_object;
		if ( 1 === $depth ) {
			$classes   = (array) $data_object->classes;
			$classes[] = 'fx-mega__col';
			if ( in_array( 'menu-item-has-children', $classes, true ) ) {
				$classes[] = 'fx-mega__group';
			}
			$data_object->classes = $classes;
		}
		parent::start_el( $output, $data_object, $depth, $args, $current_object_id );
	}

	public function end_el( &$output, $data_object, $depth = 0, $args = null ) {
		parent::end_el( $output, $data_object, $depth, $args );
		$this->parents = array_slice( $this->parents, 0, $depth, true );
	}
}

/** Pozycja główna z podmenu dostaje klasę fx-has-mega. */
add_filter(
	'nav_menu_css_class',
	function ( $classes, $item, $args, $depth ) {
		if ( 0 === $depth && isset( $args->theme_location ) && 'primary' === $args->theme_location && isset( $args->walker ) && $args->walker instanceof FX_Mega_Walker && in_array( 'menu-item-has-children', (array) $classes, true ) ) {
			$classes[] = 'fx-has-mega';
		}
		return $classes;
	},
	10,
	4
);
