<?php

/**
 * JSON-LD Manager - Einstellungen
 * 
 * Globale Einstellungen für das JSON-LD Manager AddOn inkl. Template-Auswahl.
 * Domain-spezifische Konfiguration für Multi-Domain-Installationen.
 * 
 * @package JsonldManager
 * @version 1.0.5
 * @author  getaweb GmbH
 */

// Domain-Konfiguration
use FriendsOfRedaxo\JsonLdManager\DomainConfig;

$activeDomainId = DomainConfig::getActiveDomainId();
$domainDisplay = DomainConfig::renderDomainDisplay();

// Form-Verarbeitung
$func = rex_request('func', 'string', '');
$csrfToken = rex_csrf_token::factory('jsonld_manager_settings');
$csrfTokenField = $csrfToken->getHiddenField();

if ($func === 'update_settings') {
    if (!$csrfToken->isValid()) {
        echo rex_view::error('Sicherheitsprüfung fehlgeschlagen (CSRF). Bitte Seite neu laden.');
    } else {
    
    // Grundeinstellungen
    $autoOutput = rex_post('auto_output', 'int', 0) ? true : false;
    $cacheEnabled = rex_post('cache_enabled', 'int', 0) ? true : false;
    $debugMode = rex_post('debug_mode', 'int', 0) ? true : false;
    $validateJson = rex_post('validate_json', 'int', 0) ? true : false;
    
    // Template-Auswahl für JSON-LD
    $templateIds = rex_post('template_ids', 'array', []);
    $selectedTemplateIds = array_values(array_unique(array_filter(array_map('intval', $templateIds), function ($id) {
        return $id > 0;
    })));
    
    // Konfiguration domain-spezifisch speichern
    $config = [
        'settings' => [
            'auto_output' => $autoOutput,
            'cache_enabled' => $cacheEnabled,
            'debug_mode' => $debugMode,
            'validate_json' => $validateJson
        ],
        'templates' => [
            'enabled_ids' => $selectedTemplateIds
        ]
    ];
    
    // Domain-spezifische Konfiguration 
    $configKey = DomainConfig::isMultiDomain() ? 'global_settings_domain_' . $activeDomainId : 'global_settings';
    rex_config::set('jsonld_manager', $configKey, $config);
    
    echo rex_view::success('Einstellungen für Domain ' . htmlspecialchars(DomainConfig::getActiveDomain()['domain'] ?? 'Standard') . ' wurden erfolgreich gespeichert.');
    }
    
} elseif ($func === 'clear_cache') {
    if (!$csrfToken->isValid()) {
        echo rex_view::error('Sicherheitsprüfung fehlgeschlagen (CSRF). Bitte Seite neu laden.');
    } else {
    
    // JSON-LD Cache komplett löschen
    if (class_exists('FriendsOfRedaxo\JsonLdManager\Frontend\Renderer')) {
        FriendsOfRedaxo\JsonLdManager\Frontend\Renderer::clearCache();
    }
    
    // REDAXO Cache für JSON-LD löschen
    if (class_exists('rex_cache')) {
        rex_cache::deleteNamespace('jsonld_manager');
    }
    
    echo rex_view::success('JSON-LD Cache wurde erfolgreich geleert.');
    }
}

// Aktuelle Konfiguration domain-spezifisch laden
$configKey = DomainConfig::isMultiDomain() ? 'global_settings_domain_' . $activeDomainId : 'global_settings';
$globalConfig = rex_config::get('jsonld_manager', $configKey, []);

// Fallback zu globaler Konfiguration wenn domain-spezifische nicht existiert
if (empty($globalConfig) && DomainConfig::isMultiDomain()) {
    $globalConfig = rex_config::get('jsonld_manager', 'global_settings', []);
}

$settings = $globalConfig['settings'] ?? [];
$templates = $globalConfig['templates'] ?? [];

// Default-Werte setzen
$autoOutput = $settings['auto_output'] ?? true;
$cacheEnabled = $settings['cache_enabled'] ?? true; 
$debugMode = $settings['debug_mode'] ?? false;
$validateJson = $settings['validate_json'] ?? true;
$selectedTemplateIds = $templates['enabled_ids'] ?? [];

// Verfügbare Templates laden
$availableTemplates = [];
$templateSql = rex_sql::factory();
$templateSql->setQuery('SELECT id, name FROM ' . rex::getTable('template') . ' WHERE active = 1 ORDER BY name ASC, id ASC');
foreach ($templateSql as $templateRow) {
    $templateId = (int) $templateRow->getValue('id');
    $availableTemplates[$templateId] = (string) $templateRow->getValue('name');
}

?>

<?php
// Template-Optionen vorab generieren
$templateOptions = '';
if ($availableTemplates) {
    foreach ($availableTemplates as $templateId => $templateName) {
        $selected = in_array((int) $templateId, $selectedTemplateIds, true) ? ' selected' : '';
        $templateOptions .= '<option value="' . (int) $templateId . '"' . $selected . '>' . htmlspecialchars($templateName) . ' (ID ' . (int) $templateId . ')</option>';
    }
} else {
    $templateOptions = '<option value="">Keine aktiven Templates gefunden</option>';
}

