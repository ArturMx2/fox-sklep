<?php
/**
 * Komunikat o oszczędności pod ceną na karcie produktu.
 *
 * Logika i teksty przeniesione 1:1 z dotychczasowego kodu fox.sklep.pl (fox_show_product_savings_box):
 * - tylko produkty w promocji, liczone z ceny regularnej do promocyjnej,
 * - produkt prosty: „Kupujesz taniej o X (Y%)” + cena detaliczna,
 * - produkt wariantowy: największa oszczędność spośród przecenionych wariantów – „Oszczędzasz nawet X (Y%)”.
 * Zmiany: warianty liczone bez get_available_variations() (szybciej), style w style.css (kolory motywu).
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

add_action( 'woocommerce_single_product_summary', 'fx_savings_box', 11 );

function fx_savings_box() {
	if ( ! is_product() ) {
		return;
	}
	global $product;
	if ( ! $product instanceof WC_Product || ! $product->is_on_sale() ) {
		return;
	}

	if ( $product->is_type( 'simple' ) ) {
		$regular = (float) $product->get_regular_price();
		$sale    = (float) $product->get_sale_price();
		if ( $regular <= 0 || $sale <= 0 || $sale >= $regular ) {
			return;
		}
		$amount  = $regular - $sale;
		$percent = round( ( $amount / $regular ) * 100 );
		echo '<div class="fx-savings">';
		echo '<div class="fx-savings__title">🔥 Kupujesz taniej o ' . wc_price( $amount ) . ' (' . esc_html( $percent ) . '%)</div>'; // phpcs:ignore
		echo '<div class="fx-savings__sub">Cena detaliczna: <strong>' . wc_price( $regular ) . '</strong></div>'; // phpcs:ignore
		echo '</div>';
		return;
	}

	if ( $product->is_type( 'variable' ) ) {
		$best_amount  = 0;
		$best_percent = 0;
		foreach ( $product->get_visible_children() as $vid ) {
			$v = wc_get_product( $vid );
			if ( ! $v || ! $v->is_purchasable() || ! $v->is_on_sale() ) {
				continue;
			}
			$regular = (float) $v->get_regular_price();
			$sale    = (float) $v->get_sale_price();
			if ( $regular > 0 && $sale > 0 && $sale < $regular ) {
				$amount = $regular - $sale;
				if ( $amount > $best_amount ) {
					$best_amount  = $amount;
					$best_percent = round( ( $amount / $regular ) * 100 );
				}
			}
		}
		if ( $best_amount > 0 ) {
			// Dane formatu ceny – po wyborze wariantu skrypt podmienia kwotę na oszczędność tego wariantu.
			echo '<div class="fx-savings fx-savings--variable" data-dec="' . esc_attr( wc_get_price_decimal_separator() ) . '" data-th="' . esc_attr( wc_get_price_thousand_separator() ) . '" data-n="' . (int) wc_get_price_decimals() . '" data-sym="' . esc_attr( html_entity_decode( get_woocommerce_currency_symbol() ) ) . '" data-pos="' . esc_attr( get_option( 'woocommerce_currency_pos', 'right' ) ) . '">';
			echo '<div class="fx-savings__title">🔥 Oszczędzasz nawet ' . wc_price( $best_amount ) . ' (' . esc_html( $best_percent ) . '%)</div>'; // phpcs:ignore
			echo '<div class="fx-savings__sub">względem ceny detalicznej</div>';
			echo '</div>';
		}
	}
}
