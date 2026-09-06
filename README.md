# JSON-LD Manager für REDAXO

JSON-LD Manager ist ein REDAXO-AddOn zur Verwaltung und Ausgabe strukturierter Daten (Schema.org) für Website, Organization, Person, LocalBusiness, WebPage, BreadcrumbList und dynamische URL-Profile.

Das AddOn bündelt die JSON-LD-Konfiguration zentral im Backend und generiert die Ausgabe konsistent für Vorschau und Frontend.

Inspiriert vom JSON-LD AddOn von Peter Wolfrum.

## Schnell erklärt

Das AddOn erzeugt strukturierte Daten im JSON-LD-Format für deine Website. Damit können Suchmaschinen Inhalte, Unternehmen, Standorte und einzelne Seiten besser einordnen.

Wichtig ist dabei nicht nur, dass JSON-LD vorhanden ist, sondern dass es inhaltlich sauber und nur dort ausgegeben wird, wo es wirklich passt.

## Empfohlene Reihenfolge

### 1. Allgemeine Angaben zuerst pflegen

Nach der Installation solltest du zuerst unter `Allgemeine Angaben` die Basisdaten der Website pflegen:

- `Organization Schema`
- `WebSite Schema`
- optional `Person Schema` (z. B. Künstler:in / Betreiber:in der Website)
- optional `LocalBusiness`

Wenn du ein lokales Unternehmen mit realem Standort hast, ist es sinnvoll, mindestens ein `LocalBusiness` anzulegen. Wenn es mehrere Standorte gibt, können auch mehrere Standorte gepflegt werden.

Diese Angaben sind die Grundlage für die spätere Ausgabe auf den Artikeln.

### 2. Danach die einzelnen Artikel prüfen

Anschließend kannst du pro Artikel festlegen, wie mit JSON-LD umgegangen werden soll.

Dort sind vor allem diese Möglichkeiten wichtig:

- Standard-Ausgabe für den Artikel verwenden
- einen bestimmten `LocalBusiness`-Standort zuordnen
- eigenes Custom-JSON für einen Artikel hinterlegen
- JSON-LD für einen Artikel bewusst deaktivieren

Nicht jede Seite sollte automatisch JSON-LD ausgeben. Das ist auch aus SEO-Sicht wichtig.

Seiten können zum Beispiel bewusst ohne JSON-LD bleiben, wenn:

- sie nur sehr wenig oder wenig hilfreichen Inhalt haben
- es reine Hilfs-, Filter- oder technische Seiten sind
- die hinterlegten Schema-Daten nicht wirklich zum Seiteninhalt passen
- die Ausgabe eher unklar oder widersprüchlich wäre

Die Faustregel ist einfach: Lieber korrekt und gezielt als überall etwas ausgeben.

### 3. Zum Schluss die Ausgabe in den Einstellungen aktivieren

Unter `Einstellungen` legst du fest, ob die JSON-LD-Ausgabe im Frontend wirklich aktiv sein soll.

Dort sind besonders diese Punkte wichtig:

- `Automatische Ausgabe`
- `Template-Auswahl`
- `Cache`
- `Validierung`
- `Debug-Modus`

Vor allem die `Template-Auswahl` ist wichtig: Es muss mindestens ein passendes Template ausgewählt sein. Nur dann darf das AddOn die JSON-LD-Ausgabe automatisch im Frontend einbinden.

Die übrigen Optionen helfen dabei:

- `Cache` verbessert die Performance
- `Validierung` prüft die JSON-Syntax
- `Debug-Modus` hilft bei der Kontrolle während der Entwicklung

### 3a. Sprachangaben kopieren (Einstellungen)

Im Bereich `Einstellungen` steht bei mehrsprachigen Projekten die Funktion `Sprachangaben kopieren` zur Verfügung.

Damit kannst du eine vollständige sprachabhängige JSON-LD-Konfiguration von einer Quellsprache in eine Zielsprache übernehmen.

Kopiert werden:

- sprachbezogene globale Konfigurationen (`Organization`, `WebSite`, domain-spezifische Sprach-Keys)
- artikelbezogene Schema-Zuordnungen (`jsonld_schemas`)
- LocalBusiness-Standorte der Sprache (`jsonld_localbusiness_branches`)

Wichtiges Verhalten:

