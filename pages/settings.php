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

/**
 * Branch-IDs in verschiedenen Speicherformaten auf neue IDs mappen.
 *
 * @param mixed $value
 * @param array<int,int> $branchIdMap
 * @return mixed
 */
function jsonld_manager_remap_branch_ids($value, array $branchIdMap)
{
    $mapSingleId = static function ($id) use ($branchIdMap) {
        $id = (int) $id;
        return $branchIdMap[$id] ?? $id;
    };

    if (is_array($value)) {
        return array_values(array_filter(array_map($mapSingleId, $value), static function (int $id): bool {
            return (int) $id > 0;
        }));
    }

    if (is_numeric($value)) {
        return $mapSingleId((int) $value);
    }

    if (is_string($value)) {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return $value;
        }

        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            return array_values(array_filter(array_map($mapSingleId, $decoded), static function (int $id): bool {
                return (int) $id > 0;
            }));
        }

        if (str_contains($trimmed, ',')) {
            $mapped = array_values(array_filter(array_map($mapSingleId, explode(',', $trimmed)), static function ($id): bool {
                return (int) $id > 0;
            }));
            return implode(',', $mapped);
        }

        if (is_numeric($trimmed)) {
            return $mapSingleId((int) $trimmed);
        }
    }

    return $value;
}

/**
 * Kopiert alle JSON-LD Inhalte von einer Sprache in eine andere.
 *
 * @return array<string,int>
 */
