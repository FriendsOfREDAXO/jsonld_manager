<?php

$profileId = rex_get('profile_id', 'int');
$func = rex_get('func', 'string');
$csrfToken = rex_csrf_token::factory('jsonld_manager_url_config');

// URL-Profil laden mit allen relevanten Feldern
$profile = rex_sql::factory()->getArray(
    'SELECT * FROM ' . rex::getTable('url_generator_profile') . ' WHERE id = ?',
    [$profileId]
);

if (!$profile) {
    echo rex_view::error('URL-Profil nicht gefunden.');
    return;
}

$profile = $profile[0];

// Echte YForm-Tabelle aus table_parameters extrahieren
$yformTableName = null;
if (!empty($profile['table_parameters'])) {
    $tableParams = json_decode((string) $profile['table_parameters'], true);
    if ($tableParams && !empty($tableParams['table_name'])) {
        $yformTableName = (string) $tableParams['table_name'];
    }
}

// Fallback: Korrigiere Tabellenname (entferne 1_xxx_ Prefix)  
if (!$yformTableName) {
    $yformTableName = str_replace('1_xxx_', '', (string) ($profile['table_name'] ?? ''));
}

if (preg_match('/^[A-Za-z0-9_]+$/', $yformTableName) !== 1) {
    echo rex_view::error('Ungültiger oder unsicherer Tabellenname im URL-Profil.');
    return;
}

$profile['real_table_name'] = $yformTableName;

// Bestehende Mappings laden
$mapping = rex_sql::factory()->getArray(
    'SELECT * FROM ' . rex::getTable('jsonld_url_profile_mappings') . ' WHERE url_profile_id = ?',
    [$profileId]
);

// Messages sammeln
$messages = [];

// Formular verarbeiten
if (rex_post('save', 'string') === '1') {
    if (!$csrfToken->isValid()) {
        $messages[] = rex_view::error('Sicherheitsprüfung fehlgeschlagen (CSRF). Bitte Seite neu laden.');
    } else {
    $schemaType = rex_post('schema_type', 'string');
    $active = rex_post('active', 'int', 1);
    $fieldMappings = rex_post('field_mappings', 'string', '{}');
    
    // JSON validieren und auf bekannte Mapping-Formate reduzieren
    $mappingsArray = json_decode($fieldMappings, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($mappingsArray)) {
        $messages[] = rex_view::error('Ungültiges JSON in den Feld-Mappings.');
    } elseif (preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $schemaType) !== 1) {
        $messages[] = rex_view::error('Ungültiger Schema-Typ.');
    } else {
        $mappingsArray = \FriendsOfRedaxo\JsonLdManager\Mapping\DynamicFieldMapper::sanitizeMappings($mappingsArray);
        $fieldMappings = json_encode($mappingsArray, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($fieldMappings)) {
            $fieldMappings = '{}';
        }

        // Mapping speichern/aktualisieren
        $sql = rex_sql::factory();
        if (count($mapping) > 0) {
            // Update
            $sql->setTable(rex::getTable('jsonld_url_profile_mappings'));
            $sql->setWhere(['id' => (int) ($mapping[0]['id'] ?? 0)]);
        } else {
            // Insert
            $sql->setTable(rex::getTable('jsonld_url_profile_mappings'));
            $sql->setValue('url_profile_id', $profileId);
            $sql->setValue('created', date('Y-m-d H:i:s'));
        }
        
        $sql->setValue('schema_type', $schemaType);
        $sql->setValue('active', $active);
        $sql->setValue('field_mappings', $fieldMappings);
        $sql->setValue('modified', date('Y-m-d H:i:s'));
        
        try {
            if (count($mapping) > 0) {
                $sql->update();
            } else {
                $sql->insert();
            }
            $messages[] = rex_view::success('Mapping erfolgreich gespeichert.');
            
            // Mapping neu laden
            $mapping = rex_sql::factory()->getArray(
                'SELECT * FROM ' . rex::getTable('jsonld_url_profile_mappings') . ' WHERE url_profile_id = ?',
                [$profileId]
            );
        } catch (rex_sql_exception $e) {
            $messages[] = rex_view::error('Fehler beim Speichern: ' . $e->getMessage());
        }
    }
    }
}