- Bestehende Daten der Zielsprache werden vor dem Kopieren entfernt und anschließend durch die Quell-Daten ersetzt.
- Beim Kopieren der Standorte wird ein internes ID-Mapping erstellt, damit Artikel-Zuordnungen in der Zielsprache auf die neu erzeugten Standort-IDs zeigen.

Empfehlung:

- Die Funktion nur bewusst einsetzen (z. B. initiale Sprachanlage oder kompletter Relaunch einer Sprache), da sie die Zielsprache überschreibt.

Hinweis:

- Die Seite `Sprachangaben kopieren` wird nur angezeigt, wenn im System mehr als eine Sprache vorhanden ist.

### 4. Optional: Dynamische URLs

Wenn das AddOn `URL` installiert ist und dort mindestens ein Profil angelegt wurde, kannst du zusätzlich den Bereich `Dynamische URLs` nutzen.

Dort lassen sich JSON-LD-Zuordnungen für dynamische Inhalte konfigurieren, zum Beispiel für Datensätze aus YForm-Tabellen, die über URL-Profile ausgegeben werden.

Auf einer dynamischen URL ersetzt das zugeordnete Schema (z. B. `Product`) das `WebPage`-Schema im gemeinsamen `@graph` neben `Organization`, `WebSite` und `BreadcrumbList`; es wird nur einmal ausgegeben. Wer die automatische Ausgabe abgeschaltet hat, kann das Schema eines Datensatzes im Template auch einzeln mit `generateDynamicJsonLd($profileId, $dataId)` als eigenes Script-Tag ausgeben.

Das ist optional und nicht nötig, um die normale JSON-LD-Ausgabe für Website und Artikel zu verwenden.

#### Strukturierte Teilobjekte (Angebot, Adresse, Öffnungszeiten, …)

Einige Schema-Properties verlangen kein einfaches Textfeld, sondern ein eigenes Objekt, zum Beispiel `Product.offers` (`Offer` mit Preis, Währung und Verfügbarkeit) oder `LocalBusiness.address` (`PostalAddress`). Für diese Properties bietet das Feld-Mapping neben „Feld“ und „Statischer Wert“ die Option **Strukturiert**: Die Unterfelder werden einzeln aus YForm-Spalten oder festen Werten befüllt, das AddOn baut daraus ein valides Teilobjekt.

Unterstützt werden:

| Property | Objekt | Unterfelder |
| --- | --- | --- |
| `offers` | `Offer` | Preis, Währung, Verfügbarkeit, Preis gültig bis, URL |
| `brand` | `Brand` | Name |
| `aggregateRating` | `AggregateRating` | Bewertung, Anzahl, beste Bewertung |
| `address` | `PostalAddress` | Straße, PLZ, Ort, Region, Land |
| `contactPoint` | `ContactPoint` | Telefon, E-Mail, Kontaktart |
| `location` | `Place` | Name plus Adressfelder |
| `organizer`, `provider` | `Organization` | Name, URL |
| `author` | `Person` | Name, URL |
| `openingHoursSpecification` | `OpeningHoursSpecification[]` | je Zeile Wochentage, Öffnet, Schließt |

Werte werden dabei normalisiert: Preise wie `12,50 €` werden zu `12.50`, Verfügbarkeiten akzeptieren `InStock`/`OutOfStock` ebenso wie Ja/Nein- oder 1/0-Felder, Wochentage dürfen als `Mo`, `Montag` oder `Monday` vorliegen.

Gespeichert wird das als `{"type":"nested","fields":{"price":{"type":"field","value":"preis"},"priceCurrency":{"type":"static","value":"EUR"}}}` bzw. `{"type":"opening_hours","rows":[{"days":["Monday","Friday"],"opens":{"type":"field","value":"mo_von"},"closes":{"type":"field","value":"mo_bis"}}]}` neben den bisherigen flachen Mappings.

#### FAQ-Seiten und Übersichtslisten

Eine URL-Profil-Zuordnung erzeugt pro Datensatz genau ein Schema-Objekt. `FAQPage` (alle Frage/Antwort-Paare in einem `mainEntity`-Array) und `ItemList`/`CollectionPage` (mehrere Einträge einer Übersichtsseite) lassen sich damit nicht abbilden. Dafür gibt es Template-Funktionen, die mehrere YForm-Zeilen zusammenfassen und direkt im Template oder Modul ausgegeben werden:

