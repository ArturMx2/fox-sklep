# Zmiany w motywie FOX.SKLEP.PL

## 3.8.5
- Import tabel rozmiarów: przy tabelach bez rozpoznanych przypisań „Brak przypisań – szczegóły” (pola tabeli i wpisy w ustawieniach CommerceKit), żeby dopasować import.
- Wtyczka podglądu 1.2.0: zaimportowane tabele widać w Produkty → „Tabele rozmiarów (nowy motyw)” także przed włączeniem motywu (wcześniej „Nieprawidłowy typ treści”).

## 3.8.4
- Import tabel rozmiarów dostępny także przed włączeniem motywu – we wtyczce „FOX – podgląd motywu” 1.1.0 (Narzędzia → Import tabel rozmiarów). Motyw nie dubluje narzędzia, gdy wtyczka jest włączona.

## 3.8.3 – poprawki po porównaniu SEO na fox.sklep.pl
- Dane produktu dla Google (Product, cena, dostępność) zawsze na stronie produktu – w podglądzie na fox.sklep.pl ginęły na części produktów.
- „Jedna karta na model” (kolory) domyślnie wyłączona: lista kategorii ma te same produkty i te same strony /page/N/ co obecny sklep.
- Strona listy poza zakresem (/page/N/ większa niż liczba stron) → 301 do ostatniej strony zamiast 404.

## 3.8.2 – porównanie SEO na działającym sklepie
- Nowa wtyczka „FOX – podgląd motywu i porównanie SEO” (tools/fox-podglad, osobny zip): nowy motyw widoczny na fox.sklep.pl tylko z kluczem podglądu (klienci i Google widzą obecny motyw, podgląd ma noindex i omija cache), a Narzędzia → Porównanie SEO porównuje obie wersje tych samych adresów jako gość. Lista adresów: 71 stron z największym ruchem w Search Console.
- Porównanie SEO w motywie korzysta z trybu „ten sam sklep”, gdy wtyczka jest włączona; sprawdza też, czy podgląd nie został podany z cache.

## 3.8.1 – SEO 1:1 ze sklepem fox.sklep.pl
- Tabele rozmiarów mają własne strony pod starymi adresami CommerceKit: /ckit_size_guide/nazwa/ (40 adresów z ruchem w Google). Import tabel zachowuje adres oraz tytuł i opis SEO z Yoast. Gdy CommerceKit jest włączony, adresy dalej obsługuje on. Stary adres tabeli, której nie ma w motywie → 301 do strony „Tabela rozmiarów”.
- Opis kategorii bez dolnego opisu zostaje w całości nad produktami (jak w obecnym sklepie) – motyw nie przenosi już części tekstu pod listę.
- Produktów na stronę: domyślnie 12, jak w obecnym sklepie (Dostosuj → Narzędzia sklepu).

## 3.8.0 – porównanie SEO przed startem
- Narzędzia → „Porównanie SEO”: dla listy adresów pobiera tę samą podstronę ze starego sklepu i ze strony z nowym motywem i porównuje kod HTTP, tytuł, opis, canonical, robots, H1, nagłówki H2/H3 (które zniknęły, które doszły), ile zdań tekstu ze starej strony jest na nowej, dane strukturalne, okruszki, produkty linkowane z listy, linki w treści i obrazki bez alt. Wynik w tabeli + raport CSV. Nic nie zmienia w sklepie.
- Produktów na stronę w kategoriach tyle co w starym motywie (wiersze × kolumny z ustawień WooCommerce), mimo 3 kolumn w nowym układzie – /page/2/, /page/3/ pokazują te same produkty co dotąd. Można zmienić w Dostosuj → Narzędzia sklepu.
- Kropki kolorów na liście produktów mają opis (alt) „Nazwa produktu – kolor”.

