<?php

/**
 * JSON-LD Manager AddOn - Update
 *
 * Führt notwendige Datenbank-Updates und Konfigurationsänderungen
 * zwischen verschiedenen AddOn-Versionen durch.
 *
 * Das Update ist idempotent aufgebaut: fehlende Tabellen, Spalten und
 * Indizes werden ergänzt, vorhandene Daten bleiben erhalten.
 */

$addon = rex_addon::get('jsonld_manager');
$currentVersion = (string) $addon->getVersion();
$installedVersion = (string) $addon->getConfig('version', '0.0.0');

if (version_compare($installedVersion, $currentVersion, '>=')) {
    return;
}

$sql = rex_sql::factory();
$updates = [];

$tableExists = static function (string $tableName) use ($sql): bool {
    $sql->setQuery('SHOW TABLES LIKE ?', [$tableName]);
    return $sql->getRows() > 0;
};

$columnExists = static function (string $tableName, string $columnName) use ($sql): bool {
    $sql->setQuery("SHOW COLUMNS FROM `$tableName` LIKE ?", [$columnName]);
    return $sql->getRows() > 0;
};

$indexExists = static function (string $tableName, string $indexName) use ($sql): bool {
    $sql->setQuery("SHOW INDEX FROM `$tableName` WHERE Key_name = ?", [$indexName]);
    return $sql->getRows() > 0;
};