// YForm-Tabellen-Struktur: Alle Felder direkt aus der YForm-Tabelle laden
$tableFields = [];
try {
    // WICHTIG: YForm-Tabellenname bereits mit rex_ Prefix
    $tableFields = rex_sql::factory()->getArray('DESCRIBE ' . $yformTableName);
} catch (rex_sql_exception $e) {
    // Fallback: Aus table_parameters laden
    if (!empty($profile['table_parameters']) && is_string($profile['table_parameters'])) {
        $tableParams = json_decode($profile['table_parameters'], true);
        
        if ($tableParams) {
            // Aus table_parameters die verfügbaren Felder extrahieren
            $fields = [];
            
            // Standard-Felder
            $fields[] = ['Field' => 'id'];
            $fields[] = ['Field' => 'status'];
            
            // Felder aus den Segmenten
            if (!empty($tableParams['column_segment_part_1'])) {
                $fields[] = ['Field' => $tableParams['column_segment_part_1']];
            }
            if (!empty($tableParams['column_segment_part_2'])) {
                $fields[] = ['Field' => $tableParams['column_segment_part_2']];
            }
            if (!empty($tableParams['column_segment_part_3'])) {
                $fields[] = ['Field' => $tableParams['column_segment_part_3']];
            }
            
            // SEO-Felder
            if (!empty($tableParams['column_seo_title'])) {
                $fields[] = ['Field' => $tableParams['column_seo_title']];
            }
            if (!empty($tableParams['column_seo_description'])) {
                $fields[] = ['Field' => $tableParams['column_seo_description']];
            }
            if (!empty($tableParams['column_seo_image'])) {
                $fields[] = ['Field' => $tableParams['column_seo_image']];
            }
            
            // Restriction-Felder
            if (!empty($tableParams['restriction_1_column'])) {
                $fields[] = ['Field' => $tableParams['restriction_1_column']];
            }
            if (!empty($tableParams['restriction_2_column'])) {
                $fields[] = ['Field' => $tableParams['restriction_2_column']];
            }
            
            // Duplikate entfernen
            $uniqueFields = [];
            foreach ($fields as $field) {
                $uniqueFields[$field['Field']] = $field;
            }
            $tableFields = array_values($uniqueFields);
        }
    } else {
        // Letzter Fallback: Standard-Felder
        $tableFields = [
            ['Field' => 'id'],
            ['Field' => 'status'],
            ['Field' => 'name'],
            ['Field' => 'rasse']
        ];
    }
}

// Sample-Daten aus der echten YForm-Tabelle laden (erste 3 Datensätze für Vorschau)
$sampleData = [];
// WICHTIG: YForm-Tabellenname bereits mit rex_ Prefix
try {
    $sampleData = rex_sql::factory()->getArray(
        'SELECT * FROM ' . $yformTableName . ' WHERE status = 1 LIMIT 3'
    );
} catch (rex_sql_exception $e) {
    $sampleData = [];
}

// Schema-Properties Definition
// Properties, die in DynamicFieldMapper::getNestedPropertyDefinitions() hinterlegt sind,
// lassen sich zusätzlich "strukturiert" aus mehreren Feldern zusammensetzen.
$schemaProperties = [
    'Article' => [
        'headline' => 'Überschrift/Titel',
        'description' => 'Beschreibung',
        'author' => 'Autor',
        'datePublished' => 'Veröffentlichungsdatum',
        'dateModified' => 'Änderungsdatum',
        'image' => 'Bild-URL',
        'url' => 'Artikel-URL'
    ],
    'BlogPosting' => [
        'headline' => 'Blog-Titel',
        'description' => 'Blog-Beschreibung',
        'author' => 'Blog-Autor',
        'datePublished' => 'Veröffentlichungsdatum',
        'image' => 'Beitragsbild',
        'wordCount' => 'Wortanzahl'
    ],
    'NewsArticle' => [
        'headline' => 'News-Schlagzeile',
        'description' => 'News-Beschreibung',
        'author' => 'Reporter/Autor',
        'datePublished' => 'Publikationsdatum',
        'image' => 'News-Bild'
    ],
    'Person' => [
        'name' => 'Vollständiger Name',
        'givenName' => 'Vorname',
        'familyName' => 'Nachname',
        'jobTitle' => 'Berufsbezeichnung',
        'email' => 'E-Mail-Adresse',
        'telephone' => 'Telefonnummer',
        'image' => 'Profilbild',
        'url' => 'Website/Profil-URL'
    ],
    'Organization' => [
        'name' => 'Firmenname',
        'description' => 'Firmenbeschreibung',
        'url' => 'Website-URL',
        'logo' => 'Firmenlogo',
        'contactPoint' => 'Kontaktinformationen (Telefon, E-Mail)',
        'address' => 'Adresse (Straße, PLZ, Ort, Land)'
    ],
    'LocalBusiness' => [
        'name' => 'Geschäftsname',
        'description' => 'Geschäftsbeschreibung',
        'address' => 'Geschäftsadresse (Straße, PLZ, Ort, Land)',
        'telephone' => 'Telefonnummer',
        'email' => 'E-Mail',
        'url' => 'Website-URL',
        'priceRange' => 'Preisspanne (z. B. €€)',
        'openingHoursSpecification' => 'Öffnungszeiten (strukturiert, je Wochentag)',
        'openingHours' => 'Öffnungszeiten (Freitext, z. B. "Mo-Fr 09:00-18:00")',
        'image' => 'Geschäftsbild'
    ],
    'Product' => [
        'name' => 'Produktname',
        'description' => 'Produktbeschreibung',
        'image' => 'Produktbild',
        'sku' => 'Artikelnummer',
        'brand' => 'Marke',
        'offers' => 'Angebot (Preis, Währung, Verfügbarkeit)',
        'aggregateRating' => 'Bewertung (Durchschnitt, Anzahl)',
        'category' => 'Produktkategorie',
        'url' => 'Produkt-URL'
    ],
    'Service' => [
        'name' => 'Service-Name',
        'description' => 'Service-Beschreibung',
        'provider' => 'Anbieter (Name, URL)',
        'serviceType' => 'Service-Typ',
        'areaServed' => 'Servicegebiet',
        'offers' => 'Angebot (Preis, Währung, Verfügbarkeit)'
    ],
    'Event' => [
        'name' => 'Event-Name',
        'description' => 'Event-Beschreibung',
        'startDate' => 'Startdatum (ISO 8601)',
        'endDate' => 'Enddatum (ISO 8601)',
        'location' => 'Veranstaltungsort (Name, Adresse)',
        'organizer' => 'Veranstalter (Name, URL)',
        'offers' => 'Tickets/Angebot (Preis, Währung, Verfügbarkeit)',
        'image' => 'Event-Bild',
        'url' => 'Event-URL'
    ],
    'Course' => [
        'name' => 'Kurs-Name',
        'description' => 'Kurs-Beschreibung',
        'provider' => 'Kursanbieter (Name, URL)',
        'courseMode' => 'Kursart (online/offline)',
        'educationalLevel' => 'Bildungsebene'
    ],
    'Animal' => [
        'name' => 'Tiername',
        'species' => 'Tierart/Spezies',
        'breed' => 'Rasse',
        'gender' => 'Geschlecht',
        'age' => 'Alter',
        'description' => 'Beschreibung',
        'image' => 'Tierbild'
    ]
];

