<?php

/**
 * JSON-LD Manager - Website Schema Konfiguration
 * 
 * Dreispaltiges Dashboard für individuelle Website-Schema-Konfiguration:
 * - Links: Artikel-Liste (ID, Name, Template, Status)
 * - Mitte: Metainfo-Felder Konfiguration 
 * - Rechts: JSON-LD Vorschau
 * 
 * @package JsonldManager
 * @version 1.0.0
 * @author  getaweb GmbH
 */

// Output Buffering starten für Fragment
ob_start();
$csrfToken = rex_csrf_token::factory('jsonld_manager_article_config');
$csrfTokenField = $csrfToken->getHiddenField();

// === AJAX REQUEST HANDLING ===
if (rex_request('ajax', 'string') === 'get_article_data') {
    $articleId = rex_request('article_id', 'int', 0);
    if ($articleId > 0) {
        $response = [
            'success' => true,
            'article' => getArticleData($articleId),
            'config' => getWebsiteSchemaConfig($articleId),
            'jsonld' => generateWebPageJsonLd($articleId)
        ];
        rex_response::sendJson($response);
        exit;
    }
}

// AJAX: Website Schema Configuration speichern
if (rex_post('action') === 'save_website_config' && rex_post('article_id', 'int') && $csrfToken->isValid()) {
    $articleId = rex_post('article_id', 'int');
    $localbusiness_branch_ids = rex_post('localbusiness_branch_ids', 'array', []);
    $config = [
        'name_field' => rex_post('name_field', 'string', ''),
        'description_field' => rex_post('description_field', 'string', ''),
        'url_field' => rex_post('url_field', 'string', ''),
        'image_field' => rex_post('image_field', 'string', ''),
        'localbusiness_branch_ids' => $localbusiness_branch_ids,
        'active' => rex_post('active', 'bool', false)
    ];
    
    // Save to database
    $sql = rex_sql::factory();
    $sql->setTable(rex::getTable('jsonld_schemas'));
    $sql->setWhere('article_id = :aid AND clang_id = :cid AND schema_type = "WebPage"', ['aid' => $articleId, 'cid' => rex_clang::getStartId()]);
    
    if ($sql->getRows() > 0) {
        $sql->setValues([
            'config' => json_encode($config),
            'active' => $config['active'] ? 1 : 0,
            'modified' => date('Y-m-d H:i:s')
        ]);
        $sql->update();
    } else {
        $sql->setValues([
            'article_id' => $articleId,
            'clang_id' => rex_clang::getStartId(),
            'schema_type' => 'WebPage',
            'config' => json_encode($config),
            'active' => $config['active'] ? 1 : 0,
            'created' => date('Y-m-d H:i:s'),
            'modified' => date('Y-m-d H:i:s')
        ]);
        $sql->insert();
    }
    
    echo rex_view::success('Website Schema wurde gespeichert.');
} elseif (rex_post('action') === 'save_website_config') {
    echo rex_view::error('Sicherheitsprüfung fehlgeschlagen (CSRF). Bitte Seite neu laden.');
}

/**
 * Artikel-Daten für AJAX abrufen
 */
/** @return array<string, mixed>|null */
function getArticleData(int $articleId): ?array {
    $sql = rex_sql::factory();
    $sql->setQuery('SELECT id, name, template_id, updatedate FROM ' . rex::getTable('article') . ' WHERE id = ? AND clang_id = ?', [$articleId, rex_clang::getStartId()]);
    
    if ($sql->hasNext()) {
        $templateSql = rex_sql::factory();
        $templateSql->setQuery('SELECT name FROM ' . rex::getTable('template') . ' WHERE id = ?', [$sql->getValue('template_id')]);
        $templateName = $templateSql->hasNext() ? $templateSql->getValue('name') : 'Unbekannt';
        
        return [
            'id' => $sql->getValue('id'),
            'name' => $sql->getValue('name'),
            'template_id' => $sql->getValue('template_id'),
            'template_name' => $templateName,
            'updatedate' => $sql->getValue('updatedate')
        ];
    }
    return null;
}

