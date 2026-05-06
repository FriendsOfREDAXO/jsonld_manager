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
if ($profile['table_parameters']) {
    $tableParams = json_decode($profile['table_parameters'], true);
    if ($tableParams && !empty($tableParams['table_name'])) {
        $yformTableName = $tableParams['table_name'];
    }
}

// Fallback: Korrigiere Tabellenname (entferne 1_xxx_ Prefix)  
if (!$yformTableName) {
    $yformTableName = str_replace('1_xxx_', '', $profile['table_name']);
}

if (!is_string($yformTableName) || preg_match('/^[A-Za-z0-9_]+$/', $yformTableName) !== 1) {
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
    
    // JSON validieren
    $mappingsArray = json_decode($fieldMappings, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $messages[] = rex_view::error('Ungültiges JSON in den Feld-Mappings.');
    } else {
        // Mapping speichern/aktualisieren
        $sql = rex_sql::factory();
        if (count($mapping) > 0) {
            // Update
            $sql->setTable(rex::getTable('jsonld_url_profile_mappings'));
            $sql->setWhere(['id' => $mapping[0]['id']]);
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
    if ($profile['table_parameters']) {
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
    'FAQPage' => [
        'mainEntity' => 'FAQ-Einträge (Array)',
        'name' => 'FAQ-Titel',
        'description' => 'FAQ-Beschreibung'
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
        'contactPoint' => 'Kontaktinformationen',
        'address' => 'Adresse'
    ],
    'LocalBusiness' => [
        'name' => 'Geschäftsname',
        'description' => 'Geschäftsbeschreibung',
        'address' => 'Geschäftsadresse',
        'telephone' => 'Telefonnummer',
        'email' => 'E-Mail',
        'openingHours' => 'Öffnungszeiten',
        'image' => 'Geschäftsbild'
    ],
    'Product' => [
        'name' => 'Produktname',
        'description' => 'Produktbeschreibung',
        'image' => 'Produktbild',
        'sku' => 'Artikelnummer',
        'brand' => 'Marke',
        'offers' => 'Preis/Angebot',
        'category' => 'Produktkategorie'
    ],
    'Service' => [
        'name' => 'Service-Name',
        'description' => 'Service-Beschreibung',
        'provider' => 'Anbieter',
        'serviceType' => 'Service-Typ',
        'areaServed' => 'Servicegebiet'
    ],
    'Event' => [
        'name' => 'Event-Name',
        'description' => 'Event-Beschreibung',
        'startDate' => 'Startdatum',
        'endDate' => 'Enddatum',
        'location' => 'Veranstaltungsort',
        'organizer' => 'Veranstalter',
        'image' => 'Event-Bild'
    ],
    'Course' => [
        'name' => 'Kurs-Name',
        'description' => 'Kurs-Beschreibung',
        'provider' => 'Kursanbieter',
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

// Config laden falls vorhanden
$config = [];
if (!empty($mapping)) {
    $config = [
        'schema_type' => $mapping[0]['schema_type'] ?? '',
        'active' => $mapping[0]['active'] ?? 1,
        'field_mappings' => json_decode($mapping[0]['field_mappings'] ?? '{}', true)
    ];
}

// Sample-Daten für JavaScript (ersten Datensatz verwenden)
$sampleData = !empty($sampleData) ? $sampleData[0] : [];

// Fragment-Inhalt komplett aufbauen
ob_start();

// Messages anzeigen
foreach ($messages as $message) {
    echo $message;
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

echo '          <div class="form-group">';
echo '            <label for="schema_type">Schema.org Typ</label>';
echo '            <select id="schema_type" name="schema_type" class="form-control selectpicker" data-live-search="true" data-size="8" onchange="updateSchemaFields()" required>';
echo '              <option value="">-- Schema-Typ wählen --</option>';
echo '              <optgroup label="Artikel & Content">';
foreach(['Article', 'BlogPosting', 'NewsArticle'] as $schemaType) {
    $selected = ($config['schema_type'] === $schemaType) ? ' selected' : '';
    echo '                <option value="' . rex_escape($schemaType) . '"' . $selected . '>' . rex_escape($schemaType) . '</option>';
}
echo '              </optgroup>';
echo '              <optgroup label="Personen & Unternehmen">';
foreach(['Organization', 'Person'] as $schemaType) {
    $selected = ($config['schema_type'] === $schemaType) ? ' selected' : '';
    echo '                <option value="' . rex_escape($schemaType) . '"' . $selected . '>' . rex_escape($schemaType) . '</option>';
}
echo '              </optgroup>';
echo '              <optgroup label="Geschäfte & Services">';
foreach(['LocalBusiness', 'Service'] as $schemaType) {
    $selected = ($config['schema_type'] === $schemaType) ? ' selected' : '';
    echo '                <option value="' . rex_escape($schemaType) . '"' . $selected . '>' . rex_escape($schemaType) . '</option>';
}
echo '              </optgroup>';
echo '              <optgroup label="Produkte & Angebote">';
foreach(['Product'] as $schemaType) {
    $selected = ($config['schema_type'] === $schemaType) ? ' selected' : '';
    echo '                <option value="' . rex_escape($schemaType) . '"' . $selected . '>' . rex_escape($schemaType) . '</option>';
}
echo '              </optgroup>';
echo '              <optgroup label="Events & Kurse">';
foreach(['Course', 'Event'] as $schemaType) {
    $selected = ($config['schema_type'] === $schemaType) ? ' selected' : '';
    echo '                <option value="' . rex_escape($schemaType) . '"' . $selected . '>' . rex_escape($schemaType) . '</option>';
}
echo '              </optgroup>';
echo '              <optgroup label="Sonstige">';
foreach(['Animal', 'FAQPage'] as $schemaType) {
    $selected = ($config['schema_type'] === $schemaType) ? ' selected' : '';
    echo '                <option value="' . rex_escape($schemaType) . '"' . $selected . '>' . rex_escape($schemaType) . '</option>';
}
echo '              </optgroup>';
echo '            </select>';
echo '          </div>';

echo '          <div id="field-mappings-container" style="display:none;">';
echo '            <h4>Feld-Mappings</h4>';
echo '            <p class="help-block">Verknüpfen Sie Schema.org Properties mit Feldern aus der YForm-Tabelle "' . rex_escape($yformTableName) . '" oder verwenden Sie statische Werte.</p>';
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

?>
<script>
// Schema-Properties und Sample-Daten für JavaScript verfügbar machen
const schemaProperties = <?= json_encode($schemaProperties) ?>;
const tableFields = <?= json_encode(array_column($tableFields, 'Field')) ?>;
const sampleData = <?= json_encode($sampleData) ?>;
const profileNamespace = <?= json_encode($profile['namespace']) ?>;

// Field-Mappings Object für Live-Updates
let fieldMappings = {};

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
        const row = document.createElement("div");
        row.className = "form-group";
        
        row.innerHTML = `
            <label for="mapping_${property}"><strong>${description}</strong> <small style="color:#999;">(${property})</small></label>
            <div class="row">
                <div class="col-md-6">
                    <select id="mapping_${property}" data-property="${property}" class="form-control selectpicker" data-live-search="true" data-size="10" onchange="updateMapping()">
                        <option value="">-- Feld wählen --</option>
                        <option value="__STATIC__">Statischer Wert</option>
                        ${tableFields.map(field => `<option value="${field}">${field}</option>`).join("")}
                    </select>
                </div>
                <div class="col-md-6">
                    <input type="text" id="static_${property}" placeholder="Statischer Wert" class="form-control" style="display:none;" onchange="updateMapping()">
                </div>
            </div>
        `;
        
        mappingsDiv.appendChild(row);
        
        // Bootstrap Selectpicker initialisieren
        const select = row.querySelector("select.selectpicker");
        if (select && typeof $.fn.selectpicker !== 'undefined') {
            $(select).selectpicker();
        }
        
        // Change handler für static value toggle (Selectpicker Event)
        const selectElement = row.querySelector("select");
        const staticInput = row.querySelector("input[type=text]");
        
        // Event für Bootstrap Selectpicker
        $(selectElement).on('changed.bs.select', function() {
            if (this.value === "__STATIC__") {
                staticInput.style.display = "block";
                staticInput.focus();
            } else {
                staticInput.style.display = "none";
                staticInput.value = "";
            }
            updateMapping();
        });
        
        // Fallback für normale selects
        selectElement.addEventListener("change", function() {
            if (this.value === "__STATIC__") {
                staticInput.style.display = "block";
                staticInput.focus();
            } else {
                staticInput.style.display = "none";
                staticInput.value = "";
            }
        });
    });
    
    updatePreview();
}

function updateMapping() {
    fieldMappings = {};
    
    // Alle Mapping-Selects durchgehen
    document.querySelectorAll("[data-property]").forEach(select => {
        const property = select.dataset.property;
        const value = select.value;
        const staticInput = document.getElementById(`static_${property}`);
        
        if (value) {
            if (value === "__STATIC__") {
                if (staticInput.value) {
                    fieldMappings[property] = {
                        type: "static",
                        value: staticInput.value
                    };
                }
            } else {
                fieldMappings[property] = {
                    type: "field", 
                    value: value
                };
            }
        }
    });
    
    updatePreview();
}

function updatePreview() {
    const schemaType = document.getElementById("schema_type").value;
    const preview = document.getElementById("json-preview");
    
    if (!schemaType) {
        preview.textContent = "Wählen Sie einen Schema-Typ aus.";
        return;
    }
    
    // Sample-Dataset für Vorschau verwenden (erste Zeile)
    const sampleRecord = sampleData && sampleData.length > 0 ? sampleData[0] : {};
    
    // JSON-LD Schema mit Sample-Daten generieren
    const schema = {
        "@context": "https://schema.org",
        "@type": schemaType,
        "@id": `${window.location.origin}/${profileNamespace}/${sampleRecord.id || "sample-id"}`,
        "url": `${window.location.origin}/${profileNamespace}/${sampleRecord.slug || sampleRecord.id || "sample-url"}`
    };
    
    // Felder basierend auf Mappings hinzufügen - KORREKTE AUFLÖSUNG
    Object.entries(fieldMappings).forEach(([property, mapping]) => {
        if (mapping.type === "static") {
            schema[property] = mapping.value;
        } else if (mapping.type === "field") {
            // Feldwert aus Sample-Daten auflösen
            const fieldValue = sampleRecord[mapping.value];
            if (fieldValue !== undefined && fieldValue !== null && fieldValue !== '') {
                schema[property] = fieldValue;
            } else {
                // Fallback für Debug: Zeige welches Feld gemappt wurde
                schema[property] = `[Feld: ${mapping.value}] (Sample-Daten nicht verfügbar)`;
            }
        }
    });
    
    preview.textContent = JSON.stringify(schema, null, 2);
}

// Form-Submit Handler
document.getElementById("jsonld-main-form").addEventListener("submit", function(e) {
    document.getElementById("field-mappings-input").value = JSON.stringify(fieldMappings);
});

// Initialer Load der gespeicherten Konfiguration
document.addEventListener("DOMContentLoaded", function() {
    // Bootstrap Selectpicker für Schema-Type initialisieren
    if (typeof $.fn.selectpicker !== 'undefined') {
        $('#schema_type').selectpicker();
    }
    
    const savedMappings = <?= json_encode($config['field_mappings'] ?? []) ?>;
    
    // Mappings wiederherstellen
    if (Object.keys(savedMappings).length > 0) {
        fieldMappings = savedMappings;
        
        setTimeout(() => {
            updateSchemaFields();
            
            // Saved values in select boxes wiederherstellen
            Object.entries(savedMappings).forEach(([property, mapping]) => {
                const select = document.getElementById(`mapping_${property}`);
                const staticInput = document.getElementById(`static_${property}`);
                
                if (select) {
                    if (mapping.type === "static") {
                        select.value = "__STATIC__";
                        if (staticInput) {
                            staticInput.style.display = "block";
                            staticInput.value = mapping.value || "";
                        }
                    } else if (mapping.type === "field") {
                        select.value = mapping.value || "";
                    }
                    
                    // Selectpicker aktualisieren falls vorhanden
                    if (typeof $.fn.selectpicker !== 'undefined' && $(select).hasClass('selectpicker')) {
                        $(select).selectpicker('refresh');
                    }
                }
            });
            
            updatePreview();
        }, 100);
    } else {
        // Schema-Felder laden, falls Schema-Typ bereits gesetzt
        updateSchemaFields();
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
