
# Changelog

## v1.0.12 (25. August 2026)

### Added
- Domain- und sprachabhängige Speicherung sowie dynamische Auslieferung von `llms.txt`.
- YRewrite-Multidomain- und Mehrsprachen-Unterstützung für Editor, Speichern, Leeren, Grundstruktur, Import, Export und Frontend-Debug-Anzeige.
- Dynamische Sprachrouten: `/llms.txt` für die YRewrite-Startsprache und der jeweilige YRewrite-Sprachpfad für weitere aktive Sprachen.
- Verlustfreie, idempotente Migration bestehender globaler Config-Inhalte und physischer `llms.txt`-Dateien zur primären Domain und deren Startsprache.

### Changed
- `llms.txt` wird nicht mehr als gemeinsame Datei im Webroot gepflegt, sondern getrennt pro Domain und Sprache in `rex_config` gespeichert.
- Leere Inhalte liefern auf der jeweiligen Sprachroute HTTP 404 ohne Fallback auf andere Domains oder Sprachen.
- Der Endpoint antwortet als reiner Text mit `Content-Type: text/plain; charset=UTF-8` und `X-Content-Type-Options: nosniff`.

### Security
- Schreibende Backend-Aktionen bleiben CSRF-geschützt und der öffentliche Endpoint ist ausschließlich lesend.
- Strikte Domainauflösung verhindert die Ausgabe fremder Domaininhalte bei unbekannten oder nicht eindeutig auflösbaren YRewrite-Hosts.

### Documentation
- README um domainabhängige Verwaltung, dynamische Auslieferung, Leerzustand und Migration ergänzt.

## v1.0.10 (06. August 2026)

### Added
- Neue Unterseite `llms.txt` unter `Allgemeine Angaben` mit Editor und Hinweisen.
- Bereich `Grundstruktur | Import / Export` in der rechten Spalte inkl. Buttons für `Grundstruktur laden`, `Datei importieren` und `Als Datei herunterladen`.
- Debug-Overlay zeigt `llms.txt` bei aktivem Debug-Modus als eigenen Eintrag.

### Security
- Import akzeptiert ausschließlich `.txt` und `.md`.
- Serverseitige Dateiprüfungen aktiv (Dateiendung, MIME-Typ, Größenlimit, Upload-Integrität).
- Inhalte mit potenziell ausführbarem Code werden blockiert (u. a. PHP-/Script-Tags, Shebangs, Codeblöcke, Ausführungsfunktionen).

### Changed
- Textarea schrittweise für bessere Bearbeitung deutlich erhöht.
- Speichern mit Inhalt schreibt `llms.txt` im Webroot; leeres Speichern löscht ausschließlich `llms.txt`.
- Initiale Vorlage dient nur als Start-Hilfe; bewusst leerer Zustand bleibt nach leerem Speichern erhalten.
- Inhalt wird zusätzlich in `rex_config` gespiegelt und kann über `Aus Config wiederherstellen` zurückgeschrieben werden.
- Soft-Checks erweitert (Strukturhinweise, empfohlene Abschnitte, lange Zeilen).

### Documentation
- README um Abschnitt zur `llms.txt`-Verwaltung ergänzt (Ablauf, Sicherheit, Import/Export).


## v1.0.2 (31. Juli 2026)

### Changed
- Reihenfolge der Tabs unter `Allgemeine Angaben` angepasst: `Person Schema` wird nun vor `LocalBusiness Schema` angezeigt.


## v1.0.1 (21. Juli 2026)