/**
 * Website Schema Konfiguration abrufen
 */
/** @return array<string, mixed> */
function getWebsiteSchemaConfig(int $articleId): array {
    $sql = rex_sql::factory();
    $sql->setQuery('SELECT config, active FROM ' . rex::getTable('jsonld_schemas') . ' WHERE article_id = ? AND clang_id = ? AND schema_type = "WebPage"', [$articleId, rex_clang::getStartId()]);
    
    if ($sql->hasNext()) {
        $configRaw = $sql->getValue('config');
        $config = is_string($configRaw) ? (json_decode($configRaw, true) ?? []) : [];
        $config['active'] = (bool) $sql->getValue('active');
        // Kompatibilität: Einzelwert zu Array
        if (isset($config['localbusiness_branch_id']) && !isset($config['localbusiness_branch_ids'])) {
            $config['localbusiness_branch_ids'] = $config['localbusiness_branch_id'] ? [$config['localbusiness_branch_id']] : [];
        }
        if (!isset($config['localbusiness_branch_ids'])) {
            $config['localbusiness_branch_ids'] = [];
        }
        return $config;
    }
    return [
        'name_field' => '',
        'description_field' => '',
        'url_field' => '',
        'image_field' => '',
        'localbusiness_branch_ids' => [],
        'active' => false
    ];
}

/**
 * Verfügbare Meta-Felder ermitteln
 * @return string[]
 */
function getAvailableMetaFields(): array {
    $fields = [
        '' => 'Bitte wählen',
        'name' => 'Artikelname',
        'art_description' => 'Meta Beschreibung'
    ];
    
    // YRewrite prüfen
    if (rex_addon::get('yrewrite')->isAvailable()) {
        $fields['yrewrite_title'] = 'YRewrite Titel';
        $fields['yrewrite_description'] = 'YRewrite Beschreibung';
    }
    
    // Metainfo-Felder hinzufügen
    if (rex_addon::get('metainfo')->isAvailable()) {
        try {
            $sql = rex_sql::factory();
            // Erst versuchen, die Tabellenspalten zu ermitteln
            $sql->setQuery('SHOW COLUMNS FROM ' . rex::getTable('metainfo_field'));
            $columns = [];
            while ($sql->hasNext()) {
                $columns[] = $sql->getValue('Field');
                $sql->next();
            }
            
            // Richtige Query basierend auf verfügbaren Spalten
            if (in_array('object_type', $columns)) {
                $sql->setQuery('SELECT name, title FROM ' . rex::getTable('metainfo_field') . ' WHERE object_type = "article" ORDER BY title');
            } else {
                $sql->setQuery('SELECT name, title FROM ' . rex::getTable('metainfo_field') . ' ORDER BY title');
            }
            
            while ($sql->hasNext()) {
                $fields['meta_' . $sql->getValue('name')] = 'Meta: ' . $sql->getValue('title');
                $sql->next();
            }
        } catch (Exception $e) {
            // Fallback: Keine Metainfo-Felder hinzufügen bei Fehlern
        }
    }
    
    return $fields;
}

/**
 * Verfügbare LocalBusiness Standorte abrufen
 * @return array<int, string>
 */
function getAvailableLocalBusinessBranches(): array {
    $branches = [0 => 'Keine LocalBusiness Zuordnung (für allgemeine Seiten)'];
    
    try {
        $sql = rex_sql::factory();
        $sql->setQuery('SELECT id, branch_name, is_main_branch FROM ' . rex::getTable('jsonld_localbusiness_branches') . ' ORDER BY is_main_branch DESC, sort_order ASC, branch_name ASC');
        
        while ($sql->hasNext()) {
            $label = (string) $sql->getValue('branch_name');
            if ($sql->getValue('is_main_branch')) {
                $label .= ' (Hauptstandort)';
            }
            $branches[(int) $sql->getValue('id')] = $label;
            $sql->next();
        }
    } catch (Exception $e) {
        // Fallback: Keine Standorte verfügbar
    }
    
    return $branches;
}

