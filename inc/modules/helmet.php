<?php
/**
 * Obowiązkowy rzeczywisty obwód głowy przy zakupie kasku.
 *
 * Przeniesione 1:1 z dotychczasowego kodu fox.sklep.pl (wersja 3.0, klasyczny checkout):
 * wykrywanie kasku po słowie „kask” w nazwie, pole z obowiązkowym potwierdzeniem pomiaru,
 * zapis jako obwod_glowy / pomiar_glowy_potwierdzony / fox_head_circumference,
 * dopisanie do uwag zamówienia (BaseLinker), notatka wewnętrzna, panel, e-maile, strona podziękowania.
 * Te same nazwy pól co dotychczas – integracje i stare zamówienia działają bez zmian.
 * Zmiana: style przeniesione do style.css motywu.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;


/* ==========================================================================
 * 1. WYKRYWANIE KASKU W KOSZYKU
 * ========================================================================== */

/**
 * Sprawdza, czy nazwa produktu zawiera słowo „kask”.
 *
 * Działa również dla wariantów produktów.
 */
function fox_hc_v3_product_is_helmet( $product ) {

	if ( ! $product instanceof WC_Product ) {
		return false;
	}

	$product_names = array(
		$product->get_name(),
	);

	/*
	 * Przy wariancie sprawdzamy także nazwę produktu nadrzędnego.
	 */
	if ( $product->is_type( 'variation' ) ) {

		$parent_product = wc_get_product(
			$product->get_parent_id()
		);

		if ( $parent_product instanceof WC_Product ) {
			$product_names[] = $parent_product->get_name();
		}
	}

	foreach ( $product_names as $product_name ) {

		$product_name = (string) $product_name;

		if ( function_exists( 'mb_stripos' ) ) {

			$contains_helmet = false !== mb_stripos(
				$product_name,
				'kask',
				0,
				'UTF-8'
			);

		} else {

			$contains_helmet = false !== stripos(
				$product_name,
				'kask'
			);
		}

		if ( $contains_helmet ) {
			return true;
		}
	}

	return false;
}


/**
 * Sprawdza, czy w koszyku znajduje się przynajmniej jeden kask.
 */
function fox_hc_v3_cart_has_helmet() {

	if (
		! function_exists( 'WC' ) ||
		! WC()->cart
	) {
		return false;
	}

	foreach ( WC()->cart->get_cart() as $cart_item ) {

		if (
			empty( $cart_item['data'] ) ||
			! $cart_item['data'] instanceof WC_Product
		) {
			continue;
		}

		if ( fox_hc_v3_product_is_helmet( $cart_item['data'] ) ) {
			return true;
		}
	}

	return false;
}


/* ==========================================================================
 * 2. OBSŁUGA WARTOŚCI POMIARU
 * ========================================================================== */

/**
 * Pobiera pomiar przesłany przez formularz.
 */
function fox_hc_v3_get_posted_measurement() {

	if ( ! isset( $_POST['fox_head_circumference'] ) ) {
		return '';
	}

	$value = wc_clean(
		wp_unslash(
			$_POST['fox_head_circumference']
		)
	);

	$value = trim( (string) $value );

	/*
	 * Klient może wpisać 57,5 albo 57.5.
	 */
	return str_replace( ',', '.', $value );
}


/**
 * Formatuje wartość pomiaru.
 *
 * 57.0 zmienia na 57
 * 57.5 pozostawia jako 57,5
 */
function fox_hc_v3_format_measurement( $value ) {

	$value = (float) $value;

	$formatted = rtrim(
		rtrim(
			number_format( $value, 1, '.', '' ),
			'0'
		),
		'.'
	);

	return str_replace( '.', ',', $formatted );
}


/**
 * Sprawdza, czy klient zaznaczył potwierdzenie.
 */
function fox_hc_v3_measurement_is_confirmed() {

	return ! empty(
		$_POST['fox_head_measurement_confirmed']
	);
}