```php
// FAQPage aus einer YForm-Tabelle (alle aktiven Zeilen, sortiert nach prio)
echo jsonld_render_faq('rex_faq', 'frage', 'antwort', ['status' => 1], [
    'order_by' => 'prio ASC',
    'name' => 'Häufige Fragen',
]);

// ItemList für eine Produktübersicht, Detail-URLs über das URL-AddOn (Namespace "produkt")
echo jsonld_render_item_list('rex_produkte', 'Product', ['status' => 1, 'kategorie_id' => 3], [
    'name' => 'titel',
    'image' => 'bild',
    'offers' => ['type' => 'nested', 'fields' => [
        'price' => ['type' => 'field', 'value' => 'preis'],
        'priceCurrency' => ['type' => 'static', 'value' => 'EUR'],
    ]],
    'sku' => static fn (array $row): string => 'P-' . $row['id'],
], [
    'url_namespace' => 'produkt',
    'order_by' => 'titel ASC',
    'list_type' => 'CollectionPage',
]);
```

Filter sind einfache Spalte-Wert-Paare (Arrays ergeben `IN (...)`, `null` ergibt `IS NULL`); Tabellen-, Spalten- und Sortierangaben werden validiert. Feld-Zuordnungen akzeptieren Spaltennamen, Callables (`fn(array $row)`) oder die strukturierten Mapping-Formate von oben. Im Debug-Modus erscheinen die Schemas im Debug-Overlay.

#### PHP-Helfer für eigene Schemas

`FriendsOfRedaxo\JsonLdManager\SchemaHelper` stellt statische Methoden für valide Teilobjekte bereit, die sich in eigenen Templates und Modulen verwenden lassen: `offer()`, `openingHoursSpecification()`, `postalAddress()`, `contactPoint()`, `geoCoordinates()`, `aggregateRating()`, `brand()`, `organization()`, `person()`, `place()`, `question()`, `faqPage()`, `itemList()` sowie `withType()` für beliebige Typen. Leere Werte werden entfernt; ein Objekt ohne Inhalt liefert ein leeres Array. Ein fertiges Schema-Array gibt `jsonld_render_schema($schema)` als `<script type="application/ld+json">` aus.

```php
use FriendsOfRedaxo\JsonLdManager\SchemaHelper;

$product = [
    '@context' => 'https://schema.org',
    '@type' => 'Product',
    'name' => $row['titel'],
    'brand' => SchemaHelper::brand($row['marke']),
    'offers' => SchemaHelper::offer($row['preis'], 'EUR', $row['lieferbar']),
];
echo jsonld_render_schema($product);
```

## Anforderungen

- REDAXO >= 5.20.0
- PHP >= 8.3

### Erforderliche AddOns

- **YForm >= 5.0.1** (Datenmanagement und Formulare)

### Empfohlene AddOns

- **YRewrite >= 1.12.0** (URL-Generierung und Multi-Domain-Support)
  - Ohne YRewrite funktioniert das AddOn mit Standard-REDAXO URLs
  - Mit YRewrite: SEO-URLs und Multi-Domain-Support

## Funktionsumfang

### Backend

- Artikelseite mit JSON-LD-Vorschau pro Artikel
- Sprachauswahl mit persistenter Auswahl innerhalb des AddOns
- Custom JSON pro Artikel und Sprache
- JSON-LD pro Artikel deaktivierbar (pro Sprache)
- Zuordnung eines LocalBusiness-Standorts pro Artikel und Sprache
- Dynamische URL-Profile mit Schema-Mapping (wenn URL-Addon aktiv ist)
- Strukturierte Feld-Zuordnung für verschachtelte Properties (Angebot, Adresse, Öffnungszeiten, Veranstaltungsort, …)
- Debug-Modus mit JSON-LD Overlay im Frontend

### Allgemeine Angaben

- Organization Schema (sprachabhängig)
- WebSite Schema (sprachabhängig)
- Person Schema (sprachabhängig)
- LocalBusiness Standortverwaltung (sprachabhängig)
- Domainabhängige llms.txt-Verwaltung (Editor, Grundstruktur, Import/Export)

### Frontend