## 3.7.0 – zarządzanie stroną główną w Dostosuj
- Każda sekcja strony głównej ma „Pokaż sekcję”: baner (pojedynczy), Moto / Rower, H1 i wstęp, kategorie Moto / Rower / Casual (osobno), produkty, Odkrywaj, duże kafle, dyscypliny, zawodnik, poradniki, najczęstsze pytania, sklep stacjonarny, „O sklepie”.
- Liczba kafli do ustawienia: Odkrywaj 1–12, duże kafle 1–8, dyscypliny 1–8. Po zmianie liczby od razu pojawiają się pola kolejnych kafli (zbędne są ukryte). Nieparzysty ostatni duży kafel rozciąga się na całą szerokość.
- Wyłączony H1 i wstęp: H1 zostaje w kodzie strony (niewidoczny), żeby nie stracić go dla Google.

## 3.6.3 – czytelne nazwy rozmiarów
- Rozmiar „OS” wyświetlany jako „Uniwersalny”, „YOS” jako „Junior” – na karcie produktu (przyciski rozmiarów), w podglądzie rozmiarów na listingu, w filtrze, w kompletach i w koszyku.
- Lista do edycji w Narzędziach sklepu („OS = Uniwersalny”, jedna para na linię). Tylko wyświetlanie – w produktach, zamówieniach i BaseLinkerze zostają oryginalne wartości.

## 3.6.2
- Etykiety z nazwy – reguły startowe: Dziecięce (kids, dziecięce), Juniorskie (junior, youth, młodzieżowe), Damskie (lady, women, wmn, damskie). Do zmiany lub wyłączenia w WooCommerce → Etykiety z nazwy.

## 3.6.1 – etykiety z nazwy produktu
- WooCommerce → Etykiety z nazwy: reguły „nazwa zawiera: lady|women|damsk → etykieta Damskie, kolor” (jak „Dynamiczne naklejki” z enduro.sklep.pl). Nic nie zapisuje się w produktach.
- Etykiety w stylu motywu (pigułki na zdjęciu, obok promocji i nowości), na listingu i karcie produktu; kolor tekstu (biały/czarny) dobierany automatycznie do tła. Tryb: pierwsza pasująca albo wszystkie (razem najwyżej 4 etykiety).
- Reguły ze starej wtyczki przejmowane automatycznie (przyda się na enduro.sklep.pl).

## 3.6.0 – komplety jak Fox UK, ale z koszykiem
- „Co jest w zestawie?”: duże kafle części (zdjęcie, typ części – Koszulka/Spodnie/Kask…, nazwa, cena z przekreśloną regularną), 3 w rzędzie na stronie kompletu, 2 na telefonie. Etykieta „Do −X% w zestawie”.
- Rozmiar wybierany przyciskami prosto w kaflu (tylko dostępne rozmiary); produkty w jednym rozmiarze bez wyboru. Kółko ✓ na zdjęciu dodaje/odejmuje część.
- Dół: „Razem” z przekreśloną ceną, „Oszczędzasz X zł · w tym −5% za zestaw” (albo podpowiedź, że rabat jest za wszystkie części), przycisk „Dodaj zestaw do koszyka”. Brak rozmiaru → „Wybierz rozmiar: Kask”, przewinięcie do kafla.
- Na telefonie pasek z sumą i przyciskiem przyklejony do dołu ekranu. Strona kompletu ma szerszą kolumnę z kaflami. To samo na kartach części (sekcja „Zbuduj komplet”).

## 3.5.3 – pole „Komplet (zestaw)” w edycji produktu
- Osobne pole „Komplet (zestaw)” pod opisem produktu (zamiast ukrytego w zakładce „Produkty podlinkowane”): przełącznik „To jest strona kompletu”, wybór części (wyszukiwarka po nazwie i SKU, kolejność = kolejność wyświetlania), podgląd „Teraz w komplecie”, tytuł sekcji, zdjęcie całego kompletu.
- Strona kompletu może mieć dowolną nazwę (przełącznik); stare komplety ze Smart Composite są zaznaczone automatycznie – wystarczy wybrać części na nowo i zapisać.

