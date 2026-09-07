<?php
/**
 * JSON-LD Manager - Export / Import (Stufe 1: globale Angaben)
 *
 * Exportiert und importiert die projektübergreifend sinnvollen Daten:
 *  - globale Schemas (Organization / WebSite / Person / LocalBusiness) inkl.
 *    Domain-/Sprach-Varianten
 *  - LocalBusiness-Standorte
 *  - Grundeinstellungen (inkl. Template-Zuordnung)
 *  - llms.txt-Inhalte
 *  - Legacy-Meta-Rohdaten
 *
 * Artikelbezogene Zuordnungen (rex_jsonld_schemas, url_rules, url_profile_mappings,
 * pro-Artikel-Config) sind bewusst NICHT enthalten (Stufe 2).
 *
 * Grundsatz: Unterschiedliche Voraussetzungen der Zielinstallation (andere
 * Domains, Sprachen, Templates) dürfen NICHT zu Fehlern führen. Alles, was sich
 * nicht zuordnen lässt, wird übersprungen und im Import-Bericht aufgeführt.
 */

use FriendsOfRedaxo\JsonLdManager\DomainConfig;

$addon = rex_addon::get('jsonld_manager');
$func = rex_request('func', 'string', '');
$csrfToken = rex_csrf_token::factory('jsonld_manager_settings');
$csrfTokenField = $csrfToken->getHiddenField();
$message = '';

const JSONLD_EI_FORMAT = 'jsonld_manager_export';
const JSONLD_EI_FORMAT_VERSION = 1;
const JSONLD_EI_MAX_UPLOAD_BYTES = 8 * 1024 * 1024; // 8 MB

/** Basis-Keys, die exportiert/importiert werden. */
function jsonld_ei_bases(): array
{
    return [
        'organization_schema' => 'schemas',
        'website_schema' => 'schemas',
        'person_schema' => 'schemas',
        'localbusiness_schema' => 'schemas',
        'global_settings' => 'settings',
        'llms_txt_content' => 'llms',
        'llms_txt_legacy_file_content' => 'llms',
        'legacy_meta_raw' => 'legacy',
    ];
}

/** id => host (nur bei YRewrite). */
function jsonld_ei_domain_hosts(): array
{
    $map = [];
    foreach (DomainConfig::getDomains() as $row) {
        $map[(int) ($row['id'] ?? 0)] = (string) ($row['domain'] ?? '');
    }
    unset($map[0]);
    return $map;
}

/** id => code. */
function jsonld_ei_clang_codes(): array
{
    $map = [];
    foreach (rex_clang::getAll() as $clang) {
        $map[$clang->getId()] = $clang->getCode();
    }
    return $map;
}

/** id => name. */
function jsonld_ei_templates(): array
{
    $map = [];
    try {
        foreach (rex_sql::factory()->getArray('SELECT id, name FROM ' . rex::getTable('template') . ' ORDER BY name ASC') as $row) {
            $map[(int) $row['id']] = (string) $row['name'];
        }
    } catch (\Throwable $e) {
        // ohne Templates weiter
    }
    return $map;
}