- Einheitliche Generierung über `FriendsOfRedaxo\JsonLdManager\JsonLdGenerator`
- Sprachabhängige Ausgabe über `clang_id`
- Branch-spezifische LocalBusiness-Daten pro Sprache
- Ausgabe bereinigt leere Werte rekursiv (nur befüllte JSON-LD Felder)
- Template-Funktionen `jsonld_render_faq()`, `jsonld_render_item_list()` und `jsonld_render_schema()` für FAQ-Seiten, Übersichtslisten und eigene Schemas
- `SchemaHelper` für valide Teilobjekte (Offer, PostalAddress, OpeningHoursSpecification, AggregateRating, …)




## llms.txt-Verwaltung

Unter `Allgemeine Angaben > llms.txt` steht ein eigener Editor zur Verfügung.

**Verhalten beim Speichern:**
- Der Inhalt wird pro Domain und aktiver REDAXO-Sprache in der AddOn-Konfiguration gespeichert.
- Bei mehreren Domains wird die add-onweit aktive Domain über die vorhandene Domain-Auswahl unter `Einstellungen` festgelegt; auf der llms.txt-Seite wird zusätzlich die vorhandene Sprachauswahl des AddOns verwendet.
- Die YRewrite-Startsprache jeder Domain ist am Standardpfad `/llms.txt` erreichbar, zum Beispiel `https://domain-a.de/llms.txt` und `https://domain-b.de/llms.txt`.
- Weitere aktive und von der Domain unterstützte Sprachen werden unter dem tatsächlich von YRewrite erzeugten Sprachpfad ausgeliefert, zum Beispiel `/en/llms.txt`. Stellt YRewrite ausnahmsweise keinen Sprachpfad bereit, wird `/llms_{sprachcode}.txt` verwendet. Sprachcodes, Startsprache und Präfixe sind nicht hartcodiert.
- Ohne YRewrite bleibt `/llms.txt` der Startsprache vorbehalten; weitere aktive Sprachen sind als `/llms_{sprachcode}.txt` erreichbar.
- Die Ausgabe erfolgt dynamisch als `text/plain; charset=UTF-8` und bezieht sich ausschließlich auf die aktuell aufgerufene Domain und Sprache.
- Bei leerem Inhalt antwortet die jeweilige Sprach-URL mit HTTP 404. Es gibt keinen Fallback auf Inhalte einer anderen Domain oder Sprache.

**Grundstruktur und Import/Export:**
- `Grundstruktur laden` lädt eine empfohlene Vorlage in den Editor (ohne sofortiges Speichern).
- Import akzeptiert nur `.txt` und `.md` Dateien, blockiert ausführbare Code-Inhalte und ändert ausschließlich die ausgewählte Domain.
- Export liefert den Inhalt der ausgewählten Domain und Sprache mit dem Dateinamen `llms.txt`.

**Sicherheit:**
- Uploads werden serverseitig geprüft (Dateiendung, MIME-Typ, Inhaltsprüfung auf potenziell ausführbaren Code).

**Migration bestehender Installationen:**
- Ein vorhandener globaler Config-Inhalt und/oder eine physische Datei `llms.txt` wird beim Update einmalig und ohne Überschreiben bestehender Inhalte der primären Domain und deren YRewrite-Startsprache zugeordnet.
- Bei mehreren Domains wird der alte Inhalt nicht auf alle Domains kopiert.
- Eine physische `llms.txt` wird erst nach verifizierter Übernahme oder separater Sicherung entfernt, damit sie die dynamische Auslieferung nicht durch die Webserver-Regel für vorhandene Dateien blockiert.

## Legacy Meta-Integration

Mit der Funktion **Legacy Meta-Integration** können alte oder zusätzliche Meta-/SEO-Tags zentral im Backend gepflegt werden. Diese werden – zusätzlich zur JSON-LD-Ausgabe – im HTML-Head nach dem letzten vorhandenen <meta> Tag ausgegeben (nur in den unter Einstellungen aktivierten Templates).

**Sicherheit:**
- Es sind ausschließlich reine Meta-Tags und statisches HTML erlaubt. PHP, JavaScript, Event-Handler und unsichere Tags werden automatisch blockiert.
- Die Eingabe erfolgt über ein eigenes Backend-Formular mit Validierung und CSRF-Schutz.