/**
 * WebPage JSON-LD generieren
 */
/** @return array<string, mixed>|null */
function generateWebPageJsonLd(int $articleId): ?array {
    // Basis Article-Daten
    $article = rex_article::get($articleId, rex_clang::getStartId());
    if (!$article) return null;
    
    // Gespeicherte Konfiguration laden
    $config = getWebsiteSchemaConfig($articleId);
    $jsonld = [
        "@context" => "https://schema.org",
        "@type" => "WebPage",
        "@id" => \FriendsOfRedaxo\JsonLdManager\DomainConfig::getSafeArticleUrl($articleId) . '#webpage'
    ];
    // LocalBusiness-IDs als Array berücksichtigen
    if (!empty($config['localbusiness_branch_ids'])) {
        $jsonld['localBusinessBranchIds'] = $config['localbusiness_branch_ids'];
    }
    
    // Name/Title
    if (!empty($config['name_field'])) {
        $nameValue = getFieldValue($article, $config['name_field']);
        if ($nameValue) $jsonld['name'] = $nameValue;
    } else {
        $jsonld['name'] = $article->getName();
    }
    
    // Description
    if (!empty($config['description_field'])) {
        $descValue = getFieldValue($article, $config['description_field']);
        if ($descValue) $jsonld['description'] = $descValue;
    }
    
    // URL
    $jsonld['url'] = \FriendsOfRedaxo\JsonLdManager\DomainConfig::getSafeArticleUrl($articleId);

    // Image
    if (!empty($config['image_field'])) {
        $imageValue = getFieldValue($article, $config['image_field']);
        if ($imageValue) {
            $jsonld['image'] = \FriendsOfRedaxo\JsonLdManager\DomainConfig::getSafeArticleUrl(1) . '/media/' . $imageValue;
        }
    }
    
    return $jsonld;
}

/**
 * Feld-Wert aus Artikel extrahieren
 */
function getFieldValue(rex_article $article, string $fieldName): mixed {
    switch ($fieldName) {
        case 'name':
            return $article->getName();
        case 'yrewrite_title':
            return rex_addon::get('yrewrite')->isAvailable() ? $article->getValue('yrewrite_title') : '';
        case 'yrewrite_description':
            return rex_addon::get('yrewrite')->isAvailable() ? $article->getValue('yrewrite_description') : '';
        case 'art_description':
            return $article->getValue('art_description');
        default:
            if (strpos($fieldName, 'meta_') === 0) {
                $metaKey = substr($fieldName, 5);
                return $article->getValue($metaKey);
            }
            return $article->getValue($fieldName);
    }
}

// === ARTIKEL-LISTE ABRUFEN ===
$filterClang = rex_request('filter_clang', 'int', rex_clang::getStartId());
$search = rex_request('search', 'string', '');

// SQL Query für Artikel
$sql = rex_sql::factory();
$whereClause = 'clang_id = ' . $filterClang;

if ($search) {
    $whereClause .= ' AND name LIKE ' . $sql->escape('%' . $search . '%');
}

$sql->setQuery('SELECT id, name, template_id, updatedate FROM ' . rex::getTable('article') . ' WHERE ' . $whereClause . ' ORDER BY name LIMIT 50');

// Template-Namen abrufen
$templateSql = rex_sql::factory();
$templateSql->setQuery('SELECT id, name FROM ' . rex::getTable('template') . ' ORDER BY name');
$templates = [];
while ($templateSql->hasNext()) {
    $templates[(int) $templateSql->getValue('id')] = (string) $templateSql->getValue('name');
    $templateSql->next();
}

// Verfügbare Meta-Felder laden
$availableFields = getAvailableMetaFields();

// Verfügbare LocalBusiness Standorte laden  
$availableBranches = getAvailableLocalBusinessBranches();