// HTML Content direkt ausgeben
ob_start();
?>

<div class="row">
    <div class="col-md-6">
        
        <form method="post" action="" class="form-horizontal" id="jsonld-settings-form">
            <input type="hidden" name="func" value="update_settings">
            <input type="hidden" name="domain_id" value="<?= $activeDomainId ?>">
            <?= $csrfTokenField ?>
            
            <!-- Grundeinstellungen Panel -->
            <div class="panel panel-primary">
                <header class="panel-heading">
                    <h1 class="panel-title">Grundeinstellungen</h1>
                </header>
                <div class="panel-body">
                    
                    <div class="form-group">
                        <label class="col-sm-4 control-label">Automatische Ausgabe</label>
                        <div class="col-sm-8">
                            <div class="checkbox">
                                <label>
                                    <input type="checkbox" name="auto_output" value="1"<?= $autoOutput ? ' checked="checked"' : '' ?>>
                                    JSON-LD automatisch in Templates ausgeben
                                </label>
                            </div>
                            <small class="help-block">Wenn aktiviert, wird JSON-LD automatisch über Extension Points in das Template eingebunden.</small>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="col-sm-4 control-label">Performance</label>
                        <div class="col-sm-8">
                            <div class="checkbox">
                                <label>
                                    <input type="checkbox" name="cache_enabled" value="1"<?= $cacheEnabled ? ' checked="checked"' : '' ?>>
                                    JSON Caching aktivieren
                                </label>
                            </div>
                            <small class="help-block">Verbessert die Performance durch Zwischenspeicherung der generierten JSON-LD Strukturen.</small>
                            
                            <!-- Cache löschen Button -->
                            <div style="margin-top: 10px;">
                                <a href="<?= rex_url::currentBackendPage(['func' => 'clear_cache'] + $csrfToken->getUrlParams()) ?>" 
                                   class="btn btn-primary btn-sm" 
                                   onclick="return confirm('Cache wirklich löschen?')">
                                    <i class="fa fa-trash"></i> Cache löschen
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="col-sm-4 control-label">Validierung</label>
                        <div class="col-sm-8">
                            <div class="checkbox">
                                <label>
                                    <input type="checkbox" name="validate_json" value="1"<?= $validateJson ? ' checked="checked"' : '' ?>>
                                    JSON Syntax-Validierung
                                </label>
                            </div>
                            <small class="help-block">Validiert JSON-LD vor der Ausgabe und zertifiziert Syntax-Konformität.</small>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="col-sm-4 control-label">Debug-Modus</label>
                        <div class="col-sm-8">
                            <div class="checkbox">
                                <label>
                                    <input type="checkbox" name="debug_mode" value="1"<?= $debugMode ? ' checked="checked"' : '' ?>>
                                    Debug-Informationen anzeigen
                                </label>
                            </div>
                            <small class="help-block">Wenn aktiviert, werden detaillierte Debug-Ausgaben als JSON-LD Overlay im Frontend angezeigt.</small>
                        </div>
                    </div>
                    
                </div>
            </div>
            
    </div>
    
    <div class="col-md-6">
        
        <!-- Domain-Auswahl (nur bei Multi-Domain) -->
        <?php if (DomainConfig::isMultiDomain()): ?>
        <div class="panel panel-info">
            <header class="panel-heading">
                <h1 class="panel-title">Domain-Konfiguration</h1>
            </header>
            <div class="panel-body">
                <?= DomainConfig::renderDomainSelect($activeDomainId) ?>
                <p class="help-block">Wählen Sie die Domain aus, für die Sie die Einstellungen konfigurieren möchten. Jede Domain wird separat verwaltet.</p>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Template-Integration Panel -->
        <div class="panel panel-default">
            <header class="panel-heading">
                <h1 class="panel-title">Template-Integration</h1>
            </header>
            <div class="panel-body">
                
                <div class="form-group">
                    <label class="col-sm-12 control-label">Templates für JSON-LD</label>
                    <div class="col-sm-12">
                        <select multiple name="template_ids[]" class="form-control selectpicker" data-live-search="true" data-size="10" size="8" id="jsonld-template-select">
                            <?= $templateOptions ?>
                        </select>
                        <small class="help-block">Wählen Sie die Templates aus, in denen JSON-LD automatisch ausgegeben werden soll.</small>
                    </div>
                </div>
                
            </div>
        </div>
        
    </div>
</div>

<div class="panel-footer text-right">
    <button type="submit" class="btn btn-apply" form="jsonld-settings-form">
        Speichern
    </button>
</div>

</form>

<?php
$content = ob_get_clean();

// Fragment mit Header erzeugen
$fragment = new rex_fragment();
$fragment->setVar('title', 'Einstellungen', false);
$fragment->setVar('body', $content, false);
echo $fragment->parse('core/page/section.php');