// FAQPage wird bewusst nicht mehr angeboten: Ein URL-Profil-Datensatz ergibt genau ein
// Schema-Objekt, eine FAQ-Seite braucht aber mehrere Frage/Antwort-Paare (siehe jsonld_render_faq()).
// Bestehende Zuordnungen bleiben bearbeitbar.
$legacySchemaProperties = [
    'FAQPage' => [
        'mainEntity' => 'FAQ-Einträge (Array)',
        'name' => 'FAQ-Titel',
        'description' => 'FAQ-Beschreibung'
    ],
];

$nestedDefinitions = \FriendsOfRedaxo\JsonLdManager\Mapping\DynamicFieldMapper::getNestedPropertyDefinitions();

// Config laden falls vorhanden
$config = [
    'schema_type' => '',
    'active' => 1,
    'field_mappings' => [],
];
if (!empty($mapping)) {
    $fieldMappingsConfig = [];
    if (isset($mapping[0]['field_mappings']) && is_string($mapping[0]['field_mappings'])) {
        $fieldMappingsConfig = json_decode($mapping[0]['field_mappings'], true) ?: [];
    }
    $config = [
        'schema_type' => (string) ($mapping[0]['schema_type'] ?? ''),
        'active' => (int) ($mapping[0]['active'] ?? 1),
        'field_mappings' => is_array($fieldMappingsConfig) ? $fieldMappingsConfig : [],
    ];
}

$isLegacySchemaType = isset($legacySchemaProperties[$config['schema_type']]);
if ($isLegacySchemaType) {
    $schemaProperties[$config['schema_type']] = $legacySchemaProperties[$config['schema_type']];
}

// Sample-Daten für JavaScript (ersten Datensatz verwenden)
$sampleData = !empty($sampleData) ? $sampleData[0] : [];

// Fragment-Inhalt komplett aufbauen
ob_start();

// Messages anzeigen
foreach ($messages as $message) {
    echo $message;
}

if ($isLegacySchemaType) {
    echo rex_view::warning(
        'Der Schema-Typ <strong>' . rex_escape($config['schema_type']) . '</strong> lässt sich über die URL-Profil-Zuordnung nicht vollständig abbilden: '
        . 'Pro Datensatz entsteht nur ein einzelnes Schema-Objekt, eine FAQ-Seite benötigt aber alle Frage/Antwort-Paare in einem <code>mainEntity</code>-Array. '
        . 'Verwenden Sie dafür im Template <code>jsonld_render_faq(\'' . rex_escape($yformTableName) . '\', \'frage_feld\', \'antwort_feld\')</code>.'
    );
}

$backUrl = rex_url::currentBackendPage(['func' => '', 'profile_id' => '']);

