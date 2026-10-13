<?php
/**
 * Moduł „Zbuduj komplet”.
 *
 * W edycji produktu (zakładka „Produkty podlinkowane”) wybierasz części kompletu,
 * np. do koszulki: spodnie i rękawice z tej samej grafiki. Moduł pokazuje się
 * na karcie produktu, który ma komplet, i na kartach jego części.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

/* ---------- Panel administracyjny ---------- */

add_action( 'woocommerce_product_options_related', function () {
	global $post;
	$ids = fx_kit_get_ids( $post->ID );
	?>
	<div class="options_group">
		<p class="form-field">
			<label for="fx_kit_ids">Zbuduj komplet – części</label>
			<select class="wc-product-search" multiple="multiple" style="width:50%;" id="fx_kit_ids" name="fx_kit_ids[]" data-placeholder="Wyszukaj produkt…" data-action="woocommerce_json_search_products" data-exclude="<?php echo (int) $post->ID; ?>">
				<?php
				foreach ( $ids as $id ) {
					$p = wc_get_product( $id );
					if ( $p ) {
						echo '<option value="' . (int) $id . '" selected="selected">' . esc_html( wp_strip_all_tags( $p->get_formatted_name() ) ) . '</option>';
					}
				}
				?>
			</select>
			<?php echo wc_help_tip( 'Produkty, które razem z tym tworzą komplet (np. spodnie i rękawice w tej samej grafice). Ten produkt jest dodawany automatycznie jako pierwszy. Moduł pokaże się też na kartach tych części.' ); // phpcs:ignore ?>
		</p>
		<?php
		woocommerce_wp_text_input(
			array(
				'id'          => '_fx_kit_title',
				'label'       => 'Komplet – tytuł',
				'placeholder' => 'Cała grafika jednym kliknięciem',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'          => '_fx_kit_img',
				'label'       => 'Komplet – adres zdjęcia',
				'placeholder' => 'https://… (zdjęcie całego kompletu na modelu)',
				'description' => 'Wklej adres pliku z biblioteki mediów. Puste = zdjęcie główne produktu.',
				'desc_tip'    => true,
			)
		);
		?>
	</div>
	<?php
} );

add_action( 'woocommerce_admin_process_product_object', function ( $product ) {
	// phpcs:disable WordPress.Security.NonceVerification -- WooCommerce weryfikuje nonce zapisu produktu.
	$ids = isset( $_POST['fx_kit_ids'] ) ? array_filter( array_map( 'absint', (array) wp_unslash( $_POST['fx_kit_ids'] ) ) ) : array();
	$ids = array_values( array_diff( array_unique( $ids ), array( $product->get_id() ) ) );
	if ( $ids ) {
		// Zapis ",12,34," pozwala szybko znaleźć komplet z poziomu jego części.
		$product->update_meta_data( '_fx_kit_ids', ',' . implode( ',', $ids ) . ',' );
	} else {
		$product->delete_meta_data( '_fx_kit_ids' );
	}
	if ( isset( $_POST['_fx_kit_title'] ) ) {
		$product->update_meta_data( '_fx_kit_title', sanitize_text_field( wp_unslash( $_POST['_fx_kit_title'] ) ) );
	}
	if ( isset( $_POST['_fx_kit_img'] ) ) {
		$product->update_meta_data( '_fx_kit_img', esc_url_raw( wp_unslash( $_POST['_fx_kit_img'] ) ) );
	}
	// phpcs:enable
} );

function fx_kit_get_ids( $product_id ) {
	$raw = get_post_meta( $product_id, '_fx_kit_ids', true );
	if ( ! $raw ) {
		return array();
	}
	return array_values( array_filter( array_map( 'absint', explode( ',', trim( $raw, ',' ) ) ) ) );
}

/**
 * Produkt-„gospodarz” kompletu: sam produkt albo pierwszy produkt, którego komplet zawiera ten produkt.
 */
function fx_kit_host( $product_id ) {
	if ( fx_kit_get_ids( $product_id ) ) {
		return $product_id;
	}
	$found = get_posts(
		array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'key'     => '_fx_kit_ids',
					'value'   => ',' . (int) $product_id . ',',
					'compare' => 'LIKE',
				),
			),
		)
	);
	return $found ? (int) $found[0] : 0;
}

/**
 * Opcje rozmiaru dla produktu (warianty dostępne w magazynie).
 */