## 3.5.2 – sortowanie „Największa przecena”
- Nowa pozycja w sortowaniu: „Największa przecena” – najpierw produkty z najwyższym % rabatu (dla wariantowych: najwyższy rabat spośród wariantów na stanie), potem pozostałe od najnowszych. Działa z filtrami, Promocjami i doczytywaniem produktów.
- % rabatu liczony przy zapisie produktu i raz dziennie dla całego sklepu (także końce promocji z harmonogramu).

## 3.5.1 – kolory rozpoznawane z nazwy
- Próbka koloru ustawia się sama z nazwy wartości atrybutu koloru (polskie i angielskie nazwy, odcienie: granatowy/navy, grafitowy, fluo yellow/orange/red, khaki, oliwkowy, bordowy, camo…).
- Dwa kolory jako próbka pół na pół: „Czarno-biały”, „Black/White”, „Red & Black”, „Grey Yellow”.
- Działa w filtrze koloru i w przyciskach wariantów na karcie produktu. Ręcznie wpisany kolor ma pierwszeństwo; w panelu atrybutu widać, jaki kolor został rozpoznany.

## 3.5.0 – filtry w kolumnie po lewej
- Filtry (Tylko promocje, Rozmiar, Kolor) w kolumnie po lewej stronie listy produktów, przyklejone przy przewijaniu; produkty nadal w 3 kolumnach. Kafelki podkategorii zostają na górze.
- Rozmiary jako przyciski, kolory jako lista z próbką koloru i nazwą. Liczba wybranych przy nagłówku grupy, „Wyczyść wszystkie filtry”.
- Nad produktami wybrane filtry do szybkiego usunięcia (×).
- Telefon i tablet: przycisk „Filtry (2)” otwiera panel z lewej.
- Gdy filtr nic nie znajdzie, kolumna filtrów zostaje (można zmienić wybór).

## 3.4.0 – nowy koszyk
- Kroki zamówienia: Koszyk → Dane, dostawa i płatność → Potwierdzenie (koszyk, zamówienie, strona podziękowania).
- Układ: lista produktów po lewej, podsumowanie po prawej (przyklejone przy przewijaniu); na telefonie pozycje jako karty.
- Pozycje: nazwa modelu + „Rozmiar: M · Kod: 31372-005”, przekreślona cena regularna przy promocji, przyciski − / + przy ilości, koszyk przelicza się sam (bez „Zaktualizuj koszyk”).
- Pasek „Brakuje Ci X zł do darmowej dostawy” (próg w Narzędziach sklepu, domyślnie 300 zł).
- Podsumowanie: „Przejdź do zamówienia”, „Kontynuuj zakupy” (wraca do ostatnio oglądanej listy), atuty (dostawa, zwroty, płatności).
- Pole kuponu w normalnym rozmiarze; produkty polecane pod koszykiem w 3 kolumnach.

## 3.3.4
- Kod pocztowy sklepu stacjonarnego (55-080) w danych dla Google i w sekcji sklepu na stronie głównej.

## 3.3.3 – telefon: strona nie ucieka na boki
- Naprawione: tabela wyboru wariantów na karcie produktu była szersza niż ekran telefonu (cała strona przesuwała się w bok i po oddaleniu nie wypełniała szerokości). Dodatkowo zabezpieczenie: nic nie może poszerzyć strony poza ekran.
- Polska odmiana: „5 kolorów”, „3 produkty”, „12 komentarzy” (wcześniej „5 kolory”, „3 produktów”).

## 3.3.2 – stare adresy filtrów
- Stare linki filtrów z YITH / WooCommerce (np. /sklep/?product_cat=buty-rowerowe-fox-union&filter_blvariant=37) przekierowują 301 na kategorię z nowym filtrem (?rozmiar=37). Działa dla rozmiaru, koloru i promocji; sortowanie zostaje.

