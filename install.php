<?php

/**
 * JSON-LD Manager AddOn - Installation
 * 
 * Erstellt die notwendigen Datenbank-Tabellen und Konfigurationen
 * für das JSON-LD Schema Management System.
 * 
 * @package JsonldManager
 * @version 1.0.0
 * @author  REDAXO Developer
 */

// Datenbank-Schema erstellen
$sql = rex_sql::factory();

// Tabelle: rex_jsonld_schemas - Schema-Konfiguration pro Artikel
$sql->setQuery('
    CREATE TABLE IF NOT EXISTS `'.rex::getTable('jsonld_schemas').'` (
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

// Tabelle: rex_jsonld_url_rules - URL-Regeln für dynamische Seiten
$sql->setQuery('
    CREATE TABLE IF NOT EXISTS `'.rex::getTable('jsonld_url_rules').'` (
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

// Tabelle: rex_jsonld_localbusiness_branches - Standorteverwaltung  
$sql->setQuery('
    CREATE TABLE IF NOT EXISTS `'.rex::getTable('jsonld_localbusiness_branches').'` (
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

// Tabelle: rex_jsonld_url_profile_mappings - URL-Profile zu Schema Mappings
$sql->setQuery('
    CREATE TABLE IF NOT EXISTS `'.rex::getTable('jsonld_url_profile_mappings').'` (
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

// Standard-Konfiguration setzen
$addon = rex_addon::get('jsonld_manager');

// Grundeinstellungen
if (!$addon->hasConfig()) {
    $addon->setConfig([
        'settings' => [
            'auto_output' => true,
            'cache_enabled' => true,
            'debug_mode' => false,
            'output_position' => 'head_end',
            'validate_json' => true
        ],
        'whitelist' => [
            'get_params' => ['marke', 'kategorie', 'her', 'typ', 'model', 'size', 'color'],
            'media_types' => ['base_1200', 'base_0800', 'base_0600']
        ],
        'schemas' => [
            'default_type' => 'WebPage',
            'enabled_types' => [
                'WebPage',
                'BreadcrumbList', 
                'Organization',
                'Product',
                'FAQPage',
                'ItemList'
            ]
        ],
        'integration' => [
            'yrewrite_enabled' => rex_addon::get('yrewrite')->isAvailable(),
            'url_addon_enabled' => rex_addon::get('url')->isAvailable()
        ]
    ]);
}

// Standard Schema-Mappings für WebPage erstellen
$sql->setQuery('
    INSERT IGNORE INTO `'.rex::getTable('jsonld_schemas').'` 
    (`id`, `article_id`, `clang_id`, `schema_type`, `active`, `priority`, `config`, `created`, `modified`) 
    VALUES 
    (1, 0, 0, "default_webpage", 1, 999, ?, NOW(), NOW())
', [
    json_encode([
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
                'url' => ['source' => 'base_url']
            ]
        ]
    ])
]);

// Installation abgeschlossen
// REDAXO zeigt automatisch eine Erfolgsmeldung an