function fx_kit_options( $p ) {
	$opts = array();
	if ( $p->is_type( 'variable' ) ) {
		foreach ( $p->get_children() as $vid ) {
			$v = wc_get_product( $vid );
			if ( ! $v || ! $v->exists() || ! $v->is_purchasable() || ! $v->is_in_stock() || ! $v->variation_is_visible() ) {
				continue;
			}
			$labels = array();
			$skip   = false;
			foreach ( $v->get_variation_attributes() as $attr => $value ) {
				if ( '' === $value ) { // Wariant „dowolny” – wymagałby osobnego wyboru, pomijamy.
					$skip = true;
					break;
				}
				$tax = str_replace( 'attribute_', '', $attr );
				if ( taxonomy_exists( $tax ) ) {
					$term     = get_term_by( 'slug', $value, $tax );
					$labels[] = $term ? $term->name : $value;
				} else {
					$labels[] = $value;
				}
			}
			if ( $skip ) {
				continue;
			}
			$opts[] = array(
				'id'    => $vid,
				'label' => implode( ' / ', $labels ),
				'price' => (float) wc_get_price_to_display( $v ),
				'sku'   => $v->get_sku(),
			);
		}
	} elseif ( $p->is_purchasable() && $p->is_in_stock() ) {
		$opts[] = array(
			'id'    => 0,
			'label' => '',
			'price' => (float) wc_get_price_to_display( $p ),
			'sku'   => $p->get_sku(),
		);
	}
	return $opts;
}

/* ---------- Sekcja na karcie produktu ---------- */

function fx_kit_section( $product ) {
	if ( ! $product ) {
		return;
	}
	$host_id = fx_kit_host( $product->get_id() );
	if ( ! $host_id ) {
		return;
	}
	$ids   = array_merge( array( $host_id ), fx_kit_get_ids( $host_id ) );
	$items = array();
	foreach ( array_unique( $ids ) as $id ) {
		$p = wc_get_product( $id );
		if ( ! $p || 'publish' !== $p->get_status() ) {
			continue;
		}
		$opts = fx_kit_options( $p );
		if ( ! $opts ) {
			continue;
		}
		$items[] = array( $p, $opts );
	}
	if ( count( $items ) < 2 ) {
		return;
	}

	$title = get_post_meta( $host_id, '_fx_kit_title', true );
	$title = $title ? $title : 'Cała grafika jednym kliknięciem';
	$img   = get_post_meta( $host_id, '_fx_kit_img', true );
	$host  = wc_get_product( $host_id );
	?>
	<section class="fx-kit" id="fx-kit" data-fx-kit>
		<div class="fx-kit__head">
			<div>
				<div class="fx-kicker">Zbuduj komplet</div>
				<h2><?php echo esc_html( $title ); ?></h2>
			</div>
			<p>Odznacz części, których nie potrzebujesz, i wybierz rozmiar każdej z osobna.</p>
		</div>
		<div class="fx-kit__grid">
			<div class="fx-kit__photo">
				<?php
				if ( $img ) {
					echo '<img class="fx-cover" src="' . esc_url( $img ) . '" alt="' . esc_attr( $title ) . '" loading="lazy">';
				} elseif ( $host && $host->get_image_id() ) {
					echo wp_get_attachment_image( $host->get_image_id(), 'large', false, array( 'class' => 'fx-cover', 'style' => 'object-fit:contain', 'loading' => 'lazy' ) );
				}
				?>
			</div>
			<div class="fx-kit__list">
				<?php foreach ( $items as $i => $row ) : ?>
					<?php
					list( $p, $opts ) = $row;
					$is_current       = ( $p->get_id() === $product->get_id() );
					$fid              = 'fx-kit-' . $p->get_id();
					$cats             = wc_get_product_terms( $p->get_id(), 'product_cat', array( 'fields' => 'names' ) );
					?>
					<div class="fx-kit__item" data-product-id="<?php echo (int) $p->get_id(); ?>" data-name="<?php echo esc_attr( $p->get_name() ); ?>" data-category="<?php echo esc_attr( $cats ? $cats[0] : '' ); ?>">
						<label class="fx-kit__pick" for="<?php echo esc_attr( $fid ); ?>-on">
							<input type="checkbox" id="<?php echo esc_attr( $fid ); ?>-on" checked>
							<span class="fx-kit__thumb"><?php echo $p->get_image( 'woocommerce_gallery_thumbnail' ); // phpcs:ignore ?></span>
							<span>
								<span class="fx-kit__name"><?php echo esc_html( $p->get_name() ); ?><?php echo $is_current ? ' <small style="color:var(--fx-muted)">(ten produkt)</small>' : ''; ?></span>
								<span class="fx-kit__price"><?php echo wp_kses_post( $p->get_price_html() ); ?></span>
							</span>
						</label>
						<?php if ( $p->is_type( 'variable' ) ) : ?>
							<div class="fx-kit__size">
								<label for="<?php echo esc_attr( $fid ); ?>-size">Rozmiar</label>
								<select id="<?php echo esc_attr( $fid ); ?>-size" data-fx-kit-size>
									<option value="" data-price="0">Wybierz</option>
									<?php foreach ( $opts as $o ) : ?>
										<option value="<?php echo (int) $o['id']; ?>" data-price="<?php echo esc_attr( $o['price'] ); ?>" data-sku="<?php echo esc_attr( $o['sku'] ); ?>"><?php echo esc_html( $o['label'] ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
						<?php else : ?>
							<input type="hidden" data-fx-kit-size value="0" data-price="<?php echo esc_attr( $opts[0]['price'] ); ?>" data-sku="<?php echo esc_attr( $opts[0]['sku'] ); ?>">
						<?php endif; ?>
					</div>
				<?php endforeach; ?>

				<div class="fx-kit__foot">
					<div>
						<div class="fx-kit__summary" data-fx-kit-summary></div>
						<div class="fx-kit__total" data-fx-kit-total aria-live="polite"></div>
					</div>
					<button type="button" class="fx-btn fx-btn--block" style="width:auto;padding:0 32px" data-fx-kit-add>Dodaj komplet</button>
				</div>
				<div class="fx-kit__msg" data-fx-kit-msg role="status" aria-live="polite"></div>
			</div>
		</div>
	</section>
	<?php
}

/* ---------- Dane dla skryptu ---------- */

add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_product() ) {
		return;
	}
	wp_localize_script(
		'fx-theme',
		'fxKit',
		array(
			'url'       => WC_AJAX::get_endpoint( 'fx_kit_add' ),
			'cartUrl'   => wc_get_cart_url(),
			'datalayer' => (bool) fx_mod( 'datalayer' ),
			'currency'  => get_woocommerce_currency(),
			'symbol'    => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
			'pos'       => get_option( 'woocommerce_currency_pos', 'right_space' ),
			'dec'       => wc_get_price_decimals(),
			'ds'        => wc_get_price_decimal_separator(),
			'ts'        => wc_get_price_thousand_separator(),
		)
	);
}, 20 );

