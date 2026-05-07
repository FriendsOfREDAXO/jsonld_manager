<?php

namespace FriendsOfRedaxo\JsonLdManager\Frontend;

/**
 * JSON-LD Renderer - Frontend API
 * 
 * Hauptklasse für die Frontend-Integration des JSON-LD Managers.
 * Stellt die Template-API zur Verfügung und generiert JSON-LD Output.
 * 
 * @package JsonldManager\Frontend
 * @version 1.0.0
 * @author  REDAXO Developer
 */
class Renderer
{
    /**
     * @var array Cache für generierte JSON-LD Daten
     */
    private static $cache = [];
    
    /**
     * @var array Debug-Informationen sammeln
     */
    private static $debugInfo = [];
    
    /**
     * JSON-LD für aktuelle Seite ausgeben
     * 
     * @param string|null $schemaType Spezifischer Schema-Type (optional)
     * @param array $additionalData Zusätzliche Daten für Mapping (optional)
     * @return string JSON-LD als formatierter String
     */
    public static function render($schemaType = null, $additionalData = [])
    {
        try {
            $article = \rex_article::getCurrent();
            if (!$article) {
                return '';
            }
            
            $addon = \rex_addon::get('jsonld_manager');
            
            // Debug-Info sammeln
            $isDebugMode = self::getSetting($addon, 'debug_mode', false);
            if ($isDebugMode) {
                self::addDebugInfo('render_start', 'JSON-LD Rendering gestartet', [
                    'article_id' => $article->getId(),
                    'clang_id' => $article->getClangId(),
                    'schema_type' => $schemaType,
                    'cache_key' => 'pending',
                    'additional_data' => $additionalData
                ]);
            }

            // Branch-ID für diesen Artikel laden
            $useBranchIds = self::resolveBranchIdsForArticle($article->getId(), (int) $article->getClangId());
            $cacheKey = md5($article->getId() . '_' . $article->getClangId() . '_' . ($schemaType ?: 'auto') . '_' . serialize($useBranchIds) . '_' . serialize($additionalData));

            // Cache-Check
            if (isset(self::$cache[$cacheKey])) {
                if ($isDebugMode) {
                    self::addDebugInfo('cache_hit', 'Cache-Treffer', [
                        'cache_key' => $cacheKey,
                        'branch_ids' => $useBranchIds
                    ]);
                }
                return self::$cache[$cacheKey];
            }
            
            // Einheitliche Backend/Frontend-Logik: zentralen Generator verwenden
            $jsonLdItems = \FriendsOfRedaxo\JsonLdManager\JsonLdGenerator::generateForArticle($article->getId(), $useBranchIds, $isDebugMode, (int) $article->getClangId());
            
            if (empty($jsonLdItems)) {
                return '';
            }
            
            // JSON-LD formatieren
            $output = '';
            $payload = self::normalizeOutputPayload($jsonLdItems);
            $output = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            
            // Validierung
            if (self::getSetting($addon, 'validate_json', true)) {
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \Exception('JSON-LD Validierungsfehler: ' . json_last_error_msg());
                }
            }
            
            // Cache speichern
            if (self::getSetting($addon, 'cache_enabled', true)) {
                self::$cache[$cacheKey] = $output;
                if (class_exists('\rex_cache')) {
                    \rex_cache::set('jsonld_manager', 'article_'.$article->getId().'_'.$article->getClangId(), $output);
                }
            }
            
            // Debug-Info für erfolgreiche Generierung
            if ($isDebugMode) {
                self::addDebugInfo('render_success', 'JSON-LD erfolgreich generiert', [
                    'items_count' => count($jsonLdItems ?? []),
                    'output_length' => strlen($output),
                    'cached' => self::getSetting($addon, 'cache_enabled', true),
                    'branch_ids' => $useBranchIds
                ]);
                
                self::outputConsoleDebug('JSON-LD erfolgreich generiert', [
                    'JSON-LD Items' => count($jsonLdItems ?? []),
                    'Output-Länge' => strlen($output) . ' Zeichen',
                    'Branch-IDs' => implode(', ', $useBranchIds),
                    'Gecacht' => self::getSetting($addon, 'cache_enabled', true) ? 'Ja' : 'Nein'
                ]);
                
                // JSON-LD Content in Console ausgeben (verkürzt)
                if (!empty($output)) {
                    $shortOutput = strlen($output) > 300 ? substr($output, 0, 300) . '...' : $output;
                    self::outputConsoleDebug('Generated JSON-LD Preview', ['Content' => $shortOutput]);
                }
            }
            
            return $output;
            
        } catch (\Exception $e) {
            // Debug-Info für Fehler
            if (self::getSetting(\rex_addon::get('jsonld_manager'), 'debug_mode', false)) {
                self::addDebugInfo('render_error', 'JSON-LD Fehler', [
                    'error_message' => $e->getMessage(),
                    'error_file' => $e->getFile(),
                    'error_line' => $e->getLine()
                ]);
                return '<!-- JSON-LD Error: ' . htmlspecialchars($e->getMessage()) . ' -->';
            }
            return '';
        }
    }
    
    /**
     * JSON-LD für aktuelle Seite automatisch generieren (für Extension Point)
     * 
     * @return string JSON-LD Output
     */
    public static function getCurrentPageJsonLd()
    {
        // URL-Regeln prüfen
        $urlRuleData = \FriendsOfRedaxo\JsonLdManager\Url\RuleEngine::matchCurrentUrl();
        
        if ($urlRuleData) {
            return self::render($urlRuleData['schema_type'], $urlRuleData['data']);
        }
        
        // Standard-Rendering
        return self::render();
    }
    
    /**
     * Schema-Konfigurationen für Artikel ermitteln
     * 
     * @param int $articleId Artikel-ID
     * @param int $clangId Sprach-ID
     * @param string|null $schemaType Gewünschter Schema-Type
     * @return array Array von Schema-Konfigurationen
     */
    private static function getArticleSchemas($articleId, $clangId, $schemaType = null)
    {
        $isDebugMode = self::getSetting(\rex_addon::get('jsonld_manager'), 'debug_mode', false);
        if ($isDebugMode) {
            self::addDebugInfo('schema_load', 'Lade Schema-Konfigurationen', [
                'article_id' => $articleId,
                'clang_id' => $clangId,
                'requested_schema_type' => $schemaType
            ]);
        }
        
        $sql = \rex_sql::factory();
        
        $where = 'article_id = ? AND clang_id = ? AND active = 1';
        $params = [$articleId, $clangId];
        
        if ($schemaType) {
            $where .= ' AND schema_type = ?';
            $params[] = $schemaType;
        }
        
        $sql->setQuery('
            SELECT * FROM '.\rex::getTable('jsonld_schemas').'
            WHERE '.$where.'
            ORDER BY priority ASC
        ', $params);
        
        $schemas = [];
        while ($sql->hasNext()) {
            $config = json_decode($sql->getValue('config'), true) ?: [];
            $schemas[] = [
                'id' => $sql->getValue('id'),
                'type' => $sql->getValue('schema_type'),
                'config' => $config,
                'priority' => $sql->getValue('priority')
            ];
            $sql->next();
        }
        
        if ($isDebugMode) {
            self::addDebugInfo('schema_load', 'Schema-Konfigurationen geladen', [
                'found_schemas' => count($schemas),
                'schema_types' => array_column($schemas, 'type')
            ]);
        }
        
        return $schemas;
    }
    
    /**
     * Aktive LocalBusiness Branch für Artikel laden
     * 
     * @param int $articleId Artikel-ID
     * @param int $clangId Sprach-ID
     * @return array|null LocalBusiness Schema oder null
     */
    private static function getActiveLocalBusinessBranch($articleId, $clangId)
    {
        // Artikel-Schema laden um LocalBusiness Branch ID zu finden
        $sql = \rex_sql::factory();
        $sql->setQuery('
            SELECT config FROM '.\rex::getTable('jsonld_schemas').'
            WHERE article_id = ? AND clang_id = ? AND schema_type = "WebPage" AND active = 1
            LIMIT 1
        ', [$articleId, $clangId]);
        
        if ($sql->getRows() === 0) {
            return null;
        }
        
        $config = json_decode($sql->getValue('config'), true) ?: [];
        $branchIds = $config['localbusiness_branch_ids'] ?? [];
        if (!is_array($branchIds)) {
            $branchIds = $branchIds ? [(int) $branchIds] : [];
        }
        if (empty($branchIds) && !empty($config['localbusiness_branch_id'])) {
            $branchIds = [(int) $config['localbusiness_branch_id']];
        }
        $branchId = (int) ($branchIds[0] ?? 0);
        
        if ($branchId <= 0) {
            return null; // Keine LocalBusiness Zuordnung
        }
        
        // LocalBusiness Branch laden und Status prüfen
        $branchSql = \rex_sql::factory();
        $branchSql->setQuery('
            SELECT branch_name, config FROM '.\rex::getTable('jsonld_localbusiness_branches').'
            WHERE id = ? AND clang_id = ?
        ', [$branchId, (int) $clangId]);
        
        if ($branchSql->getRows() === 0) {
            // Branch nicht gefunden
            $isDebugMode = self::getSetting(\rex_addon::get('jsonld_manager'), 'debug_mode', false);
            if ($isDebugMode) {
                self::addDebugInfo('localbusiness_missing', 'LocalBusiness Branch nicht gefunden', [
                    'article_id' => $articleId,
                    'branch_id' => $branchId
                ]);
                self::outputConsoleDebug('LocalBusiness Branch nicht gefunden', [
                    'Artikel-ID' => $articleId,
                    'Branch-ID' => $branchId
                ]);
            }
            return null;
        }
        
        $branchConfig = json_decode($branchSql->getValue('config'), true) ?: [];
        
        // Prüfen ob LocalBusiness enabled ist
        if (empty($branchConfig['enabled']) || !$branchConfig['enabled']) {
            return null;
        }
        
        // LocalBusiness Schema aus Branch-Config generieren
        return [
            'type' => 'LocalBusiness',
            'config' => [
                'branch_name' => $branchSql->getValue('branch_name'),
                'mappings' => self::buildLocalBusinessMappings($branchConfig)
            ],
            'priority' => 50 // Höhere Priorität als WebPage
        ];
    }
    
    /**
     * LocalBusiness Mappings aus Branch-Konfiguration erstellen
     * 
     * @param array $branchConfig Branch-Konfiguration
     * LocalBusiness Mappings aus Branch-Konfiguration erstellen
     * 
     * @param array $branchConfig Branch-Konfiguration
     * @return array Schema.org LocalBusiness Mappings
     */
    private static function buildLocalBusinessMappings($branchConfig)
    {
        $mappings = [
            '@context' => 'https://schema.org',
            '@type' => $branchConfig['businessType'] ?: 'LocalBusiness'
        ];
        
        if (!empty($branchConfig['name'])) {
            $mappings['name'] = $branchConfig['name'];
        }
        
        if (!empty($branchConfig['slogan'])) {
            $mappings['slogan'] = $branchConfig['slogan'];
        }
        
        if (!empty($branchConfig['telephone'])) {
            $mappings['telephone'] = $branchConfig['telephone'];
        }
        
        if (!empty($branchConfig['priceRange'])) {
            $mappings['priceRange'] = $branchConfig['priceRange'];
        }
        
        // Address
        $address = [];
        if (!empty($branchConfig['streetAddress'])) {
            $address['streetAddress'] = $branchConfig['streetAddress'];
        }
        if (!empty($branchConfig['addressLocality'])) {
            $address['addressLocality'] = $branchConfig['addressLocality'];
        }
        if (!empty($branchConfig['postalCode'])) {
            $address['postalCode'] = $branchConfig['postalCode'];
        }
        if (!empty($branchConfig['addressCountry'])) {
            $address['addressCountry'] = $branchConfig['addressCountry'];
        }
        
        if (!empty($address)) {
            $address['@type'] = 'PostalAddress';
            $mappings['address'] = $address;
        }
        
        // Geo-Koordinaten
        if (!empty($branchConfig['geo']['latitude']) && !empty($branchConfig['geo']['longitude'])) {
            $mappings['geo'] = [
                '@type' => 'GeoCoordinates',
                'latitude' => $branchConfig['geo']['latitude'],
                'longitude' => $branchConfig['geo']['longitude']
            ];
        }
        
        // Öffnungszeiten
        if (!empty($branchConfig['openingHoursSpecification']) && is_array($branchConfig['openingHoursSpecification'])) {
            $mappings['openingHoursSpecification'] = $branchConfig['openingHoursSpecification'];
        }
        
        // Weitere Properties
        if (!empty($branchConfig['images'])) {
            $images = array_filter(array_map('trim', explode(',', $branchConfig['images'])));
            if (!empty($images)) {
                $mappings['image'] = count($images) === 1 ? $images[0] : $images;
            }
        }
        
        if (!empty($branchConfig['knowsLanguage']) && is_array($branchConfig['knowsLanguage'])) {
            $mappings['knowsLanguage'] = $branchConfig['knowsLanguage'];
        }
        
        if (!empty($branchConfig['paymentAccepted'])) {
            $mappings['paymentAccepted'] = $branchConfig['paymentAccepted'];
        }
        
        if (!empty($branchConfig['currenciesAccepted'])) {
            $mappings['currenciesAccepted'] = $branchConfig['currenciesAccepted'];
        }
        
        if (!empty($branchConfig['areaServed'])) {
            $mappings['areaServed'] = $branchConfig['areaServed'];
        }
        
        if (!empty($branchConfig['hasMap'])) {
            $mappings['hasMap'] = $branchConfig['hasMap'];
        }
        
        return $mappings;
    }
    
    /**
     * Standard WebPage Schema generieren
     * 
     * @param rex_article $article REDAXO Artikel
     * @return array Schema-Konfiguration
     */
    private static function getDefaultWebPageSchema($article)
    {
        return [
            'type' => 'WebPage',
            'config' => [
                'mappings' => [
                    '@context' => 'https://schema.org',
                    '@type' => 'WebPage',
                    'name' => ['source' => 'article_name'],
                    'description' => ['source' => 'meta_description', 'fallback' => 'article_teaser'],
                    'url' => ['source' => 'canonical_url'],
                    'inLanguage' => ['source' => 'clang_code']
                ]
            ],
            'priority' => 999
        ];
    }
    
    /**
     * Schema-Daten basierend auf Mapping generieren
     * 
     * @param array $schema Schema-Konfiguration
     * @param rex_article $article REDAXO Artikel
     * @param array $additionalData Zusätzliche Daten
     * @return array|null Generierte Schema-Daten
     */
    private static function generateSchemaData($schema, $article, $additionalData = [])
    {
        if (!isset($schema['config']['mappings'])) {
            return null;
        }
        
        $mappings = $schema['config']['mappings'];
        $data = [];
        
        foreach ($mappings as $property => $mapping) {
            if (is_array($mapping)) {
                if (isset($mapping['source'])) {
                    // Property-Mapping
                    $value = \FriendsOfRedaxo\JsonLdManager\Mapping\DataSourceExtended::getValue(
                        $mapping['source'], 
                        $article, 
                        $additionalData
                    );
                    
                    // Fallback
                    if (empty($value) && isset($mapping['fallback'])) {
                        $value = \FriendsOfRedaxo\JsonLdManager\Mapping\DataSourceExtended::getValue(
                            $mapping['fallback'], 
                            $article, 
                            $additionalData
                        );
                    }
                    
                    // Transform
                    if (!empty($value) && isset($mapping['transform'])) {
                        $value = \FriendsOfRedaxo\JsonLdManager\Mapping\DataSourceExtended::transformValue($value, $mapping['transform']);
                    }
                    
                    if (!empty($value)) {
                        $data[$property] = $value;
                    } elseif (isset($mapping['required']) && $mapping['required']) {
                        // Required field fehlt - Schema nicht ausgeben
                        return null;
                    }
                } else {
                    // Verschachteltes Object
                    $nestedData = self::processNestedMapping($mapping, $article, $additionalData);
                    if (!empty($nestedData)) {
                        $data[$property] = $nestedData;
                    }
                }
            } else {
                // Direkte Werte (z.B. @context, @type)
                $data[$property] = $mapping;
            }
        }
        
        return !empty($data) ? $data : null;
    }
    
    /**
     * Verschachtelte Mappings verarbeiten
     * 
     * @param array $mapping Mapping-Konfiguration
     * @param rex_article $article REDAXO Artikel
     * @param array $additionalData Zusätzliche Daten
     * @return array|null Verarbeitete Daten
     */
    private static function processNestedMapping($mapping, $article, $additionalData = [])
    {
        $data = [];
        
        foreach ($mapping as $key => $value) {
            if (is_array($value) && isset($value['source'])) {
                $resolvedValue = \FriendsOfRedaxo\JsonLdManager\Mapping\DataSourceExtended::getValue($value['source'], $article, $additionalData);
                
                if (empty($resolvedValue) && isset($value['fallback'])) {
                    $resolvedValue = \FriendsOfRedaxo\JsonLdManager\Mapping\DataSourceExtended::getValue($value['fallback'], $article, $additionalData);
                }
                
                if (!empty($resolvedValue)) {
                    $data[$key] = $resolvedValue;
                }
            } elseif (!is_array($value)) {
                $data[$key] = $value;
            }
        }
        
        return !empty($data) ? $data : null;
    }
    
    /**
     * Cache löschen
     * 
     * @param int|null $articleId Spezifische Artikel-ID (optional)
     */
    public static function clearCache($articleId = null)
    {
        if ($articleId) {
            if (class_exists('\rex_cache')) {
                \rex_cache::delete('jsonld_manager', 'article_'.$articleId);
            }
        } else {
            if (class_exists('\rex_cache')) {
                \rex_cache::deleteNamespace('jsonld_manager');
            }
        }
        
        self::$cache = [];
    }
    
    /**
     * Debug-Information hinzufügen
     * 
     * @param string $type Debug-Typ
     * @param string $message Debug-Nachricht
     * @param array $data Zusätzliche Daten
     */
    private static function addDebugInfo($type, $message, $data = [])
    {
        self::$debugInfo[] = [
            'timestamp' => microtime(true),
            'type' => $type,
            'message' => $message,
            'data' => $data
        ];
    }
    
    /**
     * Debug-Info zur Browser-Console ausgeben
     * 
     * @param string $message Debug-Nachricht
     * @param array $data Zusätzliche Daten
     */
    private static function outputConsoleDebug($message, $data = [])
    {
        // Console-Debug-Ausgabe deaktiviert
        return;
    }
    
    /**
     * Debug-Modal ausgeben (sollte vor </body> eingebunden werden)
     * 
     * @return string Debug-Modal HTML
     */
    public static function renderDebugModal()
    {
        if (empty(self::$debugInfo) || !self::getSetting(\rex_addon::get('jsonld_manager'), 'debug_mode', false)) {
            return '';
        }
        
        $html = '
        <div id="jsonld-debug-modal" style="
            position: fixed;
            bottom: 20px;
            left: 20px;
            width: 400px;
            max-height: 300px;
            background: #2c2c2c;
            color: #ffffff;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.5);
            z-index: 10000000;
            font-family: \'Courier New\', monospace;
            font-size: 12px;
            overflow: hidden;
        ">
            <div style="
                background: #ff4444;
                color: white;
                padding: 8px 12px;
                font-weight: bold;
                display: flex;
                justify-content: space-between;
                align-items: center;
            ">
                <span>🔧 JSON-LD Manager Debug</span>
                <span onclick="document.getElementById(\'jsonld-debug-modal\').style.display=\'none\'" style="
                    cursor: pointer;
                    font-size: 16px;
                    line-height: 1;
                ">&times;</span>
            </div>
            <div style="
                max-height: 250px;
                overflow-y: auto;
                padding: 10px;
            ">
        ';
        
        foreach (self::$debugInfo as $info) {
            $time = date('H:i:s', $info['timestamp']) . substr($info['timestamp'], strpos($info['timestamp'], '.'), 4);
            $typeColor = self::getDebugTypeColor($info['type']);
            
            $html .= '
                <div style="margin-bottom: 8px; padding-bottom: 8px; border-bottom: 1px solid #444;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                        <span style="color: ' . $typeColor . '; font-weight: bold;">' . htmlspecialchars($info['type']) . '</span>
                        <span style="color: #999; font-size: 10px;">' . $time . '</span>
                    </div>
                    <div style="color: #ddd; margin-bottom: 4px;">' . htmlspecialchars($info['message']) . '</div>';
            
            if (!empty($info['data'])) {
                $html .= '<details style="color: #aaa; font-size: 10px;">
                    <summary style="cursor: pointer; color: #888;">Details</summary>
                    <pre style="margin: 4px 0; padding: 4px; background: #1a1a1a; border-radius: 3px; overflow-x: auto;">' . 
                    htmlspecialchars(json_encode($info['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . 
                    '</pre>
                </details>';
            }
            
            $html .= '</div>';
        }
        
        $html .= '
            </div>
        </div>
        ';
        
        return $html;
    }
    
    /**
     * Farbe für Debug-Typ ermitteln
     * 
     * @param string $type Debug-Typ
     * @return string Hex-Farbe
     */
    private static function getDebugTypeColor($type)
    {
        $colors = [
            'render_start' => '#4CAF50',
            'cache_hit' => '#2196F3',
            'render_success' => '#8BC34A',
            'render_error' => '#F44336',
            'schema_load' => '#FF9800',
            'data_mapping' => '#9C27B0'
        ];
        
        return $colors[$type] ?? '#FFC107';
    }

    /**
     * Addon-Setting robust laden (unterstützt alte und neue Config-Struktur).
     *
     * @param \rex_addon $addon
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    private static function getSetting(\rex_addon $addon, $key, $default = null)
    {
        $global = $addon->getConfig('global_settings', []);
        if (isset($global['settings']) && array_key_exists($key, $global['settings'])) {
            return $global['settings'][$key];
        }

        return $addon->getConfig('settings.' . $key, $default);
    }

    /**
     * Effektive Branch-ID für Artikel ermitteln.
     *
     * @param int $articleId
     * @return int|null
     */
    private static function resolveBranchIdsForArticle($articleId, $clangId = 1)
    {
        $localizedKey = 'article_branch_' . $articleId . '_clang_' . (int) $clangId;
        $storedBranchConfig = \rex_config::get('jsonld_manager', $localizedKey, 0);
        if (is_array($storedBranchConfig)) {
            $selectedBranchIds = array_values(array_unique(array_filter(array_map('intval', $storedBranchConfig))));
        } else {
            $selectedBranchId = (int) $storedBranchConfig;
            $selectedBranchIds = $selectedBranchId > 0 ? [$selectedBranchId] : [];
        }
        if (!empty($selectedBranchIds)) {
            return $selectedBranchIds;
        }

        try {
            $sql = \rex_sql::factory();
            $sql->setQuery('SELECT id FROM ' . \rex::getTable('jsonld_localbusiness_branches') . ' WHERE is_main_branch = 1 AND clang_id = ? LIMIT 1', [(int) $clangId]);
            if ($sql->getRows() > 0) {
                return [(int) $sql->getValue('id')];
            }

            $sql->setQuery('SELECT id FROM ' . \rex::getTable('jsonld_localbusiness_branches') . ' WHERE clang_id = ? ORDER BY sort_order ASC, id ASC LIMIT 1', [(int) $clangId]);
            if ($sql->getRows() > 0) {
                return [(int) $sql->getValue('id')];
            }
        } catch (\Exception $e) {
            // Fallback: Keine Branch verwenden
        }

        return [];
    }

    /**
     * Einheitliches JSON-LD Payload bauen.
     *
     * @param array $jsonLdItems
     * @return array
     */
    private static function normalizeOutputPayload(array $jsonLdItems)
    {
        if (count($jsonLdItems) === 1) {
            return $jsonLdItems[0];
        }

        return [
            '@context' => 'https://schema.org',
            '@graph' => array_values($jsonLdItems)
        ];
    }
}

\class_alias(__NAMESPACE__ . '\\Renderer', 'JsonldManager\\Frontend\\Renderer');