## 3.3.1 – filtry rozmiaru, wstęp kategorii bez zwijania
- Filtry przepisane na własny mechanizm (bez tabeli „lookup” WooCommerce, której import z BaseLinkera nie uzupełnia – stąd pusta lista po wyborze rozmiaru). Rozmiar pasuje, gdy produkt ma wariant w tym rozmiarze NA STANIE; działa też, gdy wariant ma zapisaną nazwę zamiast slugu (np. „M”).
- Kilka atrybutów rozmiaru naraz (np. „wariant” dla odzieży i osobny dla butów) – wykrywane po nazwie: rozmiar / size / blvariant / wielkość.
- Rozmiary w filtrze tylko dostępne w kategorii, w kolejności: dziecięce, S–4XL, potem liczbowe rosnąco.
- Nowe adresy: ?rozmiar=m,l&kolor=czarny&promocja=1 (noindex, także w Yoast).
- Wstęp kategorii nad produktami pokazywany w całości (bez „Pokaż więcej”).

## 3.3.0 – strona główna pod SEO (dane z Search Console)
- H1 na górze strony („Sklep FOX Racing – sprzęt na crossa, enduro i MTB”) + wstęp z frazami „sklep crossowy, enduro i rowerowy”, „odzież MTB”, atuty (wysyłka 48 h, darmowa dostawa, zwroty, sklep pod Wrocławiem).
- Kafle kategorii z nazwami-frazami: „Sklep crossowy i enduro” (kaski Fox na crossa i enduro, stroje na crossa Fox, buty crossowe, zbroje…), „Odzież rowerowa i kaski MTB”, „Odzież FOX na co dzień”. Zdjęcie z kategorii albo z jej najnowszego produktu, liczba produktów, kategorie nieistniejące pomijane.
- Produkty: „Nowości na crossa i enduro” / „Nowości rowerowe Fox MTB”. Nazwy produktów na kartach jako H3 (sekcje H2).
- Najczęstsze pytania (7, z linkami do kategorii) + dane strukturalne FAQPage.
- Sklep stacjonarny: adres, godziny, telefon + dane strukturalne SportingGoodsStore (lokalne wyniki, Mapy).
- Tekst „O sklepie” na dole (H2 + H3, linki do kategorii, zwinięty pod „Czytaj więcej”).
- Tytuł i opis strony głównej w Google z Dostosuj (nadpisują Yoast/Rank Math tylko na stronie głównej; puste = jak dotąd).
- Wszystkie teksty edytowalne: Dostosuj → FOX.SKLEP.PL → Strona główna – H1 i tekst SEO / kategorie / najczęstsze pytania / sklep stacjonarny.

## 3.2.0 – przełącznik Moto / Rower
- Strona główna: baner podzielony na MOTO i ROWER (na telefonie obok siebie, cała połowa klikalna) + przełącznik „Pokazuję sprzęt: Moto | Rower”.
- Wybór przestawia sekcje bez przeładowania: produkty (z kategorii moto albo rowerowej), kafle „Odkrywaj” i duże kafle (ustawienie „pokazuj dla” przy każdym), poradniki (osobna kategoria wpisów dla każdego świata).
- Wybór zapamiętany w przeglądarce; ustawia się też sam, gdy klient ogląda kategorie albo produkty moto lub rowerowe. Bez migania strony, działa z cache.
- SEO: obie wersje sekcji są w HTML strony głównej; kategorie i adresy bez zmian. Zdarzenie fx_world w dataLayer (GA4).
- Ustawienia: Dostosuj → FOX.SKLEP.PL → Strona główna – Moto / Rower (zdjęcia, teksty, kategorie, domyślny świat). Wyłączenie przywraca pojedynczy baner.

## 3.1.1 – bezpieczeństwo
- Formularz „Powiadom o dostępności”: limit 5 zapisów na 10 minut z jednego IP (ochrona przed zalewaniem bazy i wysyłką na cudze adresy).
- Wzmocnienia (Narzędzia sklepu, domyślnie włączone): ukryta wersja WordPressa, blokada wyciągania loginów (?author=1, /wp-json/wp/v2/users i oEmbed dla niezalogowanych), nagłówki bezpieczeństwa (nosniff, X-Frame-Options, Referrer-Policy, Permissions-Policy). REST WooCommerce / BaseLinker bez zmian.