$createTables = static function () use ($sql): void {
    $sql->setQuery('
        CREATE TABLE IF NOT EXISTS `' . rex::getTable('jsonld_schemas') . '` (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `article_id` int(10) unsigned NOT NULL,
            `clang_id` int(10) unsigned NOT NULL DEFAULT 1,
            `domain_id` int(10) unsigned NULL DEFAULT NULL,
            `schema_type` varchar(100) NOT NULL,
            `active` tinyint(1) NOT NULL DEFAULT 1,
            `priority` int(3) NOT NULL DEFAULT 100,
            `config` longtext,
            `created` datetime NOT NULL,
            `modified` datetime NOT NULL,
            PRIMARY KEY (`id`),
            KEY `article_clang_domain` (`article_id`, `clang_id`, `domain_id`),
            KEY `schema_type` (`schema_type`),
            KEY `active` (`active`),
            KEY `domain_id` (`domain_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ');

    $sql->setQuery('
        CREATE TABLE IF NOT EXISTS `' . rex::getTable('jsonld_url_rules') . '` (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `article_id` int(10) unsigned NOT NULL,
            `domain_id` int(10) unsigned NULL DEFAULT NULL,
            `name` varchar(255) NOT NULL,
            `url_pattern` text NOT NULL,
            `get_params` text,
            `schema_config` longtext,
            `active` tinyint(1) NOT NULL DEFAULT 1,
            `priority` int(3) NOT NULL DEFAULT 100,
            `created` datetime NOT NULL,
            `modified` datetime NOT NULL,
            PRIMARY KEY (`id`),
            KEY `article_domain` (`article_id`, `domain_id`),
            KEY `active` (`active`),
            KEY `domain_id` (`domain_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ');

    $sql->setQuery('
        CREATE TABLE IF NOT EXISTS `' . rex::getTable('jsonld_localbusiness_branches') . '` (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `branch_name` varchar(255) NOT NULL,
            `clang_id` int(10) unsigned NOT NULL DEFAULT 1,
            `domain_id` int(10) unsigned NULL DEFAULT NULL,
            `is_main_branch` tinyint(1) NOT NULL DEFAULT 0,
            `sort_order` int(10) NOT NULL DEFAULT 100,
            `config` longtext,
            `created` datetime NOT NULL,
            `modified` datetime NOT NULL,
            PRIMARY KEY (`id`),
            KEY `clang_domain` (`clang_id`, `domain_id`),
            KEY `is_main_branch` (`is_main_branch`),
            KEY `sort_order` (`sort_order`),
            KEY `domain_id` (`domain_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ');

    $sql->setQuery('
        CREATE TABLE IF NOT EXISTS `' . rex::getTable('jsonld_url_profile_mappings') . '` (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `url_profile_id` int(10) unsigned NOT NULL,
            `domain_id` int(10) unsigned NULL DEFAULT NULL,
            `schema_type` varchar(100) NOT NULL,
            `active` tinyint(1) NOT NULL DEFAULT 1,
            `field_mappings` longtext,
            `created` datetime NOT NULL,
            `modified` datetime NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `profile_schema_domain` (`url_profile_id`, `schema_type`, `domain_id`),
            KEY `url_profile_id` (`url_profile_id`),
            KEY `schema_type` (`schema_type`),
            KEY `active` (`active`),
            KEY `domain_id` (`domain_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ');
};

try {
    $createTables();
    $updates[] = '✅ Fehlende Tabellen geprüft/ergänzt';

    $urlRulesTable = rex::getTable('jsonld_url_rules');
    if (!$columnExists($urlRulesTable, 'name')) {
        $sql->setQuery("ALTER TABLE `$urlRulesTable` ADD COLUMN `name` varchar(255) NOT NULL AFTER `article_id`");
        $updates[] = '✅ Spalte "name" zu jsonld_url_rules hinzugefügt';
    }

    $branchesTable = rex::getTable('jsonld_localbusiness_branches');
    if (!$columnExists($branchesTable, 'clang_id')) {
        $sql->setQuery("ALTER TABLE `$branchesTable` ADD COLUMN `clang_id` int(10) unsigned NOT NULL DEFAULT 1 AFTER `branch_name`");
        $updates[] = '✅ Spalte "clang_id" zu jsonld_localbusiness_branches hinzugefügt';
    }
    if ($columnExists($branchesTable, 'active')) {
        $sql->setQuery("ALTER TABLE `$branchesTable` DROP COLUMN `active`");
        $updates[] = '✅ Veraltete Spalte "active" aus jsonld_localbusiness_branches entfernt';
    }

    $domainColumns = [
        'jsonld_schemas' => 'clang_id',
        'jsonld_url_rules' => 'article_id',
        'jsonld_localbusiness_branches' => 'clang_id',
        'jsonld_url_profile_mappings' => 'url_profile_id',
    ];

    foreach ($domainColumns as $table => $afterColumn) {
        $tableName = rex::getTable($table);
        if ($tableExists($tableName) && !$columnExists($tableName, 'domain_id')) {
            $sql->setQuery("ALTER TABLE `$tableName` ADD COLUMN `domain_id` int(10) unsigned NULL DEFAULT NULL AFTER `$afterColumn`");
            $updates[] = "✅ Spalte \"domain_id\" zu $table hinzugefügt";
        }
    }

    $indexDefinitions = [
        'jsonld_schemas' => [
            ['name' => 'schema_type', 'sql' => 'ADD KEY `schema_type` (`schema_type`)'],
            ['name' => 'active', 'sql' => 'ADD KEY `active` (`active`)'],
            ['name' => 'domain_id', 'sql' => 'ADD KEY `domain_id` (`domain_id`)'],
            ['name' => 'article_clang_domain', 'sql' => 'ADD KEY `article_clang_domain` (`article_id`, `clang_id`, `domain_id`)'],
            ['drop' => 'article_clang'],
        ],
        'jsonld_url_rules' => [
            ['name' => 'active', 'sql' => 'ADD KEY `active` (`active`)'],
            ['name' => 'domain_id', 'sql' => 'ADD KEY `domain_id` (`domain_id`)'],
            ['name' => 'article_domain', 'sql' => 'ADD KEY `article_domain` (`article_id`, `domain_id`)'],
            ['drop' => 'article_id'],
        ],
        'jsonld_localbusiness_branches' => [
            ['name' => 'is_main_branch', 'sql' => 'ADD KEY `is_main_branch` (`is_main_branch`)'],
            ['name' => 'sort_order', 'sql' => 'ADD KEY `sort_order` (`sort_order`)'],
            ['name' => 'domain_id', 'sql' => 'ADD KEY `domain_id` (`domain_id`)'],
            ['name' => 'clang_domain', 'sql' => 'ADD KEY `clang_domain` (`clang_id`, `domain_id`)'],
            ['drop' => 'clang_id'],
        ],
        'jsonld_url_profile_mappings' => [
            ['name' => 'url_profile_id', 'sql' => 'ADD KEY `url_profile_id` (`url_profile_id`)'],
            ['name' => 'schema_type', 'sql' => 'ADD KEY `schema_type` (`schema_type`)'],
            ['name' => 'active', 'sql' => 'ADD KEY `active` (`active`)'],
            ['name' => 'domain_id', 'sql' => 'ADD KEY `domain_id` (`domain_id`)'],
            ['name' => 'profile_schema_domain', 'sql' => 'ADD UNIQUE KEY `profile_schema_domain` (`url_profile_id`, `schema_type`, `domain_id`)'],
            ['drop' => 'profile_schema'],
        ],
    ];

    foreach ($indexDefinitions as $table => $definitions) {
        $tableName = rex::getTable($table);
        if (!$tableExists($tableName)) {
            continue;
        }

        foreach ($definitions as $definition) {
            if (isset($definition['drop']) && $definition['drop'] !== $definition['name'] ?? null && $indexExists($tableName, $definition['drop']) && !isset($definition['sql'])) {
                try {
                    $sql->setQuery("ALTER TABLE `$tableName` DROP KEY `{$definition['drop']}`");
                } catch (\rex_sql_exception $e) {
                    // ignore
                }
                continue;
            }

            if (isset($definition['drop']) && $indexExists($tableName, $definition['drop']) && isset($definition['name']) && !$indexExists($tableName, $definition['name'])) {
                try {
                    $sql->setQuery("ALTER TABLE `$tableName` DROP KEY `{$definition['drop']}`");
                } catch (\rex_sql_exception $e) {
                    // ignore
                }
            }

            if (isset($definition['name']) && isset($definition['sql']) && !$indexExists($tableName, $definition['name'])) {
                $sql->setQuery("ALTER TABLE `$tableName` {$definition['sql']}");
            }
        }
    }

    $updates[] = '✅ Tabellenstruktur und Indizes aktualisiert';
} catch (\rex_sql_exception $e) {
    echo rex_view::error('JSON-LD Manager Update abgebrochen: ' . htmlspecialchars($e->getMessage()));
    return;
}

$config = $addon->getConfig();

if (!isset($config['settings']) || !is_array($config['settings'])) {
    $config['settings'] = [];
}
if (!isset($config['whitelist']) || !is_array($config['whitelist'])) {
    $config['whitelist'] = [];
}
if (!isset($config['schemas']) || !is_array($config['schemas'])) {
    $config['schemas'] = [];
}
if (!isset($config['integration']) || !is_array($config['integration'])) {
    $config['integration'] = [];
}

$config['settings'] = array_replace([
    'auto_output' => true,
    'cache_enabled' => true,
    'debug_mode' => false,
    'output_position' => 'head_end',
    'validate_json' => true,
], $config['settings']);

$config['whitelist'] = array_replace([
    'get_params' => ['marke', 'kategorie', 'her', 'typ', 'model', 'size', 'color'],
    'media_types' => ['base_1200', 'base_0800', 'base_0600'],
], $config['whitelist']);

$config['schemas'] = array_replace([
    'default_type' => 'WebPage',
    'enabled_types' => ['WebPage', 'BreadcrumbList', 'Organization', 'Product', 'FAQPage', 'ItemList'],
], $config['schemas']);

$config['integration'] = array_replace([
    'yrewrite_enabled' => rex_addon::get('yrewrite')->isAvailable(),
    'url_addon_enabled' => rex_addon::get('url')->isAvailable(),
], $config['integration']);

$startClangId = rex_clang::getStartId();
foreach (['organization_schema', 'website_schema', 'localbusiness_schema'] as $baseKey) {
    $localizedKey = $baseKey . '_clang_' . $startClangId;
    if (!isset($config[$localizedKey]) && isset($config[$baseKey]) && is_array($config[$baseKey])) {
        $config[$localizedKey] = $config[$baseKey];
        $updates[] = '✅ Bestehende Konfiguration nach Sprach-Keys migriert: ' . $baseKey;
    }
}

$defaultSchemaConfig = [
    'name' => 'Default WebPage Schema',
    'description' => 'Standard Schema für alle Seiten ohne spezifische Zuordnung',
    'auto_apply' => true,
    'mappings' => [
        '@context' => 'https://schema.org',
        '@type' => 'WebPage',
        'name' => ['source' => 'article_name', 'fallback' => 'seo_title'],
        'description' => ['source' => 'meta_description', 'fallback' => 'article_teaser'],
        'url' => ['source' => 'canonical_url'],
        'inLanguage' => ['source' => 'clang_code'],
        'isPartOf' => [
            '@type' => 'WebSite',
            'name' => ['source' => 'sitename'],
            'url' => ['source' => 'base_url'],
        ],
    ],
];

try {
    $sql->setQuery(
        'INSERT IGNORE INTO `' . rex::getTable('jsonld_schemas') . '` (`id`, `article_id`, `clang_id`, `schema_type`, `active`, `priority`, `config`, `created`, `modified`) VALUES (1, 0, 0, "default_webpage", 1, 999, ?, NOW(), NOW())',
        [json_encode($defaultSchemaConfig)]
    );
} catch (\rex_sql_exception $e) {
    // ignore, table is already present and data should stay intact
}

$config['version'] = $currentVersion;
$addon->setConfig($config);

rex_cache::deleteNamespace('jsonld_manager');

echo rex_view::success(
    '<h4>JSON-LD Manager Update erfolgreich</h4>'
    . '<p><strong>Version ' . htmlspecialchars($installedVersion) . ' → ' . htmlspecialchars($currentVersion) . '</strong></p>'
    . '<ul><li>' . implode('</li><li>', array_map('htmlspecialchars', array_unique($updates))) . '</li></ul>'
    . '<p>Bestehende Daten und Konfigurationen wurden beibehalten.</p>'
);