**Funktionsweise:**
- Die gepflegten Meta-Tags werden im Frontend-Head nach dem letzten <meta> Tag eingefügt (Fallback: vor </head>).
- Die Ausgabe erfolgt nur in Templates, die in den AddOn-Einstellungen aktiviert wurden.
- Die Daten werden zentral in der REDAXO-Konfiguration gespeichert.

**Typische Anwendungsfälle:**
- Migration alter SEO-/Meta-Tags aus früheren Systemen
- Ergänzung spezieller Meta-Informationen, die nicht über REDAXO-Metainfo gepflegt werden

**Hinweis:**
Die Funktion richtet sich an Entwickler und Redakteure, die volle Kontrolle über die Meta-Ausgabe benötigen, ohne die Templates direkt anpassen zu müssen.

Das AddOn ist auf Mehrsprachigkeit ausgelegt:

- Sprachbezogene Konfigurationen über `LanguageConfig`
- Artikel-Zuordnungen (Branch, Custom JSON, Disable-Flag) pro Sprache
- LocalBusiness-Standorte pro Sprache (`clang_id`)
- Sprachwahl wird im AddOn gespeichert und beim Seitenwechsel beibehalten

## Multi-Domain-Support

Das AddOn unterstützt vollständig YRewrite Multi-Domain-Installationen:

### Automatische Erkennung
- Erkennt automatisch ob eine Multi-Domain-Installation vorliegt
- Bei nur einer Domain verhält sich das AddOn wie gewohnt
- Bei mehreren Domains wird eine Domain-Auswahl eingeblendet

### Domain-spezifische Konfiguration
- **Alle Einstellungen** werden getrennt pro Domain verwaltet
- **Grundeinstellungen**: Auto-Output, Cache, Debug-Modus etc. pro Domain
- **Schema-Konfigurationen**: Organization, WebSite, LocalBusiness pro Domain
- **Artikel-Zuordnungen**: Custom JSON, Branch-Zuweisungen etc. pro Domain
- **Dynamic URLs**: URL-Profile und Mappings pro Domain
- **llms.txt**: Eigener Inhalt pro Domain und Sprache; `/llms.txt` für die jeweilige Startsprache und YRewrite-Sprachpfade für weitere Sprachen

### Benutzeroberfläche
- **Domain-Auswahl**: Dropdown-Menü zum Wechseln zwischen Domains
- **Domain-Anzeige**: Aktuelle Domain wird in der Kopfzeile angezeigt
- **Persistente Auswahl**: Gewählte Domain bleibt beim Navigieren erhalten
- **Debug-Information**: Frontend-Debug zeigt Domain-Information an

### Strikte Domain-Trennung
- **Keine automatische Migration** zwischen Domains
- **Separate Datenbankeinträge** für jede Domain
- **Manuelle Zuordnung**: LocalBusiness-Standorte können manuell zugeordnet werden
- **Eigenständige Konfiguration**: Jede Domain startet mit leeren Einstellungen

## Sicherheit

Für schreibende Backend-Aktionen wird CSRF-Schutz verwendet:

- Einstellungen
- Organization/WebSite/LocalBusiness-Speichern
- Artikelaktionen (Custom JSON speichern, JSON deaktivieren/aktivieren, Branch-Zuordnung)
- schreibende AJAX-Aktionen im AddOn

## Installation

1. AddOn installieren und aktivieren.
2. Allgemeine Angaben (Organization/WebSite/LocalBusiness) konfigurieren.
3. Artikelseite öffnen und Zuordnungen/Overrides pro Artikel setzen.

## Veröffentlichung

- Namespace: `FriendsOfRedaxo\JsonLdManager`
- Repository/Support: `https://github.com/FriendsOfREDAXO/jsonld_manager`
- Lizenz: MIT

## Hinweise zur Integration

Die Generierung läuft zentral über die Klassen im AddOn. Je nach Projekt-Setup wird die Ausgabe über Renderer/Template-Funktionen eingebunden.

Wenn bereits projektspezifische Template-Logik existiert, sollte die Integration dort konsistent umgesetzt werden.

Ist das URL-Addon deaktiviert oder ohne Profile, wird der Bereich "Dynamische URLs" im Backend ausgeblendet.

## Changelog und Lizenz

- Änderungen: [CHANGELOG.md](CHANGELOG.md)
- Lizenz: [LICENSE.md](LICENSE.md)
