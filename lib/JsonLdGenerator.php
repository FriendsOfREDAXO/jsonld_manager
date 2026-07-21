<?php

namespace FriendsOfRedaxo\JsonLdManager;

use rex_article;
use Exception;
use rex;
use RuntimeException;
use rex_config;
use rex_sql;
use rex_clang;
use rex_addon;
use rex_url;
use rex_yform_manager_dataset;
use Url\Url;

/**
 * JSON-LD Generator - Zentrale Klasse für Backend und Frontend
 * 
 * Einheitliche JSON-LD Generierung für Konsistenz zwischen Backend-Vorschau und Frontend-Ausgabe
 * 
 * @package JsonldManager
 * @version 1.0.2
 * @author  REDAXO Developer
 */
class JsonLdGenerator
{
    /**
     * Baut die komplette JSON-LD-Ausgabe fuer einen Artikel.
     * Diese Methode ist die gemeinsame Quelle fuer Backend-Vorschau, AJAX und Frontend.
     *
    * @param int $articleId
    * @param int|array<int, int>|string|null $branchIds Override fuer LocalBusiness-Branch-IDs; null nutzt gespeicherte Auswahl/Fallback.
     * @param bool $isDebugMode
     * @param int|null $clangId
    * @return array{disabled: bool, custom: bool, items: array<int, array<string, mixed>>, payload: array<mixed, mixed>|null, json: string, branch_ids: array<int, int>, meta: array<string, mixed>, error: string|null}
     */
    public static function getArticleOutput(int $articleId, int|array|string|null $branchIds = null, bool $isDebugMode = false, mixed $clangId = null): array
    {
        $articleId = (int) $articleId;
        $effectiveClangId = self::normalizeClangId($clangId);
        $resolvedBranchIds = self::resolveBranchIdsForArticle($articleId, $effectiveClangId, $branchIds);

        // BranchConfigs initialisieren, damit branch_names für Debug-Overlay bereitstehen
        $branchConfigs = self::loadBranchConfigs($resolvedBranchIds, $effectiveClangId, [], $isDebugMode);

        $output = [
            'disabled' => false,
            'custom' => false,
            'items' => [],
            'payload' => null,
            'json' => '',
            'branch_ids' => $resolvedBranchIds,
            'meta' => self::buildDebugMeta($articleId, $effectiveClangId, $resolvedBranchIds, [], null, 'generated'),
            'error' => null,
        ];

        if ($articleId <= 0 || !rex_article::get($articleId, $effectiveClangId)) {
            return $output;
        }

        if (self::isArticleJsonDisabled($articleId, $effectiveClangId)) {
            $output['disabled'] = true;
            return $output;
        }

        $customJson = trim(self::getCustomJson($articleId, $effectiveClangId));
        if ($customJson !== '') {
            $decoded = json_decode($customJson, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                $output['custom'] = true;
                $output['error'] = 'Custom JSON-LD ist ungueltig: ' . json_last_error_msg();
                return $output;
            }

            try {
                $payload = self::pruneEmptyValues($decoded);
                $output['custom'] = true;
                $output['payload'] = $payload;
                $output['json'] = self::encodePayload($payload);
                $output['meta'] = self::buildDebugMeta($articleId, $effectiveClangId, $resolvedBranchIds, [], $payload, 'custom');
            } catch (Exception $e) {
                $output['custom'] = true;
                $output['error'] = $e->getMessage();
            }

            return $output;
        }

        $items = self::generateForArticle($articleId, $resolvedBranchIds, $isDebugMode, $effectiveClangId);
        if (empty($items)) {
            return $output;
        }


        try {
            $payload = self::buildPayload($items);
            $output['items'] = $items;
            $output['payload'] = $payload;
            $output['json'] = self::encodePayload($payload);
            // branch_names für Debug-Tabs sammeln
            $branchNames = [];
            if (!empty($branchConfigs)) {
                foreach ($branchConfigs as $branchConfigEntry) {
                    $branchNames[$branchConfigEntry['branch_id']] = $branchConfigEntry['config']['name'] ?? '';
                }
            }
            $meta = self::buildDebugMeta($articleId, $effectiveClangId, $resolvedBranchIds, $items, $payload, 'generated');
            if (!empty($branchNames)) {
                $meta['branch_names'] = $branchNames;
            }
            $output['meta'] = $meta;
        } catch (Exception $e) {
            $output['error'] = $e->getMessage();
        }

        return $output;
    }

    /**
     * Rendert die Artikel-Ausgabe als application/ld+json Script-Tag.
     *
     * @param int $articleId
    * @param int|array<int, int>|string|null $branchIds
     * @param bool $includeDebugOverlay
     * @param int|null $clangId
     */
    public static function renderArticleScript(int $articleId, int|array|string|null $branchIds = null, bool $includeDebugOverlay = false, mixed $clangId = null): string
    {
        $output = self::getArticleOutput($articleId, $branchIds, $includeDebugOverlay, $clangId);


        if ($output['disabled'] || $output['json'] === '') {
            if ($output['error'] && rex::isDebugMode()) {
                return '<!-- JSON-LD Error: ' . htmlspecialchars($output['error'], ENT_QUOTES) . ' -->' . "\n";
            }
            return '';
        }

        $html = '<script type="application/ld+json">' . "\n" . $output['json'] . "\n" . '</script>' . "\n";

        if ($includeDebugOverlay && function_exists('jsonld_render_debug_overlay_script')) {
            $payload = is_array($output['payload']) ? $output['payload'] : [];
            $html .= jsonld_render_debug_overlay_script($payload, $output['meta']);
        }

        return $html;
    }

    /**
     * Einheitliches JSON-LD Payload bauen.
     *
     * @param array<int, array<string, mixed>> $jsonLdItems
     * @return array<mixed, mixed>
     */
    public static function buildPayload(array $jsonLdItems): array
    {
        if (count($jsonLdItems) === 1) {
            return self::pruneEmptyValues($jsonLdItems[0]);
        }

        return self::pruneEmptyValues([
            '@context' => 'https://schema.org',
            '@graph' => array_values($jsonLdItems),
        ]);
    }

    /**
     * JSON-LD Payload einheitlich formatieren.
     *
     * @param array<mixed, mixed> $payload
     */
    public static function encodePayload(array $payload): string
    {
        $json = json_encode(
            $payload,
            JSON_PRETTY_PRINT
            | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
        );
        if ($json === false || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('JSON-LD Encoding Error: ' . json_last_error_msg());
        }

        return $json;
    }