/* ==========================================================================
 * 3. WYŚWIETLENIE SEKCJI W CHECKOUT
 * ========================================================================== */

/**
 * Wyświetla całą sekcję jako jeden spójny box.
 */
add_action(
	'woocommerce_before_checkout_billing_form',
	'fox_hc_v3_display_measurement_box',
	5
);

function fox_hc_v3_display_measurement_box( $checkout ) {

	if ( ! fox_hc_v3_cart_has_helmet() ) {
		return;
	}

	$measurement = '';

	if ( isset( $_POST['fox_head_circumference'] ) ) {

		$measurement = wc_clean(
			wp_unslash(
				$_POST['fox_head_circumference']
			)
		);
	}

	$is_confirmed = fox_hc_v3_measurement_is_confirmed();

	?>

	<div class="fox-helmet-check">

		<div class="fox-helmet-check__heading">

			<span
				class="fox-helmet-check__icon"
				aria-hidden="true"
			>
				✓
			</span>

			<div class="fox-helmet-check__heading-content">

				<h3>
					Sprawdźmy rozmiar kasku dwa razy
				</h3>

				<p>
					W Twoim koszyku znajduje się kask. Przed realizacją
					zamówienia porównamy wybrany rozmiar z podanym
					obwodem głowy.
				</p>

			</div>

		</div>

		<div class="fox-helmet-check__alert">

			<strong>
				Podaj rzeczywisty wynik z miarki.
			</strong>

			<span>
				Nie zaokrąglaj pomiaru w górę, nie dodawaj zapasu
				i nie wybieraj rozmiaru „na przyszłość”.
			</span>

		</div>

		<div class="fox-helmet-check__field">

			<label for="fox_head_circumference">

				Rzeczywisty obwód głowy w cm

				<abbr
					class="required"
					title="wymagane"
				>
					*
				</abbr>

			</label>

			<div class="fox-helmet-check__input-wrapper">

				<input
					type="text"
					name="fox_head_circumference"
					id="fox_head_circumference"
					value="<?php echo esc_attr( $measurement ); ?>"
					placeholder="np. 57"
					inputmode="decimal"
					autocomplete="off"
					maxlength="4"
					pattern="[0-9]{2}([,.][0-9])?"
					required
					aria-required="true"
					aria-describedby="fox_head_measurement_help"
				>

				<span class="fox-helmet-check__unit">
					cm
				</span>

			</div>

			<p
				id="fox_head_measurement_help"
				class="fox-helmet-check__help"
			>
				Poprowadź miarkę poziomo około 1–2 cm nad brwiami,
				nad uszami i przez najszersze miejsce z tyłu głowy.
			</p>

		</div>

		<label
			class="fox-helmet-check__confirmation"
			for="fox_head_measurement_confirmed"
		>

			<input
				type="checkbox"
				name="fox_head_measurement_confirmed"
				id="fox_head_measurement_confirmed"
				value="1"
				<?php checked( $is_confirmed ); ?>
				required
				aria-required="true"
			>

			<span>
				<strong>
					Potwierdzam, że podałem rzeczywisty wynik z miarki.
				</strong>

				Bez zaokrąglania w górę i bez dodawania zapasu.
			</span>

		</label>

		<div class="fox-helmet-check__footer">

			<strong>
				Nie znasz obwodu głowy? Najpierw go zmierz.
			</strong>

			<p>
				Wiek, wzrost ani rozmiar ubrań nie są wyznacznikiem
				rozmiaru kasku. Dobieranie kasku na podstawie wieku
				bardzo często kończy się wyborem niewłaściwego rozmiaru.
			</p>

		</div>

	</div>

	<?php
}


/* ==========================================================================
 * 4. WALIDACJA FORMULARZA
 * ========================================================================== */

/**
 * Blokuje złożenie zamówienia bez poprawnego pomiaru.
 */
add_action(
	'woocommerce_after_checkout_validation',
	'fox_hc_v3_validate_measurement',
	10,
	2
);