## 3.1.0 – tabele rozmiarów: produkty + import z CommerceKit
- Tabela rozmiarów przypisywana do kategorii (z podkategoriami) i teraz także do konkretnych produktów – pole „Konkretne produkty” na ekranie tabeli (wyszukiwarka produktów). Kolejność: ustawienie w produkcie → tabela przypisana do produktu → najbliższa kategoria.
- Narzędzia → Import tabel rozmiarów: przenosi tabele z CommerceKit (treść, kategorie, produkty). Podgląd przed importem, oryginały nietknięte, ponowny import aktualizuje bez duplikatów. Działa też po wyłączeniu CommerceKit.

## 3.0.0 – komplety bez wtyczki
- Komplety wykrywane automatycznie po nazwie: koszulka, spodnie i rękawice z tą samą grafiką i kolorem (np. „… FOX 180 FRAME RED”) tworzą komplet. Rok, ukośniki i wielkość liter nie mają znaczenia; JUNIOR/KIDS/LADY osobno.
- Strony kompletów („Strój na crossa FOX …”) zostają pod tymi samymi adresami: zamiast „Dodaj do koszyka” wybór części i rozmiarów, cena „Komplet od …”. Do koszyka trafiają osobne produkty (stany i BaseLinker bez zmian).
- Stare komplety ze Smart Composite: części odczytywane z zapisanych danych wtyczki (po jej wyłączeniu też).
- Rabat za komplet (domyślnie 5%, Narzędzia sklepu): gdy w koszyku są wszystkie części, ich ceny spadają; w koszyku przekreślona cena, „w komplecie −5%” i wiersz „Rabat za komplet”.
- Na kartach części sekcja „Zbuduj komplet” z linkiem „Zobacz cały komplet”. Ręczny wybór części w produkcie nadal działa i ma pierwszeństwo; w panelu widać, co wykryto automatycznie.

## 2.9.0 – kolory modelu w listingu
- Rodziny kolorów: produkty jednego modelu w różnych kolorach łączone automatycznie po SKU FOX (31324-170, 31324-002… → model 31324). Każdy kolor zostaje osobnym produktem z własnym adresem.
- Listing: pod kartą miniatury kolorów. Najechanie (telefon: dotknięcie) podmienia zdjęcie, nazwę, cenę, etykiety, rozmiary i serduszko; kliknięcie prowadzi do produktu w tym kolorze. Kolor niedostępny – przekreślony.
- Jedna karta na model w kategorii (Wygląd → Dostosuj → FOX.SKLEP.PL → Narzędzia sklepu, domyślnie włączone): widać kolor dostępny na stanie, najnowszy. Przy filtrach i w wyszukiwarce – każdy pasujący kolor osobno.
- Karta produktu: „Kolor: Pink (4 kolory)” z miniaturami pozostałych kolorów.
- Produkt → Dane produktu → pole „Rodzina kolorów” (obok SKU): ręczne łączenie (ten sam tekst w kilku produktach) albo „-”, żeby nie łączyć.
- Filtr rozmiaru wykrywa też atrybut BaseLinkera „wariant” (pa_blvariant).

## 2.8.0 – włącznik „Promocje”
- W pasku filtrów kategorii włącznik „Promocje” – pokazuje tylko produkty w promocji (także warianty). Działa razem z rozmiarem, kolorem, sortowaniem i nieskończonym przewijaniem.
- Widoczny tylko w kategoriach, w których jest choć jedna promocja. Strony z włączonym filtrem mają noindex (adres ?filter_promocja=1).

## 2.7.1 – wstęp kategorii i nawigacja
- Opis kategorii nad produktami: na całą szerokość, zwinięty do ~3 linii z „Pokaż więcej” (cały tekst zostaje w HTML dla Google), obok „Czytaj pełny opis”.
- Okruszki: wyraźne linki ze strzałkami, większe pola kliknięcia, na telefonie w jednej linii z przewijaniem. Na karcie produktu bez powtarzania nazwy produktu.
- Karta produktu: przycisk „Wróć do: [kategoria]”. Jeśli klient przyszedł z listy (także z filtrami i doczytanymi produktami), wraca dokładnie tam; przy wejściu z Google – do kategorii głównej produktu (Yoast „Ustaw jako główną”) albo najgłębszej.

