<?php
/**
 * Etykiety z nazwy produktu (jak „Dynamiczne naklejki” z enduro.sklep.pl).
 *
 * WooCommerce → Etykiety z nazwy: „nazwa zawiera: lady|women|damsk” → etykieta „Damskie”, kolor.
 * Nic nie zapisuje się w produktach – etykiety liczone przy wyświetlaniu, w listingu i na karcie produktu,
 * razem z pozostałymi etykietami motywu (promocja, nowość…). Kolor tekstu dobierany automatycznie
 * (biały albo czarny) dla czytelności. Reguły ze starej wtyczki (shoptimizer_dynamic_badges_rules)
 * są przejmowane automatycznie przy pierwszym uruchomieniu.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

const FX_TB_OPTION = 'fx_title_badges';

function fx_tb_rules() {
	static $rules = null;
	if ( null !== $rules ) {
		return $rules;
	}
	$rules = get_option( FX_TB_OPTION, null );
	if ( null === $rules ) {
		// Przejęcie reguł ze starej wtyczki (enduro.sklep.pl / Shoptimizer).
		$old   = get_option( 'shoptimizer_dynamic_badges_rules', array() );
		$mode  = get_option( 'shoptimizer_dynamic_badges_settings', array() );
		$rules = array( 'mode' => ( is_array( $mode ) && isset( $mode['match_mode'] ) && 'all' === $mode['match_mode'] ) ? 'all' : 'first', 'rules' => array() );
		foreach ( is_array( $old ) ? $old : array() as $r ) {
			if ( ! empty( $r['pattern_raw'] ) && ! empty( $r['label'] ) ) {
				$rules['rules'][] = array( 'pattern' => $r['pattern_raw'], 'label' => $r['label'], 'color' => ! empty( $r['color'] ) ? $r['color'] : '#111111', 'on' => ! empty( $r['enabled'] ) );
			}
		}
		if ( ! $rules['rules'] ) {
			// Start: najważniejsze dla klientów – damskie, juniorskie, dziecięce.
			$rules = array(
				'mode'  => 'first',
				'rules' => array(
					array( 'pattern' => 'kids|dziecięc|dzieciec|dla dzieci|toddler', 'label' => 'Dziecięce', 'color' => '#2E7D4F', 'on' => true ),
					array( 'pattern' => 'junior|youth|młodzież|mlodziez', 'label' => 'Juniorskie', 'color' => '#1E5BD8', 'on' => true ),
					array( 'pattern' => 'lady|ladies|women|womens|wmn|damsk', 'label' => 'Damskie', 'color' => '#B8326E', 'on' => true ),
				),
			);
		}
		update_option( FX_TB_OPTION, $rules, false );
	}
	if ( ! is_array( $rules ) || ! isset( $rules['rules'] ) ) {
		$rules = array( 'mode' => 'first', 'rules' => array() );
	}
	return $rules;
}

/** Etykiety pasujące do nazwy: [ [ tekst, kolor ], … ]. */
function fx_tb_match( $title ) {
	$cfg   = fx_tb_rules();
	$title = wp_strip_all_tags( (string) $title );
	$out   = array();
	foreach ( $cfg['rules'] as $r ) {
		if ( empty( $r['on'] ) ) {
			continue;
		}
		$tokens = array_filter( array_map( 'trim', explode( '|', (string) $r['pattern'] ) ) );
		foreach ( $tokens as $t ) {
			if ( false !== mb_stripos( $title, $t, 0, 'UTF-8' ) ) {
				$out[] = array( $r['label'], $r['color'] );
				break;
			}
		}
		if ( $out && 'first' === $cfg['mode'] ) {
			break;
		}
	}
	return $out;
}