echo '<div class="row jsonld-config-layout">';
echo '  <div class="col-md-6">';
echo '    <div class="panel panel-primary">';
echo '      <header class="panel-heading">';
echo '        <h1 class="panel-title">Schema-Konfiguration</h1>';
echo '      </header>';
echo '      <div class="panel-body">';

echo '        <form id="jsonld-main-form" method="post">';
echo rex_csrf_token::factory('jsonld_manager_url_config')->getHiddenField();
echo '          <input type="hidden" name="func" value="save_url_config">';
echo '          <input type="hidden" name="profile_id" value="' . (int) $profileId . '">';
echo '          <input type="hidden" name="save" value="1">';

$schemaTypeGroups = [
    'Artikel & Content' => ['Article', 'BlogPosting', 'NewsArticle'],
    'Personen & Unternehmen' => ['Organization', 'Person'],
    'Geschäfte & Services' => ['LocalBusiness', 'Service'],
    'Produkte & Angebote' => ['Product'],
    'Events & Kurse' => ['Course', 'Event'],
    'Sonstige' => ['Animal'],
];
if ($isLegacySchemaType) {
    $schemaTypeGroups['Nicht empfohlen'] = [$config['schema_type']];
}

echo '          <div class="form-group">';
echo '            <label for="schema_type">Schema.org Typ</label>';
echo '            <select id="schema_type" name="schema_type" class="form-control selectpicker" data-live-search="true" data-size="8" onchange="updateSchemaFields()" required>';
echo '              <option value="">-- Schema-Typ wählen --</option>';
foreach ($schemaTypeGroups as $groupLabel => $groupTypes) {
    echo '              <optgroup label="' . rex_escape($groupLabel) . '">';
    foreach ($groupTypes as $schemaType) {
        $selected = ($config['schema_type'] === $schemaType) ? ' selected' : '';
        echo '                <option value="' . rex_escape($schemaType) . '"' . $selected . '>' . rex_escape($schemaType) . '</option>';
    }
    echo '              </optgroup>';
}
echo '            </select>';
echo '            <p class="help-block">FAQ-Seiten und Übersichtslisten werden nicht pro Datensatz zugeordnet, sondern im Template über <code>jsonld_render_faq()</code> bzw. <code>jsonld_render_item_list()</code> erzeugt.</p>';
echo '          </div>';

echo '          <div id="field-mappings-container" style="display:none;">';
echo '            <h4>Feld-Mappings</h4>';
echo '            <p class="help-block">Verknüpfen Sie Schema.org Properties mit Feldern aus der YForm-Tabelle "' . rex_escape($yformTableName) . '" oder verwenden Sie statische Werte. Properties wie Angebot, Adresse oder Öffnungszeiten können mit „Strukturiert“ aus mehreren Feldern zu einem gültigen Teilobjekt zusammengesetzt werden.</p>';
echo '            <div id="field-mappings"></div>';
echo '          </div>';

echo '          <input type="hidden" name="field_mappings" id="field-mappings-input">';
echo '        </form>';

echo '      </div>';
echo '    </div>';
echo '  </div>';
echo '  <div class="col-md-6 jsonld-preview-col">';
echo '    <div class="jsonld-preview-sticky">';
echo '      <pre id="json-preview" style="background: #f8f9fa; color: #333; border: 1px solid #ddd; padding: 15px; min-height: 260px; font-size: 12px; overflow-x: auto; font-family: Monaco, Menlo, monospace; border-radius: 4px;">Wählen Sie einen Schema-Typ aus.</pre>';
echo '    </div>';
echo '  </div>';
echo '</div>';

echo '<div class="rex-form-panel-footer">';
echo '  <div class="btn-toolbar">';
if ($backUrl) {
    echo '    <a href="' . $backUrl . '" class="btn btn-default">Zurück zur Übersicht</a>';
}
echo '    <div class="pull-right">';
echo '      <button type="submit" form="jsonld-main-form" class="btn btn-apply">Speichern</button>';
echo '    </div>';
echo '  </div>';
echo '</div>';

$content = ob_get_clean();

$fragment = new rex_fragment();
$fragment->setVar('title', 'JSON-LD Schema für URL-Profil: ' . rex_escape($profile['article_name']));
$fragment->setVar('body', $content, false);
echo $fragment->parse('core/page/section.php');

$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$schemaPropertiesJson = json_encode($schemaProperties, $jsonFlags);
$nestedDefinitionsJson = json_encode($nestedDefinitions, $jsonFlags);
$tableFieldsJson = json_encode(array_column($tableFields, 'Field'), $jsonFlags);
$sampleDataJson = json_encode($sampleData, $jsonFlags);
$profileNamespaceJson = json_encode((string) ($profile['namespace'] ?? ''), $jsonFlags);
$savedMappingsJson = json_encode($config['field_mappings'], $jsonFlags);