### Fixed
- Person-`image` wurde in der Backend-Vorschau der Artikel-Seite mit einer fehlerhaften URL (`…local../media/…`, zwei Punkte) ausgegeben. Ursache war `rex_url::media()`, das im Backend einen relativen Pfad (`../media/…`) liefert und beim Verketten mit der Base-URL die doppelten Punkte erzeugte. Der Media-Pfad wird nun explizit als `/media/…` gebaut und funktioniert in Frontend und Backend-Vorschau identisch. Frontend und `global_person` waren nicht betroffen. (#13)
- Breadcrumb-Liste: Kategorien, die über das `yrewrite_scheme`-AddOn ausgeschlossen sind, werden nicht mehr in der `BreadcrumbList` ausgegeben. Ersetzt einen zuvor projektspezifisch hartcodierten Kategorie-Filter. (#15)

### Changed
- Projektspezifische Rückstände entfernt: hartcodierter Breadcrumb-Kategorie-Filter und ein Beispiel-Placeholder im LocalBusiness-Slogan-Feld.


## v1.0.0 (20. Juli 2026)

Erstes stabiles Release – die Beta-Phase ist abgeschlossen.

### Added
- Neues globales **Person Schema** (`@type: Person`) unter `Allgemeine Angaben`. Pflegbar sind die wichtigsten Felder gemäß schema.org/Person: `name`, `jobTitle`, `url`, `image` (Portrait über Media-Widget, wird zu absoluter URL aufgelöst) und `sameAs` (Profil-/Referenz-Links).
- Custom-JSON-LD-Bereich auf der Person-Seite für zusätzliche Angaben wie `knowsAbout`, `alumniOf`, `memberOf`, `award` oder `nationality`.
- Person-Schema wird im Frontend automatisch in den JSON-LD-Graph eingehängt (`@id … /#person`) und – sofern vorhanden – per `worksFor` mit der Organisation verknüpft.

### Changed
- Person-Schema berücksichtigt Mehrsprachigkeit und Multi-Domain identisch zu den übrigen globalen Schemas (WebSite, Organization, LocalBusiness).
- Versionsnummer stabil auf `1.0.0` angehoben (Ende der Beta).

## v1.0.0.beta.20 (16. Juni 2026)

### Added
- Eigene Unterseite `Sprachangaben kopieren` im Bereich `Einstellungen` ergänzt, um sprachabhängige JSON-LD-Inhalte getrennt von den allgemeinen Settings zu verwalten.
- Sprachkopier-Funktion im Backend optisch an die übrigen AddOn-Seiten angepasst.

### Changed
- Sprachkopier-Flow aus der Settings-Seite herausgelöst und als eigene Seite geführt.
- Tab- und Button-Beschriftung von `Sprache kopieren` auf `Sprachangaben kopieren` vereinheitlicht.
- Sprachkopier-Seite wird nur angezeigt, wenn im System mehr als eine Sprache vorhanden ist.
- Settings-Seite bereinigt, damit dort nur noch allgemeine Konfigurationen bearbeitet werden.

### Fixed
- Unbeabsichtigte Wechselwirkungen zwischen Settings-Speichern und Sprachkopier-Formular beseitigt.
- Sprachkopier-Formular so umgebaut, dass es nicht mehr in den Settings-Submit hineinwirkt.
- Update-Meldungen des AddOns werden nicht mehr an einer falschen Stelle ausgegeben, sondern in den üblichen Installations-/Update-Kontext integriert.

## v1.0.0.beta.19 (08. Juni 2026)

### Added
- Umfassende Rexstan-Qualitätsrunde über das gesamte Addon durchgeführt und final auf fehlerfreien Stand gebracht.
- Zusätzliche Härtung für JavaScript-Datenübergabe in der URL-Mapping-Konfiguration ergänzt: JSON-Werte werden jetzt vorab berechnet und mit sicheren Fallbacks ausgegeben, damit fehlerhafte Eingabedaten kein ungültiges Inline-Skript erzeugen.

### Changed
- Signaturen, Typangaben und Rückgabetypen in zentralen Klassen und Backend-Seiten konsolidiert, um Laufzeitverhalten und statische Analyse in Einklang zu bringen.
- JSON-Decode-/Encode-Grenzen in mehreren Flows vereinheitlicht und defensiver umgesetzt.
- Interne Generator-Pfade für dynamische JSON-LD-Ausgabe bereinigt und auf konsistente Payload-Erzeugung umgestellt.
- Backend-Controller- und Formularlogik für Artikel-, Dynamic-URL-, WebSite- und LocalBusiness-Seiten strukturell verbessert, ohne öffentliche API zu ändern.

### Fixed
- Mehrere potenzielle Laufzeitprobleme bei gemischten Datentypen behoben (insbesondere bei Mapping-, Config- und Vorschaupfaden).
- Fehlerhafte bzw. fragile JSON-LD-Skriptgenerierung in dynamischen Fällen korrigiert.
- Statische Analysewarnungen zu Array-Shapes, immer-wahren Bedingungen und uneindeutigen Typpfaden in relevanten Dateien beseitigt.
- Kontaktpunkt-Filterung im LocalBusiness-Flow so angepasst, dass nur tatsächlich leere Werte entfernt werden und der Rest stabil bleibt.

### Quality
- Vollständiger Rexstan-Lauf auf Addon-Ebene erfolgreich abgeschlossen: keine verbleibenden Fehler.
- Bestehende Funktionalität wurde dabei rückwärtskompatibel weitergeführt (kein BC-Break in Konfiguration oder Ausgabeformat beabsichtigt).

### Notes
- Diese Version ist ein technischer Stabilisierungs- und Qualitätsrelease, der die Grundlage für die nächsten Feature-Schritte verbessert.

## v1.0.0.beta.18 (08. Juni 2026)

### Fixed
- Frontend-Fehler für nicht eingeloggte Besucher behoben: `Session not started, call rex_login::startSession() before!`.
- `DomainConfig::getActiveDomainId()` greift im Frontend nicht mehr auf `rex_session()` / `rex_set_session()` zu.
- Aktive Domain wird im Frontend über `rex_yrewrite::getCurrentDomain()` ermittelt.

### Improved
- `domainExists()` in `DomainConfig` prüft YRewrite-Verfügbarkeit und fängt SQL-Fehler robust ab.

## 2026-06-02

### Added
- Neues Fieldset `Sprache kopieren` auf der Einstellungsseite (`settings`) zum Kopieren aller sprachbezogenen JSON-LD Inhalte von einer Quelle in eine Zielsprache.
- Neue Custom-Fieldsets in den Formularen für Organization (`schemas`), WebSite (`global_website`) und LocalBusiness (`global_localbusiness`).
- Je Formular kann ein JSON-Objekt mit zusätzlichen Schema-Eigenschaften hinterlegt werden.

### Security
- Serverseitige JSON-Validierung/Sanitizing für Custom-Angaben hinzugefügt; ungültige Eingaben werden nicht gespeichert.
- Geschützte Schlüssel `@context`, `@type` und `@id` werden nicht überschrieben.
- JSON-LD Ausgabe-Encoder wurde mit `JSON_HEX_*` abgesichert, um Script-Injection über Textinhalte zu verhindern.
- Validierung fängt tiefe/fehlerhafte Verschachtelungen kontrolliert ab (kein ungefangener Runtime-Abbruch im Save-Flow).

### Changed
- Beim Sprachkopieren werden `jsonld_localbusiness_branches` und `jsonld_schemas` inklusive Zuordnungen übernommen.
- Branch-basierte Referenzen werden per ID-Mapping korrekt auf die neuen Ziel-Standort-IDs umgeschrieben (`localbusiness_branch_id`, `localbusiness_branch_ids`, `article_branch_*`).
- Zielsprach-spezifische JSON-LD Konfigurations-Keys werden vor dem Kopieren ersetzt, um eine konsistente 1:1-Übernahme zu gewährleisten.
- Generator merged Custom-Daten jetzt zentral in Organization-, WebSite- und LocalBusiness-Schema.
- WebSite-SearchAction liest kompatibel sowohl `search_action` als auch `potentialAction`.
- LocalBusiness-Custom-Merge auf Generator-Ende verschoben (Parität zur Backend-Vorschau).
- Multi-Domain-Setzung des Hauptstandorts auf aktive Domain begrenzt.
- Entspricht der Umsetzung von GitHub Issue `#10`.
- Status: GitHub Issue `#10` ist geschlossen.

### Fixed
- LocalBusiness-Bilder werden im Frontend wieder korrekt im JSON-LD (`image`) ausgegeben.
- Normalisierung für `images`/`image` aus Branch-Konfiguration ergänzt (CSV/Array, relative Media-Dateien und absolute URLs).
- Backend-Vorschau in `global_localbusiness` korrigiert, damit die Domain-Base-URL in JavaScript korrekt gesetzt wird.
- GitHub: Entspricht dem Abschluss von Issue `#9`.

### GitHub
- Mit den heutigen Änderungen werden die aktuell offenen Tickets geschlossen: `#4`, `#5`, `#6`, `#7`, `#8`, `#9` und `#10`.

## v1.0.0.beta16 (02. Juni 2026)

**UX / Light-Mode Lesbarkeit:**

**GitHub:**

## v1.0.0.beta16 (18. Mai 2026)

**Release-Konsolidierung:**
- Versionsnummer in `package.yml` auf `v1.0.0.beta16` vereinheitlicht.
- Suffix-Variante `beta16a` wird nicht mehr verwendet.

**Hinweis:**
- Funktionale Änderungen sind in den Einträgen vom 18. Mai 2026 in `CHANGELOG_chatGPT.md` dokumentiert.

## 1.0.0-beta.15 (13. Mai 2026)

**Bugfixes / LocalBusiness / Frontend:**
- Mehrere einem Artikel zugeordnete LocalBusiness-Standorte werden im Frontend jetzt wieder korrekt als eigene Schema-Einträge ausgegeben.
- Frontend-Auflösung der Branch-Zuordnung liest jetzt konsistent die zentrale Artikel-Zuordnung und berücksichtigt zusätzlich die in `jsonld_schemas` gespeicherten `WebPage`-Zuordnungen.
- Mehrere unvollständig zentralisierte Helper-Funktionen rund um Branch-Keys, Disable-Keys und Artikel-Branch-IDs ergänzt bzw. repariert.
- Fehler in `article_jsonld.php` durch doppelte Funktionsdefinitionen und beschädigten Funktionsblock behoben.
- Direkte Namespace-Cache-Löschung aus `update.php` entfernt.
- Backend-Vorschau, AJAX-Vorschau, Frontend-Ausgabe und Debug-Overlay nutzen jetzt dieselbe zentrale JSON-LD-Ausgabe-Pipeline.
- LocalBusiness-`geo` wird jetzt immer mit `@type: GeoCoordinates` ausgegeben.
- Organization-Ausgabe ergänzt `logo`, `sameAs`, `PostalAddress` und `ContactPoint` jetzt auch im Frontend/Debug.
- Organization-Referenzen in `publisher` und `about` nutzen jetzt nur noch `@id`; `@type: Organization` steht nur im Organization-Haupteintrag.

**Debug-Overlay / UX:**
- Reihenfolge der JSON-LD-Ausgabe auf `Organization -> WebSite -> WebPage -> LocalBusiness -> BreadcrumbList` umgestellt.
- Debug-Tabs für LocalBusiness werden jetzt verständlicher beschriftet und mit `LocalBusiness:` präfixiert.
- Debug-Kasten im Frontend ist jetzt ein- und ausklappbar.
- Toggle-Icon im Debug-Kasten korrigiert.

**Hinweis:**
- Bezieht sich weiterhin auf das Thema aus GitHub Ticket `#1`.

## 1.0.0-beta.14 (08. Mai 2026)

**Neu:**
- Legacy Meta-Integration: Meta-/SEO-Tags können jetzt zentral im Backend gepflegt werden (Seite "Legacy Meta Integration").
- Sichere Validierung: Nur reine Meta-Tags und statisches HTML erlaubt, PHP/JS/Event-Handler werden blockiert.
- Frontend-Ausgabe: Die gepflegten Meta-Tags werden im HTML-Head nach dem letzten <meta> Tag (Fallback: vor </head>) ausgegeben – nur in aktivierten Templates.
- Ausgabe erfolgt über OUTPUT_FILTER Extension Point, Template-Check wie bei JSON-LD.
- README.md um Abschnitt "Legacy Meta-Integration" erweitert.
- Versionsnummer auf 1.0.0-beta.14 erhöht.

**Technik:**
- Keine bestehenden Funktionen oder Ausgaben des AddOns beeinträchtigt.

**Hinweis:**
- Die Funktion richtet sich an Entwickler/Redakteure, die alte SEO-/Meta-Tags übernehmen oder spezielle Meta-Informationen ergänzen möchten.

## 1.0.0-beta.13 (07. Mai 2026)

**Artikel / LocalBusiness:**
- Multi-Select für LocalBusiness-Standorte in `article_jsonld` wiederhergestellt und AJAX-Speicherung über separaten Speichern-Button ergänzt
- Gespeicherte Standort-Auswahl wird nach Reload korrekt wieder im Select vorausgewählt
- Hauptstandort wird als effektiver Fallback im Select ebenfalls vorausgewählt, wenn keine eigene Auswahl gespeichert ist
- JSON-LD Vorschau und Frontend-Ausgabe erzeugen jetzt für alle gewählten Standorte eigene `LocalBusiness`-Einträge
- Statusliste zeigt pro zusätzlichem Nicht-Hauptstandort einen eigenen Place-Marker (`1-x`)

**Bereinigung:**
- Unbenutzten Parameter in `generateArticleJsonLd()` entfernt
- Standort-Auswahl-Handling für Einzelwert- und Array-Speicherung vereinheitlicht

**UI / Artikelübersicht:**
- Zeilenhintergrund in der Artikelübersicht über die volle Breite bis hinter die Status-Marker vereinheitlicht
- Hover-Zustand für Artikelzeilen ergänzt, damit inaktive Zeilen klarer erfassbar sind

## 1.0.0-beta.12 (07. Mai 2026)

**Bugfixes & Wartung:**
- Update-Fehler: Cache-Löschung nach Update (rex_delete_cache) ergänzt
- CSS/JS: Assets werden korrekt und nur im Backend eingebunden (boot.php)
- Version auf 1.0.0-beta.12 erhöht

## 1.0.0-beta.11 (06. Mai 2026)

**Sicherheit & Veröffentlichung:**
- Sichere URL-Ermittlung über YRewrite-Domain oder REDAXO-Core-Konfiguration statt direkter Request-Header
- Dynamic-URL-Generierung für Multi-Domain-Setups gehärtet
- Tabellennamen aus URL-/YForm-Profilen vor SQL-Verwendung validiert
- GET-Fallback bei dynamischen Feld-Mappings auf explizit erlaubte Parameter begrenzt
- FOR-Namespace auf `FriendsOfRedaxo\JsonLdManager` umgestellt
- Veröffentlichungs-Metadaten in `package.yml`, README und Support-Links für FOR vorbereitet

**Update & Deinstallation:**
- `update.php` vollständig repariert und als idempotente Migration neu aufgebaut
- Update von älteren Beta-Versionen ergänzt fehlende Tabellen, Spalten, Indizes und Default-Konfigurationen jetzt wieder datenbewahrend
- Deinstallation ergänzt: `rex_jsonld_localbusiness_branches` und `rex_jsonld_url_profile_mappings` werden jetzt ebenfalls entfernt

**Cache & Bereinigung:**
- Cache-Invalidierung bei `ART_UPDATED` und `ART_DELETED` umgesetzt
- Veraltetes deaktiviertes Debug-Logging aus `JsonLdGenerator` entfernt

## 1.0.0-beta.10 (04. Mai 2026)

**Code-Bereinigung:**
- Alle verbleibenden Debug-Ausgaben entfernt (error_log in JsonLdGenerator)
- matchesUrlProfile() Funktion optimiert und vereinfacht

## 1.0.0-beta.9 (04. Mai 2026)

**Bug-Fixes & Optimierungen:**

- **Kritisch:** Dynamic URL Mapping-Values werden jetzt korrekt aufgelöst
  - Backend Debug-Interface zeigt echte Feldwerte aus YForm-Tabellen statt Mapping-Objekte
  - Vorher: `"headline": {"type":"field","value":"title"}`
  - Nachher: `"headline": "YouTube trifft Museum - Die Klasse 10-2 erlebt Geschichte live!"`
- DynamicJsonLd.php: Vollständig reparierte Mapping-Auflösung für Array-basierte Feld-Mappings
- JsonLdGenerator.php: Optimierte YForm Manager Integration mit `rex_yform_manager_dataset::get()`
- JavaScript-Vorschau im Backend zeigt Sample-Daten korrekt an
- Frontend JSON-LD nutzt zentrale Generator-Klasse für konsistente Ausgabe
- Alle Debug-Ausgaben entfernt für sauberen Produktions-Code

**Betroffene Features:**
- Dynamische URL-Profile (NewsArticle, Product, FAQPage)  
- JSON-LD Debug-Overlay im Frontend
- Backend Debug-Interface mit Tab-Navigation
- YForm-Tabellen Integration über URL AddOn

## 1.0.0-beta.8

**Stabilisierung, Sicherheit und UI-Fixes:**

- Debug-Overlay im Frontend wird nur noch angezeigt, wenn:
  - Debug-Modus aktiv ist und
  - ein Backend-Benutzer eingeloggt ist.
- JSON-LD Frontend-Ausgabe wird unterbunden, wenn in den Einstellungen keine Templates ausgewählt sind.
- Dynamic-URLs Konfiguration robuster gemacht:
  - Routing über `rex_get(...)`
  - CSRF-Validierung bei Speichervorgängen
- Backend-Asset-Laden auf JSON-LD-Manager-Seiten stabilisiert (CSS/JS).
- Visuelle Nachbesserungen auf der Artikelseite:
  - aktive Zeile klar hervorgehoben
  - Status-Icons in aktiver Zeile korrekt ausgerichtet/ohne Artefakte
  - JSON-Ausgabefeld mit konsistentem Scroll-Verhalten.

## 1.0.0-beta.7

**Code-Optimierung und Bereinigung:**

- Code-Audit durchgeführt und CSS-Duplikation entfernt
- JavaScript vereinfacht - nur tatsächlich verwendete Funktionen beibehalten  
- Zentrale CSS-Datei für alle Styles (assets/css/jsonld_manager.css)
- Dead-Code Bereinigung in allen Dateien
- Error-Handling verbessert mit rex_logger Integration
- Performance-Optimierungen in Backend-Interface

## 1.0.0-beta.6

**YRewrite optional gemacht - AddOn funktioniert jetzt auch ohne YRewrite:**

- YRewrite-Fallbacks: Vollständige Absicherung aller YRewrite-Abhängigkeiten
- Optional dependency: YRewrite von "requires" zu "suggests" geändert
- Standard-URLs: Fallback auf REDAXO Standard-URLs wenn YRewrite nicht verfügbar
- Domain-Erkennung: Automatische Erkennung ob Multi-Domain aktiv ist
- Error-Handling: Try-Catch für Datenbank-Zugriffe auf YRewrite-Tabellen
- Kompatibilität: 100% rückwärtskompatibel mit bestehenden Installationen

## 1.0.0-beta.5

**Domain-Support für Multi-Domain-Installationen hinzugefügt:**

- Multi-Domain-System: Vollständige Unterstützung für YRewrite Multi-Domain Installationen  
- DomainConfig-Klasse: Analoge Implementierung zu LanguageConfig für Domain-Management
- Domain-Tabs: Visuelle Domain-Auswahl auf allen Backend-Seiten (rot markiert)
- Domain-spezifische Konfiguration: Alle Einstellungen werden pro Domain verwaltet
- Datenbank-Migration: Alle Tabellen erweitert um `domain_id` (NULL für Rückwärtskompatibilität)
- Strikte Domain-Trennung: Keine automatische Migration zwischen Domains
- Domain-Information: Anzeige der aktiven Domain in Versionsnummer (fett)
- Frontend-Debug: Domain-Information im Debug-Overlay (Domain: example.com (ID: 1))
- AddOn-Abhängigkeiten: YForm >=5.0.1 und YRewrite >=1.12.0 als Pflicht-AddOns
- Update-Migration: Automatisches Update bestehender Installationen mit domain_id Spalten

**Technische Details:**
- Neue Tabellen-Indizes: `article_clang_domain`, `article_domain`, `clang_domain`, `profile_schema_domain`
- Domain-Session-Management analog zu Sprach-Management
- Domain-spezifische Template-Funktionen (`jsonld_is_debug_enabled()`)
- Kompatibilität: Bestehende Single-Domain Installationen funktionieren unverändert

## 1.0.0-beta.4

- Debug-Overlay überarbeitet (Tabs pro Schema, kompakteres Layout, Copy-Icon).
- JSON-LD Vorschau und Frontend-Ausgabe bereinigen leere Werte konsistent.
- Dynamic-URLs und allgemeines JSON-LD werden gemeinsam ausgegeben.
- Navigation/Verhalten bei deaktiviertem URL-Addon stabilisiert.
- UI-Cleanup in `article_jsonld` und `dynamic_urls` (Tabelle, doppelten Code entfernt).

Die aktuelle Entwicklungsphase (`1.0.0-beta`) enthält fortlaufende Änderungen vor dem Stable-Release.
