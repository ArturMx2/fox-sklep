# Zmiany w motywie FOX.SKLEP.PL

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
