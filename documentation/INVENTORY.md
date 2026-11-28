# Inventar

Was besitzen wir, wo liegt es, wie geht es ihm. Das Inventar gehört zum Kern (`app/`), nicht zu einem Modul, weil Verleih, Kiosk und öffentliche Seite darauf aufbauen.

Etiketten, Kurzlinks, Kamera-Scan und die Box-Inhaltsliste als PDF stehen in [LABELS.md](LABELS.md).

## Inhalt

- [Begriffe](#begriffe)
- [Standorte und Kennziffern](#standorte-und-kennziffern)
- [Gegenstände, Boxen, Mengenartikel](#gegenstände-boxen-mengenartikel)
- [Erfassen](#erfassen)
- [Artikelnummern](#artikelnummern)
- [Umlagern und Bewegungsprotokoll](#umlagern-und-bewegungsprotokoll)
- [Zustand](#zustand)
- [Ausmustern und Löschen](#ausmustern-und-löschen)
- [Fotos auf S3](#fotos-auf-s3)
- [Rechte](#rechte)
- [Code](#code)

## Begriffe

| Begriff | Bedeutung |
|---|---|
| Standort | Adresse, Gebäude (optional), Raum, Lagerplatz oder Fach |
| Kennziffer | Pfad eines Standorts aus den Kürzeln, z. B. `EU-014-R03.4` |
| Gegenstand | alles Inventarisierte, auch Boxen |
| Box | Gegenstand mit „ist Behälter“, kann andere Gegenstände enthalten |
| Mengenartikel | Gegenstand mit Stückzahl und Einheit (Kabel, Becher, Klebeband) |
| Einheit | Stück, Meter, Rollen …; vorgegebene Liste in den Einstellungen |
| Ort | wo ein Gegenstand gerade liegt: ein Standort oder eine Box |
| Stammplatz | wohin ein Gegenstand gehört; optional |

## Standorte und Kennziffern

Fünf Ebenen: Adresse → (Gebäude) → Raum → Lagerplatz → Fach. Das Gebäude ist optional (seit Review Phase 5): Ein Raum liegt in einer Adresse oder in einem Gebäude. Die Ebene ergibt sich aus dem übergeordneten Standort (`LocationType::childTypes()`); unter einer Adresse wird beim Anlegen gewählt, ob ein Raum oder ein Gebäude entsteht. Danach bleibt die Ebene fest, ein Raum darf aber zwischen Adresse und Gebäude wechseln.

- Kürzel bestehen nur aus Buchstaben und Ziffern und werden groß geschrieben.
- Die Kennziffer verbindet die Kürzel mit `-`, das Fach mit `.`: `EU` · `EU-014` · `EU-014-R03` · `EU-014-R03.4`.
- Die Kennziffer ist gespeichert (`locations.full_code`, eindeutig) und wird bei jeder Umbenennung oder Verschiebung für alle Standorte darunter neu berechnet (`SaveLocation`). Eindeutige Kennziffern bedeuten: Ein Kürzel gibt es unter demselben Standort nur einmal.
- Ein Standort lässt sich mit allem darunter löschen, wenn dort nichts liegt und nichts seinen Stammplatz hat (`DeleteLocation`).
- Standorte stehen überall in natürlicher Reihenfolge der Kennziffer (R01.2 vor R01.10, R9 vor R10): Die Datenbank sortiert als Text, deshalb sortieren `Location::naturally()` und die Standortliste noch einmal.
- Der Dialog zeigt die Kennziffer schon beim Tippen (beim Bearbeiten alt → neu und ein Beispiel darunter) und markiert sie rot, wenn es sie schon gibt; Speichern ist dann gesperrt. Er rechnet wie `Location::fullCodeOf()`, entscheidend bleibt die Prüfung in `SaveLocation`.
- **Mehrere anlegen** (seit 27.11.2026): Lagerplätze und Fächer lassen sich in einer Reihe anlegen, Häkchen im Dialog, höchstens 50. Aus dem ersten Kürzel zählt `App\Support\Inventory\CodeSequence` im eigenen Muster weiter: Ziffern am Ende als Zahl mit gleicher Breite (`1` → `2`, `R01` → `R02`, `R9` → `R10`), ein Buchstabe am Ende bis `Z` (`A` → `B`). Name, Beschreibung und „Kein Etikett nötig“ gelten für alle. Die Maske zeigt alle geplanten Kennziffern, vergebene rot; `CreateLocations` prüft alle vorher und legt alles oder nichts an („Die Kennziffern … gibt es schon.“).
- Gegenstände liegen in einem Raum, auf einem Lagerplatz oder in einem Fach, z. B. ein Beamer direkt im Raum, aber nie direkt an einer Adresse oder in einem Gebäude (`Placement::check`, `LocationType::holdsItems()`).

**Gebäude und Kennziffer.** Je Gebäude gibt es den Schalter „Im Code zeigen“ (`locations.show_in_code`, Vorgabe aus):

| Gebäude | Kennziffer des Gebäudes | Raum darin |
|---|---|---|
| im Code | `EU-A` | `EU-A-014`, darunter `EU-A-014-R03.4` |
| nicht im Code | `EU-A` (für sein Etikett) | `EU-014`, als läge er direkt an der Adresse |

- `Location::fullCodeOf()` rechnet die Kinder eines Gebäudes ohne Code vom Code der Adresse weiter.
- Umlegen des Schalters oder Verschieben eines Raums rechnet alle Kennziffern darunter neu. `SaveLocation` prüft vorher alle neuen Kennziffern; gibt es eine schon (zwei Räume `014`, einer an der Adresse, einer im Gebäude ohne Code), bricht es mit „Die Kennziffer … gibt es schon.“ ab, ohne etwas zu ändern.
- Alte Kennziffern werden nicht aufgelöst: Etiketten mit dem alten Text neu drucken. QR-Codes auf Standort-Etiketten zeigen auf die Id (`location-{id}`) und gehen weiter.
- Die Oberfläche fragt vor dem Umlegen nach und sagt, wie viele Kennziffern sich ändern. Pfad und Baum zeigen das Gebäude immer, auch wenn es nicht im Code steht.

## Gegenstände, Boxen, Mengenartikel

Ort und Stammplatz zeigen jeweils auf einen Standort **oder** eine Box (`location_id`/`container_id`, `home_location_id`/`home_container_id`), nie auf beides.

**Dynamische Lagerung.** Maßgeblich ist der aktuelle Ort. Weil Umlagern nur zwei Scans kostet, darf alles dort liegen, wo gerade Platz ist. Ein Stammplatz ist nur für Dinge gedacht, die immer an denselben Platz zurück sollen; liegt so ein Gegenstand woanders, zeigt Stocks das an und bietet „Zurück an den Stammplatz“.

**Boxen.**
- Der Inhalt zeigt auf die Box. Wird die Box umgelagert, ändert sich am Inhalt nichts, er zieht einfach mit. `Item::effectiveLocation()` liefert den Standort der äußersten Box.
- Eine Box kann nicht in sich selbst oder in eine Box aus ihrem eigenen Inhalt (`Placement::check`).
- Nur Gegenstände mit „ist Behälter“ nehmen etwas auf; das Merkmal lässt sich nicht entfernen, solange etwas darin liegt.
- „Inhalt öffentlich zeigen“ (Standard: an) nutzt erst die öffentliche Box-Ansicht per QR-Code.

**Mengenartikel** (seit 28.11.2026). Ein Mengenartikel hat keinen Ort; seine Stücke liegen an Stellen (`item_stocks`, `App\Models\ItemStock`): Standorte, Boxen oder das virtuelle Fach **„Eingang“** (`place_key` `IN`). Je Stelle gibt es eine Menge und optional eine Sollmenge (`target_quantity`, unterschritten = Mangel). Der Stammplatz ist nur ein Vorschlag. Alle Änderungen laufen durch `App\Actions\Inventory\StockBook`: `receive` (Eingang), `transfer` (Umlagern zwischen Stellen), `takeOut`/`putBack` (Verleih), `remove` (verbraucht, ausgemustert), `count` (Zählen), `setTarget`. `StockBook::sourceOf()` wählt die Stelle, von der etwas kommt: die gewählte (`from`), sonst die einzige mit Stücken, sonst der Eingang; liegen Stücke an mehreren Stellen, muss man wählen („Von“ in Umlagern, Verbraucht, Ausmustern und Ausgabe). Erfasste Mengenartikel ohne Ort landen im Eingang. Das Formular ändert die Menge nicht. In Boxen stehen die Stücke als Inhalt (`BoxContents`, „8 / soll 10“), auf der Seite eines Standorts unter „Gegenstände hier“, in Listen „an N Stellen“. Abteilen und Zurückbuchen gibt es nicht mehr; alte Teile hat die Migration zusammengeführt (siehe [DECISIONS.md](DECISIONS.md)), ihr Scan führt zum Gegenstand, in den sie eingingen (`merged_into_id`).

**Wareneingang** (seit 28.11.2026, Seite `/inventory/stock-in`, Menü „Wareneingang“, Recht `inventory.edit`, `StockController`). Einbuchen: Nummer des Mengenartikels oder Barcode der Verpackung (EAN, `Gtin::normalize`) scannen oder tippen, Menge, Notiz („Bestellung Reichelt“); `StockBook::receive` legt die Stücke ins Eingang (Bewegung `receive`). Nur aktive Mengenartikel; neue Artikel entstehen über „Erfassen“ (ohne Ort ebenfalls im Eingang). Darunter die Liste „Im Eingang“ mit „Einräumen“ (Ziel scannen, Vorschlag Stammplatz). Die Dashboard-Kachel nennt „Im Eingang: N Stück in M Artikeln“ (`/inventory/stats`).

**Einpacken** (`InventoryPackModal`) ist Umlagern mit Quelle (`POST /inventory/move` mit `from`): auf der Mengenartikel-Seite („Einpacken“, Ziel frei), auf der Box-Seite („Mengenartikel einpacken“, der Artikel wird gescannt) und auf der Standort-Seite („Mengenartikel einräumen“). Vorgabe der Quelle ist das Eingang.

**Zählen und Sollmenge** (`InventoryStockEditModal`, `POST /inventory/items/{item}/stocks`, Recht `inventory.edit`): Je Stelle die gezählte Menge (`StockBook::count`, der Gesamtbestand folgt, Bewegung `count` mit Differenz) und optional eine Sollmenge (nicht im Eingang). Eine Stelle mit Sollmenge bleibt auch bei 0 bestehen. Liegt weniger als die Sollmenge dort, ist die Stelle ein Mangel („5 / soll 8“, rot). **Auffüllen** (`POST /inventory/stocks/fill`, Recht `inventory.move`) an Box und Standort holt für jede solche Stelle die Differenz aus dem Eingang, soweit dort genug liegt, und nennt, was noch fehlt.

**Sollmenge an Box und Standort** (seit 28.11.2026): „Sollmenge festlegen“ auf der Box- und Standort-Seite (`InventoryStockTargetModal`, `POST /inventory/stocks/target`, Recht `inventory.edit`) setzt die Sollmenge eines Mengenartikels an dieser Stelle, auch wenn dort noch nichts liegt („0 / soll 8“); „Auffüllen“ bringt die Stücke dann aus dem Eingang. Im Box-Inhalt und in der Liste des Standorts hat jeder Mengenartikel den Rechner-Knopf (Zählen und Sollmenge wie in der Karte „Bestand“).

**Mindestbestand** (seit 28.11.2026, `items.min_quantity`): Feld „Mindestbestand (optional)“ bei Mengenartikeln im Formular und im Erfassen. Gezählt wird der ganze Bestand (`quantity`), verliehene und vermisste Stücke eingeschlossen, bis die Mängel abgeschlossen sind (Entscheidung von Maurice). Darunter, oder wenn eine Stelle unter ihrer Sollmenge liegt, ist der Artikel ein **Mangel** (`Item::short()`): roter Hinweis in der Karte „Bestand“ („Unter Mindestbestand: 3 von 10“), Abzeichen „Mangel“ in der Liste, Filter „Unter Mindestbestand“ (`short=1`) und die Zeile „Unter Mindestbestand“ in der Dashboard-Kachel. Keine Benachrichtigung (Entscheidung von Maurice). Das Formular ändert die Menge eines vorhandenen Mengenartikels nicht mehr, das Feld ist gesperrt.

**Stücke, die nicht hier sind.** `quantity` ist der ganze Bestand: Summe der Stellen plus was ein Modul weg hat (der Verleih: ausgegeben, vermisst, zurück und noch nicht eingeräumt, `ItemExtensions::awayQuantity()`). Hier ist die Summe der Stellen (`quantityHere()`); Umlagern, Verbrauchen und Ausmustern gehen höchstens bis zur Menge der gewählten Stelle („In B-12 liegen nur 3 Stück.“). Ein Mengenartikel wird nur zum Einzelstück, wenn höchstens ein Stück an einer Stelle liegt und nichts weg ist. Angezeigt wird das als **„Vorhanden“** (`quantity_here`): auf der Seite eigenes Feld „Vorhanden: 38 Stück von 50, 12 unterwegs“, auf der Scan-Karte „50 Stück (38 vorhanden)“, in der Liste „38 / 50 Stück“, in den Dialogen Umlagern, Verbrauchen und Ausmustern als Hinweis (Ausmustern beginnt mit dem Vorhandenen).

**„Ohne Platz“** listet Gegenstände ohne Ort und solche mit Stammplatz, die nicht dort liegen (`Item::misplaced()`). Gegenstände, die ein Modul bewusst unterwegs hat, fehlen dort (seit Review Phase 5, `ItemExtensions::misplaced()` mit `withoutAway()`): der Verleih nimmt ausgegebene Gegenstände heraus. Zurück, aber noch nicht eingeräumt, und vermisst stehen wieder dort. Dasselbe gilt für die Zahl auf dem Dashboard.

**Nach Boxen gruppieren.** Schalter in „Gegenstände“ und „Ohne Platz“, seit v0.1.1 standardmäßig an. Die Wahl merkt sich der Server je Gerät und Liste in einem Cookie (`stocks_items_grouped`, `stocks_misplaced_grouped`, `ItemsTable::wantsGrouping()`): der Parameter `grouped` gewinnt und setzt das Cookie, sonst gilt das Cookie, sonst an; „aus“ schickt die Seite deshalb ausdrücklich als `grouped=0`. An: oben stehen nur Gegenstände, deren Box nicht selbst in der Liste steht, Boxen mit der Zahl ihres direkten Inhalts; der Pfeil klappt den Inhalt auf (`GET /inventory/items/{box}/contents`, höchstens 200 Zeilen, danach „… und N weitere“ mit Link zur Box), auch Boxen in Boxen. Mit Suche oder Filter ist die Liste flach (`ItemsTable::isGrouped()`). Der Inhalt wird neu geladen, wenn sich die Zeile der Box ändert oder sie neu aufgeklappt wird.

## Erfassen

Geführter Ablauf zum Anlegen vieler Gegenstände (seit 27.11.2026, Entscheidungen von Maurice), Eintrag „Erfassen“ in der Navigation, am Handy in der unteren Leiste. Recht `inventory.edit` wie das Formular, das für Bearbeiten, Einzelfälle und Etiketten auf Vorrat bleibt. Nicht am Kiosk.

**Schritte** mit Fortschrittsbalken oben (`UiSteps`, wie Ausgabe und Rückgabe):

1. **Daten:** Pflichtfelder wie im Formular (`InventoryItemFields`, Regeln aus `App\Support\Inventory\ItemInput`), dazu bei Einzelstücken „Anzahl gleicher Stücke“ (1–50). Beschreibung, Notiz, „Ist eine Box“ und „Inhalt öffentlich zeigen“ unter „Mehr“. Oben bleibt Platz für den späteren Barcode-Scan der Verpackung.
2. **Bilder:** optional, „Überspringen“; bei mehreren Stücken bekommt jedes eine Kopie.
3. **Ort:** QR-Code scannen oder Kennziffer tippen; Häkchen „Auch als Stammplatz“ (Vorgabe an). Ohne Häkchen kommt **Stammplatz** als eigener Schritt dazu, damit auf jeder Seite nur ein Scanner läuft. Ohne Ort darf man anlegen, die Übersicht warnt.
4. **Etikett** (nur mit `labels.print`): Bild des Etiketts (`LabelsPreview`, den Editor gibt es erst über „Etikett anpassen“, weil er am Handy beim Scrollen Elemente verschob) mit den eingegebenen Daten und der Nummer als Platzhalter (`FSR5-000000`, `POST /inventory/capture/preview`, `LabelData::forDraft()`), Vorlage für Gegenstand bzw. Box, Hinweis fürs Etikett, Kopien je Stück, Drucker und „Verbinden“.
5. **Übersicht:** alles in Blöcken mit „Ändern“, das Etikett nur als Bild (`LabelsPreview`).

Der letzte Knopf heißt „Erstellen und drucken“, wenn gleich gedruckt werden kann (Papier oder verbundener Niimbot), sonst „Erstellen“. `POST /inventory/capture` antwortet mit JSON (Gegenstände und ihre Etikettendaten), die Seite bleibt im Ablauf. Mehrere Stücke legt `CreateItems` in einer Transaktion an, fortlaufende Nummern, alles oder nichts. Eine zufällige `capture_key` je Durchlauf verhindert doppeltes Anlegen: Der Server merkt sich die Ids 30 Minuten im Cache und antwortet bei Wiederholung mit denselben Gegenständen (`Cache::lock` gegen gleichzeitige Anfragen).

**Etikettiert** gilt ein Gegenstand nur nach erfolgreichem Direktdruck (bei Abbruch oder Fehler nur die gedruckten) oder mit dem Häkchen „Etikett wurde aufgeklebt“ in der Ergebnis-Ansicht (Papier, Bild für die Niimbot-App; Vorgabe aus). Ohne `labels.print` wird nur angelegt, der Gegenstand bleibt „Etikett fehlt“.

**Kein Etikett nötig** (seit 27.11.2026): Häkchen oben im Schritt Etikett (ohne `labels.print` im Schritt Daten). Dann blendet der Schritt Vorlage und Drucker aus, der letzte Knopf heißt „Erstellen“, der Server schickt keine Etikettendaten und Ergebnis und Runde zeigen „kein Etikett nötig“ statt „ohne Etikett“. Siehe [LABELS.md](LABELS.md#drucken).

**Danach:** „Nächster Gegenstand“ behält Kategorie, Art, Einheit, Zustand, Ort und Stammplatz, Vorlage und Drucker und leert Name, Beschreibung, Notiz, Hinweis, Fotos und Anzahl. „Fertig“ zeigt die **Runde**: alles, was in diesem Tab erfasst wurde, mit Druckstatus und „Etiketten drucken“ für den Rest. Formular, Schritt und Runde liegen im `sessionStorage` (`useCapture`); neu laden behält sie, nur die Fotos muss man neu wählen.

**Barcode der Verpackung** (seit 27.11.2026, Entscheidungen von Maurice): Oben im Schritt Daten das Feld „EAN der Verpackung“ mit „Scannen“ (Kamera mit EAN-13, EAN-8, UPC-A, UPC-E) und „Suchen“. `GET /inventory/capture/product?code=` fragt `App\Support\Products\ProductLookup`, der der Reihe nach **UPCitemdb** (Free-Plan, 100 Abfragen am Tag, Technik), **Open Food Facts** und **Open Products Facts** (frei, Verbrauchsmaterial) fragt und daraus Vorschläge macht: Name der ersten Quelle mit Namen (bei Open Facts der deutsche), Beschreibung aus Marke und Modell, Bilder aller Quellen (höchstens 12). Alles ist abwählbar und änderbar; „Übernehmen“ füllt die Felder, die gewählten Bilder kommen vor die eigenen Fotos. Gibt es schon Gegenstände mit dieser EAN, zeigt die Karte sie an („Gibt es schon“).

- **Gespeicherte Form** (`Gtin::normalize()`): Prüfziffer geprüft, UPC-A bekommt die führende 0 (EAN-13), UPC-E wird erst zu UPC-A. `items.ean`, auch im Formular „Bearbeiten“ und in der Suche der Liste. Die Seite „Scannen“ erkennt EANs nicht (bewusst).
- **Cache je Code:** ein Treffer 30 Tage (ein zweiter Scan derselben Packung kostet keine Abfrage), „nichts gefunden“ einen Tag; Antworten mit nicht erreichbarer Quelle werden nicht gemerkt. Ist das Tageskontingent von UPCitemdb aufgebraucht (429 oder `X-RateLimit-Remaining` 0), pausiert die Quelle bis zum Reset.
- **Bilder** lädt der Server (`GET /inventory/capture/product/{code}/images/{index}`, `ImageFetcher`): nur Bilder aus dem gemerkten Treffer, nur öffentliche Adressen (Host vorher aufgelöst, private und reservierte Adressen abgelehnt, Verbindung zur geprüften Adresse, Weiterleitungen einzeln geprüft), höchstens 10 MB, als JPEG über `ImageDecoding`. Im Browser werden sie wie eigene Fotos verkleinert.
- **Herkunft der Fotos:** `item_photos.source`, `source_url`, `license`, `author`, `source_host` (der Browser schickt nur `photo_refs[i]` = „Code:Index“, die Angaben kommen aus dem Treffer auf dem Server). Am Foto ein kleines „©“; Antippen zeigt „Foto: Open Food Facts contributors, CC BY-SA 3.0“ bzw. „Bild über UPCitemdb, Quelle: shop.de“ mit Link. Über `source_host` lassen sich alle Bilder eines Shops finden. Kopien (mehrere Stücke) behalten die Angaben.
- **Bedingungen:** Open Food Facts verlangt einen eigenen User-Agent (`Stocks/<Version> (<APP_URL>; <Kontaktadresse>)`, `UserAgent`), höchstens 100 Produktabfragen pro Minute, Namensnennung (ODbL für Daten, CC BY-SA für Bilder). UPCitemdb: Nutzung für den eigenen Betrieb, keine Namensnennung verlangt; die Rechte an den Shop-Bildern bleiben bei Shops und Herstellern. Die Quellenangabe steht klein unter dem Treffer.
- **Einstellung** `inventory.product_lookup` (Verwaltung › Einstellungen › Allgemein, „Produktdaten per Barcode“, Vorgabe an): aus = keine Abfragen nach außen, nur das Feld EAN.

**Mengenartikel oder mehrere Einzelstücke:** Ist egal, welches Stück jemand bekommt, und muss man es nicht einzeln wiedererkennen: Menge (eine Nummer, ein Etikett). Sonst Einzelstücke (je Stück Nummer, Etikett, Zustand und Ort). Ein Mengenartikel liegt danach an seinem Ort oder im Eingang.

## Artikelnummern

`FSR5-004218`: Präfix der Instanz, laufende Zahl (mindestens fünf Stellen, `00421`) und Prüfziffer (`8`).

- **Präfix:** Einstellung „Präfix der Artikelnummern“ (1–6 Großbuchstaben oder Ziffern). Leer bedeutet: Kurzname der Organisation ohne Leer- und Sonderzeichen.
- **Laufende Zahl:** je Präfix in `number_sequences`; die Zeile ist beim Vergeben gesperrt, so bekommen zwei gleichzeitig angelegte Gegenstände nie dieselbe Nummer. Nach 99999 wird sie sechsstellig.
- **Prüfziffer:** Luhn-Verfahren. Eine vertippte Ziffer oder zwei vertauschte fallen auf; die Suche meldet dann „Die Prüfziffer stimmt nicht“.
- **Fest:** Eine Nummer ändert sich nie, auch nicht bei neuem Präfix.
- **Eingabe:** mit oder ohne Bindestrich, groß oder klein (`fsr5004218`).

## Umlagern und Bewegungsprotokoll

Die Seite **Umlagern** arbeitet in zwei Schritten: erst den Gegenstand oder die Box scannen, dann das Ziel (Kennziffer oder Boxnummer). Danach wird sofort gebucht, und die Seite wartet auf den nächsten Scan. Ein Barcode-Handscanner tippt den Code wie eine Tastatur. Bei Mengenartikeln fragt die Seite die Menge ab, vorausgefüllt mit allem. Auf der Seite eines Gegenstands gibt es dasselbe als Dialog mit Vorschlägen.

**Ort / Stammplatz / Beides** (seit 27.11.2026, `InventoryMoveTargetSwitch`): Mit `inventory.edit` zeigen Seite „Umlagern“, Scan-Karte („Umlagern“, „Hierher umlagern“) und der Dialog am Gegenstand einen Umschalter, Vorgabe „Ort“. `POST /inventory/move` nimmt `mode`: `place` (wie bisher), `home` (nur der Stammplatz ändert sich, der Gegenstand bleibt liegen; kein Eintrag im Bewegungsverlauf, das Änderungsprotokoll hält alten und neuen Stammplatz fest) oder `both` (umlagern, und das Ziel wird Stammplatz; bei einem Mengenartikel der Vorschlag). Ohne `inventory.edit` lehnt der Server `home` und `both` ab. Für den Stammplatz gelten dieselben Regeln wie für einen Ort (`Placement::check`).

Jede Änderung des Orts schreibt eine Zeile in `item_movements`:

| Vorgang | Action | Art |
|---|---|---|
| Anlegen | `CreateItem` | `create`, von nirgends an den Ort |
| Umlagern, auch zurück an den Stammplatz | `MoveItem` | `move` |
| Stücke eines Mengenartikels umlagern | `MoveItem` mit Menge und Stelle (`StockBook::transfer`) | `move` mit Menge, von Stelle zu Stelle |
| Wareneingang | `StockBook::receive` | `receive` mit Menge, an die Stelle (ohne Stelle: Eingang) |
| Zählen einer Stelle | `StockBook::count` | `count` |
| Ausmustern | `RetireItem` | `retire`, vom Ort nach nirgends, Notiz „Grund: Notiz“ |
| Verbraucht | `RetireItem` mit Grund verbraucht, Menge und Stelle | `consume` mit Menge von der Stelle; auch bei 0 Stück bleibt der Gegenstand |
| Teilmenge ausmustern | `RetireItem` mit Menge und Stelle | `retire` mit Menge |
| Ausgabe mit einer Buchung (Modul Verleih) | `MoveItem::takeAway`, bei Mengenartikeln `StockBook::takeOut` | `issue`, vom Ort (der Stelle) nach nirgends, Notiz mit der Buchungsnummer |
| Rücknahme (Modul Verleih) | `ItemMovement::record` | `return`, ohne Ort; Einzelstücke werden danach mit `move` eingeräumt, Stücke eines Mengenartikels mit `StockBook::putBack` (`return` an die Stelle) |

**Sperren durch Module.** Ein Modul kann einen Gegenstand sperren (`ItemExtensions::lockOf`, siehe [MODULES.md](MODULES.md#gegenstände)). Solange er ausgegeben ist (Einzelstück, Box, Inhalt einer Box; nicht ausgegebene Stücke eines Mengenartikels), lehnen `MoveItem` und `RetireItem` ab und nennen den Grund; Seite und Scan-Karte zeigen „Umlagern“ und „Ausmustern“ dann nicht.

Wird eine Box bewegt, bekommt nur die Box eine Zeile. Zeilen werden nie geändert; ein falscher Ort wird durch eine neue Bewegung korrigiert. Feldänderungen an Gegenständen, Standorten und Kategorien stehen zusätzlich im Änderungsprotokoll.

**Einträge löschen.** Mit dem Recht `inventory.history.delete` (Vorgabe: nur Admin) lassen sich Bewegungen und Zustandswechsel aus dem Verlauf löschen, z. B. eine Fehlmeldung oder ein Zustandswechsel, dessen Fotos nie auf S3 ankamen. Fotos eines Zustandswechsels werden mit gelöscht; fehlt die Datei auf S3, wird nur der Eintrag entfernt. Ort und Zustand des Gegenstands ändern sich dabei nicht (`ItemHistoryController`). Das Änderungsprotokoll hält beim Gegenstand fest, wer welchen Eintrag gelöscht hat und was darin stand („Verlaufseintrag gelöscht“). Zustandswechsel stehen dort mit Namen („Zustand geändert“), gelöschte Zustände als „Gelöscht“.

## Zustand

Die Zustände verwalten Admins unter **Verwaltung › Einstellungen › Zustände** (Recht `inventory.options.manage`, Tabelle `item_conditions`). Vorgabe: neu, gut, gebraucht, defekt. Ein Zustand kann „bedeutet einen Schaden“ sein; er wird dann rot hervorgehoben und ist später für den Verleih gesperrt. Gelöscht wird ein Zustand nur, wenn kein Gegenstand ihn hat, und einer bleibt immer.

Der Zustand eines Gegenstands ändert sich nur über „Zustand ändern“ (`ChangeItemCondition`), nicht beim Bearbeiten:

- Eine Begründung ist Pflicht, Fotos sind möglich und empfohlen.
- Jeder Wechsel steht mit altem und neuem Zustand, Begründung, Fotos, Person und Zeit in `item_condition_changes` und im Verlauf des Gegenstands. Die Zustände sind dort als Namen gespeichert, so bleibt der Verlauf lesbar, wenn ein Zustand umbenannt oder gelöscht wird.
- `loan_id` verknüpft später einen Wechsel mit einem Verleih (Verleih-Modul, Phase 3).
- Zustandsfotos sind `item_photos` mit `condition_change_id` und erscheinen nicht in der Galerie.

## Einheiten

Mengenartikel wählen ihre Einheit aus einer Liste, die Admins unter **Verwaltung › Einstellungen › Einheiten** pflegen (Recht `inventory.options.manage`, Tabelle `item_units`). Vorgabe: Stück, Meter, Rollen, Packungen, Sets; Rollen und Packungen sind als **Verbrauchsmaterial** markiert. Gegenstände speichern den Namen (`items.unit`), damit Verlauf und Meldungen lesbar bleiben; Umbenennen ändert ihn auch bei den Gegenständen. Löschen geht nur, wenn kein Gegenstand die Einheit hat.

## Ausmustern und Löschen

**Ausmustern** (`RetireItem`) statt löschen: Datum, Grund (verbraucht, defekt, verloren, verkauft, entsorgt, sonstiges) und Notiz; der Gegenstand verliert seinen Ort, behält Nummer, Fotos und Verlauf und ist in Listen ausgeblendet (Filter „Ausgemusterte zeigen“). Eine Box muss dafür leer sein.

**Verbraucht.** Mengenartikel, deren Einheit als Verbrauchsmaterial markiert ist, haben auf ihrer Seite einen eigenen Knopf „Verbraucht“: Menge und optional wofür, fertig. Die Menge wird abgezogen und als Bewegung `consume` („Verbraucht“) im Verlauf vermerkt; bei der ganzen Menge wird der Gegenstand ausgemustert. Recht: `inventory.move`, damit es jedes Mitglied schnell erledigen kann (`POST /inventory/items/{id}/consume`). Andere Mengenartikel haben den Knopf nicht.

**Teilmenge ausmustern.** Bei Mengenartikeln fragt „Ausmustern“ eine Menge ab (Vorgabe: alles), z. B. zwei defekte Kabel. Weniger als alles zieht die Menge ab und vermerkt sie mit Grund im Verlauf; der Gegenstand bleibt mit dem Rest an seinem Ort. „verbraucht“ steht dort nicht zur Wahl, das hat den eigenen Knopf. Recht: `inventory.retire`.

**Löschen** (`DeleteItem`) nur bei Fehleingaben: keine Bewegung außer dem Anlegen, keine Zustandswechsel, nicht als Box, Stammplatz oder Ursprung einer Teilung genutzt.

## Fotos auf S3

Fotos liegen immer auf der Disk `s3` unter `items/<id>/<zufälliger Name>`; der Bucket bleibt privat.

- Fotos kommen schon beim Anlegen dazu oder später auf der Seite des Gegenstands. Bis zu fünf Fotos je Gegenstand, JPG, PNG oder WebP, höchstens 5 MB. SVG ist verboten, weil es Skripte enthalten kann.
- Der Browser verkleinert Fotos vorher auf 1600 Pixel (`useImageCompression`, aus Portals).
- Stocks liefert Fotos über `/inventory/photos/{id}` mit Rechteprüfung und einem Tag Browser-Cache aus. Der S3-Server muss für Besucher nicht erreichbar sein.
- **Vorschaubilder** (seit v0.1.1, `App\Support\Inventory\PhotoThumbnail`): zu jedem Foto ein JPEG von höchstens 256 Pixeln unter `items/<id>/thumbs/<Name>.jpg`, Pfad in `item_photos.thumb_path`. Es entsteht beim Hochladen und beim Kopieren; die Migration `2026_11_26_000000_add_thumb_path_to_item_photos` erzeugt es für alle vorhandenen Fotos (scheitert nie, bricht nach fünf Fehlern in Folge ab). Fehlt es noch, entsteht es beim ersten Abruf (`ItemPhoto::thumbnailPath()`); geht auch das nicht (S3 weg, WebP ohne WebP im GD), kommt das Foto selbst. Abruf mit `?thumb=1` (`ItemPhoto::thumbUrl()`, öffentlich signiert über `/s-photos/{id}?thumb=1`). Listen, Box-Inhalt, Scan-Karte, öffentliche Inhaltsliste und PDF-Inhaltsliste nutzen das Vorschaubild, die Galerie und die große öffentliche Ansicht das Foto. Löschen entfernt beide Dateien (`ItemPhoto::files()`).
- Das erste Foto ist das Vorschaubild; „Als Vorschau“ ändert die Reihenfolge und nummeriert die Galerie ab 1 neu (vor dem 27.11.2026 setzte es „kleinster Wert − 1“, ab dem zweiten Wechsel negativ, und `item_photos.sort` nimmt keine negativen Werte: Serverfehler). Löschen entfernt auch die Datei.
- **Android** öffnet bei einem Dateifeld direkt Galerie oder Dateien, ohne die Kamera anzubieten (iOS fragt selbst). Deshalb zeigen `UiPhotoPicker` und die Gegenstandsseite unter Android eigene Knöpfe „Kamera“ (`capture="environment"`, ein Foto) und „Galerie“ (`usePlatform`).

**MinIO:** `docker-compose.yaml` und `docker-compose.sail.yaml` starten MinIO wie in Portals (`ghcr.io/coollabsio/minio`, S3-API auf Port 9000, Konsole auf http://localhost:9001). Der Dienst `minio-setup` wartet, bis MinIO bereit ist, legt den Bucket aus `AWS_BUCKET` an und beendet sich; Exit-Status 0 ist richtig. Zugang standardmäßig `minioadmin`/`minioadmin123`, im Betrieb über die `.env` ändern.

**Anderer S3-Speicher:** `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`, `AWS_ENDPOINT`, `AWS_USE_PATH_STYLE_ENDPOINT` setzen (siehe [SETUP.md](SETUP.md)); die MinIO-Dienste kann man dann weglassen.

## Rechte

| Recht | Erlaubt | Freies Mitglied | FSR-Mitglied | Vorstand |
|---|---|---|---|---|
| `inventory.view` | Inventar ansehen | ja | ja | ja |
| `inventory.move` | Umlagern, Einpacken, Auffüllen, Zustand ändern, Verbrauchsmaterial ausbuchen | ja | ja | ja |
| `inventory.edit` | Anlegen, Bearbeiten, Fotos, Wareneingang, Zählen und Sollmenge | – | ja | ja |
| `locations.manage` | Standorte und Kategorien | – | ja | ja |
| `inventory.options.manage` | Zustände und Einheiten verwalten | – | – | – (nur Admin) |
| `inventory.history.delete` | Einträge aus dem Verlauf löschen | – | – | – (nur Admin) |
| `inventory.retire` | Ausmustern (auch Teilmengen), Fehleingaben löschen | – | – | ja |

Die Spalten sind die Vorgabe der Startrollen in `config/stocks.php`; danach ändert man sie in der Rollenverwaltung.

## Code

| Ort | Inhalt |
|---|---|
| `app/Models` | `Location`, `Category`, `Item`, `ItemCondition`, `ItemPhoto`, `ItemMovement`, `ItemConditionChange` |
| `app/Enums` | `LocationType`, `ItemKind`, `RetireReason`, `MovementType` mit deutschen Bezeichnungen |
| `app/Actions/Inventory` | ein Vorgang je Klasse, siehe Tabellen oben |
| `app/Support/Inventory` | `ItemNumbers` (Nummern, Prüfziffer, Platzhalter), `CodeLookup` (Code → Gegenstand oder Standort), `Placement` (Regeln für Boxen), `ItemInput` (Regeln und Felder von Formular und Erfassen) |
| `app/Support/Table/ItemsTable.php` | Liste mit Suche, Filtern und Live-Update |
| `app/Http/Controllers/Inventory` | Seiten und JSON-Endpunkte (`lookup`, `places`, `stats`); `CaptureController` für das Erfassen |
| `resources/js/pages/Inventory/Capture.vue`, `composables/useCapture.ts`, `components/inventory/capture/` | Erfassen |
| `app/Support/Products` | Barcode der Verpackung: `Gtin`, `ProductLookup` mit den Quellen `UpcItemDb` und `OpenFacts`, `ImageFetcher`, `UserAgent` |
| `resources/js/pages/Inventory` | Seiten; Bausteine in `resources/js/components/inventory` |
| `tests/Feature/Inventory` | Tests je Regel |
| `database/seeders/InventorySeeder.php` | Demo-Inventar |