/* ---------- Dodanie kompletu do koszyka ---------- */

add_action( 'wc_ajax_fx_kit_add', 'fx_kit_add' );
function fx_kit_add() {
	if ( null === WC()->cart && function_exists( 'wc_load_cart' ) ) {
		wc_load_cart();
	}
	// phpcs:ignore WordPress.Security.NonceVerification -- dodanie do koszyka, jak w WooCommerce, nie wymaga nonce (zgodność z cache stron).
	$raw   = isset( $_POST['items'] ) ? json_decode( wp_unslash( $_POST['items'] ), true ) : array();
	$items = is_array( $raw ) ? array_slice( $raw, 0, 10 ) : array();
	$added = array();

	foreach ( $items as $it ) {
		$pid = isset( $it['product_id'] ) ? absint( $it['product_id'] ) : 0;
		$vid = isset( $it['variation_id'] ) ? absint( $it['variation_id'] ) : 0;
		$p   = wc_get_product( $pid );
		if ( ! $p ) {
			continue;
		}
		$attrs = array();
		if ( $vid ) {
			$v = wc_get_product( $vid );
			if ( ! $v || $v->get_parent_id() !== $pid ) {
				continue;
			}
			$attrs = $v->get_variation_attributes();
			if ( in_array( '', $attrs, true ) ) {
				continue;
			}
		} elseif ( $p->is_type( 'variable' ) ) {
			continue;
		}
		$ok = apply_filters( 'woocommerce_add_to_cart_validation', true, $pid, 1, $vid, $attrs );
		if ( ! $ok ) {
			continue;
		}
		$key = WC()->cart->add_to_cart( $pid, 1, $vid, $attrs );
		if ( $key ) {
			do_action( 'woocommerce_ajax_added_to_cart', $pid );
			$line    = $vid ? wc_get_product( $vid ) : $p;
			$added[] = array(
				'item_id'      => $line->get_sku() ? $line->get_sku() : (string) $line->get_id(),
				'item_name'    => $p->get_name(),
				'item_variant' => $vid ? wc_get_formatted_variation( $line, true, false, false ) : '',
				'price'        => (float) wc_get_price_to_display( $line ),
				'quantity'     => 1,
			);
		}
	}

	$errors = array();
	foreach ( wc_get_notices( 'error' ) as $n ) {
		$errors[] = wp_strip_all_tags( is_array( $n ) ? $n['notice'] : $n );
	}
	wc_clear_notices();

	if ( ! $added ) {
		wp_send_json_error( array( 'message' => $errors ? implode( ' ', $errors ) : 'Nie udało się dodać kompletu. Sprawdź rozmiary i spróbuj ponownie.' ) );
	}

	ob_start();
	woocommerce_mini_cart();
	$mini = ob_get_clean();

	wp_send_json_success(
		array(
			'added'     => $added,
			'errors'    => $errors,
			'fragments' => apply_filters( 'woocommerce_add_to_cart_fragments', array( 'div.widget_shopping_cart_content' => '<div class="widget_shopping_cart_content">' . $mini . '</div>' ) ),
			'cart_hash' => WC()->cart->get_cart_hash(),
		)
	);
}
