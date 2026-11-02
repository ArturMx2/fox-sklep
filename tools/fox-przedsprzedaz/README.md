# FOX – Przedsprzedaż Powerbike (1.0.0)

Produkty bez stanu, które mają w Powerbike przyszłą datę dostawy, wracają do sklepu jako przedsprzedaż.

- Źródło: importer Powerbike na airoh.sklep.pl (`/wp-json/pbi/v1/products`) – adres i klucz brane z „Przeceny Powerbike”, chyba że wpiszesz inne.
- Warunki: data dostawy w oknie (domyślnie 90 dni), pozycja w ofercie, brak stanu u Powerbike, wariant w sklepie bez stanu.
- Dopasowanie: po SKU (warianty SKU z importera), potem po EAN (`_woosea_ean`, `_global_unique_id`).
- W sklepie: „Na zamówienie” (zamówienia przy stanie 0 dozwolone), tekst „Przedsprzedaż – dostawa do nas ok. …”, komunikat na karcie produktu, etykieta, filtr, termin w koszyku (data dostawy + 2 dni robocze), zgoda w zamówieniu, adnotacja przy pozycji, schema.org PreOrder.
- Koniec: towar przyszedł (stan > 0), data minęła, pozycja zniknęła z importera → wraca poprzednie ustawienie.
- Bezpiecznik: przy błędzie połączenia lub pustej odpowiedzi importera nic nie jest zdejmowane.
- Panel: WooCommerce → Przedsprzedaż (lista produktów, dziennik, „Synchronizuj teraz”, „Zdejmij całą przedsprzedaż”).
- Wymaga motywu FOX ≥ 3.23.0 dla terminu w koszyku i komunikatu na karcie (bez niego działa reszta).
