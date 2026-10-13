# Zmiany w motywie FOX.SKLEP.PL

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