function fox_hc_v3_validate_measurement( $data, $errors ) {

	if ( ! fox_hc_v3_cart_has_helmet() ) {
		return;
	}

	$measurement = fox_hc_v3_get_posted_measurement();

	if ( '' === $measurement ) {

		$errors->add(
			'fox_head_measurement_required',
			'Zmierz głowę i podaj jej rzeczywisty obwód w centymetrach.',
			array(
				'id' => 'fox_head_circumference',
			)
		);

	} elseif (
		! preg_match(
			'/^[0-9]{2}(?:\.[0-9])?$/',
			$measurement
		)
	) {

		$errors->add(
			'fox_head_measurement_invalid',
			'Podaj sam rzeczywisty wynik pomiaru, np. 57 lub 57,5.',
			array(
				'id' => 'fox_head_circumference',
			)
		);

	} else {

		$measurement_number = (float) $measurement;

		if (
			$measurement_number < 40 ||
			$measurement_number > 75
		) {

			$errors->add(
				'fox_head_measurement_range',
				'Sprawdź podany pomiar. Obwód głowy powinien mieścić się w zakresie od 40 do 75 cm.',
				array(
					'id' => 'fox_head_circumference',
				)
			);
		}
	}

	if ( ! fox_hc_v3_measurement_is_confirmed() ) {

		$errors->add(
			'fox_head_measurement_confirmation',
			'Potwierdź, że podałeś rzeczywisty pomiar głowy bez dodawania zapasu.',
			array(
				'id' => 'fox_head_measurement_confirmed',
			)
		);
	}
}


/* ==========================================================================
 * 5. ZAPIS W ZAMÓWIENIU
 * ========================================================================== */

/**
 * Zapisuje pomiar:
 *
 * 1. Jako pole zamówienia obwod_glowy.
 * 2. Jako potwierdzenie pomiaru.
 * 3. W standardowym polu uwag zamówienia.
 *
 * Pole uwag jest celowo uzupełniane, aby informacja była widoczna
 * także w systemach integrujących zamówienia, np. BaseLinker.
 */
add_action(
	'woocommerce_checkout_create_order',
	'fox_hc_v3_save_measurement_in_order',
	20,
	2
);

function fox_hc_v3_save_measurement_in_order( $order, $data ) {

	if (
		! $order instanceof WC_Order ||
		! fox_hc_v3_cart_has_helmet()
	) {
		return;
	}

	$measurement = fox_hc_v3_get_posted_measurement();

	if (
		'' === $measurement ||
		! is_numeric( $measurement )
	) {
		return;
	}

	$measurement = fox_hc_v3_format_measurement(
		$measurement
	);

	$measurement_with_unit = $measurement . ' cm';

	/*
	 * Czytelne, publiczne pola zamówienia.
	 * Bez znaku "_" na początku, aby nie były traktowane
	 * jako ukryte pola techniczne.
	 */
	$order->update_meta_data(
		'obwod_glowy',
		$measurement_with_unit
	);

	$order->update_meta_data(
		'pomiar_glowy_potwierdzony',
		'TAK — rzeczywisty pomiar bez dodawania zapasu'
	);

	/*
	 * Dodatkowa techniczna kopia wartości.
	 */
	$order->update_meta_data(
		'fox_head_circumference',
		$measurement_with_unit
	);

	/*
	 * Informacja zapisywana w standardowych uwagach zamówienia.
	 * BaseLinker powinien pobrać ją jako „Uwagi”.
	 */
	$helmet_note = sprintf(
		'Obwód głowy: %s.',
		$measurement_with_unit
	);

	$current_customer_note = trim(
		(string) $order->get_customer_note()
	);

	if ( '' !== $current_customer_note ) {

		$new_customer_note =
			$helmet_note .
			"\n\n" .
			$current_customer_note;

	} else {

		$new_customer_note = $helmet_note;
	}

	$order->set_customer_note( $new_customer_note );
}


/**
 * Dodaje wewnętrzną notatkę po utworzeniu zamówienia.
 */
