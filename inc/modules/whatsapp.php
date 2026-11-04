<?php
/**
 * „Napisz do nas na WhatsApp” – dymek z okienkiem na stronie.
 *
 * Klient wybiera temat (dobór rozmiaru, dostępność, status zamówienia…), wpisuje pytanie i klika
 * „Otwórz WhatsApp” – otwiera się WhatsApp (telefon: aplikacja, komputer: WhatsApp Web / aplikacja)
 * z gotową wiadomością, do której dopisujemy produkt, SKU, wybrany rozmiar i link. Odpowiadacie
 * w WhatsApp Business jak zwykle. Bez zewnętrznych skryptów i bez ciasteczek.
 *
 * Ustawienia: Dostosuj → FOX.SKLEP.PL → WhatsApp. Bez numeru okienko się nie pokazuje.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

function fx_wa_defaults() {
	return array(
		'number'   => '',
		'title'    => 'Napisz do nas na WhatsApp',
		'sub'      => 'Doradzimy rozmiar, sprawdzimy dostępność i termin wysyłki.',
		'days'     => '1,2,3,4,5',
		'from'     => '09:00',
		'to'       => '17:00',
		'online'   => 'Jesteśmy online – odpisujemy zwykle w kilka minut',
		'offline'  => 'Odpiszemy w najbliższy dzień roboczy od {od}',
		'topics'   => "Dobór rozmiaru\nDostępność i termin wysyłki\nStatus zamówienia\nInne pytanie",
		'teaser'   => 'Masz pytanie o rozmiar? Napisz do nas',
		'teaser_s' => 30,
		'checkout' => false,
	);
}

function fx_wa( $k ) {
	$d = fx_wa_defaults();
	return get_theme_mod( 'fx_wa_' . $k, $d[ $k ] ?? '' );
}

/** Numer w formacie wa.me: same cyfry z kierunkowym (9 cyfr = Polska, dopisujemy 48). */
function fx_wa_number() {
	$n = preg_replace( '/\D/', '', (string) fx_wa( 'number' ) );
	if ( 9 === strlen( $n ) ) {
		$n = '48' . $n;
	}
	return strlen( $n ) >= 10 ? $n : '';
}

add_action( 'customize_register', function ( $wp ) {
	$wp->add_section(
		'fx_wa',
		array(
			'title'       => 'WhatsApp',
			'panel'       => 'fx_panel',
			'description' => 'Dymek „Napisz do nas na WhatsApp” w prawym dolnym rogu. Bez numeru się nie pokazuje. Klient pisze wiadomość u nas, a wysyła ją w swoim WhatsAppie – Wy odpowiadacie w WhatsApp Business.',
		)
	);
	$fields = array(
		'number'   => array( 'text', 'Numer WhatsApp Business (np. +48 600 000 000)' ),
		'title'    => array( 'text', 'Nagłówek okienka' ),
		'sub'      => array( 'textarea', 'Tekst pod nagłówkiem' ),
		'days'     => array( 'text', 'Dni, w które odpisujecie (1 = poniedziałek … 7 = niedziela, po przecinku)' ),
		'from'     => array( 'text', 'Od godziny (np. 09:00)' ),
		'to'       => array( 'text', 'Do godziny (np. 17:00)' ),
		'online'   => array( 'text', 'Tekst w godzinach pracy' ),
		'offline'  => array( 'text', 'Tekst poza godzinami ({od} = godzina rozpoczęcia)' ),
		'topics'   => array( 'textarea', 'Tematy do wyboru (jedna linia = jeden temat)' ),
		'teaser'   => array( 'text', 'Dymek-zachęta na karcie produktu (puste = bez zachęty)' ),
		'teaser_s' => array( 'number', 'Po ilu sekundach pokazać zachętę (raz na wizytę)' ),
		'checkout' => array( 'checkbox', 'Pokazuj też na stronie zamówienia (domyślnie ukryty, żeby nie rozpraszać przy płatności)' ),
	);
	$d      = fx_wa_defaults();
	foreach ( $fields as $k => $f ) {
		$san = 'checkbox' === $f[0] ? 'fx_sanitize_bool' : ( 'number' === $f[0] ? 'absint' : ( 'textarea' === $f[0] ? 'sanitize_textarea_field' : 'sanitize_text_field' ) );
		$wp->add_setting( 'fx_wa_' . $k, array( 'default' => $d[ $k ], 'sanitize_callback' => $san ) );
		$wp->add_control( 'fx_wa_' . $k, array( 'section' => 'fx_wa', 'label' => $f[1], 'type' => $f[0] ) );
	}
}, 40 );

