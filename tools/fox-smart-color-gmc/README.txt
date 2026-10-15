FOX Smart Color GMC 2.1.1
=================================

Cel
---
Automatyczne uzupełnianie koloru produktów WooCommerce z priorytetem danych tekstowych producenta i walidacją zdjęciem.

Dane produktu
-------------
1. Globalny atrybut WooCommerce: pa_kolor
2. Pole dla AdTribes / Google Merchant Center: _foxsc_gmc_color
3. Lekkie meta techniczne:
   - _foxsc_confidence
   - _foxsc_source
   - _foxsc_processed_at

Szczegółowe dane obrazu i decyzji nie są zapisywane w postmeta. Trafiają tylko do tabeli logów i są automatycznie usuwane po 30 dniach.

Bezpieczeństwo
--------------
- ręczny pa_kolor nie jest nadpisywany,
- produkty wariantowe po kolorze są pomijane,
- kolor produktu głównego nie jest atrybutem wariantowym,
- przy niskiej pewności produkt nie jest zmieniany,
- w Części i Akcesoria analizowana jest tylko gałąź Nadwozie i jej potomkowie (jeżeli te kategorie istnieją/ustawiono je w panelu),
- na sklepie bez tej gałęzi kategoryzacji analizowany jest cały katalog.

Kolejka
-------
Panel WooCommerce -> Smart Color GMC pokazuje:
- Przetworzono X / Y,
- procent,
- pozostałe / OK / pominięte / błędy / w trakcie / oczekuje,
- czas i ETA.

Przyciski:
- Wstrzymaj przetwarzanie — zachowuje kolejkę i postęp; bieżący produkt może dokończyć analizę,
- Kontynuuj przetwarzanie — wznawia od zachowanej kolejki,
- Test 100 produktów,
- Dodaj wszystkie bez koloru,
- Sprawdź wszystkie produkty,
- Ponów błędy,
- wyczyść kolejkę / logi.

Feed GMC / AdTribes
-------------------
Mapuj Google color do pola:
_foxsc_gmc_color

Pole zachowuje znaczeniową kolejność kolorów, np.:
Żółty/Czarny

WooCommerce może wyświetlać termy atrybutu w innej kolejności; dla feedu używaj _foxsc_gmc_color.

Aktualizacja z 1.x
------------------
Wtyczka zachowuje kompatybilność z wcześniejszymi wynikami. Podczas ponownego przetwarzania produktu usuwa stare ciężkie pola diagnostyczne (_foxsc_detected_colors_json, candidate, hash, algorithm version itd.) i przechodzi na lekki zestaw meta 2.0.

Wymagania
---------
- WordPress 6.4+
- PHP 7.4+
- WooCommerce 8+
- globalny atrybut WooCommerce „Kolor” ze slugiem kolor (pa_kolor)
- Imagick lub GD do analizy obrazów
- Action Scheduler WooCommerce jest preferowany; w razie braku używany jest WP-Cron.

Sprzątanie po wersjach 1.x
--------------------------
W sekcji Feed / GMC jest przycisk „Wyczyść stare pola diagnostyczne 1.x”. Najpierw migruje przydatne stare wartości do nowych lekkich meta, a następnie usuwa stare candidate/JSON/hash/trackery. Nie usuwa atrybutu pa_kolor ani aktualnego _foxsc_gmc_color.


2.0.1 HOTFIX
------------
- Naprawiono zatrzymywanie kolejki po jednej partii (np. po 4 produktach).
- Worker w tle sprawdza teraz wyłącznie oczekujące akcje Action Scheduler, a nie własną akcję będącą aktualnie w trakcie.
- Strona panelu NIE musi pozostawać otwarta; przetwarzanie kontynuuje Action Scheduler / WP-Cron.


2.1.0 — praca w tle bez konfliktów
----------------------------------
- Import z BaseLinkera: gdy BaseLinker zapisuje produkty przez REST API WooCommerce, kolejka czeka
  (10 min od ostatniego zapisu; uwzględnia też wtyczkę „FOX – SEO zdjęć”). Bieżące zadanie przerywa
  po skończonym produkcie i wraca za 5 min.
- Synchronizacja stanów nie wrzuca już produktów z kolorem z powrotem do kolejki (wcześniej każdy
  zapis produktu = ponowna analiza i zapis). Produkty bez koloru wracają najwyżej raz na 7 dni –
  koniec „przepychanki”, gdy import czyści atrybuty.
- Pole GMC dla koloru ręcznego zapisywane tylko przy zmianie (wcześniej ~20 zapytań przy każdej
  synchronizacji produktu).
- Zadanie w tle zlecane najwyżej raz na minutę, z opóźnieniem 60 s; jedno zadanie pracuje max 40 s.
- Ustawienia/kategorie sprawdzane tylko w panelu i cronie (wcześniej 2 zapytania na każdej stronie sklepu).
- Sprzątanie starych pól 1.x jednym zapytaniem zamiast 17.
- Motyw FOX 3.12.6+: zapisy produktów przez wtyczkę nie czyszczą już pamięci podręcznej motywu
  po każdym produkcie (zbiorczo co 90 s).

2.1.1 — PILNA POPRAWKA
----------------------
- Naprawiono zapis produktów: ramka „FOX Smart Color GMC” w edycji produktu zawierała własny
  formularz, który podmieniał pole „action” formularza produktu. Po kliknięciu „Aktualizuj”
  WordPress nie zapisywał zmian (np. części kompletu) i przenosił do listy wpisów.
  Przycisk „Przeanalizuj ponownie” jest teraz zwykłym linkiem.
