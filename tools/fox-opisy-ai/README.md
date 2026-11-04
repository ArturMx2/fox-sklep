# FOX – Opisy produktów AI

Następca „Generatora FOX v18”. Opisy powstają **wyłącznie z faktów ze źródeł** – AI nie dopisuje parametrów z głowy.

## Jak działa
1. **Źródła faktów** (dla modelu, np. kask 31370 we wszystkich kolorach – raz na 30 dni):
   - BaseLinker: oryginalny opis i parametry dystrybutora (po SKU; niezależne od tego, co nadpisał v18),
   - foxracing.com (oficjalne – najwyższa wiarygodność) i zaufane sklepy – wyszukiwane przez Serper (Google),
   - obecny opis w sklepie (tylko jeśli nie jest wytworem v18),
   - kategoria i atrybuty produktu, zdjęcie (tylko cechy jednoznacznie widoczne).
2. **Fakty**: AI wypisuje fakty o tym modelu z numerami źródeł i pewnością (high / medium / low). Niepewne nie trafiają do opisu.
3. **Opis główny** – raz na model (bez koloru); **wstęp, krótki opis i meta** – osobno dla każdego koloru.
4. **Kontrola**: technologie z listy (MIPS, D3O, Fluid Inside…) użyte w tekście, a nieobecne w pewnych faktach → poprawka; gdy dalej są – fragment nie jest publikowany („Do sprawdzenia”).
5. **Zapis** bez haków zapisu produktu (import z BaseLinkera nie jest wywoływany), z kopią 3 poprzednich wersji i przyciskiem „Przywróć”.

## Co jest pisane (domyślnie)
- Opis główny: pusty, krótszy niż 500 znaków albo napisany przez v18 bez źródła.
- Krótki opis: pusty, słaby albo z v18.
- Meta opis (Yoast / Rank Math): pusty albo z v18.
- Tylko produkty dostępne; bez bonów i produktów wirtualnych. Najpierw produkty po v18, potem najlepiej sprzedające się.
- Teksty napisane przez wtyczkę i ręcznie poprawione nie są ruszane. Gdy import z Base 3 razy w 30 dni nadpisze opis – wtyczka przestaje go pisać (żeby nie płacić w kółko) i zgłasza to w „Do sprawdzenia”.

## Klucze
OpenAI: Ustawienia albo `define( 'FOX_OPISY_AI_KEY', 'sk-…' );` w wp-config.php (albo klucz z wtyczki opisów zdjęć).
Serper: Ustawienia albo `define( 'FOX_OPISY_SERPER_KEY', '…' );`.
Klucze ze starego fragmentu v18 są ujawnione – unieważnij je i wygeneruj nowe.

## Wymaga
Motywu FOX.SKLEP.PL (połączenie z BaseLinkerem, kategoria główna, statystyki wyszukiwarki do słów kluczowych) i WooCommerce.