    /**
     * Entfernt rekursiv leere Werte aus JSON-LD Daten.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function pruneEmptyValues($value)
    {
        if (is_array($value)) {
            $clean = [];
            foreach ($value as $key => $item) {
                $pruned = self::pruneEmptyValues($item);
                $isEmptyString = is_string($pruned) && trim($pruned) === '';
                $isEmptyArray = is_array($pruned) && count($pruned) === 0;
                if ($pruned === null || $isEmptyString || $isEmptyArray) {
                    continue;
                }
                $clean[$key] = $pruned;
            }

            return $clean;
        }

        if (is_string($value)) {
            return trim($value);
        }

        return $value;
    }

    public static function getArticleBranchKey(int $articleId, mixed $clangId = null): string
    {
        $clangId = self::normalizeClangId($clangId);
        if (class_exists(__NAMESPACE__ . '\\DomainConfig') && DomainConfig::isMultiDomain()) {
            return 'article_branch_' . (int) $articleId . '_clang_' . $clangId . '_domain_' . DomainConfig::getActiveDomainId();
        }

        return 'article_branch_' . (int) $articleId . '_clang_' . $clangId;
    }

    public static function getDisableJsonKey(int $articleId, mixed $clangId = null): string
    {
        $clangId = self::normalizeClangId($clangId);
        if (class_exists(__NAMESPACE__ . '\\DomainConfig') && DomainConfig::isMultiDomain()) {
            return 'disable_json_' . (int) $articleId . '_clang_' . $clangId . '_domain_' . DomainConfig::getActiveDomainId();
        }

        return 'disable_json_' . (int) $articleId . '_clang_' . $clangId;
    }

    public static function getCustomJsonKey(int $articleId, mixed $clangId = null): string
    {
        $clangId = self::normalizeClangId($clangId);
        if (class_exists(__NAMESPACE__ . '\\DomainConfig') && DomainConfig::isMultiDomain()) {
            return 'custom_json_' . (int) $articleId . '_clang_' . $clangId . '_domain_' . DomainConfig::getActiveDomainId();
        }

        return 'custom_json_' . (int) $articleId . '_clang_' . $clangId;
    }

    public static function isArticleJsonDisabled(int $articleId, mixed $clangId = null): bool
    {
        return (bool) rex_config::get('jsonld_manager', self::getDisableJsonKey($articleId, $clangId), false);
    }

    public static function getCustomJson(int $articleId, mixed $clangId = null): string
    {
        return (string) rex_config::get('jsonld_manager', self::getCustomJsonKey($articleId, $clangId), '');
    }

    public static function hasCustomJson(int $articleId, mixed $clangId = null): bool
    {
        return trim(self::getCustomJson($articleId, $clangId)) !== '';
    }

    /** @return array<int, int> */
    public static function getArticleBranchIds(int $articleId, mixed $clangId = null): array
    {
        $clangId = self::normalizeClangId($clangId);
        $branchIds = self::normalizeBranchIds(rex_config::get('jsonld_manager', self::getArticleBranchKey($articleId, $clangId), []));
        if (!empty($branchIds)) {
            return $branchIds;
        }

        try {
            $sql = rex_sql::factory();
            $clangCandidates = array_values(array_unique(array_filter([
                (int) $clangId,
                (int) rex_clang::getStartId(),
            ], static function (int $id): bool {
                return $id > 0;
            })));

            foreach ($clangCandidates as $candidateClangId) {
                $sql->setQuery(
                    'SELECT config FROM ' . rex::getTable('jsonld_schemas') . ' WHERE article_id = ? AND clang_id = ? AND schema_type = "WebPage" AND active = 1 LIMIT 1',
                    [(int) $articleId, (int) $candidateClangId]
                );

                if ($sql->getRows() === 0) {
                    continue;
                }

                $config = json_decode((string) $sql->getValue('config'), true) ?: [];
                $schemaBranchIds = $config['localbusiness_branch_ids'] ?? [];
                if (empty($schemaBranchIds) && !empty($config['localbusiness_branch_id'])) {
                    $schemaBranchIds = [$config['localbusiness_branch_id']];
                }

                $schemaBranchIds = self::normalizeBranchIds($schemaBranchIds);
                if (!empty($schemaBranchIds)) {
                    return $schemaBranchIds;
                }
            }
        } catch (Exception $e) {
            // Fallback unten greift
        }

        return [];
    }

    /**
     * @param int|array<int, int|string>|string|null $branchIds
     * @return array<int, int>
     */
    public static function resolveBranchIdsForArticle(int $articleId, mixed $clangId = null, int|array|string|null $branchIds = null): array
    {
        $clangId = self::normalizeClangId($clangId);
        if ($branchIds !== null) {
            return self::normalizeBranchIds($branchIds);
        }

        $storedBranchIds = self::getArticleBranchIds((int) $articleId, $clangId);
        if (!empty($storedBranchIds)) {
            return $storedBranchIds;
        }

        return self::getDefaultBranchIds($clangId);
    }