function jsonld_ei_branches_table_has_domain_column(): bool
{
    try {
        return rex_sql::factory()
            ->getArray('SHOW COLUMNS FROM ' . rex::getTable('jsonld_localbusiness_branches') . ' LIKE "domain_id"') !== [];
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Zerlegt einen jsonld_manager-Config-Key in Basis + Domain-/Sprach-Bezug.
 *
 * @return array{base:string, block:string, scope:string, domain_id:?int, clang_id:?int}|null
 *               null = wird nicht exportiert
 */
function jsonld_ei_classify_key(string $key): ?array
{
    if (preg_match('/^(article_branch_|custom_json_|disable_json_)/', $key)) {
        return null;
    }
    if (str_contains($key, '_initial_template_shown')) {
        return null;
    }

    foreach (jsonld_ei_bases() as $base => $block) {
        $q = preg_quote($base, '/');
        if ($key === $base) {
            return ['base' => $base, 'block' => $block, 'scope' => 'global', 'domain_id' => null, 'clang_id' => null];
        }
        if (preg_match('/^' . $q . '_domain_(\d+)_clang_(\d+)$/', $key, $m)) {
            return ['base' => $base, 'block' => $block, 'scope' => 'domain_clang', 'domain_id' => (int) $m[1], 'clang_id' => (int) $m[2]];
        }
        if (preg_match('/^' . $q . '_domain_(\d+)$/', $key, $m)) {
            return ['base' => $base, 'block' => $block, 'scope' => 'domain', 'domain_id' => (int) $m[1], 'clang_id' => null];
        }
        if (preg_match('/^' . $q . '_clang_(\d+)$/', $key, $m)) {
            return ['base' => $base, 'block' => $block, 'scope' => 'clang', 'domain_id' => null, 'clang_id' => (int) $m[1]];
        }
    }

    return null;
}

/** Baut die komplette Export-Struktur. */
function jsonld_ei_build_export(): array
{
    $addon = rex_addon::get('jsonld_manager');
    $domainHosts = jsonld_ei_domain_hosts();
    $clangCodes = jsonld_ei_clang_codes();

    $entries = [];
    $skipped = [];
    foreach ((array) rex_config::get('jsonld_manager') as $key => $value) {
        $key = (string) $key;
        $meta = jsonld_ei_classify_key($key);
        if ($meta === null) {
            $skipped[] = $key;
            continue;
        }
        $entries[] = [
            'base' => $meta['base'],
            'block' => $meta['block'],
            'scope' => $meta['scope'],
            'domain_host' => $meta['domain_id'] !== null ? ($domainHosts[$meta['domain_id']] ?? null) : null,
            'clang_code' => $meta['clang_id'] !== null ? ($clangCodes[$meta['clang_id']] ?? null) : null,
            'source_domain_id' => $meta['domain_id'],
            'source_clang_id' => $meta['clang_id'],
            'value' => $value,
        ];
    }

    $branches = [];
    try {
        $rows = rex_sql::factory()->getArray(
            'SELECT * FROM ' . rex::getTable('jsonld_localbusiness_branches') . ' ORDER BY clang_id ASC, sort_order ASC, id ASC'
        );
        foreach ($rows as $row) {
            $domainId = array_key_exists('domain_id', $row) && $row['domain_id'] !== null ? (int) $row['domain_id'] : null;
            $clangId = (int) ($row['clang_id'] ?? 1);
            $branches[] = [
                'branch_name' => (string) ($row['branch_name'] ?? ''),
                'is_main_branch' => (int) ($row['is_main_branch'] ?? 0),
                'sort_order' => (int) ($row['sort_order'] ?? 100),
                'config' => (string) ($row['config'] ?? '{}'),
                'clang_code' => $clangCodes[$clangId] ?? null,
                'domain_host' => $domainId !== null ? ($domainHosts[$domainId] ?? null) : null,
                'source_clang_id' => $clangId,
                'source_domain_id' => $domainId,
            ];
        }
    } catch (\Throwable $e) {
        // Tabelle evtl. nicht vorhanden
    }

    return [
        'format' => JSONLD_EI_FORMAT,
        'format_version' => JSONLD_EI_FORMAT_VERSION,
        'meta' => [
            'exported_at' => date('c'),
            'addon_version' => (string) $addon->getVersion(),
            'redaxo_version' => rex::getVersion(),
            'server' => rex::getServer(),
            'multidomain' => DomainConfig::isMultiDomain(),
            'domains' => $domainHosts,
            'clangs' => $clangCodes,
            'templates' => jsonld_ei_templates(),
        ],
        'config' => $entries,
        'branches' => $branches,
        'skipped_keys' => array_values($skipped),
    ];
}

/**
 * Validiert eine hochgeladene/übergebene Export-Struktur grob.
 *
 * @return array{ok:bool, error:string, data:array}
 */
function jsonld_ei_parse_payload(string $raw): array
{
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['ok' => false, 'error' => 'Die Datei enthält kein gültiges JSON.', 'data' => []];
    }
    if (($data['format'] ?? null) !== JSONLD_EI_FORMAT) {
        return ['ok' => false, 'error' => 'Das ist keine JSON-LD-Manager-Exportdatei.', 'data' => []];
    }
    if ((int) ($data['format_version'] ?? 0) > JSONLD_EI_FORMAT_VERSION) {
        return ['ok' => false, 'error' => 'Die Datei stammt aus einer neueren Addon-Version. Bitte Addon aktualisieren.', 'data' => []];
    }
    $data['meta'] = is_array($data['meta'] ?? null) ? $data['meta'] : [];
    $data['config'] = is_array($data['config'] ?? null) ? $data['config'] : [];
    $data['branches'] = is_array($data['branches'] ?? null) ? $data['branches'] : [];

    return ['ok' => true, 'error' => '', 'data' => $data];
}

/**
 * Führt den Import aus. Wirft NICHT – jeder Einzelfehler landet als Zeile im Bericht.
 *
 * @param array $data  geparste Export-Struktur
 * @param array $opts  scopes[], domain_map(host=>id|'global'|'skip'), clang_map(code=>id|'skip'),
 *                     template_map(name=>id|'skip')
 * @return array{stats:array<string,int>, lines:array<int,array{level:string,text:string}>}
 */
function jsonld_ei_apply_import(array $data, array $opts): array
{
    $scopes = (array) ($opts['scopes'] ?? []);
    $domainMap = (array) ($opts['domain_map'] ?? []);
    $clangMap = (array) ($opts['clang_map'] ?? []);
    $templateMap = (array) ($opts['template_map'] ?? []);
    $sourceTemplates = (array) ($data['meta']['templates'] ?? []); // id => name

    $stats = ['config_written' => 0, 'config_skipped' => 0, 'branches_updated' => 0, 'branches_inserted' => 0, 'branches_deleted' => 0, 'branch_buckets' => 0];
    $lines = [];
    $add = static function (string $level, string $text) use (&$lines): void {
        $lines[] = ['level' => $level, 'text' => $text];
    };

    // ---- Config-Einträge ----
    foreach ($data['config'] as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $base = (string) ($entry['base'] ?? '');
        $block = (string) ($entry['block'] ?? '');
        $scope = (string) ($entry['scope'] ?? 'global');
        $value = $entry['value'] ?? null;

        if (!isset(jsonld_ei_bases()[$base])) {
            continue; // unbekannter Basis-Key -> ignorieren (Sicherheit)
        }
        if (!in_array($block, $scopes, true)) {
            $stats['config_skipped']++;
            continue;
        }

        // Ziel-Domain bestimmen
        $targetDomainId = null;
        if ($scope === 'domain' || $scope === 'domain_clang') {
            $host = (string) ($entry['domain_host'] ?? '');
            $mapped = $domainMap[$host] ?? 'skip';
            if ($mapped === 'skip' || $mapped === '') {
                $add('warning', 'Übersprungen: „' . $base . '“ für Domain „' . ($host !== '' ? $host : '?') . '“ (keine Zuordnung).');
                $stats['config_skipped']++;
                continue;
            }
            if ($mapped !== 'global') {
                $targetDomainId = (int) $mapped;
            }
        }

        // Ziel-Sprache bestimmen
        $targetClangId = null;
        if ($scope === 'clang' || $scope === 'domain_clang') {
            $code = (string) ($entry['clang_code'] ?? '');
            $mapped = $clangMap[$code] ?? 'skip';
            if ($mapped === 'skip' || $mapped === '') {
                $add('warning', 'Übersprungen: „' . $base . '“ für Sprache „' . ($code !== '' ? $code : '?') . '“ (keine Zuordnung).');
                $stats['config_skipped']++;
                continue;
            }
            $targetClangId = (int) $mapped;
        }

        // Ziel-Key zusammensetzen
        $targetKey = $base;
        if ($targetDomainId !== null) {
            $targetKey .= '_domain_' . $targetDomainId;
        }
        if ($targetClangId !== null) {
            $targetKey .= '_clang_' . $targetClangId;
        }

        // Template-IDs in den Grundeinstellungen umschreiben
        if ($base === 'global_settings' && is_array($value) && isset($value['templates']['enabled_ids']) && is_array($value['templates']['enabled_ids'])) {
            $remapped = [];
            $dropped = [];
            foreach ($value['templates']['enabled_ids'] as $oldId) {
                $name = (string) ($sourceTemplates[(int) $oldId] ?? '');
                $mappedTpl = $name !== '' ? ($templateMap[$name] ?? 'skip') : 'skip';
                if ($mappedTpl === 'skip' || $mappedTpl === '') {
                    $dropped[] = $name !== '' ? $name : ('ID ' . (int) $oldId);
                    continue;
                }
                $remapped[] = (int) $mappedTpl;
            }
            $value['templates']['enabled_ids'] = array_values(array_unique($remapped));
            if ($dropped !== []) {
                $add('warning', 'Grundeinstellungen: Template-Zuordnung entfernt für ' . implode(', ', array_map('strval', $dropped)) . ' (nicht gefunden).');
            }
        }

        // Grundeinstellungen: den Debug-Modus der Zielinstallation NICHT durch die
        // Datei überschreiben (sonst geht evtl. ungewollt das Frontend-Debug-Overlay live).
        if ($base === 'global_settings' && is_array($value) && isset($value['settings']) && is_array($value['settings'])) {
            $currentSettings = (array) rex_config::get('jsonld_manager', $targetKey, []);
            $currentDebug = $currentSettings['settings']['debug_mode'] ?? false;
            if (array_key_exists('debug_mode', $value['settings'])) {
                $value['settings']['debug_mode'] = $currentDebug;
                $add('warning', 'Grundeinstellungen: „Debug-Modus“ wurde nicht übernommen (Ziel-Einstellung bleibt: ' . ($currentDebug ? 'an' : 'aus') . ').');
            }
        }

        $existed = rex_config::has('jsonld_manager', $targetKey);
        try {
            rex_config::set('jsonld_manager', $targetKey, $value);
            $stats['config_written']++;
            $add('success', ($existed ? 'Überschrieben: ' : 'Neu angelegt: ') . $targetKey);
        } catch (\Throwable $e) {
            $add('error', 'Fehler bei „' . $targetKey . '“: ' . $e->getMessage());
            $stats['config_skipped']++;
        }
    }

    // ---- Standorte ----
    if (in_array('branches', $scopes, true) && $data['branches'] !== []) {
        $hasDomainColumn = jsonld_ei_branches_table_has_domain_column();
        $table = rex::getTable('jsonld_localbusiness_branches');

        // nach Ziel-Bucket gruppieren
        $buckets = []; // key => ['clang'=>int,'domain'=>?int,'rows'=>[]]
        foreach ($data['branches'] as $branch) {
            if (!is_array($branch)) {
                continue;
            }
            $code = (string) ($branch['clang_code'] ?? '');
            $mappedClang = $clangMap[$code] ?? 'skip';
            if ($mappedClang === 'skip' || $mappedClang === '') {
                $add('warning', 'Standort „' . (string) ($branch['branch_name'] ?? '?') . '“ übersprungen (Sprache „' . ($code !== '' ? $code : '?') . '“ nicht zugeordnet).');
                continue;
            }
            $targetClangId = (int) $mappedClang;

            $targetDomainId = null;
            if ($hasDomainColumn && ($branch['domain_host'] ?? null)) {
                $host = (string) $branch['domain_host'];
                $mappedDomain = $domainMap[$host] ?? 'skip';
                if ($mappedDomain === 'skip' || $mappedDomain === '') {
                    $add('warning', 'Standort „' . (string) ($branch['branch_name'] ?? '?') . '“ übersprungen (Domain „' . $host . '“ nicht zugeordnet).');
                    continue;
                }
                if ($mappedDomain !== 'global') {
                    $targetDomainId = (int) $mappedDomain;
                }
            }

            $bucketKey = $targetClangId . ':' . ($targetDomainId ?? 'null');
            if (!isset($buckets[$bucketKey])) {
                $buckets[$bucketKey] = ['clang' => $targetClangId, 'domain' => $targetDomainId, 'rows' => []];
            }
            $buckets[$bucketKey]['rows'][] = $branch;
        }

        $db = rex_sql::factory();
        try {
            $db->beginTransaction();
            foreach ($buckets as $bucket) {
                $stats['branch_buckets']++;
                $params = [$bucket['clang']];
                $where = 'clang_id = ?';
                if ($hasDomainColumn) {
                    if ($bucket['domain'] === null) {
                        $where .= ' AND domain_id IS NULL';
                    } else {
                        $where .= ' AND domain_id = ?';
                        $params[] = $bucket['domain'];
                    }
                }

                // Bestand des Buckets laden und über den Standortnamen abgleichen,
                // damit sich vorhandene IDs beim (Re-)Import nicht ändern und
                // Artikel-Zuordnungen erhalten bleiben.
                $existingRows = $db->getArray('SELECT id, branch_name FROM ' . $table . ' WHERE ' . $where, $params);
                $existingByName = [];
                foreach ($existingRows as $row) {
                    $name = (string) ($row['branch_name'] ?? '');
                    if (!isset($existingByName[$name])) {
                        $existingByName[$name] = [];
                    }
                    $existingByName[$name][] = (int) $row['id'];
                }

                $usedIds = [];
                $updated = 0;
                $inserted = 0;
                $mainIds = [];

                foreach ($bucket['rows'] as $branch) {
                    $name = (string) ($branch['branch_name'] ?? '');
                    $isMain = (int) ($branch['is_main_branch'] ?? 0);
                    $values = [
                        'branch_name' => $name,
                        'clang_id' => $bucket['clang'],
                        'is_main_branch' => $isMain,
                        'sort_order' => (int) ($branch['sort_order'] ?? 100),
                        'config' => (string) ($branch['config'] ?? '{}'),
                        'modified' => date('Y-m-d H:i:s'),
                    ];
                    if ($hasDomainColumn) {
                        $values['domain_id'] = $bucket['domain'];
                    }

                    $matchId = null;
                    if (!empty($existingByName[$name])) {
                        $matchId = (int) array_shift($existingByName[$name]);
                    }

                    if ($matchId !== null) {
                        $upd = rex_sql::factory();
                        $upd->setTable($table);
                        $upd->setWhere('id = :id', ['id' => $matchId]);
                        $upd->setValues($values);
                        $upd->update();
                        $usedIds[] = $matchId;
                        if ($isMain) {
                            $mainIds[] = $matchId;
                        }
                        $updated++;
                    } else {
                        $values['created'] = date('Y-m-d H:i:s');
                        $ins = rex_sql::factory();
                        $ins->setTable($table);
                        $ins->setValues($values);
                        $ins->insert();
                        $newId = (int) $ins->getLastId();
                        $usedIds[] = $newId;
                        if ($isMain) {
                            $mainIds[] = $newId;
                        }
                        $inserted++;
                    }
                }

                // Nicht mehr in der Datei enthaltene Standorte des Buckets entfernen.
                $obsoleteIds = [];
                foreach ($existingByName as $ids) {
                    foreach ($ids as $id) {
                        $obsoleteIds[] = (int) $id;
                    }
                }
                $deleted = 0;
                if ($obsoleteIds !== []) {
                    $del = rex_sql::factory();
                    $del->setQuery(
                        'DELETE FROM ' . $table . ' WHERE id IN (' . implode(',', array_fill(0, count($obsoleteIds), '?')) . ')',
                        $obsoleteIds
                    );
                    $deleted = count($obsoleteIds);
                }

                // Genau einen Hauptstandort im Bucket sicherstellen, wenn die Datei einen vorgibt.
                if ($mainIds !== [] && $usedIds !== []) {
                    $keepMain = (int) $mainIds[0];
                    $others = array_values(array_diff($usedIds, [$keepMain]));
                    if ($others !== []) {
                        $fix = rex_sql::factory();
                        $fix->setQuery(
                            'UPDATE ' . $table . ' SET is_main_branch = 0 WHERE id IN (' . implode(',', array_fill(0, count($others), '?')) . ')',
                            $others
                        );
                    }
                    $fixMain = rex_sql::factory();
                    $fixMain->setQuery('UPDATE ' . $table . ' SET is_main_branch = 1 WHERE id = ?', [$keepMain]);
                }

                $stats['branches_updated'] += $updated;
                $stats['branches_inserted'] += $inserted;
                $stats['branches_deleted'] += $deleted;

                $bucketLabel = 'Sprache-ID ' . $bucket['clang'] . ($hasDomainColumn ? ', Domain ' . ($bucket['domain'] ?? 'global') : '');
                $add('success', 'Standorte ' . $bucketLabel . ': ' . $updated . ' aktualisiert, ' . $inserted . ' neu, ' . $deleted . ' entfernt.');
            }
            $db->commit();
        } catch (\Throwable $e) {
            try {
                $db->rollBack();
            } catch (\Throwable $ignore) {
            }
            $add('error', 'Standort-Import abgebrochen und zurückgerollt: ' . $e->getMessage());
            $stats['branches_deleted'] = 0;
            $stats['branches_inserted'] = 0;
        }
    }

    // Frontend-Cache sicherheitshalber leeren
    try {
        if (class_exists('FriendsOfRedaxo\\JsonLdManager\\Frontend\\Renderer')) {
            \FriendsOfRedaxo\JsonLdManager\Frontend\Renderer::clearCache();
        }
        rex_cache::deleteNamespace('jsonld_manager');
    } catch (\Throwable $e) {
        // egal
    }

    return ['stats' => $stats, 'lines' => $lines];
}

// ===================================================================
// EXPORT
// ===================================================================
if ($func === 'jsonld_export') {
    if (!$csrfToken->isValid()) {
        $message .= rex_view::error('Sicherheitsprüfung fehlgeschlagen (CSRF). Bitte Seite neu laden.');
    } else {
        $json = json_encode(jsonld_ei_build_export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            $message .= rex_view::error('Export konnte nicht erzeugt werden.');
        } else {
            $host = (string) (parse_url(rex::getServer(), PHP_URL_HOST) ?: 'redaxo');
            $filename = 'jsonld-manager_' . preg_replace('/[^a-z0-9]+/i', '-', $host) . '_' . date('Ymd-His') . '.json';
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($json));
            echo $json;
            exit;
        }
    }
}

