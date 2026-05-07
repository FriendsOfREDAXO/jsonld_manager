<?php

namespace FriendsOfRedaxo\JsonLdManager;

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
     * JSON-LD für Artikel generieren (zentrale Funktion für Backend + Frontend)
     * 
     * @param int $articleId
     * @param int|array|null $branchId LocalBusiness Branch-ID(s) (optional)
     * @param bool $isDebugMode Debug-Ausgabe aktiviert
     * @return array JSON-LD Array nach Schema.org Best Practice Reihenfolge
     */
    public static function generateForArticle($articleId, $branchId = null, $isDebugMode = false, $clangId = null)
    {
        if (!$articleId) return [];
        
        $jsonLdItems = [];
        $effectiveClangId = $clangId ? (int) $clangId : \rex_clang::getCurrentId();
        $currentArticle = \rex_article::get($articleId, $effectiveClangId);
        if (!$currentArticle) return [];
        
        $addon = \rex_addon::get('jsonld_manager');
        
        // Konfigurationen laden
        $websiteConfig = \FriendsOfRedaxo\JsonLdManager\LanguageConfig::getLocalizedConfig($addon, 'website_schema', $effectiveClangId, []);
        $organizationConfig = \FriendsOfRedaxo\JsonLdManager\LanguageConfig::getLocalizedConfig($addon, 'organization_schema', $effectiveClangId, []);
        $localBusinessConfig = \FriendsOfRedaxo\JsonLdManager\LanguageConfig::getLocalizedConfig($addon, 'localbusiness_schema', $effectiveClangId, []);
        
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
                '@id' => rtrim(\rex::getServer(), '/') . '/#organization',
                'name' => $organizationConfig['name']
            ];
            
            if (!empty($organizationConfig['url'])) {
                $organizationSchema['url'] = $organizationConfig['url'];
            }
            
            if (!empty($organizationConfig['description'])) {
                $organizationSchema['description'] = $organizationConfig['description'];
            }
            
            if (!empty($organizationConfig['address'])) {
                $organizationSchema['address'] = $organizationConfig['address'];
            }
            
            if (!empty($organizationConfig['contactPoint'])) {
                $organizationSchema['contactPoint'] = $organizationConfig['contactPoint'];
            }
            
            $jsonLdItems[] = $organizationSchema;
            
            if ($isDebugMode) {
                self::debugLog('Organization Schema hinzugefügt', ['name' => $organizationConfig['name']]);
            }
        }
        
        // 2. WebSite Schema (Website der Organisation)
        if (!empty($websiteConfig['name'])) {
            $websiteSchema = [
                '@context' => 'https://schema.org',
                '@type' => 'WebSite',
                '@id' => self::getWebsiteUrl() . '/#website',
                'name' => $websiteConfig['name'],
                'url' => self::getWebsiteUrl() . '/',
                'inLanguage' => \rex_clang::get($effectiveClangId)->getCode()
            ];
            
            if (!empty($websiteConfig['description'])) {
                $websiteSchema['description'] = $websiteConfig['description'];
            }
            
            if (!empty($websiteConfig['search_action']) && !empty($websiteConfig['search_action']['target'])) {
                $searchAction = $websiteConfig['search_action'];
                $websiteSchema['potentialAction'] = [
                    '@type' => 'SearchAction',
                    'target' => trim($searchAction['target']),
                    'query-input' => 'required name=search_term_string'
                ];
            }
            
            // Verbindung zur Organization
            if (!empty($organizationConfig['name'])) {
                $websiteSchema['publisher'] = [
                    '@type' => 'Organization',
                    '@id' => rtrim(\rex::getServer(), '/') . '/#organization'
                ];
            }
            
            $jsonLdItems[] = $websiteSchema;
            
            if ($isDebugMode) {
                self::debugLog('WebSite Schema hinzugefügt', ['name' => $websiteConfig['name']]);
            }
        }
        
        // 3. LocalBusiness Schema(s) (falls konfiguriert)
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
        
        // 4. WebPage Schema (aktuelle Seite) oder Dynamisches Schema
        if ($articleConfig['webpage_enabled']) {
            // Dynamisches URL-Profil prüfen
            $dynamicUrlMapping = self::getDynamicUrlMapping($articleId, $effectiveClangId);
            
            if ($isDebugMode) {
                self::debugLog('DYNAMIC URL CHECK', [
                    'dynamic_mapping_found' => $dynamicUrlMapping !== null,
                    'current_url' => $_SERVER['REQUEST_URI'] ?? 'unknown',
                    'url_addon_available' => \rex_addon::get('url')->isAvailable()
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
                $webPageSchema = [
                    '@context' => 'https://schema.org',
                    '@type' => 'WebPage',
                    'url' => self::getArticleUrl($articleId),
                    'inLanguage' => \rex_clang::get($effectiveClangId)->getCode()
                ];
                
                // Name aus Konfiguration
                $nameField = $articleConfig['webpage_mappings']['name_field'] ?? 'yrewrite_title';
                $fallbackNameField = $articleConfig['webpage_mappings']['fallback_name'] ?? 'name';
            
            $webPageName = '';
            if ($nameField === 'yrewrite_title') {
                $webPageName = trim($currentArticle->getValue('yrewrite_title') ?: '');
            } else {
                $webPageName = trim($currentArticle->getValue($nameField) ?: '');
            }
            
            if (empty($webPageName)) {
                if ($fallbackNameField === 'name') {
                    $webPageName = $currentArticle->getName();
                } else {
                    $webPageName = $currentArticle->getValue($fallbackNameField);
                }
            }
            $webPageSchema['name'] = $webPageName ?: 'Artikel';
            
            // Description
            $descField = $articleConfig['webpage_mappings']['description_field'] ?? 'yrewrite_description';
            $fallbackDescField = $articleConfig['webpage_mappings']['fallback_description'] ?? 'art_description';
            
            $webPageDesc = '';
            if ($descField === 'yrewrite_description') {
                $webPageDesc = trim($currentArticle->getValue('yrewrite_description') ?: '');
            } else {
                $webPageDesc = trim($currentArticle->getValue($descField) ?: '');
            }
            
            if (empty($webPageDesc) && $fallbackDescField) {
                $webPageDesc = trim($currentArticle->getValue($fallbackDescField) ?: '');
            }
            
            if (!empty($webPageDesc)) {
                $webPageSchema['description'] = $webPageDesc;
            }
            
            // isPartOf (Verbindung zur WebSite)
            if ($articleConfig['include_ispartof'] && !empty($websiteConfig['name'])) {
                $webPageSchema['isPartOf'] = [
                    '@type' => 'WebSite',
                    '@id' => rtrim(\rex::getServer(), '/') . '/#website'
                ];
            }
            
            // about (Verbindung zur Organization)
            if ($articleConfig['include_about'] && !empty($organizationConfig['name'])) {
                $webPageSchema['about'] = [
                    '@type' => 'Organization',
                    '@id' => rtrim(\rex::getServer(), '/') . '/#organization'
                ];
            }
            
            // primaryImageOfPage
            if ($articleConfig['include_image']) {
                $imageField = $articleConfig['webpage_mappings']['image_field'] ?? 'yrewrite_image';
                $imageValue = $currentArticle->getValue($imageField);
                
                if (!empty($imageValue)) {
                    $imageUrl = '';
                    if (strpos($imageValue, 'http') === 0) {
                        $imageUrl = $imageValue;
                    } else {
                        $imageUrl = self::getWebsiteUrl() . '/media/' . $imageValue;
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
                        $webPageSchema['dateModified'] = date('Y-m-d', $dateValue);
                    } else {
                        $webPageSchema['dateModified'] = date('Y-m-d', strtotime($dateValue));
                    }
                }
            }
            
                $jsonLdItems[] = $webPageSchema;
                
                if ($isDebugMode) {
                    self::debugLog('WebPage Schema hinzugefügt', ['url' => $webPageSchema['url']]);
                }
            }
        }
        
        // 5. BreadcrumbList Schema (Navigation)
        $breadcrumbs = [];
        $position = 1;
        $startArticleId = \rex_article::getSiteStartArticleId();
        
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
            
            while ($currentCat) {
                if ($currentCat->getId() != $startArticleId) {
                    $catName = $currentCat->getName();
                    if ($catName !== 'Gut Schloss Sulzemoos') {
                        array_unshift($categories, $currentCat);
                    }
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
                'schemas' => array_map(function($item) { return $item['@type']; }, $jsonLdItems),
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
    private static function debugLog($message, $data = [])
    {
        return;
    }

    private static function normalizeBranchIds($branchId): array
    {
        if (is_array($branchId)) {
            $branchIds = $branchId;
        } elseif ($branchId) {
            $branchIds = [$branchId];
        } else {
            $branchIds = [];
        }

        $branchIds = array_map('intval', $branchIds);
        return array_values(array_unique(array_filter($branchIds, static function ($id) {
            return $id > 0;
        })));
    }

    private static function loadBranchConfigs(array $branchIds, int $clangId, array $baseConfig, bool $isDebugMode): array
    {
        $branchConfigs = [];

        foreach ($branchIds as $singleBranchId) {
            try {
                $sql = \rex_sql::factory();
                $sql->setQuery('SELECT branch_name, config FROM ' . \rex::getTable('jsonld_localbusiness_branches') . ' WHERE id = ? AND clang_id = ?', [$singleBranchId, $clangId]);
                if ($sql->hasNext()) {
                    $branchConfig = json_decode($sql->getValue('config'), true) ?: [];
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
            } catch (\Exception $e) {
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

    private static function buildLocalBusinessSchema(array $localBusinessConfig, $branchId = null): ?array
    {
        if (empty($localBusinessConfig['name'])) {
            return null;
        }

        $localBusinessSchema = [
            '@context' => 'https://schema.org',
            '@type' => $localBusinessConfig['business_type'] ?? 'LocalBusiness',
            '@id' => rtrim(\rex::getServer(), '/') . '/#localbusiness' . ($branchId ? '_' . $branchId : ''),
            'name' => $localBusinessConfig['name']
        ];

        if (!empty($localBusinessConfig['url'])) {
            $localBusinessSchema['url'] = $localBusinessConfig['url'];
        }
        if (!empty($localBusinessConfig['description'])) {
            $localBusinessSchema['description'] = $localBusinessConfig['description'];
        }
        if (!empty($localBusinessConfig['telephone']) && trim($localBusinessConfig['telephone']) !== '') {
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

        if (!empty($localBusinessConfig['geo']) && is_array($localBusinessConfig['geo'])) {
            $geo = $localBusinessConfig['geo'];
            $latitude = trim($geo['latitude'] ?? '');
            $longitude = trim($geo['longitude'] ?? '');
            if ($latitude !== '' && $longitude !== '' && $latitude !== '0' && $longitude !== '0') {
                $localBusinessSchema['geo'] = $geo;
            }
        }

        if (!empty($localBusinessConfig['openingHoursSpecification'])) {
            $openingHours = $localBusinessConfig['openingHoursSpecification'];
            if (is_array($openingHours) && count($openingHours) > 0) {
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

        return $localBusinessSchema;
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
     * Prüft ob für die aktuelle URL ein dynamisches URL-Profil existiert
     * @param int $articleId
     * @param int $clangId
     * @return array|null Mapping-Konfiguration oder null
     */
    private static function getDynamicUrlMapping($articleId, $clangId)
    {
        // URL AddOn verfügbar prüfen
        if (!\rex_addon::get('url')->isAvailable()) {
            return null;
        }
        
        try {
            // Direkt über URL AddOn prüfen statt über REQUEST_URI
            $urlObject = \Url\Url::resolveCurrent();
            
            if ($urlObject) {
                $profileNamespace = $urlObject->getProfile()->getNamespace();
                
                // URL-Profile mit aktiven Mappings laden
                $sql = \rex_sql::factory();
                $profiles = $sql->getArray('
                    SELECT p.*, m.schema_type, m.field_mappings, m.active 
                    FROM ' . \rex::getTable('url_generator_profile') . ' p
                    INNER JOIN ' . \rex::getTable('jsonld_url_profile_mappings') . ' m ON p.id = m.url_profile_id
                    WHERE m.active = 1 AND p.namespace = ?
                ', [$profileNamespace]);
                
                if (!empty($profiles)) {
                    $profile = $profiles[0]; // Erstes Match verwenden
                    return [
                        'profile' => $profile,
                        'schema_type' => $profile['schema_type'],
                        'field_mappings' => json_decode($profile['field_mappings'], true) ?: []
                    ];
                }
            }
        } catch (\Exception $e) {
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
    private static function matchesUrlProfile($profile, $currentUrl)
    {
        $namespace = $profile['namespace'] ?? '';
        
        if (empty($namespace)) {
            return false;
        }
        
        // Zusätzlich: URL AddOn Methode testen
        try {
            $urlObject = \Url\Url::resolveCurrent();
            if ($urlObject) {
                $urlNamespace = $urlObject->getProfile()->getNamespace();
                
                $directMatch = ($urlNamespace === $namespace);
                
                if ($directMatch) {
                    return true;
                }
            }
        } catch (\Exception $e) {
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
    private static function generateDynamicSchema($dynamicMapping, $articleId, $clangId, $isDebugMode)
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
                'inLanguage' => \rex_clang::get($clangId)->getCode()
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
            
        } catch (\Exception $e) {
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
    private static function getCurrentDataset($profile)
    {
        try {
            // Dataset-ID über URL AddOn ermitteln - GENAU WIE IM NEWS-MODUL
            $urlObject = \Url\Url::resolveCurrent();
            
            if ($urlObject) {
                $datasetId = $urlObject->getDatasetId();
                
                if ($datasetId) {
                    // Tabelle aus Profil-Daten ermitteln
                    $tableName = self::getYFormTableName($profile);
                    
                    if ($tableName) {
                        // WICHTIG: Wie im News-Modul - YForm Manager Dataset verwenden
                        $dataset = \rex_yform_manager_dataset::get($datasetId, $tableName);
                        
                        if ($dataset) {
                            // Dataset zu Array konvertieren für Kompatibilität
                            $dataArray = $dataset->getData();
                            return $dataArray;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
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
    private static function resolveMappingValue($mapping, $dataset, $yformTableName, $articleId, $clangId, $isDebugMode)
    {
        if (!is_array($mapping) || !isset($mapping['type'])) {
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
                if ($dataset && is_array($dataset) && isset($dataset[$value])) {
                    $fieldValue = $dataset[$value];
                    
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
                if (self::isWhitelistedGetParam($value)) {
                    return \rex_request($value, 'string');
                }
                
                // Fallback: Artikel-Feld
                $article = \rex_article::get($articleId, $clangId);
                if ($article) {
                    return $article->getValue($value);
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
    private static function getYFormTableName($profile)
    {
        // Aus table_parameters
        if (!empty($profile['table_parameters'])) {
            $tableParams = json_decode($profile['table_parameters'], true);
            if ($tableParams && !empty($tableParams['table_name'])) {
                return self::isValidTableName($tableParams['table_name']) ? $tableParams['table_name'] : null;
            }
        }
        
        // Fallback: table_name bereinigen
        if (!empty($profile['table_name'])) {
            $tableName = str_replace('1_xxx_', '', $profile['table_name']);
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
    private static function getDynamicArticleUrl($profile, $articleId)
    {
        try {
            // Aktuelle URL aus URL AddOn verwenden
            $urlObject = Url::resolveCurrent();
            
            if ($urlObject) {
                $requestPath = (string) \rex_server('REQUEST_URI', 'string', '/');
                $requestPath = '/' . ltrim(parse_url($requestPath, PHP_URL_PATH) ?: '', '/');
                return self::getWebsiteUrl() . $requestPath;
            }
        } catch (\Exception $e) {
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
        $whitelist = (array) \rex_addon::get('jsonld_manager')->getConfig('whitelist.get_params', []);
        return in_array($paramName, $whitelist, true);
    }

    private static function isValidTableName(?string $tableName): bool
    {
        return is_string($tableName) && preg_match('/^[A-Za-z0-9_]+$/', $tableName) === 1;
    }
}

\class_alias(__NAMESPACE__ . '\\JsonLdGenerator', 'JsonldManager\\JsonLdGenerator');