/** Czy pokazać dymek na tej stronie. */
function fx_wa_show() {
	if ( is_admin() || ! fx_wa_number() ) {
		return false;
	}
	if ( function_exists( 'is_checkout' ) && is_checkout() && ! is_order_received_page() && ! fx_wa( 'checkout' ) ) {
		return false;
	}
	return (bool) apply_filters( 'fx_wa_show', true );
}

add_action( 'wp_footer', function () {
	if ( ! fx_wa_show() ) {
		return;
	}
	$topics = array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) fx_wa( 'topics' ) ) ) ) );
	// Kontekst strony – dopisywany do wiadomości.
	$ctx = array( 'type' => 'page', 'url' => home_url( add_query_arg( array() ) ) );
	if ( function_exists( 'is_product' ) && is_product() ) {
		$p = wc_get_product( get_queried_object_id() );
		if ( $p ) {
			$ctx = array( 'type' => 'product', 'name' => $p->get_name(), 'sku' => $p->get_sku(), 'url' => get_permalink( $p->get_id() ) );
		}
	} elseif ( function_exists( 'is_cart' ) && is_cart() && WC()->cart ) {
		$ctx = array( 'type' => 'cart', 'items' => array() );
		foreach ( WC()->cart->get_cart() as $it ) {
			$pr                = $it['data'];
			$ctx['items'][]    = $pr->get_name() . ( $pr->get_sku() ? ' (' . $pr->get_sku() . ')' : '' ) . ' × ' . (int) $it['quantity'];
		}
	} elseif ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
		$ctx = array( 'type' => 'order', 'order' => absint( get_query_var( 'order-received' ) ) );
	}
	$days = array_values( array_filter( array_map( 'absint', explode( ',', (string) fx_wa( 'days' ) ) ) ) );
	$cfg  = array(
		'n'       => fx_wa_number(),
		'days'    => $days,
		'from'    => (string) fx_wa( 'from' ),
		'to'      => (string) fx_wa( 'to' ),
		'online'  => (string) fx_wa( 'online' ),
		'offline' => str_replace( '{od}', (string) fx_wa( 'from' ), (string) fx_wa( 'offline' ) ),
		'hol'     => function_exists( 'fx_ship_cta_conf' ) ? array_values( (array) fx_ship_cta_conf()['blocked'] ) : array(),
		'teaser'  => 'product' === $ctx['type'] ? (string) fx_wa( 'teaser' ) : '',
		'teaserS' => max( 5, (int) fx_wa( 'teaser_s' ) ),
		'ctx'     => $ctx,
	);
	?>
	<div class="fx-wa" id="fx-wa" data-state="closed">
		<div class="fx-wa__panel" id="fx-wa-panel" role="dialog" aria-modal="false" aria-labelledby="fx-wa-title" hidden>
			<div class="fx-wa__head">
				<div>
					<p class="fx-wa__title" id="fx-wa-title"><?php echo esc_html( fx_wa( 'title' ) ); ?></p>
					<p class="fx-wa__status"><span class="fx-wa__dot" aria-hidden="true"></span><span data-fx-wa-status></span></p>
				</div>
				<button type="button" class="fx-wa__x" data-fx-wa-close aria-label="Zamknij">×</button>
			</div>
			<div class="fx-wa__body">
				<?php if ( fx_wa( 'sub' ) ) : ?><p class="fx-wa__sub"><?php echo esc_html( fx_wa( 'sub' ) ); ?></p><?php endif; ?>
				<?php if ( $topics ) : ?>
					<div class="fx-wa__topics" role="radiogroup" aria-label="Temat">
						<?php foreach ( $topics as $i => $t ) : ?>
							<button type="button" class="fx-wa__topic" role="radio" aria-checked="<?php echo 0 === $i ? 'true' : 'false'; ?>" data-topic="<?php echo esc_attr( $t ); ?>"><?php echo esc_html( $t ); ?></button>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
				<label class="screen-reader-text" for="fx-wa-msg">Twoja wiadomość</label>
				<textarea id="fx-wa-msg" class="fx-wa__msg" rows="3" placeholder="Twoje pytanie (opcjonalnie)…"></textarea>
				<p class="fx-wa__ctx" data-fx-wa-ctx hidden></p>
				<button type="button" class="fx-wa__send" data-fx-wa-send>
					<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 12a8.5 8.5 0 0 1-12.6 7.4L3 21l1.6-5.2A8.5 8.5 0 1 1 21 12z"/></svg>
					Otwórz WhatsApp
				</button>
				<p class="fx-wa__note">Otworzy się WhatsApp z gotową wiadomością – wystarczy ją wysłać.</p>
			</div>
		</div>
		<p class="fx-wa__teaser" data-fx-wa-teaser hidden><button type="button" class="fx-wa__teaser-x" data-fx-wa-teaser-close aria-label="Zamknij">×</button><span></span></p>
		<button type="button" class="fx-wa__btn" data-fx-wa-toggle aria-controls="fx-wa-panel" aria-expanded="false" aria-label="Napisz do nas na WhatsApp">
			<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M21 12a8.5 8.5 0 0 1-12.6 7.4L3 21l1.6-5.2A8.5 8.5 0 1 1 21 12z"/><path d="M8.5 10.5h7M8.5 13.5h4.5"/></svg>
			<span class="fx-wa__online" aria-hidden="true"></span>
		</button>
	</div>
	<script>
	(function(){var C=<?php echo wp_json_encode( $cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?>,w=document.getElementById('fx-wa');if(!w)return;
	var panel=w.querySelector('.fx-wa__panel'),tog=w.querySelector('[data-fx-wa-toggle]'),msg=w.querySelector('#fx-wa-msg'),ctxEl=w.querySelector('[data-fx-wa-ctx]'),teaser=w.querySelector('[data-fx-wa-teaser]'),variation=null;
	function two(v){return(v<10?'0':'')+v}
	function now(){try{var p={};new Intl.DateTimeFormat('en-GB',{timeZone:'Europe/Warsaw',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',hourCycle:'h23',weekday:'short'}).formatToParts(new Date()).forEach(function(x){p[x.type]=x.value});
		return{date:p.year+'-'+p.month+'-'+p.day,dow:['Mon','Tue','Wed','Thu','Fri','Sat','Sun'].indexOf(p.weekday)+1,min:(+p.hour)*60+(+p.minute)}}catch(e){var d=new Date();return{date:d.getFullYear()+'-'+two(d.getMonth()+1)+'-'+two(d.getDate()),dow:d.getDay()||7,min:d.getHours()*60+d.getMinutes()}}}
	function mins(t){var a=String(t||'').split(':');return(+a[0]||0)*60+(+a[1]||0)}
	function online(){var n=now();return C.days.indexOf(n.dow)>=0&&C.hol.indexOf(n.date)<0&&n.min>=mins(C.from)&&n.min<mins(C.to)}
	function status(){var on=online();w.classList.toggle('is-online',on);w.querySelector('[data-fx-wa-status]').textContent=on?C.online:C.offline}
	status();setInterval(status,60000);
	// Na telefonie dymek nad paskiem koszyka („Zamawiam”).
	function lift(){var b=document.querySelector('.fx-mbar');var vis=b&&getComputedStyle(b).display!=='none';w.classList.toggle('is-lift',!!vis)}
	lift();window.addEventListener('resize',lift);document.body.addEventListener('wc_fragments_refreshed',lift);document.body.addEventListener('wc_fragments_loaded',lift);setTimeout(lift,1500);
	if(window.jQuery){jQuery(document.body).on('wc_fragments_refreshed wc_fragments_loaded added_to_cart',lift);jQuery('form.variations_form').on('found_variation',function(e,v){variation=v}).on('reset_data',function(){variation=null})}
	function size(){var out=[];document.querySelectorAll('form.variations_form select[name^="attribute_"]').forEach(function(s){if(s.value){var o=s.options[s.selectedIndex];out.push(o?o.text.trim():s.value)}});return out.join(', ')}
	function ctxText(){var c=C.ctx,t='';
		if(c.type==='product'){var sku=variation&&variation.sku?variation.sku:c.sku,sz=size();t='Produkt: '+c.name+(sku?' (SKU: '+sku+')':'')+(sz?'\nRozmiar: '+sz:'')+'\n'+c.url}
		else if(c.type==='cart'&&c.items&&c.items.length){t='Mój koszyk:\n'+c.items.join('\n')}
		else if(c.type==='order'&&c.order){t='Zamówienie nr '+c.order}
		else{t='Strona: '+location.href}
		return t}
	function topic(){var b=w.querySelector('.fx-wa__topic[aria-checked="true"]');return b?b.dataset.topic:''}
	function preview(){var t=ctxText();ctxEl.hidden=!t;ctxEl.textContent=t?'Dołączymy: '+t.split('\n')[0]+(/Rozmiar:/.test(t)?' · '+t.match(/Rozmiar: (.*)/)[1]:''):''}
	function open(){panel.hidden=false;w.dataset.state='open';tog.setAttribute('aria-expanded','true');hideTeaser(true);preview();setTimeout(function(){msg.focus()},50);track('whatsapp_open')}
	function close(){panel.hidden=true;w.dataset.state='closed';tog.setAttribute('aria-expanded','false');tog.focus()}
	function track(ev,extra){try{window.dataLayer=window.dataLayer||[];var d={event:ev,wa_page:C.ctx.type};for(var k in extra)d[k]=extra[k];window.dataLayer.push(d)}catch(e){}}
	tog.addEventListener('click',function(){panel.hidden?open():close()});
	w.querySelector('[data-fx-wa-close]').addEventListener('click',close);
	document.addEventListener('keydown',function(e){if(e.key==='Escape'&&!panel.hidden)close()});
	w.querySelectorAll('.fx-wa__topic').forEach(function(b){b.addEventListener('click',function(){w.querySelectorAll('.fx-wa__topic').forEach(function(x){x.setAttribute('aria-checked',x===b?'true':'false')});
		msg.placeholder=/status|zamówien/i.test(b.dataset.topic)?'Podaj numer zamówienia i pytanie…':'Twoje pytanie (opcjonalnie)…';msg.focus()})});
	w.querySelector('[data-fx-wa-send]').addEventListener('click',function(){var tp=topic(),q=msg.value.trim(),c=ctxText();
		var text='Dzień dobry!'+(tp?' Temat: '+tp+'.':'')+(q?'\n'+q:'')+(c?'\n\n'+c:'');
		track('whatsapp_click',{wa_topic:tp});
		window.open('https://wa.me/'+C.n+'?text='+encodeURIComponent(text),'_blank','noopener')});
	// Zachęta na karcie produktu: raz na wizytę, po X sekundach, nie gdy okienko otwarte.
	function hideTeaser(perm){teaser.hidden=true;if(perm){try{sessionStorage.setItem('fxwaT','1')}catch(e){}}}
	if(C.teaser){var seen='';try{seen=sessionStorage.getItem('fxwaT')}catch(e){}
		if(!seen)setTimeout(function(){if(!panel.hidden)return;teaser.querySelector('span').textContent=C.teaser;teaser.hidden=false;track('whatsapp_teaser')},C.teaserS*1000);
		teaser.querySelector('span').addEventListener('click',open);teaser.querySelector('[data-fx-wa-teaser-close]').addEventListener('click',function(){hideTeaser(true)})}
	})();
	</script>
	<?php
}, 70 );