/** Czarny albo biały tekst – co jest czytelniejsze na danym tle. */
function fx_tb_text_color( $hex ) {
	$hex = ltrim( (string) $hex, '#' );
	if ( 3 === strlen( $hex ) ) {
		$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
	}
	if ( 6 !== strlen( $hex ) ) {
		return '#ffffff';
	}
	$lin = function ( $c ) {
		$c = $c / 255;
		return $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
	};
	$l = 0.2126 * $lin( hexdec( substr( $hex, 0, 2 ) ) ) + 0.7152 * $lin( hexdec( substr( $hex, 2, 2 ) ) ) + 0.0722 * $lin( hexdec( substr( $hex, 4, 2 ) ) );
	return ( 1.05 / ( $l + 0.05 ) ) >= ( ( $l + 0.05 ) / 0.05 ) ? '#ffffff' : '#0C0A08';
}

/* Dołącz do etykiet motywu: zaraz po promocji. */
add_filter( 'fx_product_badges', function ( $badges, $product ) {
	$extra = array();
	foreach ( fx_tb_match( $product->get_name() ) as $m ) {
		$extra[] = array( $m[0], 'custom', $m[1] );
	}
	if ( ! $extra ) {
		return $badges;
	}
	$first = ( $badges && 'sale' === $badges[0][1] ) ? array( array_shift( $badges ) ) : array();
	return array_slice( array_merge( $first, $extra, $badges ), 0, 4 );
}, 10, 2 );

/* ---------- Panel: WooCommerce → Etykiety z nazwy ---------- */
add_action( 'admin_menu', function () {
	add_submenu_page( 'woocommerce', 'Etykiety z nazwy produktu', 'Etykiety z nazwy', 'manage_woocommerce', 'fx-title-badges', 'fx_tb_page' );
} );

add_action( 'admin_enqueue_scripts', function () {
	if ( isset( $_GET['page'] ) && 'fx-title-badges' === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
	}
} );