function jsonld_manager_copy_language_content(int $sourceClangId, int $targetClangId): array
{
    if ($sourceClangId <= 0 || $targetClangId <= 0) {
        throw new \RuntimeException('Bitte gültige Quell- und Zielsprache wählen.');
    }

    if (!rex_clang::exists($sourceClangId) || !rex_clang::exists($targetClangId)) {
        throw new \RuntimeException('Quelle oder Zielsprache existiert nicht.');
    }

    if ($sourceClangId === $targetClangId) {
        throw new \RuntimeException('Quell- und Zielsprache dürfen nicht identisch sein.');
    }

    $stats = [
        'config_removed' => 0,
        'config_copied' => 0,
        'branches_deleted' => 0,
        'branches_copied' => 0,
        'schemas_deleted' => 0,
        'schemas_copied' => 0,
    ];

    $allConfig = rex_config::get('jsonld_manager');
    $sourcePattern = '/_clang_' . preg_quote((string) $sourceClangId, '/') . '(?=_domain_|$)/';
    $targetPattern = '/_clang_' . preg_quote((string) $targetClangId, '/') . '(?=_domain_|$)/';

    // 1) Zielsprach-Keys entfernen, damit eine echte Kopie entsteht.
    foreach ($allConfig as $key => $_value) {
        if (!preg_match($targetPattern, (string) $key)) {
            continue;
        }

        if (rex_config::remove('jsonld_manager', (string) $key)) {
            $stats['config_removed']++;
        }
    }

    // 2) LocalBusiness-Branches kopieren und neue ID-Mapping-Tabelle erstellen.
    $branchIdMap = [];
    $branchesTable = rex::getTable('jsonld_localbusiness_branches');
    $branchSql = rex_sql::factory();

    $targetBranchRows = $branchSql->getArray('SELECT id FROM ' . $branchesTable . ' WHERE clang_id = ?', [$targetClangId]);
    $stats['branches_deleted'] = count($targetBranchRows);
    $branchSql->setQuery('DELETE FROM ' . $branchesTable . ' WHERE clang_id = ?', [$targetClangId]);

    $sourceBranches = $branchSql->getArray('SELECT * FROM ' . $branchesTable . ' WHERE clang_id = ? ORDER BY id ASC', [$sourceClangId]);
    foreach ($sourceBranches as $branchRow) {
        $oldId = (int) ($branchRow['id'] ?? 0);
        unset($branchRow['id']);
        $branchRow['clang_id'] = $targetClangId;
        $branchRow['modified'] = date('Y-m-d H:i:s');

        $insertSql = rex_sql::factory();
        $insertSql->setTable($branchesTable);
        $insertSql->setValues($branchRow);
        $insertSql->insert();

        $newId = (int) $insertSql->getLastId();
        if ($oldId > 0 && $newId > 0) {
            $branchIdMap[$oldId] = $newId;
        }
        $stats['branches_copied']++;
    }

    // 3) WebPage-/Schema-Zuordnungen (jsonld_schemas) kopieren + Branch-IDs remappen.
    $schemasTable = rex::getTable('jsonld_schemas');
    $schemaSql = rex_sql::factory();

    $targetSchemas = $schemaSql->getArray('SELECT id FROM ' . $schemasTable . ' WHERE clang_id = ?', [$targetClangId]);
    $stats['schemas_deleted'] = count($targetSchemas);
    $schemaSql->setQuery('DELETE FROM ' . $schemasTable . ' WHERE clang_id = ?', [$targetClangId]);

    $sourceSchemas = $schemaSql->getArray('SELECT * FROM ' . $schemasTable . ' WHERE clang_id = ? ORDER BY id ASC', [$sourceClangId]);
    foreach ($sourceSchemas as $schemaRow) {
        $config = json_decode((string) ($schemaRow['config'] ?? ''), true);
        if (!is_array($config)) {
            $config = [];
        }

        if (isset($config['localbusiness_branch_id'])) {
            $config['localbusiness_branch_id'] = jsonld_manager_remap_branch_ids($config['localbusiness_branch_id'], $branchIdMap);
        }
        if (isset($config['localbusiness_branch_ids'])) {
            $config['localbusiness_branch_ids'] = jsonld_manager_remap_branch_ids($config['localbusiness_branch_ids'], $branchIdMap);
        }

        unset($schemaRow['id']);
        $schemaRow['clang_id'] = $targetClangId;
        $schemaRow['config'] = json_encode($config, JSON_UNESCAPED_UNICODE);
        $schemaRow['modified'] = date('Y-m-d H:i:s');

        $insertSchemaSql = rex_sql::factory();
        $insertSchemaSql->setTable($schemasTable);
        $insertSchemaSql->setValues($schemaRow);
        $insertSchemaSql->insert();
        $stats['schemas_copied']++;
    }

    // 4) Sprachbezogene rex_config-Keys kopieren (inkl. domain-spezifischer Keys).
    foreach ($allConfig as $key => $value) {
        $key = (string) $key;
        if (!preg_match($sourcePattern, $key)) {
            continue;
        }

        $targetKey = preg_replace($sourcePattern, '_clang_' . $targetClangId, $key, 1);
        if (!is_string($targetKey) || $targetKey === '') {
            continue;
        }

        $targetValue = $value;
        if (str_starts_with($key, 'article_branch_')) {
            $targetValue = jsonld_manager_remap_branch_ids($value, $branchIdMap);
        }

        rex_config::set('jsonld_manager', $targetKey, $targetValue);
        $stats['config_copied']++;
    }

    return $stats;
}

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
    $selectedTemplateIds = array_values(array_unique(array_filter(array_map('intval', $templateIds), function (int $id): bool {
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
} elseif ($func === 'copy_language_content') {
    if (!$csrfToken->isValid()) {
        echo rex_view::error('Sicherheitsprüfung fehlgeschlagen (CSRF). Bitte Seite neu laden.');
    } else {
        $sourceClangId = rex_post('copy_source_clang_id', 'int', 0);
        $targetClangId = rex_post('copy_target_clang_id', 'int', 0);

        try {
            $copyStats = jsonld_manager_copy_language_content($sourceClangId, $targetClangId);
            echo rex_view::success(
                'Sprache wurde kopiert. ' .
                'Config entfernt: ' . (int) $copyStats['config_removed'] . ', ' .
                'Config kopiert: ' . (int) $copyStats['config_copied'] . ', ' .
                'Standorte gelöscht: ' . (int) $copyStats['branches_deleted'] . ', ' .
                'Standorte kopiert: ' . (int) $copyStats['branches_copied'] . ', ' .
                'Schemas gelöscht: ' . (int) $copyStats['schemas_deleted'] . ', ' .
                'Schemas kopiert: ' . (int) $copyStats['schemas_copied'] . '.'
            );
        } catch (\Throwable $e) {
            echo rex_view::error('Sprache konnte nicht kopiert werden: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES));
        }
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

$availableClangs = rex_clang::getAll(true);
$hasMultipleClangs = count($availableClangs) > 1;

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

<form method="post" action="" class="form-horizontal" id="jsonld-settings-form">
    <div class="row">
        <div class="col-md-6">
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

            <?php if ($hasMultipleClangs): ?>
            <div class="panel panel-info jsonld-language-copy-panel">
                <header class="panel-heading">
                    <h1 class="panel-title">Sprache kopieren</h1>
                </header>
                <div class="panel-body">
                    <form method="post" action="" id="jsonld-language-copy-form">
                        <input type="hidden" name="func" value="copy_language_content">
                        <?= $csrfTokenField ?>

                        <div class="form-group">
                            <div class="col-sm-12">
                                <label for="copy_source_clang_id" class="control-label jsonld-copy-label">Quellsprache</label>
                                <select name="copy_source_clang_id" id="copy_source_clang_id" class="form-control selectpicker" data-live-search="true" data-size="8" required>
                                    <option value="">Bitte wählen</option>
                                    <?php foreach ($availableClangs as $clang): ?>
                                        <option value="<?= (int) $clang->getId() ?>"><?= htmlspecialchars((string) $clang->getName()) ?> (ID <?= (int) $clang->getId() ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <div class="col-sm-12">
                                <label for="copy_target_clang_id" class="control-label jsonld-copy-label">Zielsprache</label>
                                <select name="copy_target_clang_id" id="copy_target_clang_id" class="form-control selectpicker" data-live-search="true" data-size="8" required>
                                    <option value="">Bitte wählen</option>
                                    <?php foreach ($availableClangs as $clang): ?>
                                        <option value="<?= (int) $clang->getId() ?>"><?= htmlspecialchars((string) $clang->getName()) ?> (ID <?= (int) $clang->getId() ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-group jsonld-copy-meta-group">
                            <div class="col-sm-12">
                                <small class="help-block jsonld-copy-help-block">
                                    Kopiert alle sprachabhängigen JSON-LD Inhalte von Quelle nach Ziel, inklusive globaler Schemas,
                                    artikelbezogener Zuordnungen und LocalBusiness-Standorte (inkl. ID-Mapping in den Zuordnungen).
                                    Bestehende Daten der Zielsprache werden dabei überschrieben.
                                </small>

                                <button type="submit" class="btn btn-primary jsonld-copy-submit-btn" onclick="return confirm('Alle JSON-LD Inhalte von der Quellsprache in die Zielsprache kopieren und bestehende Zieldaten überschreiben?');">
                                    <i class="fa fa-copy"></i> Sprache jetzt kopieren
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>
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
            <div class="panel panel-warning jsonld-template-warning-panel">
                <header class="panel-heading">
                    <h1 class="panel-title">Template-Integration</h1>
                </header>
                <div class="panel-body">
                    <div class="form-group">
                        <div class="col-sm-12">
                            <label for="jsonld-template-select" class="control-label" style="font-weight:600; margin-bottom:8px;">Templates für JSON-LD</label>
                            <select multiple name="template_ids[]" class="form-control selectpicker" data-live-search="true" data-size="10" size="8" id="jsonld-template-select" style="margin-bottom:8px;">
                                <?= $templateOptions ?>
                            </select>
                            <small class="help-block">Wählen Sie die Templates aus, in denen JSON-LD automatisch ausgegeben werden soll.</small>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
    <div class="rex-form-panel-footer" style="padding: 12px; background: rgba(0,0,0,.28); border-top: 1px solid rgba(255,255,255,.08); display: flex; justify-content: flex-end; align-items: center;">
        <button type="submit" class="btn btn-apply" form="jsonld-settings-form">Speichern</button>
    </div>
</form>

<?php
$content = ob_get_clean();

// Fragment mit Header erzeugen
$fragment = new rex_fragment();
$fragment->setVar('title', 'Einstellungen', false);
$fragment->setVar('body', $content, false);
echo $fragment->parse('core/page/section.php');