if (!is_string($schemaPropertiesJson)) {
    $schemaPropertiesJson = '{}';
}
if (!is_string($nestedDefinitionsJson)) {
    $nestedDefinitionsJson = '{}';
}
if (!is_string($tableFieldsJson)) {
    $tableFieldsJson = '[]';
}
if (!is_string($sampleDataJson) || $sampleDataJson === '[]') {
    $sampleDataJson = '{}';
}
if (!is_string($profileNamespaceJson)) {
    $profileNamespaceJson = '""';
}
if (!is_string($savedMappingsJson) || $savedMappingsJson === '[]') {
    $savedMappingsJson = '{}';
}

?>
<style>
.jsonld-nested-block { border: 1px solid #ddd; border-radius: 4px; padding: 10px 12px 0; margin-top: 8px; background: rgba(0,0,0,0.02); }
.jsonld-nested-block .form-group { margin-bottom: 10px; }
.jsonld-nested-block label { font-weight: normal; }
.jsonld-oh-row { border-bottom: 1px dashed #ddd; padding-bottom: 8px; margin-bottom: 8px; }
.jsonld-oh-days label { font-weight: normal; margin-right: 8px; white-space: nowrap; }
</style>
<script>
// Schema-Properties, strukturierte Property-Definitionen und Sample-Daten für JavaScript verfügbar machen
const schemaProperties = <?= $schemaPropertiesJson ?>;
const nestedDefinitions = <?= $nestedDefinitionsJson ?>;
const tableFields = <?= $tableFieldsJson ?>;
const sampleData = <?= $sampleDataJson ?>;
const profileNamespace = <?= $profileNamespaceJson ?>;
const savedMappings = <?= $savedMappingsJson ?>;
const weekDays = [["Monday", "Mo"], ["Tuesday", "Di"], ["Wednesday", "Mi"], ["Thursday", "Do"], ["Friday", "Fr"], ["Saturday", "Sa"], ["Sunday", "So"], ["PublicHolidays", "Feiertage"]];

const NESTED_VALUE = "__NESTED__";
const STATIC_VALUE = "__STATIC__";

// Field-Mappings Object für Live-Updates
let fieldMappings = {};
let openingHoursRowCounter = 0;

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

function fieldOptionsHtml(includeNested) {
    let html = '<option value="">-- Feld wählen --</option>';
    html += `<option value="${STATIC_VALUE}">Statischer Wert</option>`;
    if (includeNested) {
        html += `<option value="${NESTED_VALUE}">Strukturiert (aus mehreren Feldern)</option>`;
    }
    html += tableFields.map(field => `<option value="${escapeHtml(field)}">${escapeHtml(field)}</option>`).join("");
    return html;
}

function initSelect(select) {
    if (select && typeof $.fn.selectpicker !== "undefined") {
        $(select).selectpicker();
    }
}

function refreshSelect(select) {
    if (select && typeof $.fn.selectpicker !== "undefined" && $(select).hasClass("selectpicker")) {
        $(select).selectpicker("refresh");
    }
}

// Verbindet ein Feld-Select mit seinem Statisch-Eingabefeld (und optional einem strukturierten Block)
function bindSelect(select, staticInput, nestedBlock) {
    const apply = function () {
        const value = select.value;
        if (staticInput) {
            staticInput.style.display = value === STATIC_VALUE ? "block" : "none";
            if (value !== STATIC_VALUE) {
                staticInput.value = "";
            }
        }
        if (nestedBlock) {
            nestedBlock.style.display = value === NESTED_VALUE ? "block" : "none";
        }
    };
    $(select).on("changed.bs.select", function () {
        apply();
        if (select.value === STATIC_VALUE && staticInput) {
            staticInput.focus();
        }
        updateMapping();
    });
    select.addEventListener("change", function () {
        apply();
        updateMapping();
    });
    if (staticInput) {
        staticInput.addEventListener("input", updateMapping);
        staticInput.addEventListener("change", updateMapping);
    }
}

function leafRowHtml(idPrefix, parent, subProperty, label) {
    return `
        <div class="form-group">
            <label for="${idPrefix}_select">${escapeHtml(label)} <small style="color:#999;">(${escapeHtml(subProperty)})</small></label>
            <div class="row">
                <div class="col-md-6">
                    <select id="${idPrefix}_select" data-parent="${escapeHtml(parent)}" data-subproperty="${escapeHtml(subProperty)}" class="form-control selectpicker" data-live-search="true" data-size="10">
                        ${fieldOptionsHtml(false)}
                    </select>
                </div>
                <div class="col-md-6">
                    <input type="text" id="${idPrefix}_static" placeholder="Statischer Wert" class="form-control" style="display:none;">
                </div>
            </div>
        </div>`;
}

function bindLeafRows(container) {
    container.querySelectorAll("select[data-subproperty]").forEach(select => {
        const staticInput = staticInputFor(select);
        initSelect(select);
        bindSelect(select, staticInput, null);
    });
}

function addOpeningHoursRow(property, preset) {
    const rowsContainer = document.getElementById(`oh_rows_${property}`);
    if (!rowsContainer) {
        return null;
    }
    const rowIndex = ++openingHoursRowCounter;
    const idPrefix = `oh_${property}_${rowIndex}`;
    const row = document.createElement("div");
    row.className = "jsonld-oh-row";
    row.dataset.parent = property;

    const daysHtml = weekDays.map(([value, label]) => {
        const checked = preset && Array.isArray(preset.days) && preset.days.includes(value) ? " checked" : "";
        return `<label><input type="checkbox" value="${value}" data-oh-day${checked}> ${label}</label>`;
    }).join("");

    row.innerHTML = `
        <div class="form-group jsonld-oh-days">
            <label style="display:block;font-weight:bold;">Wochentage</label>
            ${daysHtml}
            <button type="button" class="btn btn-xs btn-default pull-right" data-oh-remove>Zeile entfernen</button>
        </div>
        ${leafRowHtml(idPrefix + "_opens", property, "opens", "Öffnet (HH:MM)")}
        ${leafRowHtml(idPrefix + "_closes", property, "closes", "Schließt (HH:MM)")}
    `;
    rowsContainer.appendChild(row);

    row.querySelectorAll("[data-oh-day]").forEach(checkbox => checkbox.addEventListener("change", updateMapping));
    row.querySelector("[data-oh-remove]").addEventListener("click", function () {
        row.remove();
        updateMapping();
    });
    bindLeafRows(row);

    if (preset) {
        applyLeafPreset(row, "opens", preset.opens);
        applyLeafPreset(row, "closes", preset.closes);
    }
    return row;
}

function applyLeafPreset(container, subProperty, leaf) {
    if (!leaf || !leaf.type) {
        return;
    }
    const select = container.querySelector(`select[data-subproperty="${subProperty}"]`);
    if (!select) {
        return;
    }
    const staticInput = staticInputFor(select);
    if (leaf.type === "static") {
        select.value = STATIC_VALUE;
        if (staticInput) {
            staticInput.style.display = "block";
            staticInput.value = leaf.value || "";
        }
    } else if (leaf.type === "field") {
        select.value = leaf.value || "";
    }
    refreshSelect(select);
}

function updateSchemaFields() {
    const schemaType = document.getElementById("schema_type").value;
    const container = document.getElementById("field-mappings-container");
    const mappingsDiv = document.getElementById("field-mappings");

    if (!schemaType) {
        container.style.display = "none";
        return;
    }

    container.style.display = "block";
    mappingsDiv.innerHTML = "";

    // Schema-Properties als Bootstrap-Interface erstellen
    const properties = schemaProperties[schemaType] || {};

    Object.entries(properties).forEach(([property, description]) => {
        const nested = nestedDefinitions[property] || null;
        const row = document.createElement("div");
        row.className = "form-group";

        let nestedHtml = "";
        if (nested) {
            if (property === "openingHoursSpecification") {
                nestedHtml = `
                    <div class="jsonld-nested-block" id="nested_${property}" style="display:none;">
                        <p class="help-block">Je Zeile: Wochentage plus Öffnungs- und Schließzeit (Feld oder fester Wert, Format HH:MM).</p>
                        <div id="oh_rows_${property}"></div>
                        <div class="form-group"><button type="button" class="btn btn-sm btn-default" id="oh_add_${property}">Zeile hinzufügen</button></div>
                    </div>`;
            } else {
                const subRows = Object.entries(nested.fields).map(([subProperty, subLabel]) =>
                    leafRowHtml(`nested_${property}_${subProperty}`, property, subProperty, subLabel)
                ).join("");
                nestedHtml = `
                    <div class="jsonld-nested-block" id="nested_${property}" style="display:none;">
                        <p class="help-block">Erzeugt ein <code>${escapeHtml(nested.type)}</code>-Objekt aus den folgenden Angaben.</p>
                        ${subRows}
                    </div>`;
            }
        }

        row.innerHTML = `
            <label for="mapping_${property}"><strong>${escapeHtml(description)}</strong> <small style="color:#999;">(${escapeHtml(property)})</small></label>
            <div class="row">
                <div class="col-md-6">
                    <select id="mapping_${property}" data-property="${escapeHtml(property)}" class="form-control selectpicker" data-live-search="true" data-size="10">
                        ${fieldOptionsHtml(nested !== null)}
                    </select>
                </div>
                <div class="col-md-6">
                    <input type="text" id="static_${property}" placeholder="Statischer Wert" class="form-control" style="display:none;">
                </div>
            </div>
            ${nestedHtml}
        `;

        mappingsDiv.appendChild(row);

        const select = row.querySelector(`select[data-property]`);
        const staticInput = row.querySelector(`#static_${property}`);
        const nestedBlock = row.querySelector(`#nested_${property}`);

        initSelect(select);
        bindSelect(select, staticInput, nestedBlock);

        if (nestedBlock) {
            if (property === "openingHoursSpecification") {
                document.getElementById(`oh_add_${property}`).addEventListener("click", function () {
                    addOpeningHoursRow(property, null);
                    updateMapping();
                });
            } else {
                bindLeafRows(nestedBlock);
            }
        }
    });

    updatePreview();
}

function staticInputFor(select) {
    if (select.dataset.property) {
        return document.getElementById(`static_${select.dataset.property}`);
    }
    return document.getElementById(select.id.replace(/_select$/, "_static"));
}

function collectLeaf(select) {
    if (!select) {
        return null;
    }
    const staticInput = staticInputFor(select);
    const value = select.value;
    if (!value || value === NESTED_VALUE) {
        return null;
    }
    if (value === STATIC_VALUE) {
        if (staticInput && staticInput.value.trim() !== "") {
            return { type: "static", value: staticInput.value };
        }
        return null;
    }
    return { type: "field", value: value };
}

function updateMapping() {
    fieldMappings = {};

    document.querySelectorAll("select[data-property]").forEach(select => {
        const property = select.dataset.property;
        const value = select.value;
        if (!value) {
            return;
        }

        if (value === NESTED_VALUE) {
            if (property === "openingHoursSpecification") {
                const rows = [];
                document.querySelectorAll(`#oh_rows_${property} .jsonld-oh-row`).forEach(row => {
                    const days = Array.from(row.querySelectorAll("[data-oh-day]:checked")).map(cb => cb.value);
                    const opens = collectLeaf(row.querySelector('select[data-subproperty="opens"]'));
                    const closes = collectLeaf(row.querySelector('select[data-subproperty="closes"]'));
                    if (days.length === 0 || (!opens && !closes)) {
                        return;
                    }
                    const entry = { days: days };
                    if (opens) entry.opens = opens;
                    if (closes) entry.closes = closes;
                    rows.push(entry);
                });
                if (rows.length > 0) {
                    fieldMappings[property] = { type: "opening_hours", rows: rows };
                }
            } else {
                const fields = {};
                document.querySelectorAll(`#nested_${property} select[data-subproperty]`).forEach(subSelect => {
                    const leaf = collectLeaf(subSelect);
                    if (leaf) {
                        fields[subSelect.dataset.subproperty] = leaf;
                    }
                });
                if (Object.keys(fields).length > 0) {
                    fieldMappings[property] = { type: "nested", fields: fields };
                }
            }
            return;
        }

        const leaf = collectLeaf(select);
        if (leaf) {
            fieldMappings[property] = leaf;
        }
    });

    updatePreview();
}

function resolveLeafPreview(leaf) {
    if (!leaf) {
        return undefined;
    }
    if (leaf.type === "static") {
        return leaf.value;
    }
    const fieldValue = sampleData[leaf.value];
    if (fieldValue !== undefined && fieldValue !== null && fieldValue !== "") {
        return fieldValue;
    }
    return `[Feld: ${leaf.value}]`;
}

function previewNested(property, mapping) {
    const definition = nestedDefinitions[property] || { type: mapping.object_type || "Thing" };
    const object = { "@type": definition.type };
    Object.entries(mapping.fields || {}).forEach(([subProperty, leaf]) => {
        let value = resolveLeafPreview(leaf);
        if (value === undefined) {
            return;
        }
        if (subProperty === "availability" && typeof value === "string" && !/^https?:/.test(value)) {
            value = "https://schema.org/" + value;
        }
        object[subProperty] = value;
    });
    if (definition.type === "Offer" && !object.priceCurrency) {
        object.priceCurrency = "EUR";
    }
    if (definition.type === "Place") {
        const addressKeys = ["streetAddress", "postalCode", "addressLocality", "addressRegion", "addressCountry"];
        const address = {};
        addressKeys.forEach(key => {
            if (object[key] !== undefined) {
                address[key] = object[key];
                delete object[key];
            }
        });
        if (Object.keys(address).length > 0) {
            object.address = Object.assign({ "@type": "PostalAddress" }, address);
        }
    }
    return object;
}

function previewOpeningHours(mapping) {
    return (mapping.rows || []).map(row => {
        const entry = { "@type": "OpeningHoursSpecification", dayOfWeek: row.days.length === 1 ? row.days[0] : row.days };
        const opens = resolveLeafPreview(row.opens);
        const closes = resolveLeafPreview(row.closes);
        if (opens !== undefined) entry.opens = opens;
        if (closes !== undefined) entry.closes = closes;
        return entry;
    });
}

function updatePreview() {
    const schemaType = document.getElementById("schema_type").value;
    const preview = document.getElementById("json-preview");

    if (!schemaType) {
        preview.textContent = "Wählen Sie einen Schema-Typ aus.";
        return;
    }

    // JSON-LD Schema mit Sample-Daten (erster Datensatz) generieren
    const schema = {
        "@context": "https://schema.org",
        "@type": schemaType,
        "@id": `${window.location.origin}/${profileNamespace}/${sampleData.id || "sample-id"}`
    };
    if (fieldMappings.url) {
        schema.url = schema["@id"];
    }

    Object.entries(fieldMappings).forEach(([property, mapping]) => {
        if (property === "url") {
            return;
        }
        if (mapping.type === "nested") {
            schema[property] = previewNested(property, mapping);
        } else if (mapping.type === "opening_hours") {
            schema[property] = previewOpeningHours(mapping);
        } else {
            const value = resolveLeafPreview(mapping);
            if (value !== undefined) {
                schema[property] = value;
            }
        }
    });

    preview.textContent = JSON.stringify(schema, null, 2);
}

function restoreSavedMappings() {
    Object.entries(savedMappings).forEach(([property, mapping]) => {
        const select = document.getElementById(`mapping_${property}`);
        const staticInput = document.getElementById(`static_${property}`);
        const nestedBlock = document.getElementById(`nested_${property}`);
        if (!select || !mapping || !mapping.type) {
            return;
        }

        if (mapping.type === "static") {
            select.value = STATIC_VALUE;
            if (staticInput) {
                staticInput.style.display = "block";
                staticInput.value = mapping.value || "";
            }
        } else if (mapping.type === "field") {
            select.value = mapping.value || "";
        } else if (mapping.type === "nested" && nestedBlock) {
            select.value = NESTED_VALUE;
            nestedBlock.style.display = "block";
            Object.entries(mapping.fields || {}).forEach(([subProperty, leaf]) => {
                applyLeafPreset(nestedBlock, subProperty, leaf);
            });
        } else if (mapping.type === "opening_hours" && nestedBlock) {
            select.value = NESTED_VALUE;
            nestedBlock.style.display = "block";
            (mapping.rows || []).forEach(row => addOpeningHoursRow(property, row));
        }

        refreshSelect(select);
    });

    updateMapping();
}

// Form-Submit Handler
document.getElementById("jsonld-main-form").addEventListener("submit", function () {
    updateMapping();
    document.getElementById("field-mappings-input").value = JSON.stringify(fieldMappings);
});

// Initialer Load der gespeicherten Konfiguration
document.addEventListener("DOMContentLoaded", function () {
    initSelect(document.getElementById("schema_type"));

    updateSchemaFields();
    if (Object.keys(savedMappings).length > 0) {
        setTimeout(restoreSavedMappings, 100);
    }

    // Sticky Preview Funktionalität initialisieren
    initPreviewFloating();
});

// Sticky Preview Funktionalität
function initPreviewFloating() {
    const previewCol = document.querySelector(".jsonld-preview-col");
    const previewSticky = document.querySelector(".jsonld-preview-sticky");
    if (!previewCol || !previewSticky) return;

    const row = previewCol.parentElement;
    const topOffset = 20;
    const placeholder = document.createElement("div");
    placeholder.style.display = "none";
    previewSticky.parentNode.insertBefore(placeholder, previewSticky);

    function resetPreviewPosition() {
        placeholder.style.display = "none";
        previewSticky.style.position = "";
        previewSticky.style.top = "";
        previewSticky.style.left = "";
        previewSticky.style.width = "";
        previewSticky.style.right = "";
    }

    function updateFloatingPreview() {
        const isDesktop = window.innerWidth >= 992;
        if (!isDesktop || !row) {
            resetPreviewPosition();
            return;
        }

        const rowRect = row.getBoundingClientRect();
        const colRect = previewCol.getBoundingClientRect();
        const colStyle = window.getComputedStyle(previewCol);
        const padLeft = parseFloat(colStyle.paddingLeft || "0") || 0;
        const padRight = parseFloat(colStyle.paddingRight || "0") || 0;
        const stickyHeight = previewSticky.offsetHeight;
        const shouldFloat = rowRect.top <= topOffset && rowRect.bottom > (topOffset + stickyHeight);

        if (shouldFloat) {
            placeholder.style.display = "block";
            placeholder.style.height = stickyHeight + "px";
            previewSticky.style.position = "fixed";
            previewSticky.style.top = topOffset + "px";
            previewSticky.style.left = (colRect.left + padLeft) + "px";
            previewSticky.style.width = Math.max(0, colRect.width - padLeft - padRight) + "px";
            previewSticky.style.right = "auto";
        } else {
            resetPreviewPosition();
        }
    }

    window.addEventListener("scroll", updateFloatingPreview);
    window.addEventListener("resize", updateFloatingPreview);
    updateFloatingPreview();
}

</script>