add_action(
	'woocommerce_checkout_order_created',
	'fox_hc_v3_add_internal_order_note',
	20
);

function fox_hc_v3_add_internal_order_note( $order ) {

	if ( ! $order instanceof WC_Order ) {
		return;
	}

	$measurement = $order->get_meta(
		'obwod_glowy',
		true
	);

	if ( ! $measurement ) {
		return;
	}

	$order->add_order_note(
		sprintf(
			'DOUBLE-CHECK KASKU: klient podał obwód głowy %s. Sprawdź zgodność z wybranym rozmiarem przed realizacją.',
			$measurement
		)
	);
}


/* ==========================================================================
 * 6. WYŚWIETLENIE W PANELU WOOCOMMERCE
 * ========================================================================== */

/**
 * Pokazuje obwód głowy w danych zamówienia.
 */
add_action(
	'woocommerce_admin_order_data_after_billing_address',
	'fox_hc_v3_display_measurement_in_admin',
	10
);

function fox_hc_v3_display_measurement_in_admin( $order ) {

	if ( ! $order instanceof WC_Order ) {
		return;
	}

	$measurement = $order->get_meta(
		'obwod_glowy',
		true
	);

	$confirmation = $order->get_meta(
		'pomiar_glowy_potwierdzony',
		true
	);

	if ( ! $measurement ) {
		return;
	}

	?>

	<div
		style="
			margin-top: 14px;
			padding: 12px 14px;
			border-left: 4px solid #e63b21;
			background: #fff4f1;
		"
	>

		<p style="margin: 0 0 6px;">

			<strong>
				Obwód głowy do weryfikacji:
			</strong>

			<br>

			<span style="font-size: 20px; font-weight: 700;">
				<?php echo esc_html( $measurement ); ?>
			</span>

		</p>

		<?php if ( $confirmation ) : ?>

			<p style="margin: 0;">

				<strong>
					Potwierdzenie klienta:
				</strong>

				<br>

				<?php echo esc_html( $confirmation ); ?>

			</p>

		<?php endif; ?>

	</div>

	<?php
}


/* ==========================================================================
 * 7. WYŚWIETLENIE W E-MAILACH
 * ========================================================================== */

/**
 * Dodaje obwód głowy do wiadomości e-mail z zamówieniem.
 */
add_filter(
	'woocommerce_email_order_meta_fields',
	'fox_hc_v3_add_measurement_to_emails',
	20,
	3
);

function fox_hc_v3_add_measurement_to_emails(
	$fields,
	$sent_to_admin,
	$order
) {

	if ( ! $order instanceof WC_Order ) {
		return $fields;
	}

	$measurement = $order->get_meta(
		'obwod_glowy',
		true
	);

	if ( ! $measurement ) {
		return $fields;
	}

	$fields['fox_head_circumference'] = array(
		'label' => 'Rzeczywisty obwód głowy',
		'value' => $measurement,
	);

	return $fields;
}


/* ==========================================================================
 * 8. WYŚWIETLENIE NA STRONIE POTWIERDZENIA ZAMÓWIENIA
 * ========================================================================== */

/**
 * Pokazuje pomiar klientowi po złożeniu zamówienia.
 */
add_action(
	'woocommerce_order_details_after_customer_details',
	'fox_hc_v3_display_measurement_in_order_details',
	20
);

function fox_hc_v3_display_measurement_in_order_details( $order ) {

	if ( ! $order instanceof WC_Order ) {
		return;
	}

	$measurement = $order->get_meta(
		'obwod_glowy',
		true
	);

	if ( ! $measurement ) {
		return;
	}

	?>

	<section class="woocommerce-customer-details fox-order-head-size">

		<h2 class="woocommerce-column__title">
			Dopasowanie kasku
		</h2>

		<p>
			<strong>Podany obwód głowy:</strong>
			<?php echo esc_html( $measurement ); ?>
		</p>

		<p>
			Przed realizacją zamówienia porównamy pomiar
			z wybranym rozmiarem kasku.
		</p>

	</section>

	<?php
}