## 2.7.0 – galeria przewijana, opis kategorii na całą szerokość
- Karta produktu (komputer): zdjęcia jedno pod drugim (pierwsze duże, kolejne po dwa), kolumna z ceną i przyciskiem przyklejona obok. Przewijając, oglądasz zdjęcia; po ostatnim zdjęciu cała sekcja jedzie dalej w dół. Gdy kolumna jest wyższa niż ekran, przykleja się dołem (przycisk koszyka zawsze widoczny).
- Telefon: zdjęcia przesuwane palcem, licznik „3 / 6”. Zmiana wariantu pokazuje jego zdjęcie.
- Bez slidera i lupy WooCommerce (mniej skryptów); klik w zdjęcie otwiera powiększenie.
- Opis kategorii pod produktami: na całą szerokość, bez kolumny „O kategorii”, ta sama typografia co opis produktu.

## 2.6.2 – formatowanie opisów
- Opis produktu: stała, czytelna typografia (tekst 16,5 px, nagłówki 23/19 px, listy, tabele), odporna na style z wtyczek, importów i wklejek z Worda.
- SEO: <h1> w opisie produktu, krótkim opisie i opisach kategorii wyświetlany jako <h2> – na stronie jest jeden H1. Treść w bazie bez zmian.
- Usuwane przy wyświetlaniu: wklejone czcionki, rozmiary, kolory i interlinie w atrybutach style, znaczniki <font> oraz puste akapity (&nbsp;).

## 2.6.1 – poprawka mega menu
- Naprawione: zamknięte (niewidoczne) panele menu łapały kursor, przez co przy zjeżdżaniu w dół lub podjeżdżaniu pod menu wyskakiwał inny panel (np. WYPRZEDAŻ).
- Menu sterowane skryptem z „intencją”: otwiera się po krótkim zatrzymaniu kursora, otwarty panel zostaje przy przejeździe po skosie przez inne pozycje, przełącza się dopiero po ~0,4 s postoju na innej pozycji, zamyka po ~0,4 s od wyjechania. Esc zamyka, Tab otwiera.
- Koszyk i zamówienie: jeśli strona ma blok WooCommerce, motyw pokazuje klasyczną wersję (wymagana przez walidację kasku i polskie teksty).

## 2.6.0 – mega menu
- Rozwijane menu w nagłówku zamienione na panel na całą szerokość: pozycje 2. poziomu (Akcesoria, Buty, Kaski…) to kolumny z nagłówkami, 3. poziom to linki pod nimi, 4. poziom (np. Kaski fullface → Proframe) wcięty.
- Kolumny układają się same – nic nie trzeba ustawiać w Wygląd → Menu; na dole panelu link „Zobacz wszystko: MOTO/ROWER”.
- Panel otwiera się z krótkim opóźnieniem (nie miga przy przesuwaniu myszy), działa też z klawiatury; menu mobilne obsługuje 4 poziomy.

## 2.5.0 – walidacja kasku 1:1 z fox.sklep.pl v3.0
- Moduł obwodu głowy zastąpiony Twoim kodem v3.0 (sekcje 1–8 bez zmian logiki): wykrywanie kasku po nazwie, pole + potwierdzenie w klasycznym checkoucie, format 2 cyfry z opcjonalnym miejscem po przecinku, zakres 40–75 cm.
- Te same pola zamówienia: obwod_glowy, pomiar_glowy_potwierdzony, fox_head_circumference; dopisek do uwag klienta (BaseLinker) i notatka wewnętrzna DOUBLE-CHECK.
- Style z sekcji 9 przeniesione do style.css (kolory motywu); usunięte ustawienia „kategorie kasków” i „zakresy rozmiarów”.

## 2.4.3
- Cena „Od X zł” dla produktów wariantowych w sklepie i kategoriach (gdy warianty mają różne ceny) –
  logika 1:1 z dotychczasowego kodu. Karta produktu bez zmian.