// ===================================================================
// IMPORT – Schritt 1: Datei einlesen, Zuordnungs-/Warnmaske aufbauen
// ===================================================================
$previewData = null;   // geparste Struktur für die Maske
$previewPayload = '';  // Roh-JSON (base64) für den Apply-Schritt

if ($func === 'jsonld_import_preview') {
    if (!$csrfToken->isValid()) {
        $message .= rex_view::error('Sicherheitsprüfung fehlgeschlagen (CSRF). Bitte Seite neu laden.');
    } else {
        $upload = $_FILES['import_file'] ?? null;
        if (!is_array($upload) || (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $message .= rex_view::warning('Bitte zuerst eine Exportdatei auswählen.');
        } elseif ((int) $upload['error'] !== UPLOAD_ERR_OK) {
            $message .= rex_view::error('Datei-Upload fehlgeschlagen.');
        } elseif ((int) ($upload['size'] ?? 0) > JSONLD_EI_MAX_UPLOAD_BYTES) {
            $message .= rex_view::error('Die Datei ist zu groß (max. ' . (JSONLD_EI_MAX_UPLOAD_BYTES / 1024 / 1024) . ' MB).');
        } elseif (!is_uploaded_file((string) ($upload['tmp_name'] ?? ''))) {
            $message .= rex_view::error('Ungültiger Upload.');
        } else {
            $raw = (string) file_get_contents((string) $upload['tmp_name']);
            $parsed = jsonld_ei_parse_payload($raw);
            if (!$parsed['ok']) {
                $message .= rex_view::error($parsed['error']);
            } else {
                $previewData = $parsed['data'];
                $previewPayload = base64_encode($raw);
            }
        }
    }
}

// ===================================================================
// IMPORT – Schritt 2: Anwenden
// ===================================================================
if ($func === 'jsonld_import_apply') {
    if (!$csrfToken->isValid()) {
        $message .= rex_view::error('Sicherheitsprüfung fehlgeschlagen (CSRF). Bitte Seite neu laden.');
    } elseif (rex_post('import_confirm', 'int', 0) !== 1) {
        $message .= rex_view::error('Bitte bestätigen Sie, dass vorhandene Daten ersetzt werden dürfen.');
    } else {
        $raw = base64_decode(rex_post('import_payload', 'string', ''), true);
        $parsed = is_string($raw) ? jsonld_ei_parse_payload($raw) : ['ok' => false, 'error' => 'Import-Daten fehlen.', 'data' => []];
        if (!$parsed['ok']) {
            $message .= rex_view::error($parsed['error']);
        } else {
            $opts = [
                'scopes' => array_values(array_intersect(
                    ['schemas', 'settings', 'llms', 'legacy', 'branches'],
                    (array) rex_post('import_scopes', 'array', [])
                )),
                'domain_map' => (array) rex_post('domain_map', 'array', []),
                'clang_map' => (array) rex_post('clang_map', 'array', []),
                'template_map' => (array) rex_post('template_map', 'array', []),
            ];

            if ($opts['scopes'] === []) {
                $message .= rex_view::warning('Es wurde kein Bereich zum Importieren ausgewählt.');
            } else {
                $result = jsonld_ei_apply_import($parsed['data'], $opts);
                $s = $result['stats'];
                $summary = 'Import abgeschlossen. '
                    . 'Config geschrieben: ' . $s['config_written'] . ', übersprungen: ' . $s['config_skipped'] . '. '
                    . 'Standorte: ' . $s['branches_updated'] . ' aktualisiert / ' . $s['branches_inserted'] . ' neu / ' . $s['branches_deleted'] . ' entfernt'
                    . ($s['branch_buckets'] > 0 ? ' (' . $s['branch_buckets'] . ' Sprach-/Domain-Bereiche)' : '') . '.';
                $message .= rex_view::success($summary);

                $rows = '';
                foreach ($result['lines'] as $line) {
                    $cls = ['success' => 'text-success', 'warning' => 'text-warning', 'error' => 'text-danger'][$line['level']] ?? '';
                    $rows .= '<li class="' . $cls . '">' . rex_escape($line['text']) . '</li>';
                }
                if ($rows !== '') {
                    $message .= '<div class="panel panel-default"><div class="panel-heading"><strong>Import-Bericht</strong></div>'
                        . '<div class="panel-body"><ul style="margin:0; padding-left:18px; max-height:340px; overflow:auto;">' . $rows . '</ul></div></div>';
                }
            }
        }
    }
}

// ===================================================================
// AUSGABE
// ===================================================================
echo $message;

$availableClangs = rex_clang::getAll();
$availableDomains = jsonld_ei_domain_hosts();
$availableTemplates = jsonld_ei_templates();

ob_start();
?>

<div class="row">
    <div class="col-md-6">
        <div class="panel panel-primary">
            <header class="panel-heading"><h1 class="panel-title">Export</h1></header>
            <div class="panel-body">
                <p class="help-block">
                    Exportiert globale Schemas (Organization, WebSite, Person, LocalBusiness), LocalBusiness-Standorte,
                    Grundeinstellungen und llms.txt als JSON-Datei. Artikelbezogene Zuordnungen sind nicht enthalten.
                </p>
                <form method="post" action="">
                    <input type="hidden" name="func" value="jsonld_export">
                    <?= $csrfTokenField ?>
                    <button type="submit" class="btn btn-primary"><i class="fa fa-download"></i> Export herunterladen</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="panel panel-primary">
            <header class="panel-heading"><h1 class="panel-title">Import</h1></header>
            <div class="panel-body">
                <p class="help-block">
                    Exportdatei hochladen. Im nächsten Schritt ordnen Sie Domains, Sprachen und Templates zu –
                    alles, was nicht passt, wird übersprungen und im Bericht aufgeführt.
                </p>
                <form method="post" action="" enctype="multipart/form-data">
                    <input type="hidden" name="func" value="jsonld_import_preview">
                    <?= $csrfTokenField ?>
                    <div class="form-group">
                        <input type="file" name="import_file" accept="application/json,.json" class="form-control">
                    </div>
                    <button type="submit" class="btn btn-default"><i class="fa fa-upload"></i> Datei prüfen</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php if ($previewData !== null): ?>
    <?php
    $meta = $previewData['meta'];
    $srcDomains = (array) ($meta['domains'] ?? []);       // id => host
    $srcClangs = (array) ($meta['clangs'] ?? []);         // id => code
    $srcTemplates = (array) ($meta['templates'] ?? []);   // id => name

    // In der Datei tatsächlich vorkommende Domains/Sprachen/Templates einsammeln
    $usedDomainHosts = [];
    $usedClangCodes = [];
    foreach ($previewData['config'] as $entry) {
        if (!empty($entry['domain_host'])) {
            $usedDomainHosts[(string) $entry['domain_host']] = true;
        }
        if (!empty($entry['clang_code'])) {
            $usedClangCodes[(string) $entry['clang_code']] = true;
        }
    }
    foreach ($previewData['branches'] as $branch) {
        if (!empty($branch['domain_host'])) {
            $usedDomainHosts[(string) $branch['domain_host']] = true;
        }
        if (!empty($branch['clang_code'])) {
            $usedClangCodes[(string) $branch['clang_code']] = true;
        }
    }
    $usedTemplateNames = [];
    foreach ($previewData['config'] as $entry) {
        if (($entry['base'] ?? '') === 'global_settings' && is_array($entry['value'] ?? null)) {
            foreach ((array) ($entry['value']['templates']['enabled_ids'] ?? []) as $tid) {
                $nm = (string) ($srcTemplates[(int) $tid] ?? '');
                if ($nm !== '') {
                    $usedTemplateNames[$nm] = true;
                }
            }
        }
    }

    $targetTemplatesByName = [];
    foreach ($availableTemplates as $tid => $tname) {
        $targetTemplatesByName[$tname] = $tid;
    }
    $targetClangsByCode = [];
    foreach ($availableClangs as $clang) {
        $targetClangsByCode[$clang->getCode()] = $clang->getId();
    }
    $targetDomainsByHost = [];
    foreach ($availableDomains as $did => $dhost) {
        $targetDomainsByHost[$dhost] = $did;
    }

    $blockCounts = ['schemas' => 0, 'settings' => 0, 'llms' => 0, 'legacy' => 0];
    foreach ($previewData['config'] as $entry) {
        $b = (string) ($entry['block'] ?? '');
        if (isset($blockCounts[$b])) {
            $blockCounts[$b]++;
        }
    }
    ?>
    <form method="post" action="">
        <input type="hidden" name="func" value="jsonld_import_apply">
        <?= $csrfTokenField ?>
        <input type="hidden" name="import_payload" value="<?= rex_escape($previewPayload) ?>">

        <div class="panel panel-primary">
            <header class="panel-heading"><h1 class="panel-title">Import vorbereiten</h1></header>
            <div class="panel-body">
                <p class="help-block" style="margin-bottom: 0;">
                    Quelle: <strong><?= rex_escape((string) ($meta['server'] ?? '?')) ?></strong>
                    &middot; Addon <?= rex_escape((string) ($meta['addon_version'] ?? '?')) ?>
                    &middot; REDAXO <?= rex_escape((string) ($meta['redaxo_version'] ?? '?')) ?>
                    &middot; erstellt <?= rex_escape((string) ($meta['exported_at'] ?? '?')) ?>
                </p>
            </div>
        </div>

        <div class="panel panel-primary">
            <header class="panel-heading"><h1 class="panel-title">1. Bereiche wählen</h1></header>
            <div class="panel-body">
                <div class="checkbox"><label><input type="checkbox" name="import_scopes[]" value="schemas" checked> Globale Schemas <span class="text-muted">(<?= (int) $blockCounts['schemas'] ?> Einträge)</span></label></div>
                <div class="checkbox"><label><input type="checkbox" name="import_scopes[]" value="branches" checked> LocalBusiness-Standorte <span class="text-muted">(<?= count($previewData['branches']) ?>)</span></label></div>
                <div class="checkbox"><label><input type="checkbox" name="import_scopes[]" value="settings" checked> Grundeinstellungen <span class="text-muted">(<?= (int) $blockCounts['settings'] ?>)</span></label></div>
                <div class="checkbox"><label><input type="checkbox" name="import_scopes[]" value="llms"<?= $blockCounts['llms'] > 0 ? ' checked' : '' ?>> llms.txt-Inhalte <span class="text-muted">(<?= (int) $blockCounts['llms'] ?>)</span></label></div>
                <div class="checkbox" style="margin-bottom: 0;"><label><input type="checkbox" name="import_scopes[]" value="legacy"<?= $blockCounts['legacy'] > 0 ? ' checked' : '' ?>> Legacy-Meta-Rohdaten <span class="text-muted">(<?= (int) $blockCounts['legacy'] ?>)</span></label></div>
            </div>
        </div>

        <?php if ($usedClangCodes): ?>
            <div class="panel panel-primary">
                <header class="panel-heading"><h1 class="panel-title">2. Sprachen zuordnen</h1></header>
                <div class="panel-body">
                    <table class="table table-striped" style="margin-bottom: 0;">
                        <thead><tr><th style="width: 30%;">Quelle (Code)</th><th>Ziel</th></tr></thead>
                        <tbody>
                        <?php foreach (array_keys($usedClangCodes) as $code): ?>
                            <tr>
                                <td style="vertical-align: middle;"><code><?= rex_escape($code) ?></code></td>
                                <td>
                                    <select name="clang_map[<?= rex_escape($code) ?>]" class="form-control">
                                        <?php foreach ($availableClangs as $clang): ?>
                                            <option value="<?= (int) $clang->getId() ?>"<?= isset($targetClangsByCode[$code]) && $targetClangsByCode[$code] === $clang->getId() ? ' selected' : '' ?>>
                                                <?= rex_escape($clang->getName() . ' (' . $clang->getCode() . ')') ?>
                                            </option>
                                        <?php endforeach; ?>
                                        <option value="skip"<?= isset($targetClangsByCode[$code]) ? '' : ' selected' ?>>— überspringen —</option>
                                    </select>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($usedDomainHosts): ?>
            <div class="panel panel-primary">
                <header class="panel-heading"><h1 class="panel-title">3. Domains zuordnen</h1></header>
                <div class="panel-body">
                    <?php if (!$availableDomains): ?>
                        <p class="help-block" style="margin-bottom: 0;">Diese Installation hat keine YRewrite-Domains. Domain-gebundene Angaben werden als global importiert.</p>
                        <?php foreach (array_keys($usedDomainHosts) as $host): ?>
                            <input type="hidden" name="domain_map[<?= rex_escape($host) ?>]" value="global">
                        <?php endforeach; ?>
                    <?php else: ?>
                        <table class="table table-striped" style="margin-bottom: 0;">
                            <thead><tr><th style="width: 30%;">Quelle (Host)</th><th>Ziel</th></tr></thead>
                            <tbody>
                            <?php foreach (array_keys($usedDomainHosts) as $host): ?>
                                <tr>
                                    <td style="vertical-align: middle;"><code><?= rex_escape($host) ?></code></td>
                                    <td>
                                        <select name="domain_map[<?= rex_escape($host) ?>]" class="form-control">
                                            <?php foreach ($availableDomains as $did => $dhost): ?>
                                                <option value="<?= (int) $did ?>"<?= isset($targetDomainsByHost[$host]) && $targetDomainsByHost[$host] === $did ? ' selected' : '' ?>>
                                                    <?= rex_escape($dhost) ?>
                                                </option>
                                            <?php endforeach; ?>
                                            <option value="global">Als global importieren</option>
                                            <option value="skip"<?= isset($targetDomainsByHost[$host]) ? '' : ' selected' ?>>— überspringen —</option>
                                        </select>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($usedTemplateNames): ?>
            <div class="panel panel-primary">
                <header class="panel-heading"><h1 class="panel-title">4. Templates zuordnen <small>(Grundeinstellungen)</small></h1></header>
                <div class="panel-body">
                    <table class="table table-striped" style="margin-bottom: 0;">
                        <thead><tr><th style="width: 30%;">Quelle (Name)</th><th>Ziel</th></tr></thead>
                        <tbody>
                        <?php foreach (array_keys($usedTemplateNames) as $tname): ?>
                            <tr>
                                <td style="vertical-align: middle;"><?= rex_escape($tname) ?></td>
                                <td>
                                    <select name="template_map[<?= rex_escape($tname) ?>]" class="form-control">
                                        <?php foreach ($availableTemplates as $tid => $targetName): ?>
                                            <option value="<?= (int) $tid ?>"<?= isset($targetTemplatesByName[$tname]) && $targetTemplatesByName[$tname] === $tid ? ' selected' : '' ?>>
                                                <?= rex_escape($targetName . ' (ID ' . $tid . ')') ?>
                                            </option>
                                        <?php endforeach; ?>
                                        <option value="skip"<?= isset($targetTemplatesByName[$tname]) ? '' : ' selected' ?>>— ignorieren —</option>
                                    </select>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <div class="panel panel-danger">
            <header class="panel-heading"><h1 class="panel-title"><i class="rex-icon fa-exclamation-triangle"></i> Vorhandene Daten werden ersetzt</h1></header>
            <div class="panel-body">
                <ul style="margin: 0 0 12px; padding-left: 18px;">
                    <li>Für jeden importierten Config-Eintrag wird der <strong>bestehende</strong> Wert der Zielinstallation überschrieben (Organization-, WebSite-, Person-, LocalBusiness-Schema, Grundeinstellungen, llms.txt – je nach Auswahl und Zuordnung).</li>
                    <li>Bei „LocalBusiness-Standorte“ wird jede Ziel-Sprache/-Domain, die aus der Datei Standorte erhält, mit der Datei abgeglichen: gleichnamige Standorte werden <strong>überschrieben</strong> (ID bleibt erhalten), fehlende <strong>neu angelegt</strong>, in der Datei nicht mehr enthaltene <strong>gelöscht</strong>.</li>
                    <li>Nicht zuzuordnende Domains/Sprachen/Templates werden übersprungen – die zugehörigen Zieldaten bleiben dann unverändert.</li>
                </ul>
                <div class="checkbox" style="margin: 0;">
                    <label><input type="checkbox" name="import_confirm" id="import_confirm" value="1"> <strong>Ich habe verstanden, dass die oben genannten vorhandenen Daten ersetzt werden.</strong></label>
                </div>
            </div>
        </div>

        <div class="rex-form-panel-footer" style="padding: 12px; background: rgba(0,0,0,.28); border-top: 1px solid rgba(255,255,255,.08); display: flex; justify-content: flex-end; align-items: center;">
            <button type="submit" class="btn btn-danger" id="import_apply_btn" disabled
                    onclick="return confirm('Import jetzt ausführen? Vorhandene Daten in den gewählten Bereichen werden ersetzt.');">
                Import ausführen
            </button>
        </div>
    </form>

    <script>
    (function () {
        var cb = document.getElementById('import_confirm');
        var btn = document.getElementById('import_apply_btn');
        if (cb && btn) {
            cb.addEventListener('change', function () { btn.disabled = !cb.checked; });
        }
    })();
    </script>
<?php endif; ?>

<?php
$content = ob_get_clean();

$fragment = new rex_fragment();
$fragment->setVar('title', 'Export / Import', false);
$fragment->setVar('body', $content, false);
echo $fragment->parse('core/page/section.php');
