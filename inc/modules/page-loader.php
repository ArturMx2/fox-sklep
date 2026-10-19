<?php
/**
 * Zaślepka ładowania: po kliknięciu w link do innej strony sklepu na środku ekranu pojawia się
 * głowa lisa, która „wypełnia się” od dołu, i napis „Ładujemy stronę…”.
 *
 * - pokazuje się dopiero, gdy nowa strona ładuje się dłużej niż ~0,3 s (szybkie przejścia bez mignięcia),
 * - nie reaguje na: nowe karty (Ctrl/Cmd, środkowy przycisk, target=_blank), kotwice #, tel:, mailto:,
 *   pobieranie plików, linki do innych domen, dodawanie do koszyka AJAX i wszystko, co obsługuje własny skrypt,
 * - znika po powrocie przyciskiem „Wstecz” i awaryjnie po 12 s,
 * - nie ładuje nic dodatkowego (logo jako SVG w kodzie strony, kilka linijek JS) – bez wpływu na PageSpeed.
 *
 * Wyłączenie / tekst: Dostosuj → FOX.SKLEP.PL → Ogólne.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_footer', function () {
	if ( ! fx_mod( 'loader_on' ) || is_customize_preview() ) {
		return;
	}
	$text = fx_mod( 'loader_text' );
	?>
	<div class="fx-loader" id="fx-loader" aria-hidden="true">
		<div class="fx-loader__box" role="status">
			<svg class="fx-loader__fox" viewBox="0 0 208 251" aria-hidden="true" focusable="false">
				<defs><path id="fx-loader-head" fill-rule="evenodd" d="M110.2 244.8 L97.8 244.8 L97.2 244.7 L96.9 244.2 L96.0 242.3 L94.7 238.0 L92.1 230.8 L91.6 228.8 L85.0 209.2 L80.7 195.8 L80.0 194.2 L71.8 168.7 L70.8 166.9 L70.2 166.7 L69.5 167.1 L67.3 169.8 L65.2 173.5 L64.1 176.2 L62.9 182.2 L62.6 185.8 L62.9 188.5 L62.6 189.0 L62.2 189.0 L60.6 187.2 L57.2 182.2 L53.2 175.0 L51.2 170.2 L50.6 167.8 L50.0 166.2 L48.7 159.2 L48.3 158.5 L47.5 158.5 L46.2 159.0 L44.4 160.5 L42.1 163.5 L41.0 165.4 L39.3 169.8 L38.3 175.2 L38.0 175.7 L37.5 175.9 L37.0 175.6 L34.2 172.1 L32.2 169.0 L29.0 162.4 L27.1 156.5 L26.2 151.2 L26.0 146.8 L25.8 146.2 L25.2 146.0 L24.8 146.1 L21.7 148.2 L18.2 151.8 L15.5 155.8 L14.5 156.3 L13.1 154.8 L11.2 151.2 L9.2 145.8 L8.0 141.8 L7.1 137.2 L6.1 129.5 L5.9 125.0 L6.1 119.5 L6.5 118.2 L7.2 113.0 L8.1 109.2 L8.7 107.8 L9.1 105.8 L11.0 101.0 L13.1 97.0 L15.1 93.5 L18.2 89.0 L22.2 84.2 L29.6 77.0 L30.1 76.2 L29.9 75.2 L29.1 73.5 L27.1 67.8 L25.1 60.5 L23.1 48.2 L22.9 39.5 L23.2 30.8 L23.9 25.2 L25.2 18.8 L27.1 12.0 L27.8 10.8 L29.0 7.0 L30.0 6.0 L31.5 6.0 L36.0 7.1 L42.7 9.2 L49.5 12.1 L57.6 16.2 L65.3 21.2 L70.5 25.1 L77.2 31.0 L84.5 38.5 L85.8 38.9 L122.9 38.8 L123.5 38.4 L126.1 35.5 L130.5 31.1 L139.7 23.2 L146.9 18.2 L150.2 16.2 L158.2 12.1 L168.2 8.1 L175.8 6.1 L177.2 5.9 L178.0 6.0 L178.6 6.5 L180.0 10.8 L180.7 12.0 L182.7 18.8 L184.7 30.2 L185.0 38.0 L184.8 46.0 L184.5 50.5 L182.6 61.2 L180.8 68.0 L178.7 73.8 L177.9 75.5 L177.8 76.2 L178.0 76.8 L182.5 80.9 L185.8 84.3 L189.6 89.0 L192.6 93.2 L194.7 96.8 L196.6 100.5 L198.5 105.2 L199.1 107.5 L199.7 108.8 L200.6 112.5 L201.7 119.2 L201.9 125.2 L201.7 130.0 L201.0 133.5 L200.7 137.5 L199.7 142.0 L199.1 143.8 L198.7 145.8 L196.5 151.4 L194.8 154.6 L193.8 156.0 L193.2 156.3 L192.4 155.8 L189.5 151.7 L187.2 149.2 L184.2 146.8 L183.0 146.2 L182.2 146.4 L180.7 156.8 L178.7 162.8 L176.8 166.9 L174.8 170.4 L172.8 173.3 L170.5 175.8 L170.0 175.8 L169.6 175.2 L168.6 169.8 L166.5 164.8 L164.8 162.1 L163.0 160.2 L161.2 158.8 L160.2 158.4 L159.7 158.5 L159.3 159.2 L157.8 166.8 L156.6 170.5 L154.6 175.2 L150.6 182.5 L147.3 187.2 L145.5 189.0 L145.2 188.9 L145.1 188.5 L145.2 185.5 L144.9 182.2 L143.7 176.0 L142.1 172.5 L140.5 169.8 L138.1 167.0 L137.5 166.7 L137.0 166.9 L136.0 169.0 L134.8 173.4 L134.1 174.8 L133.6 176.8 L130.2 186.8 L129.7 189.0 L129.0 190.3 L127.7 194.8 L125.1 202.0 L124.6 204.0 L124.0 205.2 L120.8 215.5 L120.1 217.0 L111.8 242.6 L110.9 244.2 L110.2 244.8ZM87.5 153.8 L87.9 152.5 L88.0 105.5 L87.6 101.8 L85.8 98.4 L82.5 95.9 L80.1 95.0 L76.5 94.0 L71.5 93.5 L67.8 93.5 L62.5 93.1 L55.5 93.1 L48.2 92.5 L45.8 91.8 L42.2 89.9 L40.5 88.5 L37.5 85.3 L36.5 85.7 L35.0 87.2 L34.0 88.8 L32.9 92.2 L32.9 95.0 L33.2 96.5 L34.2 98.9 L37.3 102.2 L39.5 103.8 L43.1 105.8 L45.8 106.8 L58.5 110.1 L62.2 112.0 L65.0 113.7 L67.2 114.4 L68.0 114.4 L69.5 113.6 L73.2 110.2 L74.0 109.9 L75.5 109.7 L77.0 109.9 L78.8 111.4 L80.6 114.5 L81.7 117.2 L82.6 119.8 L83.2 122.8 L83.8 124.2 L85.8 138.5 L86.3 152.8 L86.8 153.7 L87.5 153.8ZM121.0 153.8 L121.5 153.4 L121.7 152.8 L122.0 139.5 L124.0 125.2 L125.2 120.2 L126.0 117.8 L127.1 115.0 L129.0 111.8 L130.9 110.0 L132.2 109.8 L133.8 109.9 L134.5 110.2 L137.5 113.0 L139.2 114.3 L140.0 114.6 L141.0 114.6 L143.2 113.6 L145.8 112.0 L149.8 110.0 L156.5 108.2 L159.8 107.6 L164.8 105.8 L167.2 104.5 L170.3 102.5 L172.5 100.2 L173.7 98.8 L174.9 95.8 L175.2 93.8 L174.8 91.0 L173.5 88.1 L171.5 85.8 L170.8 85.6 L170.2 85.7 L168.2 88.1 L165.1 90.5 L162.7 91.8 L160.2 92.6 L152.2 93.5 L148.8 93.5 L146.8 93.7 L136.5 93.7 L132.0 94.1 L128.4 95.0 L125.2 96.2 L123.2 97.5 L121.3 99.5 L120.1 102.2 L119.8 105.8 L119.9 152.5 L120.2 153.6 L121.0 153.8Z"/></defs>
				<use href="#fx-loader-head" class="fx-loader__ghost"/>
				<use href="#fx-loader-head" class="fx-loader__fill"/>
			</svg>
			<?php if ( $text ) : ?><span class="fx-loader__txt"><?php echo esc_html( $text ); ?></span><?php endif; ?>
		</div>
	</div>
	<script>
	(function(){var L=document.getElementById('fx-loader');if(!L)return;var t,f,
	show=function(){clearTimeout(t);t=setTimeout(function(){L.classList.add('is-on');L.removeAttribute('aria-hidden')},300);clearTimeout(f);f=setTimeout(hide,12000)},
	hide=function(){clearTimeout(t);clearTimeout(f);L.classList.remove('is-on');L.setAttribute('aria-hidden','true')};
	window.addEventListener('click',function(e){
		if(e.defaultPrevented||e.button!==0||e.metaKey||e.ctrlKey||e.shiftKey||e.altKey)return;
		var a=e.target.closest&&e.target.closest('a[href]');if(!a)return;
		if(a.target&&a.target!=='_self'||a.hasAttribute('download')||a.closest('[data-no-loader],.ajax_add_to_cart,#wpadminbar'))return;
		var u;try{u=new URL(a.href,location.href)}catch(x){return}
		if(u.origin!==location.origin||!/^https?:$/.test(u.protocol))return;
		if(u.pathname===location.pathname&&u.search===location.search&&u.hash)return;
		if(/\/wp-admin\/|\.(pdf|jpe?g|png|webp|zip)$/i.test(u.pathname))return;
		show()});
	window.addEventListener('submit',function(e){if(!e.defaultPrevented&&!(e.target.closest&&e.target.closest('[data-no-loader]'))&&(!e.target.target||e.target.target==='_self'))show()});
	window.addEventListener('pageshow',hide);window.addEventListener('pagehide',function(){clearTimeout(f)});
	})();
	</script>
	<?php
}, 50 );