## 2.4.2
- Komunikat o ostatnich sztukach (1–3) zamiast „Na stanie” – teksty 1:1 z dotychczasowego kodu,
  wyróżniony kolorem; działa też dla wybranego wariantu.

## 2.4.1
- Komunikat o oszczędności pod ceną – logika i teksty 1:1 z dotychczasowego kodu fox.sklep.pl
  („Kupujesz taniej o…”, przy wariantach „Oszczędzasz nawet…”). Warianty liczone szybszą metodą,
  wygląd w kolorach motywu.

## 2.4.0
- „Produkty z tej samej serii” – logika 1:1 z dotychczasowego kodu fox.sklep.pl (fox_produkty_z_serii_v13):
  4 słowa od „FOX” w nazwie (bez JUNIOR/LADY/KIDS), produkty z tą frazą w nazwie, najnowsze, max 4,
  tylko widoczne w katalogu. Karty motywu w 4 kolumnach, sekcja nad „Sprawdź podobne produkty”.

## 2.3.1
- „Sprawdź podobne produkty” na karcie produktu – logika przeniesiona 1:1 z dotychczasowego kodu fox.sklep.pl
  (kategoria najniższego rzędu, tylko dostępne, min. 2 wspólne słowa w nazwie, remis = droższy wyżej),
  zastępuje „Z tej serii”. Dodana pamięć podręczna wyników (12 h). 6 produktów (2 rzędy po 3).

## 2.3.0
- Filtry w kategoriach: Rozmiar i Kolor (kółka w kolorze z atrybutu), tylko wartości występujące w danej
  kategorii, łączenie filtrów, aktywne filtry z krzyżykiem i „Wyczyść filtry”. Wbudowane filtrowanie
  WooCommerce – bez wtyczki. Strony z filtrami mają noindex, linki filtrów nofollow.
- Atrybuty filtrów do wyboru w Narzędziach sklepu (domyślnie wykrywane po nazwie).
- Nagłówek w dwóch rzędach: wyszukiwarka – logo – ikony, a pod spodem menu w jednej linii.
- Karty wpisów bloga w układzie jak dotychczas: zdjęcie z kategorią, awatar autora, podkreślony tytuł,
  zajawka, „Czytaj więcej”, data i komentarze.

## 2.2.0
- Karta produktu: zamiast losowych „Podobnych produktów” sekcja „Z tej serii” – produkty z tej samej
  najgłębszej kategorii (np. V1), najpierw dostępne i o najbardziej podobnej nazwie, z linkiem do całej serii.
- Listing: po najechaniu na produkt widać dostępne rozmiary (tylko te na stanie, w kolejności z atrybutu).
- Kategorie: kolejne strony doładowują się same przy przewijaniu; adres zmienia się na /page/N/,
  a zwykła paginacja zostaje w kodzie dla Google.
- Usunięta sekcja i pole „Rozmiar i krój”.
- Domyślnie 3 kolumny × 4 rzędy na stronę.

## 2.1.1
- Karta produktu: opis przeniesiony pod część zakupową, na całą szerokość – pełny opis po lewej,
  szczegóły (informacje dodatkowe, rozmiar i krój, dostawa, opinie) w akordeonie po prawej.
- Kolumna zakupowa i zdjęcia nigdy nie są przyklejane, pływające kolumny zamknięte przed sekcjami poniżej –
  nic nie najeżdża na podobne produkty (sprawdzone pomiarem na komputerze i telefonie).

## 2.1.0 – własne narzędzia zamiast CommerceKit
- Etykiety: automatyczne (−X% / Promocja, Nowość, Ostatnie sztuki, Wyprzedane) i ręczne w produkcie lub kategorii („!” = czarna).
- Tabele rozmiarów: Produkty → Tabele rozmiarów, przypisanie do kategorii lub produktu, okno na karcie produktu.
- Przyciski wariantów dla wszystkich atrybutów, kolory jako kółka (kolor ustawiany przy wartości atrybutu).
- Ulubione: serduszko na kartach i produkcie, licznik w nagłówku, strona /ulubione/ (tworzy się sama, noindex),
  lista gościa w przeglądarce, zalogowanego na koncie.
