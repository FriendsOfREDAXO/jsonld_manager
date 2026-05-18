
# Changelog

Dieses Changelog wird ab Version `1.0.0` neu geführt.

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