function fx_tb_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	if ( isset( $_POST['fx_tb_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['fx_tb_nonce'] ) ), 'fx_tb_save' ) ) {
		$rules = array();
		foreach ( isset( $_POST['rules'] ) ? (array) wp_unslash( $_POST['rules'] ) : array() as $r ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$pattern = isset( $r['pattern'] ) ? sanitize_text_field( $r['pattern'] ) : '';
			$label   = isset( $r['label'] ) ? sanitize_text_field( $r['label'] ) : '';
			$color   = isset( $r['color'] ) ? sanitize_hex_color( $r['color'] ) : '';
			if ( '' !== trim( $pattern ) && '' !== $label ) {
				$rules[] = array( 'pattern' => $pattern, 'label' => $label, 'color' => $color ? $color : '#111111', 'on' => ! empty( $r['on'] ) );
			}
		}
		$mode = ( isset( $_POST['mode'] ) && 'all' === $_POST['mode'] ) ? 'all' : 'first';
		update_option( FX_TB_OPTION, array( 'mode' => $mode, 'rules' => $rules ), false );
		echo '<div class="notice notice-success"><p>Zapisano.</p></div>';
	}
	$cfg = get_option( FX_TB_OPTION, null );
	$cfg = is_array( $cfg ) ? $cfg : fx_tb_rules();
	?>
	<div class="wrap">
		<h1>Etykiety z nazwy produktu</h1>
		<p>Wpisz frazy rozdzielone „|” – jeśli nazwa produktu zawiera którąś z nich, na zdjęciu produktu pojawi się etykieta. Wielkość liter nie ma znaczenia. Nic nie zapisuje się w produktach.</p>
		<form method="post">
			<?php wp_nonce_field( 'fx_tb_save', 'fx_tb_nonce' ); ?>
			<p>
				<label><input type="radio" name="mode" value="first" <?php checked( 'first', $cfg['mode'] ); ?>> Pokazuj pierwszą pasującą etykietę</label>&nbsp;&nbsp;
				<label><input type="radio" name="mode" value="all" <?php checked( 'all', $cfg['mode'] ); ?>> Pokazuj wszystkie pasujące (razem z pozostałymi najwyżej 4 etykiety)</label>
			</p>
			<table class="widefat striped" style="max-width:1100px">
				<thead><tr><th style="width:34%">Nazwa zawiera (frazy rozdzielone |)</th><th style="width:24%">Etykieta</th><th style="width:140px">Kolor</th><th>Podgląd</th><th style="width:70px">Włączona</th><th style="width:40px"></th></tr></thead>
				<tbody id="fx-tb-rows">
					<?php foreach ( $cfg['rules'] as $i => $r ) : ?>
						<tr>
							<td><input type="text" class="widefat" name="rules[<?php echo (int) $i; ?>][pattern]" value="<?php echo esc_attr( $r['pattern'] ); ?>" placeholder="np. lady|women|damsk"></td>
							<td><input type="text" class="widefat fx-tb-label" name="rules[<?php echo (int) $i; ?>][label]" value="<?php echo esc_attr( $r['label'] ); ?>" placeholder="np. Damskie"></td>
							<td><input type="text" class="fx-tb-color" name="rules[<?php echo (int) $i; ?>][color]" value="<?php echo esc_attr( $r['color'] ); ?>"></td>
							<td><span class="fx-tb-prev" style="display:inline-block;padding:5px 10px;border-radius:999px;font:700 12px/1.2 sans-serif;background:<?php echo esc_attr( $r['color'] ); ?>;color:<?php echo esc_attr( fx_tb_text_color( $r['color'] ) ); ?>"><?php echo esc_html( $r['label'] ); ?></span></td>
							<td><input type="checkbox" name="rules[<?php echo (int) $i; ?>][on]" value="1" <?php checked( ! empty( $r['on'] ) ); ?>></td>
							<td><button type="button" class="button fx-tb-del" aria-label="Usuń">✕</button></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p><button type="button" class="button" id="fx-tb-add">+ Dodaj regułę</button></p>
			<p><button type="submit" class="button button-primary">Zapisz</button></p>
		</form>
		<h2>Przykłady</h2>
		<p><code>lady|women|damsk</code> → Damskie · <code>junior|youth|kids|dziec</code> → Dziecięce · <code>mips</code> → MIPS · <code>gore-tex|goretex</code> → Gore-Tex · <code>dngr</code> → Kolekcja DNGR · <code>limited|le </code> → Edycja limitowana</p>
	</div>
	<script>
	jQuery(function ($) {
		function pick($el) {
			$el.wpColorPicker({ change: function (e, ui) { var tr = $(e.target).closest('tr'); tr.find('.fx-tb-prev').css('background', ui.color.toString()); } });
		}
		pick($('.fx-tb-color'));
		$('#fx-tb-rows').on('input', '.fx-tb-label', function () { $(this).closest('tr').find('.fx-tb-prev').text(this.value || 'Podgląd'); });
		$('#fx-tb-rows').on('click', '.fx-tb-del', function () { $(this).closest('tr').remove(); });
		$('#fx-tb-add').on('click', function () {
			var i = Date.now();
			var tr = $('<tr><td><input type="text" class="widefat" name="rules[' + i + '][pattern]" placeholder="np. lady|women|damsk"></td><td><input type="text" class="widefat fx-tb-label" name="rules[' + i + '][label]" placeholder="np. Damskie"></td><td><input type="text" class="fx-tb-color" name="rules[' + i + '][color]" value="#0C0A08"></td><td><span class="fx-tb-prev" style="display:inline-block;padding:5px 10px;border-radius:999px;font:700 12px/1.2 sans-serif;background:#0C0A08;color:#fff">Podgląd</span></td><td><input type="checkbox" name="rules[' + i + '][on]" value="1" checked></td><td><button type="button" class="button fx-tb-del">✕</button></td></tr>');
			$('#fx-tb-rows').append(tr); pick(tr.find('.fx-tb-color'));
		});
	});
	</script>
	<?php
}