// CSS für Layout
echo '<style>
.website-config-dashboard {
    margin: 20px 0;
}
.article-table-container {
    max-height: 600px;
    overflow-y: auto;
}
.article-table-container table {
    margin-bottom: 0;
}
.article-row {
    cursor: pointer;
}
.article-row:hover {
    background-color: rgba(255, 255, 255, 0.06);
}
.article-row.active {
    background-color: rgba(51, 122, 183, 0.25);
}
#json-preview {
    background: #f8f9fa;
    color: #333;
    border: 1px solid #ddd;
    padding: 15px;
    min-height: 400px;
    max-height: 600px;
    overflow: auto;
    font-size: 12px;
    font-family: Monaco, Menlo, monospace;
    margin-bottom: 12px;
    border-radius: 4px;
    white-space: pre-wrap;
}
.config-panel {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 20px;
}
.selected-article-info {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 4px;
    padding: 15px;
    margin-bottom: 20px;
}
</style>';

// JavaScript
echo '<script>
let currentArticleId = null;

function selectArticle(articleId, articleName) {
    // Vorherige Auswahl entfernen
    document.querySelectorAll(".article-row").forEach(row => {
        row.classList.remove("active");
    });
    
    // Neue Auswahl markieren
    const selectedRow = document.querySelector(`[data-article-id="${articleId}"]`);
    if (selectedRow) {
        selectedRow.classList.add("active");
    }
    
    currentArticleId = articleId;
    
    // AJAX Request für Artikel-Daten
    fetch(window.location.href, {
        method: "POST",
        headers: {
            "Content-Type": "application/x-www-form-urlencoded",
        },
        body: `ajax=get_article_data&article_id=${articleId}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            updateConfigPanel(data.article, data.config);
            updateJsonPreview(data.jsonld);
        }
    })
    .catch(error => {});
}

function updateConfigPanel(article, config) {
    document.getElementById("selected-article-name").textContent = article.name;
    document.getElementById("selected-article-id").textContent = article.id;
    document.getElementById("selected-article-template").textContent = article.template_name;
    
    // Config-Felder setzen
    document.getElementById("name_field").value = config.name_field || "";
    document.getElementById("description_field").value = config.description_field || "";
    document.getElementById("image_field").value = config.image_field || "";
    // Multi-Select für Standorte
    const lbSelect = document.getElementById("localbusiness_branch_ids");
    if (lbSelect) {
        Array.from(lbSelect.options).forEach(opt => {
            opt.selected = (config.localbusiness_branch_ids || []).map(String).includes(String(opt.value));
        });
        if (typeof jQuery !== "undefined" && typeof jQuery.fn.selectpicker !== "undefined") {
            jQuery(lbSelect).selectpicker("refresh");
        }
    }
    document.getElementById("active").checked = config.active || false;
    if (typeof jQuery !== "undefined" && typeof jQuery.fn.selectpicker !== "undefined") {
        jQuery("#name_field, #description_field, #image_field, #localbusiness_branch_ids").selectpicker("refresh");
    }
    
    // Verstecktes Feld für Artikel-ID
    document.getElementById("config_article_id").value = article.id;
    
    // Panel einblenden
    document.getElementById("config-panel").style.display = "block";
}

function updateJsonPreview(jsonld) {
    const preview = document.getElementById("json-preview");
    if (jsonld) {
        preview.textContent = JSON.stringify(jsonld, null, 2);
    } else {
        preview.textContent = "Keine JSON-LD Daten verfügbar";
    }
}

function saveWebsiteConfig() {
    if (!currentArticleId) {
        alert("Bitte wählen Sie zuerst einen Artikel aus");
        return;
    }
    
    const form = document.getElementById("website-config-form");
    const formData = new FormData(form);
    // Multi-Select: alle ausgewählten Optionen einsammeln
    const lbSelect = document.getElementById("localbusiness_branch_ids");
    if (lbSelect) {
        formData.delete("localbusiness_branch_ids[]");
        Array.from(lbSelect.selectedOptions).forEach(opt => {
            formData.append("localbusiness_branch_ids[]", opt.value);
        });
    }
    formData.append("action", "save_website_config");
    formData.append("article_id", currentArticleId);
    fetch(window.location.href, {
        method: "POST",
        body: formData
    })
    .then(response => response.text())
    .then(data => {
        // Erfolgsmeldung anzeigen, kein Reload
        if (typeof rex !== "undefined" && typeof rex.flashMessage === "function") {
            rex.flashMessage("Website Schema wurde gespeichert.", "success");
        } else {
            alert("Website Schema wurde gespeichert.");
        }
        // Optional: Vorschau neu laden
        updatePreviewLive();
    })
    .catch(error => {
        alert("Fehler beim Speichern");
    });
}

// Live-Update der JSON-LD Vorschau bei Formular-Änderungen
function updatePreviewLive() {
    if (!currentArticleId) return;
    
    // Neue AJAX Anfrage mit aktuellen Formular-Daten
    selectArticle(currentArticleId, "");
}

document.addEventListener("DOMContentLoaded", function() {
    // Event Listener für Formular-Felder
    ["name_field", "description_field", "image_field", "localbusiness_branch_ids", "active"].forEach(fieldId => {
        const field = document.getElementById(fieldId);
        if (field) {
            field.addEventListener("change", updatePreviewLive);
        }
    });
});
</script>';

// HTML Layout - Dreispaltiges Dashboard
echo '<div class="website-config-dashboard">';

// Filter-Bereich
echo '<div class="row" style="margin-bottom: 20px;">
    <div class="col-md-12">
        <div class="panel panel-primary">
            <header class="panel-heading">
                <h1 class="panel-title">Website Schema Konfiguration</h1>
            </header>
            <div class="panel-body">
                <form method="get" class="form-inline">
                    <input type="hidden" name="page" value="jsonld_manager/article">
                    <div class="form-group" style="margin-right: 15px;">
                        <input type="text" name="search" value="' . htmlspecialchars($search) . '" 
                               class="form-control" placeholder="Artikel suchen" style="width: 250px;">
                    </div>
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">Suchen</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>';

// Dreispaltige Hauptlayout
echo '<div class="row">';

// Spalte 1: Artikel-Liste (Links)
echo '<div class="col-md-4">
    <div class="panel panel-primary">
        <header class="panel-heading">
            <h1 class="panel-title">Artikel (' . $sql->getRows() . ')</h1>
        </header>
        <div class="panel-body" style="padding: 0;">
            <div class="article-table-container">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Template</th>
                        </tr>
                    </thead>
                    <tbody>';

if ($sql->getRows() == 0) {
    echo '<tr><td colspan="3" class="text-center text-muted">Keine Artikel gefunden.</td></tr>';
} else {
    while ($sql->hasNext()) {
        $articleId = (int) $sql->getValue('id');
        $articleName = (string) $sql->getValue('name');
        $templateId = (int) $sql->getValue('template_id');
        $templateName = $templates[$templateId] ?? 'Unbekannt';
        
        echo '<tr class="article-row" data-article-id="' . $articleId . '" onclick="selectArticle(' . $articleId . ', \'' . htmlspecialchars($articleName, ENT_QUOTES) . '\')">
            <td>' . $articleId . '</td>
            <td><strong>' . htmlspecialchars($articleName) . '</strong></td>
            <td><small>' . htmlspecialchars((string) $templateName) . '</small></td>
        </tr>';
        
        $sql->next();
    }
}

echo '</tbody>
                </table>
            </div>
        </div>
    </div>
</div>';

// Spalte 2: Metainfo-Felder Konfiguration (Mitte)
echo '<div class="col-md-4">
    <div class="panel panel-primary">
        <header class="panel-heading">
            <h1 class="panel-title">Schema Konfiguration</h1>
        </header>
        <div class="panel-body">
            <div id="config-panel" style="display: none;">
                <div class="selected-article-info">
                    <h4>Ausgewählter Artikel</h4>
                    <p><strong>Name:</strong> <span id="selected-article-name"></span></p>
                    <p><strong>ID:</strong> <span id="selected-article-id"></span></p>
                    <p><strong>Template:</strong> <span id="selected-article-template"></span></p>
                </div>
                
                <form id="website-config-form" onsubmit="event.preventDefault(); saveWebsiteConfig();">
                    ' . $csrfTokenField . '
                    <input type="hidden" id="config_article_id" name="article_id" value="">
                    
                    <div class="form-group">
                        <label for="active">Schema aktivieren</label>
                        <div>
                            <label class="checkbox-inline">
                                <input type="checkbox" id="active" name="active" value="1">
                                WebPage Schema für diesen Artikel aktivieren
                            </label>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <div class="form-group">
                        <label for="name_field">Name/Titel-Feld:</label>
                        <select id="name_field" name="name_field" class="form-control selectpicker" data-live-search="true" data-size="10">';

foreach ($availableFields as $value => $label) {
    echo '<option value="' . htmlspecialchars($value) . '">' . htmlspecialchars($label) . '</option>';
}

echo '</select>
                        <small class="help-block">Welches Feld soll als Name/Titel verwendet werden?</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="description_field">Beschreibungs-Feld:</label>
                        <select id="description_field" name="description_field" class="form-control selectpicker" data-live-search="true" data-size="10">';

foreach ($availableFields as $value => $label) {
    echo '<option value="' . htmlspecialchars($value) . '">' . htmlspecialchars($label) . '</option>';
}

echo '</select>
                        <small class="help-block">Welches Feld soll als Beschreibung verwendet werden?</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="image_field">Bild-Feld:</label>
                        <select id="image_field" name="image_field" class="form-control selectpicker" data-live-search="true" data-size="10">';

foreach ($availableFields as $value => $label) {
    echo '<option value="' . htmlspecialchars($value) . '">' . htmlspecialchars($label) . '</option>';
}

echo '</select>
                        <small class="help-block">Welches Feld enthält das Artikelbild?</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="localbusiness_branch_ids">LocalBusiness Standorte:</label>
                        <select id="localbusiness_branch_ids" name="localbusiness_branch_ids[]" class="form-control selectpicker" data-live-search="true" data-size="10" multiple>';
foreach ($availableBranches as $value => $label) {
    echo '<option value="' . htmlspecialchars((string) $value) . '">' . htmlspecialchars((string) $label) . '</option>';
}
echo '</select>
                        <small class="help-block">Mehrere Standorte auswählbar. "Keine" für allgemeine Seiten wie Impressum.</small>
                    </div>
                    
                    <div class="form-group">
                        <button type="submit" class="btn btn-success btn-lg btn-block">
                            Konfiguration speichern
                        </button>
                    </div>
                </form>
            </div>
            
            <div id="no-selection" style="text-align: center; color: #999; margin-top: 50px;">
                <p><strong>Bitte wählen Sie einen Artikel aus der Liste aus</strong></p>
                <p>Klicken Sie auf einen Artikel in der linken Spalte, um die Schema-Konfiguration zu bearbeiten.</p>
            </div>
        </div>
    </div>
</div>';

// Spalte 3: JSON-LD Vorschau (Rechts)
echo '<div class="col-md-4">
    <div class="panel panel-primary">
        <header class="panel-heading">
            <h1 class="panel-title">JSON-LD Vorschau</h1>
        </header>
        <div class="panel-body">
            <pre id="json-preview">Wählen Sie einen Artikel aus, um die JSON-LD Vorschau zu sehen...</pre>
            <div class="help-block">
                <strong>Hinweis:</strong> Die Vorschau wird automatisch aktualisiert, wenn Sie die Konfiguration ändern.
            </div>
        </div>
    </div>
</div>';

echo '</div>'; // Ende row
echo '</div>'; // Ende website-config-dashboard

$content = ob_get_clean();

$fragment = new rex_fragment();
$fragment->setVar('title', 'Website Schema Konfiguration', false);
$fragment->setVar('body', $content, false);
echo $fragment->parse('core/page/section.php');