- Powiadomienia o dostępności: zapis e-maila przy niedostępnym rozmiarze, automatyczny e-mail po powrocie na stan,
  lista w WooCommerce → Powiadomienia o dostępności.
- Walidacja kasku: obowiązkowy obwód głowy przy zamówieniu z kaskiem, porównanie z zakresem rozmiaru,
  zapis w zamówieniu, panelu i e-mailu. Działa w klasycznym i blokowym zamówieniu.
- Ustawienia: Wygląd → Dostosuj → FOX.SKLEP.PL → Narzędzia sklepu.
- Wszystko przetestowane na lokalnym WordPressie z WooCommerce 11.2.

## 2.0.0 – przebudowa części sklepowej
- Motyw nie nadpisuje już żadnych szablonów WooCommerce (listing, kategoria, karta produktu).
  Wszystko działa na standardowym HTML WooCommerce, którego oczekują wtyczki (CommerceKit, YITH, GTM4WP).
- Wygląd sklepu wyłącznie przez CSS, własne elementy przez oficjalne haki WooCommerce:
  wstęp i pełny opis kategorii, podkategorie jako pigułki, akordeon z opisem, Zbuduj komplet.
- Układ siatki robi WooCommerce: 3 produkty w rzędzie, 2 na telefonie.
- Własne przyciski rozmiarów tylko wtedy, gdy wariantami nie zajmuje się wtyczka.
- Przycisk „Dodaj do koszyka” w kolorze motywu także przed wyborem rozmiaru.
- Przetestowane na lokalnym WordPressie z WooCommerce 11.2: kategorie, karta produktu, koszyk, telefon.

## 1.0.8
- Karta w listingu wraca do prostego układu (bez haków, w których CommerceKit otwiera własne opakowania i chowa zdjęcie, nazwę i cenę).
- Etykiety wtyczek pod ceną jako małe plakietki, serduszko ulubionych w prawym górnym rogu.

## 1.0.7
- Listing: elementy wtyczek, które nie są kartą produktu, nie są już ukrywane (ukrywały produkty na fox.sklep.pl) – zajmują cały wiersz, puste znikają.

## 1.0.6
- Listing: 3 produkty w rzędzie (2 na telefonie), siatka odporna na style i elementy wtyczek
  (CommerceKit, YITH, WooCommerce) – koniec pustych komórek i rozjechanych kart.
- Etykiety wtyczek (Promocja!, Nowy!, Ocieplane) jako małe plakietki na zdjęciu, serduszko ulubionych w prawym rogu.
- Ceny w kolorze motywu zamiast oliwkowego z WooCommerce.
- Karta produktu: kolumna z ceną nie jest już przyklejana, galeria nie wychodzi poza swoją kolumnę.
- Strona główna: 6 produktów w sekcji nowości (domyślnie).

## 1.0.5
- Kategorie: gdy nie ma osobnego opisu dolnego, nad produktami zostaje tylko wstęp
  (pierwszy akapit), dalsza część opisu przechodzi pod listing. Tekst bez zmian, w tej samej kolejności.

## 1.0.4
- Próbna aktualizacja przez Git Updater (bez zmian w wyglądzie).
- Dodany ten plik z historią zmian.

## 1.0.3
- Nagłówek dla Git Updater – aktualizacje motywu z GitHuba.

## 1.0.2
- Stopka: każdy widżet to osobna kolumna, na telefonie dwie kolumny.

## 1.0.1
- Ukrywanie niewyrenderowanych kodów WPBakery ([vc_row] itp.).
- Komunikaty WooCommerce w stylu motywu.

## 1.0.0
- Pierwsza wersja: strona główna z Personalizacją, kategorie z opisem nad i pod listingiem,
  karta produktu z akordeonem opisu, moduł Zbuduj komplet.