    /**
     * JSON-LD für Artikel generieren (zentrale Funktion für Backend + Frontend)
     * 
     * @param int $articleId
     * @param int|array|null $branchId LocalBusiness Branch-ID(s) (optional)
     * @param bool $isDebugMode Debug-Ausgabe aktiviert
     * @return array JSON-LD Array nach Schema.org Best Practice Reihenfolge
     */
    /**
     * @param int|array<int, int|string>|string|null $branchId
     * @return array<int, array<string, mixed>>
     */
    public static function generateForArticle(int $articleId, int|array|string|null $branchId = null, bool $isDebugMode = false, mixed $clangId = null): array
    {
        if (!$articleId) return [];
        
        $jsonLdItems = [];
        $effectiveClangId = self::normalizeClangId($clangId);
        $currentArticle = rex_article::get($articleId, $effectiveClangId);
        if (!$currentArticle) return [];
        
        $addon = rex_addon::get('jsonld_manager');
        
        // Konfigurationen laden
        $websiteConfig = self::getGlobalSchemaConfig($addon, 'website_schema', $effectiveClangId, []);
        $organizationConfig = self::getGlobalSchemaConfig($addon, 'organization_schema', $effectiveClangId, []);
        $personConfig = self::getGlobalSchemaConfig($addon, 'person_schema', $effectiveClangId, []);
        $localBusinessConfig = LanguageConfig::getLocalizedConfig($addon, 'localbusiness_schema', $effectiveClangId, []);
        
        $branchIds = self::normalizeBranchIds($branchId);
        $branchConfigs = self::loadBranchConfigs($branchIds, $effectiveClangId, $localBusinessConfig, $isDebugMode);
        $primaryBranchId = $branchIds[0] ?? null;
        
        // Debug: LocalBusiness-Config Status
        if ($isDebugMode) {
            self::debugLog('LocalBusiness-Config Check', [
                'config_keys' => array_keys($localBusinessConfig),
                'name_value' => $localBusinessConfig['name'] ?? 'EMPTY',
                'is_empty' => empty($localBusinessConfig['name']),
                'branch_id_used' => $primaryBranchId,
                'branch_ids_used' => $branchIds
            ]);
        }
        
        $articleConfig = $addon->getConfig('article_config', [
            'webpage_enabled' => 1,
            'webpage_mappings' => [
                'name_field' => 'yrewrite_title',
                'description_field' => 'yrewrite_description',
                'image_field' => 'yrewrite_image',
                'date_field' => 'updatedate',
                'fallback_name' => 'name',
                'fallback_description' => 'art_description'
            ],
            'include_ispartof' => 1,
            'include_about' => 1,
            'include_image' => 1,
            'include_datemodified' => 1
        ]);
        
        // === SCHEMA.ORG BEST PRACTICE REIHENFOLGE ===
        
        // 1. Organization Schema (Grundentität)
        if (!empty($organizationConfig['name'])) {
            $organizationSchema = [
                '@context' => 'https://schema.org',
                '@type' => 'Organization',
                '@id' => rtrim(rex::getServer(), '/') . '/#organization',
                'name' => $organizationConfig['name']
            ];
            
            if (!empty($organizationConfig['url'])) {
                $organizationSchema['url'] = $organizationConfig['url'];
            }

            if (!empty($organizationConfig['logo'])) {
                $organizationSchema['logo'] = $organizationConfig['logo'];
            }
            
            if (!empty($organizationConfig['description'])) {
                $organizationSchema['description'] = $organizationConfig['description'];
            }

            if (!empty($organizationConfig['sameAs'])) {
                $sameAs = self::normalizeStringList($organizationConfig['sameAs']);
                if (!empty($sameAs)) {
                    $organizationSchema['sameAs'] = $sameAs;
                }
            }
            
            if (!empty($organizationConfig['address']) && is_array($organizationConfig['address'])) {
                $address = self::pruneEmptyValues(array_merge(
                    ['@type' => 'PostalAddress'],
                    $organizationConfig['address']
                ));
                if (count($address) > 1) {
                    $organizationSchema['address'] = $address;
                }
            }
            
            if (!empty($organizationConfig['contactPoint']) && is_array($organizationConfig['contactPoint'])) {
                $contactPoint = self::pruneEmptyValues(array_merge(
                    ['@type' => 'ContactPoint'],
                    $organizationConfig['contactPoint']
                ));
                if (count($contactPoint) > 1) {
                    $organizationSchema['contactPoint'] = $contactPoint;
                }
            }

            if (!empty($organizationConfig['custom_jsonld']) && is_array($organizationConfig['custom_jsonld'])) {
                $organizationSchema = CustomJsonLdHelper::mergeIntoSchema($organizationSchema, $organizationConfig['custom_jsonld']);
            }
            
            $jsonLdItems[] = $organizationSchema;
            
            if ($isDebugMode) {
                self::debugLog('Organization Schema hinzugefügt', ['name' => $organizationConfig['name']]);
            }
        }
        
        // 2. WebSite Schema (Website der Organisation)
        if (!empty($websiteConfig['name'])) {
            $websiteClang = rex_clang::get($effectiveClangId);
            $websiteSchema = [
                '@context' => 'https://schema.org',
                '@type' => 'WebSite',
                '@id' => self::getWebsiteUrl() . '/#website',
                'name' => $websiteConfig['name'],
                'url' => self::getWebsiteUrl() . '/',
                'inLanguage' => $websiteClang ? $websiteClang->getCode() : 'de'
            ];
            
            if (!empty($websiteConfig['description'])) {
                $websiteSchema['description'] = $websiteConfig['description'];
            }
            
            $searchAction = [];
            if (!empty($websiteConfig['search_action']) && is_array($websiteConfig['search_action'])) {
                $searchAction = $websiteConfig['search_action'];
            } elseif (!empty($websiteConfig['potentialAction']) && is_array($websiteConfig['potentialAction'])) {
                $searchAction = $websiteConfig['potentialAction'];
            }

            if (!empty($searchAction['target']) && (!array_key_exists('enabled', $searchAction) || !empty($searchAction['enabled']))) {
                $websiteSchema['potentialAction'] = [
                    '@type' => 'SearchAction',
                    'target' => trim($searchAction['target']),
                    'query-input' => 'required name=search_term_string'
                ];
            }

            // Verbindung zur Organization
            if (!empty($organizationConfig['name'])) {
                $websiteSchema['publisher'] = [
                    '@id' => rtrim(rex::getServer(), '/') . '/#organization'
                ];
            }

            if (!empty($websiteConfig['custom_jsonld']) && is_array($websiteConfig['custom_jsonld'])) {
                $websiteSchema = CustomJsonLdHelper::mergeIntoSchema($websiteSchema, $websiteConfig['custom_jsonld']);
            }
            
            $jsonLdItems[] = $websiteSchema;
            
            if ($isDebugMode) {
                self::debugLog('WebSite Schema hinzugefügt', ['name' => $websiteConfig['name']]);
            }
        }
        
        // 2b. Person Schema (z. B. Künstler / Betreiber der Website)
        if (!empty($personConfig['name'])) {
            $personSchema = [
                '@context' => 'https://schema.org',
                '@type' => 'Person',
                '@id' => self::getWebsiteUrl() . '/#person',
                'name' => $personConfig['name'],
            ];

            if (!empty($personConfig['jobTitle'])) {
                $personSchema['jobTitle'] = $personConfig['jobTitle'];
            }
            if (!empty($personConfig['url'])) {
                $personSchema['url'] = $personConfig['url'];
            }

            $personImage = self::normalizePersonImageUrl($personConfig['image'] ?? '');
            if ($personImage !== '') {
                $personSchema['image'] = $personImage;
            }

            if (!empty($personConfig['sameAs'])) {
                $sameAs = self::normalizeStringList($personConfig['sameAs']);
                if (!empty($sameAs)) {
                    $personSchema['sameAs'] = $sameAs;
                }
            }

            // Verbindung zur Organization (falls vorhanden)
            if (!empty($organizationConfig['name'])) {
                $personSchema['worksFor'] = [
                    '@id' => rtrim(rex::getServer(), '/') . '/#organization',
                ];
            }

            if (!empty($personConfig['custom_jsonld']) && is_array($personConfig['custom_jsonld'])) {
                $personSchema = CustomJsonLdHelper::mergeIntoSchema($personSchema, $personConfig['custom_jsonld']);
            }

            $jsonLdItems[] = $personSchema;

            if ($isDebugMode) {
                self::debugLog('Person Schema hinzugefügt', ['name' => $personConfig['name']]);
            }
        }

        // 3. WebPage Schema (aktuelle Seite) oder Dynamisches Schema
        if ($articleConfig['webpage_enabled']) {
            // Dynamisches URL-Profil prüfen
            $dynamicUrlMapping = self::getDynamicUrlMapping($articleId, $effectiveClangId);
            
            if ($isDebugMode) {
                self::debugLog('DYNAMIC URL CHECK', [
                    'dynamic_mapping_found' => $dynamicUrlMapping !== null,
                    'current_url' => $_SERVER['REQUEST_URI'] ?? 'unknown',
                    'url_addon_available' => rex_addon::get('url')->isAvailable()
                ]);
            }
            
            if ($dynamicUrlMapping) {
                if ($isDebugMode) {
                    self::debugLog('DYNAMIC SCHEMA ERSTELLEN', [
                        'schema_type' => $dynamicUrlMapping['schema_type'],
                        'mappings' => $dynamicUrlMapping['field_mappings']
                    ]);
                }
                
                // Dynamisches Schema erstellen
                $schema = self::generateDynamicSchema($dynamicUrlMapping, $articleId, $effectiveClangId, $isDebugMode);
                if ($schema) {
                    $jsonLdItems[] = $schema;
                } else {
                    if ($isDebugMode) {
                        self::debugLog('DYNAMIC SCHEMA FAILED - Fallback zu WebPage');
                    }
                    // Fallback zu WebPage wenn dynamisches Schema fehlschlägt
                }
            } else {
                // Standard WebPage Schema
                $webpageClang = rex_clang::get($effectiveClangId);
                $webPageSchema = [
                    '@context' => 'https://schema.org',
                    '@type' => 'WebPage',
                    'url' => self::getArticleUrl($articleId),
                    'inLanguage' => $webpageClang ? $webpageClang->getCode() : 'de'
                ];
                
                // Name aus Konfiguration
                $nameField = $articleConfig['webpage_mappings']['name_field'] ?? 'yrewrite_title';
                $fallbackNameField = $articleConfig['webpage_mappings']['fallback_name'] ?? 'name';
            
            $webPageName = '';
            if ($nameField === 'yrewrite_title') {
                $webPageName = trim((string) ($currentArticle->getValue('yrewrite_title') ?: ''));
            } else {
                $webPageName = trim((string) ($currentArticle->getValue($nameField) ?: ''));
            }
            
            if (empty($webPageName)) {
                if ($fallbackNameField === 'name') {
                    $webPageName = $currentArticle->getName();
                } else {
                    $webPageName = (string) $currentArticle->getValue($fallbackNameField);
                }
            }
            $webPageSchema['name'] = $webPageName ?: 'Artikel';
            
            // Description
            $descField = $articleConfig['webpage_mappings']['description_field'] ?? 'yrewrite_description';
            $fallbackDescField = $articleConfig['webpage_mappings']['fallback_description'] ?? 'art_description';
            
            $webPageDesc = '';
            if ($descField === 'yrewrite_description') {
                $webPageDesc = trim((string) ($currentArticle->getValue('yrewrite_description') ?: ''));
            } else {
                $webPageDesc = trim((string) ($currentArticle->getValue($descField) ?: ''));
            }
            
            if (empty($webPageDesc) && $fallbackDescField) {
                $webPageDesc = trim((string) ($currentArticle->getValue($fallbackDescField) ?: ''));
            }
            
            if (!empty($webPageDesc)) {
                $webPageSchema['description'] = $webPageDesc;
            }
            
            // isPartOf (Verbindung zur WebSite)
            if ($articleConfig['include_ispartof'] && !empty($websiteConfig['name'])) {
                $webPageSchema['isPartOf'] = [
                    '@type' => 'WebSite',
                    '@id' => rtrim(rex::getServer(), '/') . '/#website'
                ];
            }
            
            // about (Verbindung zur Organization)
            if ($articleConfig['include_about'] && !empty($organizationConfig['name'])) {
                $webPageSchema['about'] = [
                    '@id' => rtrim(rex::getServer(), '/') . '/#organization'
                ];
            }
            
            // primaryImageOfPage
            if ($articleConfig['include_image']) {
                $imageField = $articleConfig['webpage_mappings']['image_field'] ?? 'yrewrite_image';
                $imageValue = $currentArticle->getValue($imageField);
                
                if (!empty($imageValue)) {
                    $imageUrl = '';
                    $imageValueString = (string) $imageValue;
                    if (strpos($imageValueString, 'http') === 0) {
                        $imageUrl = $imageValueString;
                    } else {
                        $imageUrl = self::getWebsiteUrl() . '/media/' . $imageValueString;
                    }
                    
                    $webPageSchema['primaryImageOfPage'] = [
                        '@type' => 'ImageObject',
                        'url' => $imageUrl
                    ];
                }
            }
            
            // dateModified
            if ($articleConfig['include_datemodified']) {
                $dateField = $articleConfig['webpage_mappings']['date_field'] ?? 'updatedate';
                $dateValue = $currentArticle->getValue($dateField);
                
                if (!empty($dateValue) && $dateValue !== '0000-00-00 00:00:00') {
                    if (is_numeric($dateValue)) {
                        $webPageSchema['dateModified'] = date('Y-m-d', (int) $dateValue);
                    } else {
                        $timestamp = strtotime((string) $dateValue);
                        if (false !== $timestamp) {
                            $webPageSchema['dateModified'] = date('Y-m-d', $timestamp);
                        }
                    }
                }
            }
            
                $jsonLdItems[] = $webPageSchema;
                
                if ($isDebugMode) {
                    self::debugLog('WebPage Schema hinzugefügt', ['url' => $webPageSchema['url']]);
                }
            }
        }

        // 4. LocalBusiness Schema(s) (falls konfiguriert)
        if (!empty($branchConfigs)) {
            foreach ($branchConfigs as $branchConfigEntry) {
                $localBusinessSchema = self::buildLocalBusinessSchema($branchConfigEntry['config'], $branchConfigEntry['branch_id']);
                if (!$localBusinessSchema) {
                    continue;
                }
                $jsonLdItems[] = $localBusinessSchema;

                if ($isDebugMode) {
                    self::debugLog('LocalBusiness Schema hinzugefügt', [
                        'name' => $branchConfigEntry['config']['name'] ?? '',
                        'branch_id' => $branchConfigEntry['branch_id'],
                        'has_address' => !empty($localBusinessSchema['address']),
                        'has_geo' => !empty($localBusinessSchema['geo'])
                    ]);
                }
            }
        } elseif (!empty($localBusinessConfig['name'])) {
            $localBusinessSchema = self::buildLocalBusinessSchema($localBusinessConfig, $primaryBranchId);
            if ($localBusinessSchema) {
                $jsonLdItems[] = $localBusinessSchema;
            }

            if ($isDebugMode) {
                self::debugLog('LocalBusiness Schema hinzugefügt', [
                    'name' => $localBusinessConfig['name'],
                    'branch_id' => $primaryBranchId,
                    'has_address' => !empty($localBusinessSchema['address']),
                    'has_geo' => !empty($localBusinessSchema['geo'])
                ]);
            }
        } else {
            if ($isDebugMode) {
                self::debugLog('LocalBusiness Schema ÜBERSPRUNGEN', [
                    'reason' => 'name empty or missing',
                    'branch_id' => $primaryBranchId,
                    'config_keys' => $localBusinessConfig ? array_keys($localBusinessConfig) : 'config is empty'
                ]);
            }
        }
        
        // 5. BreadcrumbList Schema (Navigation)
        $breadcrumbs = [];
        $position = 1;
        $startArticleId = rex_article::getSiteStartArticleId();
        
        // Startseite immer als erstes Element
        $breadcrumbs[] = [
            '@type' => 'ListItem',
            'position' => $position++,
            'name' => 'Startseite',
            'item' => self::getArticleUrl($startArticleId)
        ];
        
        // Nur wenn NICHT auf Startseite: weitere Breadcrumb-Elemente hinzufügen
        if ($currentArticle->getId() !== $startArticleId) {
            // Kategorien
            $currentCat = $currentArticle->getCategory();
            $categories = [];

            // Vom yrewrite_scheme-AddOn ausgeschlossene Kategorien gehören nicht in
            // die Breadcrumb-Navigation (z. B. rein strukturelle Sammel-Kategorien). (#15)
            $excludedCategories = [];
            if (rex_addon::get('yrewrite_scheme')->isAvailable()) {
                $excludedCategories = (array) rex_config::get('yrewrite_scheme', 'excluded_categories', []);
            }

            while ($currentCat) {
                if ($currentCat->getId() != $startArticleId
                    && !in_array($currentCat->getId(), $excludedCategories)) {
                    array_unshift($categories, $currentCat);
                }
                $currentCat = $currentCat->getParent();
            }
            
            foreach ($categories as $cat) {
                if ($cat->getId() != $currentArticle->getId()) {
                    $breadcrumbs[] = [
                        '@type' => 'ListItem',
                        'position' => $position++,
                        'name' => $cat->getName(),
                        'item' => self::getArticleUrl($cat->getId())
                    ];
                }
            }
            
            // Aktueller Artikel (nur wenn nicht Startseite)
            $breadcrumbs[] = [
                '@type' => 'ListItem',
                'position' => $position,
                'name' => $currentArticle->getName(),
                'item' => self::getArticleUrl($currentArticle->getId())
            ];
        }
        
        // BreadcrumbList IMMER hinzufügen
        $jsonLdItems[] = [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $breadcrumbs
        ];
        
        if ($isDebugMode) {
            self::debugLog('BreadcrumbList Schema hinzugefügt', ['items' => count($breadcrumbs)]);
            self::debugLog('JSON-LD Generation abgeschlossen', [
                'total_schemas' => count($jsonLdItems),
                'schemas' => array_map(function(array $item) { return $item['@type']; }, $jsonLdItems),
                'branch_id_used' => $primaryBranchId,
                'branch_ids_used' => $branchIds
            ]);
        }
        
        return $jsonLdItems;
    }
    
