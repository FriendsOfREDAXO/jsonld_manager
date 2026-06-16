<?php

use FriendsOfRedaxo\JsonLdManager\DomainConfig;

$activeDomainId = DomainConfig::getActiveDomainId();
$domainDisplay = DomainConfig::renderDomainDisplay();
$func = rex_request('func', 'string', '');
$csrfToken = rex_csrf_token::factory('jsonld_manager_settings');
$csrfTokenField = $csrfToken->getHiddenField();

function jsonld_manager_remap_branch_ids($value, array $branchIdMap)
{
    $mapSingleId = static function ($id) use ($branchIdMap) {
        $id = (int) $id;
        return $branchIdMap[$id] ?? $id;
    };

    if (is_array($value)) {
        return array_values(array_filter(array_map($mapSingleId, $value), static fn (int $id): bool => $id > 0));
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
            return array_values(array_filter(array_map($mapSingleId, $decoded), static fn (int $id): bool => $id > 0));
        }
        if (str_contains($trimmed, ',')) {
            $mapped = array_values(array_filter(array_map($mapSingleId, explode(',', $trimmed)), static fn ($id): bool => (int) $id > 0));
            return implode(',', $mapped);
        }
        if (is_numeric($trimmed)) {
            return $mapSingleId((int) $trimmed);
        }
    }
    return $value;
}

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

    $stats = ['config_removed' => 0, 'config_copied' => 0, 'branches_deleted' => 0, 'branches_copied' => 0, 'schemas_deleted' => 0, 'schemas_copied' => 0];
    $allConfig = rex_config::get('jsonld_manager');
    $sourcePattern = '/_clang_' . preg_quote((string) $sourceClangId, '/') . '(?=_domain_|$)/';
    $targetPattern = '/_clang_' . preg_quote((string) $targetClangId, '/') . '(?=_domain_|$)/';

    foreach ($allConfig as $key => $_value) {
        if (preg_match($targetPattern, (string) $key) && rex_config::remove('jsonld_manager', (string) $key)) {
            $stats['config_removed']++;
        }
    }

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

    foreach ($allConfig as $key => $value) {
        $key = (string) $key;
        if (!preg_match($sourcePattern, $key)) {
            continue;
        }
        $targetKey = preg_replace($sourcePattern, '_clang_' . $targetClangId, $key, 1);
        if (!is_string($targetKey) || $targetKey === '') {
            continue;
        }
        $targetValue = str_starts_with($key, 'article_branch_') ? jsonld_manager_remap_branch_ids($value, $branchIdMap) : $value;
        rex_config::set('jsonld_manager', $targetKey, $targetValue);
        $stats['config_copied']++;
    }

    return $stats;
}

if ($func === 'copy_language_content') {
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

$availableClangs = rex_clang::getAll(true);
$availableColumns = count($availableClangs) >= 2 ? 2 : 1;

ob_start();
?>
<form method="post" action="" id="jsonld-language-copy-form" class="jsonld-language-copy-form">
    <input type="hidden" name="func" value="copy_language_content">
    <?= $csrfTokenField ?>

    <div class="row">
        <div class="col-md-6">
            <div class="form-group">
                <label for="copy_source_clang_id" class="control-label">Quellsprache</label>
                <select name="copy_source_clang_id" id="copy_source_clang_id" class="form-control selectpicker" data-live-search="true" data-size="8">
                    <option value="">Bitte wählen</option>
                    <?php foreach ($availableClangs as $clang): ?>
                        <option value="<?= (int) $clang->getId() ?>"><?= htmlspecialchars((string) $clang->getName()) ?> (ID <?= (int) $clang->getId() ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="col-md-6">
            <div class="form-group">
                <label for="copy_target_clang_id" class="control-label">Zielsprache</label>
                <select name="copy_target_clang_id" id="copy_target_clang_id" class="form-control selectpicker" data-live-search="true" data-size="8">
                    <option value="">Bitte wählen</option>
                    <?php foreach ($availableClangs as $clang): ?>
                        <option value="<?= (int) $clang->getId() ?>"><?= htmlspecialchars((string) $clang->getName()) ?> (ID <?= (int) $clang->getId() ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <p class="help-block" style="margin-top: 8px;">
        Kopiert alle sprachabhängigen JSON-LD Inhalte von Quelle nach Ziel, inklusive globaler Schemas,
        artikelbezogener Zuordnungen und LocalBusiness-Standorte (inkl. ID-Mapping in den Zuordnungen).
        Bestehende Daten der Zielsprache werden dabei überschrieben.
    </p>
    <div class="rex-form-panel-footer" style="padding: 12px; background: rgba(0,0,0,.28); border-top: 1px solid rgba(255,255,255,.08); display: flex; justify-content: flex-end; align-items: center; margin-top: 0;">
        <button type="submit" class="btn btn-apply" onclick="return confirm('Alle JSON-LD Inhalte von der Quellsprache in die Zielsprache kopieren und bestehende Zieldaten überschreiben?');">Sprachangaben kopieren</button>
    </div>
</form>
<?php
$content = ob_get_clean();

$fragment = new rex_fragment();
$fragment->setVar('title', 'Sprache kopieren', false);
$fragment->setVar('body', $content, false);
echo $fragment->parse('core/page/section.php');