    /**
     * Debug-Log ausgeben
     * 
     * @param string $message
     * @param array $data
     */
    /** @param array<string, mixed> $data */
    private static function debugLog(string $message, array $data = []): void
    {
        if (!rex::isDebugMode()) {
            return;
        }

        \rex_logger::factory()->log(\Psr\Log\LogLevel::DEBUG, $message, [
            'jsonld_data' => json_encode($data, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /** @return array<int, int> */
    public static function normalizeBranchIds(mixed $branchId): array
    {
        if (is_array($branchId)) {
            $branchIds = $branchId;
        } elseif (is_string($branchId) && $branchId !== '') {
            $decoded = json_decode($branchId, true);
            if (is_array($decoded)) {
                $branchIds = $decoded;
            } else {
                $branchIds = explode(',', $branchId);
            }
        } elseif ($branchId) {
            $branchIds = [$branchId];
        } else {
            $branchIds = [];
        }

        $branchIds = array_map('intval', $branchIds);
        return array_values(array_unique(array_filter($branchIds, static function (int $id): bool {
            return $id > 0;
        })));
    }

    /** @return array<int, string> */
    private static function normalizeStringList(mixed $value): array
    {
        if (is_array($value)) {
            $items = $value;
        } elseif (is_string($value) && $value !== '') {
            $items = preg_split('/[\r\n,]+/', $value) ?: [];
        } else {
            $items = [];
        }

        $items = array_map(static function ($item): string {
            return trim((string) $item);
        }, $items);

        return array_values(array_unique(array_filter($items, static function (string $item): bool {
            return $item !== '';
        })));
    }

    private static function normalizeClangId(mixed $clangId = null): int
    {
        $clangId = $clangId !== null ? (int) $clangId : 0;
        return $clangId > 0 ? $clangId : (int) rex_clang::getCurrentId();
    }

    /** @return array<int, int> */
    private static function getDefaultBranchIds(int $clangId): array
    {
        try {
            [$domainWhere, $domainParams] = self::getBranchDomainCondition();
            $sql = rex_sql::factory();

            $sql->setQuery(
                'SELECT id FROM ' . rex::getTable('jsonld_localbusiness_branches') . '
                 WHERE clang_id = ?' . $domainWhere . '
                 ORDER BY is_main_branch DESC, sort_order ASC, id ASC
                 LIMIT 1',
                array_merge([$clangId], $domainParams)
            );

            if ($sql->getRows() > 0) {
                return [(int) $sql->getValue('id')];
            }
        } catch (Exception $e) {
            // Keine Branch verwenden
        }

        return [];
    }

    /**
     * @param array<string, mixed> $default
     * @return array<string, mixed>
     */
    private static function getGlobalSchemaConfig(\rex_addon_interface $addon, string $baseKey, int $clangId, array $default = []): array
    {
        if (class_exists(__NAMESPACE__ . '\\DomainConfig') && DomainConfig::isMultiDomain()) {
            $configKey = $baseKey . '_domain_' . DomainConfig::getActiveDomainId() . '_clang_' . $clangId;
            $domainConfig = $addon->getConfig($configKey, null);
            if (is_array($domainConfig)) {
                return $domainConfig;
            }
        }

        return LanguageConfig::getLocalizedConfig($addon, $baseKey, $clangId, $default);
    }

    /**
     * @return array{0: string, 1: array<int, int>}
     */
    private static function getBranchDomainCondition(): array
    {
        if (
            class_exists(__NAMESPACE__ . '\\DomainConfig')
            && DomainConfig::isMultiDomain()
            && self::tableHasColumn(rex::getTable('jsonld_localbusiness_branches'), 'domain_id')
        ) {
            return [' AND (domain_id = ? OR domain_id IS NULL)', [(int) DomainConfig::getActiveDomainId()]];
        }

        return ['', []];
    }

    private static function tableHasColumn(string $table, string $column): bool
    {
        static $cache = [];
        $cacheKey = $table . '.' . $column;
        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        try {
            $sql = rex_sql::factory();
            $sql->setQuery('SHOW COLUMNS FROM ' . $table . ' LIKE ?', [$column]);
            $cache[$cacheKey] = $sql->getRows() > 0;
        } catch (Exception $e) {
            $cache[$cacheKey] = false;
        }

        return $cache[$cacheKey];
    }

    /**
     * @param array<int, int> $branchIds
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private static function buildDebugMeta(int $articleId, int $clangId, array $branchIds, array $items, ?array $payload, string $source): array
    {
        $types = self::extractTypes($items, $payload);
        $meta = [
            'article_id' => $articleId,
            'clang_id' => $clangId,
            'branch_ids' => $branchIds,
            'branch_id' => $branchIds[0] ?? null,
            'types' => array_values(array_unique($types)),
            'source' => $source,
        ];

        if (class_exists(__NAMESPACE__ . '\\DomainConfig') && DomainConfig::isMultiDomain()) {
            $activeDomain = DomainConfig::getActiveDomain();
            if ($activeDomain) {
                $meta['domain_id'] = (int) $activeDomain['id'];
                $meta['domain_name'] = (string) $activeDomain['domain'];
            }
        }

        return $meta;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<mixed, mixed>|null $payload
     * @return array<int, string>
     */
    private static function extractTypes(array $items, ?array $payload): array
    {
        $types = [];
        foreach ($items as $item) {
            if (isset($item['@type'])) {
                $types[] = (string) $item['@type'];
            }
        }

        if ($payload !== null) {
            $payloadItems = [];
            if (isset($payload['@graph']) && is_array($payload['@graph'])) {
                $payloadItems = $payload['@graph'];
            } elseif (isset($payload['@type'])) {
                $payloadItems = [$payload];
            } elseif (array_is_list($payload)) {
                $payloadItems = $payload;
            }

            foreach ($payloadItems as $item) {
                if (is_array($item) && isset($item['@type'])) {
                    $types[] = (string) $item['@type'];
                }
            }
        }

        return $types;
    }

    /**
     * @param array<int, int> $branchIds
     * @param array<string, mixed> $baseConfig
     * @return array<int, array{branch_id: int, config: array<string, mixed>}>
     */
    private static function loadBranchConfigs(array $branchIds, int $clangId, array $baseConfig, bool $isDebugMode): array
    {
        $branchConfigs = [];

        foreach ($branchIds as $singleBranchId) {
            try {
                $sql = rex_sql::factory();
                $sql->setQuery('SELECT branch_name, config FROM ' . rex::getTable('jsonld_localbusiness_branches') . ' WHERE id = ? AND clang_id = ?', [$singleBranchId, $clangId]);
                if ($sql->hasNext()) {
                    $configRaw = $sql->getValue('config');
                    $branchConfig = is_string($configRaw) ? (json_decode($configRaw, true) ?: []) : [];
                    $mergedConfig = array_merge($baseConfig, $branchConfig);
                    if (empty($mergedConfig['name'])) {
                        $mergedConfig['name'] = $sql->getValue('branch_name');
                    }
                    $branchConfigs[] = [
                        'branch_id' => $singleBranchId,
                        'config' => $mergedConfig,
                    ];

                    if ($isDebugMode) {
                        self::debugLog('Branch-Config geladen', [
                            'branch_id' => $singleBranchId,
                            'branch_name' => $sql->getValue('branch_name'),
                            'merged_keys' => array_keys($mergedConfig)
                        ]);
                    }
                }
            } catch (Exception $e) {
                if ($isDebugMode) {
                    self::debugLog('Branch-Config Fehler', [
                        'branch_id' => $singleBranchId,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        return $branchConfigs;
    }


            /**
             * @param array<string, mixed> $localBusinessConfig
             * @return array<string, mixed>|null
             */
            private static function buildLocalBusinessSchema(array $localBusinessConfig, ?int $branchId = null): ?array
            {

            if (empty($localBusinessConfig['name'])) {
                return null;
            }

            // Bugfix: @type muss aus businessType (Backend) oder business_type (Fallback) kommen
            $type = $localBusinessConfig['businessType'] ?? $localBusinessConfig['business_type'] ?? 'LocalBusiness';
            $localBusinessSchema = [
                '@context' => 'https://schema.org',
                '@type' => $type,
                '@id' => rtrim(rex::getServer(), '/') . '/#localbusiness' . ($branchId ? '_' . $branchId : ''),
                'name' => $localBusinessConfig['name']
            ];

            $imageUrls = self::normalizeLocalBusinessImageUrls($localBusinessConfig);
            if (!empty($imageUrls)) {
                $localBusinessSchema['image'] = count($imageUrls) === 1 ? $imageUrls[0] : $imageUrls;
            }

            // hasMap ergänzen, wenn gesetzt
            if (!empty($localBusinessConfig['hasMap'])) {
                $localBusinessSchema['hasMap'] = $localBusinessConfig['hasMap'];
            }

        // Preisbereich ergänzen, wenn gesetzt
        if (!empty($localBusinessConfig['priceRange'])) {
            $localBusinessSchema['priceRange'] = $localBusinessConfig['priceRange'];
        }

        if (!empty($localBusinessConfig['url'])) {
            $localBusinessSchema['url'] = $localBusinessConfig['url'];
        }
        if (!empty($localBusinessConfig['description'])) {
            $localBusinessSchema['description'] = $localBusinessConfig['description'];
        }
        if (!empty($localBusinessConfig['telephone'])) {
            $localBusinessSchema['telephone'] = $localBusinessConfig['telephone'];
        }
        if (!empty($localBusinessConfig['paymentAccepted'])) {
            $localBusinessSchema['paymentAccepted'] = $localBusinessConfig['paymentAccepted'];
        }
        if (!empty($localBusinessConfig['currenciesAccepted'])) {
            $localBusinessSchema['currenciesAccepted'] = $localBusinessConfig['currenciesAccepted'];
        }
        if (!empty($localBusinessConfig['slogan'])) {
            $localBusinessSchema['slogan'] = $localBusinessConfig['slogan'];
        }
        if (!empty($localBusinessConfig['knowsLanguage'])) {
            $localBusinessSchema['knowsLanguage'] = $localBusinessConfig['knowsLanguage'];
        }

        if (!empty($localBusinessConfig['contactPoint']) && is_array($localBusinessConfig['contactPoint'])) {
            $contactPoint = self::pruneEmptyValues(array_merge(
                ['@type' => 'ContactPoint'],
                $localBusinessConfig['contactPoint']
            ));

            if (count($contactPoint) > 1) {
                $localBusinessSchema['contactPoint'] = $contactPoint;
            }
        }

        if (!empty($localBusinessConfig['geo']) && is_array($localBusinessConfig['geo'])) {
            $geo = $localBusinessConfig['geo'];
            $latitude = trim($geo['latitude'] ?? '');
            $longitude = trim($geo['longitude'] ?? '');
            if ($latitude !== '' && $longitude !== '' && $latitude !== '0' && $longitude !== '0') {
                $localBusinessSchema['geo'] = array_merge(
                    ['@type' => 'GeoCoordinates'],
                    $geo,
                    [
                        'latitude' => $latitude,
                        'longitude' => $longitude,
                    ]
                );
            }
        }

        if (!empty($localBusinessConfig['openingHoursSpecification'])) {
            $openingHours = $localBusinessConfig['openingHoursSpecification'];
            if (is_array($openingHours)) {
                $hasValidHours = false;
                foreach ($openingHours as $hours) {
                    if (is_array($hours) && !empty($hours['dayOfWeek']) && (!empty($hours['opens']) || !empty($hours['closes']))) {
                        $hasValidHours = true;
                        break;
                    }
                }
                if ($hasValidHours) {
                    $localBusinessSchema['openingHoursSpecification'] = $openingHours;
                }
            }
        }

        $address = [];
        if (!empty($localBusinessConfig['streetAddress'])) $address['streetAddress'] = $localBusinessConfig['streetAddress'];
        if (!empty($localBusinessConfig['street'])) $address['streetAddress'] = $localBusinessConfig['street'];
        if (!empty($localBusinessConfig['addressLocality'])) $address['addressLocality'] = $localBusinessConfig['addressLocality'];
        if (!empty($localBusinessConfig['city'])) $address['addressLocality'] = $localBusinessConfig['city'];
        if (!empty($localBusinessConfig['postalCode'])) $address['postalCode'] = $localBusinessConfig['postalCode'];
        if (!empty($localBusinessConfig['postal_code'])) $address['postalCode'] = $localBusinessConfig['postal_code'];
        if (!empty($localBusinessConfig['addressCountry'])) $address['addressCountry'] = $localBusinessConfig['addressCountry'];
        if (!empty($localBusinessConfig['country'])) $address['addressCountry'] = $localBusinessConfig['country'];

        if (!empty($localBusinessConfig['address']) && is_array($localBusinessConfig['address'])) {
            $localBusinessSchema['address'] = $localBusinessConfig['address'];
        } elseif (!empty($address)) {
            $localBusinessSchema['address'] = array_merge(['@type' => 'PostalAddress'], $address);
        }

        if (!empty($localBusinessConfig['custom_jsonld']) && is_array($localBusinessConfig['custom_jsonld'])) {
            $localBusinessSchema = CustomJsonLdHelper::mergeIntoSchema($localBusinessSchema, $localBusinessConfig['custom_jsonld']);
        }

        return $localBusinessSchema;
    }

    /**
     * @param array<string, mixed> $localBusinessConfig
     * @return array<int, string>
     */
    private static function normalizeLocalBusinessImageUrls(array $localBusinessConfig): array
    {
        $rawImages = $localBusinessConfig['images'] ?? $localBusinessConfig['image'] ?? [];
        if (is_string($rawImages)) {
            $rawImages = preg_split('/[\r\n,]+/', $rawImages) ?: [];
        }

        if (!is_array($rawImages)) {
            return [];
        }

        $baseUrl = rtrim(self::getWebsiteUrl(), '/');
        $imageUrls = [];

        foreach ($rawImages as $rawImage) {
            $file = trim((string) $rawImage);
            if ($file === '') {
                continue;
            }

            if (preg_match('~^https?://~i', $file)) {
                $imageUrls[] = $file;
                continue;
            }

            $mediaPath = rex_url::media($file);

            $imageUrls[] = str_starts_with($mediaPath, 'http') ? $mediaPath : $baseUrl . $mediaPath;
        }

        return array_values(array_unique($imageUrls));
    }

    /**
     * Portrait-/Bildwert einer Person zu einer absoluten URL normalisieren.
     */
    private static function normalizePersonImageUrl(mixed $rawImage): string
    {
        $file = trim((string) $rawImage);
        if ($file === '') {
            return '';
        }

        if (preg_match('~^https?://~i', $file)) {
            return $file;
        }

        // Falls mehrere Werte gespeichert wurden, ersten verwenden
        $parts = preg_split('/[\r\n,]+/', $file) ?: [];
        $file = trim((string) ($parts[0] ?? ''));
        if ($file === '') {
            return '';
        }

        if (preg_match('~^https?://~i', $file)) {
            return $file;
        }

        // WICHTIG: rex_url::media() nicht verwenden – liefert im Backend einen
        // relativen Pfad ("../media/…") und ergibt beim Verketten mit der Base-URL
        // die fehlerhafte "…local../media/…"-Ausgabe. Media-Pfad explizit bauen.
        $baseUrl = rtrim(self::getWebsiteUrl(), '/');

        return $baseUrl . '/media/' . ltrim($file, '/');
    }

    /**
     * Domain-spezifische URL-Generierung für Multi-Domain Support
     * 
     * @return string Base-URL der aktiven Domain
     */
    private static function getWebsiteUrl(): string
    {
        return DomainConfig::getBaseUrl();
    }
    
    /**
     * Prüft ob für die aktuelle URL ein dynamisches URL-Profil existiert.
     *
     * @return array{profile: array<string, mixed>, schema_type: mixed, field_mappings: array<mixed, mixed>}|null Mapping-Konfiguration oder null
     */
    private static function getDynamicUrlMapping(int $articleId, int $clangId): ?array
    {
        // URL AddOn verfügbar prüfen
        if (!rex_addon::get('url')->isAvailable()) {
            return null;
        }
        
        try {
            // Direkt über URL AddOn prüfen statt über REQUEST_URI
            $urlObject = Url::resolveCurrent();
            
            if ($urlObject) {
                $profileObject = $urlObject->getProfile();
                if (null === $profileObject) {
                    return null;
                }

                $profileNamespace = $profileObject->getNamespace();
                
                // URL-Profile mit aktiven Mappings laden
                $sql = rex_sql::factory();
                $profiles = $sql->getArray('
                    SELECT p.*, m.schema_type, m.field_mappings, m.active 
                    FROM ' . rex::getTable('url_generator_profile') . ' p
                    INNER JOIN ' . rex::getTable('jsonld_url_profile_mappings') . ' m ON p.id = m.url_profile_id
                    WHERE m.active = 1 AND p.namespace = ?
                ', [$profileNamespace]);
                
                if (!empty($profiles)) {
                    $profile = $profiles[0]; // Erstes Match verwenden
                    $fieldMappingsRaw = $profile['field_mappings'] ?? null;
                    $fieldMappings = is_string($fieldMappingsRaw) ? (json_decode($fieldMappingsRaw, true) ?: []) : [];

                    return [
                        'profile' => $profile,
                        'schema_type' => $profile['schema_type'],
                        'field_mappings' => $fieldMappings,
                    ];
                }
            }
        } catch (Exception $e) {
            // Fehler ignorieren, fallback zu Standard-Schema
        }
        
        return null;
    }
    
    /**
     * Prüft ob eine URL zu einem URL-Profil passt
     * @param array $profile
     * @param string $currentUrl
     * @return bool
     */
    /** @param array<string, mixed> $profile */
    // @phpstan-ignore-next-line bewusst als interne Reserve-API vorhanden
    private static function matchesUrlProfile(array $profile, string $currentUrl): bool
    {
        $namespace = $profile['namespace'] ?? '';
        
        if (empty($namespace)) {
            return false;
        }
        
        // Zusätzlich: URL AddOn Methode testen
        try {
            $urlObject = Url::resolveCurrent();
            if ($urlObject) {
                $profileObject = $urlObject->getProfile();
                if (null === $profileObject) {
                    return false;
                }

                $urlNamespace = $profileObject->getNamespace();
                
                $directMatch = ($urlNamespace === $namespace);
                
                if ($directMatch) {
                    return true;
                }
            }
        } catch (Exception $e) {
            // Fehler ignorieren
        }
        
        return false;
    }
    
    /**
     * Generiert ein dynamisches Schema basierend auf URL-Profil-Mapping
     * @param array $dynamicMapping
     * @param int $articleId
     * @param int $clangId
     * @param bool $isDebugMode
     * @return array|null
     */
    /**
     * @param array<string, mixed> $dynamicMapping
     * @return array<string, mixed>|null
     */
    private static function generateDynamicSchema(array $dynamicMapping, int $articleId, int $clangId, bool $isDebugMode): ?array
    {
        try {
            $schemaType = $dynamicMapping['schema_type'] ?? 'WebPage';
            $fieldMappings = $dynamicMapping['field_mappings'] ?? [];
            $profile = $dynamicMapping['profile'];
            
            if ($isDebugMode) {
                self::debugLog('Dynamisches Schema wird generiert', [
                    'schema_type' => $schemaType,
                    'profile_namespace' => $profile['namespace'] ?? 'unknown',
                    'mappings_count' => count($fieldMappings)
                ]);
            }
            
            // Basis-Schema erstellen
            $schema = [
                '@context' => 'https://schema.org',
                '@type' => $schemaType,
                'url' => self::getDynamicArticleUrl($profile, $articleId),
                'inLanguage' => (static function () use ($clangId): string {
                    $clang = rex_clang::get($clangId);

                    return $clang ? $clang->getCode() : 'de';
                })(),
            ];
            
            // YForm-Dataset über URL AddOn ermitteln
            $dataset = self::getCurrentDataset($profile);
            $yformTableName = self::getYFormTableName($profile);
            
            if ($isDebugMode) {
                self::debugLog('Dataset ermittelt', [
                    'dataset_found' => $dataset !== null,
                    'dataset_is_array' => is_array($dataset),
                    'table_name' => $yformTableName,
                    'dataset_id' => $dataset['id'] ?? 'none'
                ]);
            }
            
            // Feld-Mappings auflösen
            foreach ($fieldMappings as $property => $mapping) {
                $resolvedValue = self::resolveMappingValue($mapping, $dataset, $yformTableName, $articleId, $clangId, $isDebugMode);
                
                if ($resolvedValue !== null && $resolvedValue !== '') {
                    $schema[$property] = $resolvedValue;
                }
            }
            
            // @ID für Verlinkungen
            if ($schemaType === 'NewsArticle') {
                $schema['@id'] = $schema['url'] . '#' . strtolower($schemaType);
            }
            
            return $schema;
            
        } catch (Exception $e) {
            if ($isDebugMode) {
                self::debugLog('Fehler beim Generieren des dynamischen Schemas', [
                    'error' => $e->getMessage(),
                    'line' => $e->getLine()
                ]);
            }
            return null;
        }
    }
    
    /**
     * Ermittelt das aktuelle YForm-Dataset über direkte SQL-Abfrage
     * @param array $profile URL-Profil-Daten  
     * @return array|null Dataset-Array oder null
     */
    /**
     * @param array<string, mixed> $profile
     * @return array<string, mixed>|null
     */
    private static function getCurrentDataset(array $profile): ?array
    {
        try {
            // Dataset-ID über URL AddOn ermitteln - GENAU WIE IM NEWS-MODUL
            $urlObject = Url::resolveCurrent();
            
            if ($urlObject) {
                $datasetId = $urlObject->getDatasetId();
                
                if ($datasetId) {
                    // Tabelle aus Profil-Daten ermitteln
                    $tableName = self::getYFormTableName($profile);
                    
                    if ($tableName) {
                        // WICHTIG: Wie im News-Modul - YForm Manager Dataset verwenden
                        $dataset = rex_yform_manager_dataset::get($datasetId, $tableName);
                        
                        if ($dataset) {
                            // Dataset zu Array konvertieren für Kompatibilität
                            $dataArray = $dataset->getData();
                            return $dataArray;
                        }
                    }
                }
            }
        } catch (Exception $e) {
            // Fehler ignorieren
        }
        
        return null;
    }
    
    /**
     * Löst einen Mapping-Wert auf
     * @param array $mapping
     * @param array|null $dataset Dataset-Array aus SQL-Abfrage
     * @param string|null $yformTableName
     * @param int $articleId
     * @param int $clangId
     * @param bool $isDebugMode
     * @return mixed
     */
    /**
     * @param array<string, mixed> $mapping
     * @param array<string, mixed>|null $dataset
     */
    private static function resolveMappingValue(array $mapping, ?array $dataset, ?string $yformTableName, int $articleId, int $clangId, bool $isDebugMode): mixed
    {
        if (!isset($mapping['type'])) {
            return null;
        }
        
        $type = $mapping['type'];
        $value = $mapping['value'] ?? '';
        
        if ($isDebugMode) {
            self::debugLog('Löse Mapping auf', [
                'type' => $type,
                'value' => $value,
                'table' => $yformTableName,
                'dataset_available' => $dataset !== null,
                'dataset_is_array' => is_array($dataset)
            ]);
        }
        
        switch ($type) {
            case 'static':
                return $value;
                
            case 'field':
                // Priorität 1: Dataset-Array (direkte SQL-Abfrage wie im Backend)
                $valueKey = (string) $value;
                if (null !== $dataset && isset($dataset[$valueKey])) {
                    $fieldValue = $dataset[$valueKey];
                    
                    if ($isDebugMode) {
                        self::debugLog('Feld-Wert über Dataset-Array aufgelöst', [
                            'field' => $value,
                            'resolved_value' => $fieldValue,
                            'method' => 'dataset[$field]'
                        ]);
                    }
                    
                    return $fieldValue;
                }
                
                // Fallback: GET-Parameter
                if (self::isWhitelistedGetParam($valueKey)) {
                    return \rex_request($valueKey, 'string');
                }
                
                // Fallback: Artikel-Feld
                $article = rex_article::get($articleId, $clangId);
                if ($article) {
                    return $article->getValue($valueKey);
                }
                
                break;
        }
        
        return null;
    }
    
    /**
     * Ermittelt YForm-Tabellenname aus URL-Profil
     * @param array $profile
     * @return string|null
     */
    /** @param array<string, mixed> $profile */
    private static function getYFormTableName(array $profile): ?string
    {
        // Aus table_parameters
        if (!empty($profile['table_parameters'])) {
            $tableParamsRaw = $profile['table_parameters'];
            $tableParams = is_string($tableParamsRaw) ? json_decode($tableParamsRaw, true) : null;
            if ($tableParams && !empty($tableParams['table_name'])) {
                $tableName = $tableParams['table_name'];
                if (is_string($tableName) && self::isValidTableName($tableName)) {
                    return $tableName;
                }

                return null;
            }
        }
        
        // Fallback: table_name bereinigen
        if (!empty($profile['table_name'])) {
            $tableName = str_replace('1_xxx_', '', (string) $profile['table_name']);
            return self::isValidTableName($tableName) ? $tableName : null;
        }
        
        return null;
    }
    
    /**
     * URL für dynamische Profile generieren
     * @param array $profile URL-Profil-Daten
     * @param int $articleId
     * @return string
     */
    /** @param array<string, mixed> $profile */
    private static function getDynamicArticleUrl(array $profile, int $articleId): string
    {
        try {
            // Aktuelle URL aus URL AddOn verwenden
            $urlObject = Url::resolveCurrent();
            
            if ($urlObject) {
                $requestPath = (string) \rex_server('REQUEST_URI', 'string', '/');
                $requestPath = '/' . ltrim(parse_url($requestPath, PHP_URL_PATH) ?: '', '/');
                return self::getWebsiteUrl() . $requestPath;
            }
        } catch (Exception $e) {
            // Fallback zu Standard-URL
        }
        
        // Fallback: Standard-Artikel-URL
        return self::getArticleUrl($articleId);
    }
    
    /**
     * URL für Artikel generieren (rex_getUrl kann je nach YRewrite-Setup relative oder absolute URLs liefern)
     */
    private static function getArticleUrl(int $articleId): string
    {
        $url = \rex_getUrl($articleId);
        
        // Wenn rex_getUrl bereits absolute URL liefert, direkt verwenden
        if (strpos($url, 'http') === 0) {
            return $url;
        }
        
        // Sonst Base-URL hinzufügen
        return self::getWebsiteUrl() . $url;
    }

    private static function isWhitelistedGetParam(string $paramName): bool
    {
        $whitelist = (array) rex_addon::get('jsonld_manager')->getConfig('whitelist.get_params', []);
        return in_array($paramName, $whitelist, true);
    }

    private static function isValidTableName(?string $tableName): bool
    {
        return is_string($tableName) && preg_match('/^[A-Za-z0-9_]+$/', $tableName) === 1;
    }
}

\class_alias(__NAMESPACE__ . '\\JsonLdGenerator', 'JsonldManager\\JsonLdGenerator');
