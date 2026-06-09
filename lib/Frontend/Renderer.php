<?php

namespace FriendsOfRedaxo\JsonLdManager\Frontend;

use rex_article;
use rex_addon;
use FriendsOfRedaxo\JsonLdManager\JsonLdGenerator;
use rex_cache;
use Exception;
use FriendsOfRedaxo\JsonLdManager\Url\RuleEngine;
use rex_sql;
use rex;
use FriendsOfRedaxo\JsonLdManager\Mapping\DataSourceExtended;

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
    /** @var array<string, string> Cache für generierte JSON-LD Daten */
    private static array $cache = [];

    /** @var array<int, array<string, mixed>> Debug-Informationen sammeln */
    private static array $debugInfo = [];

    /**
     * JSON-LD für aktuelle Seite ausgeben
     * 
    * @param string|null $schemaType Spezifischer Schema-Type (optional)
    * @param array<string, mixed> $additionalData Zusätzliche Daten für Mapping (optional)
     * @return string JSON-LD als formatierter String
     */
    public static function render(?string $schemaType = null, array $additionalData = []): string
    {
        try {
            $article = rex_article::getCurrent();
            if (!$article) {
                return '';
            }

            $addon = rex_addon::get('jsonld_manager');
            $isDebugMode = self::getSetting($addon, 'debug_mode', false);
            $branchIds = JsonLdGenerator::resolveBranchIdsForArticle(
                (int) $article->getId(),
                (int) $article->getClangId()
            );

            $cacheKey = md5($article->getId() . '_' . $article->getClangId() . '_' . ($schemaType ?: 'auto') . '_' . serialize($branchIds) . '_' . serialize($additionalData));

            if (isset(self::$cache[$cacheKey])) {
                return self::$cache[$cacheKey];
            }

            $articleOutput = JsonLdGenerator::getArticleOutput(
                (int) $article->getId(),
                null,
                $isDebugMode,
                (int) $article->getClangId()
            );

            if ($articleOutput['disabled'] || $articleOutput['json'] === '') {
                if ($articleOutput['error'] && $isDebugMode) {
                    return '<!-- JSON-LD Error: ' . htmlspecialchars($articleOutput['error']) . ' -->';
                }
                return '';
            }

            $output = $articleOutput['json'];

            if (self::getSetting($addon, 'cache_enabled', true)) {
                self::$cache[$cacheKey] = $output;
                if (class_exists('\rex_cache')) {
                    rex_cache::set('jsonld_manager', 'article_'.$article->getId().'_'.$article->getClangId(), $output);
                }
            }

            if ($isDebugMode) {
                self::addDebugInfo('render_success', 'JSON-LD erfolgreich generiert', [
                    'items_count' => count($articleOutput['items']),
                    'output_length' => strlen($output),
                    'cached' => self::getSetting($addon, 'cache_enabled', true),
                    'branch_ids' => $articleOutput['branch_ids'],
                    'source' => $articleOutput['custom'] ? 'custom' : 'generated'
                ]);
            }

            return $output;
        } catch (Exception $e) {
            if (self::getSetting(rex_addon::get('jsonld_manager'), 'debug_mode', false)) {
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
    public static function getCurrentPageJsonLd(): string
    {
        // URL-Regeln prüfen
        $urlRuleData = RuleEngine::matchCurrentUrl();

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
    * @return array<int, array{id: mixed, type: mixed, config: array<string, mixed>, priority: mixed}> Array von Schema-Konfigurationen
     */
    // @phpstan-ignore-next-line bewusst als interne Reserve-API vorhanden
    private static function getArticleSchemas(int $articleId, int $clangId, ?string $schemaType = null): array
    {
        $isDebugMode = self::getSetting(rex_addon::get('jsonld_manager'), 'debug_mode', false);
        if ($isDebugMode) {
            self::addDebugInfo('schema_load', 'Lade Schema-Konfigurationen', [
                'article_id' => $articleId,
                'clang_id' => $clangId,
                'requested_schema_type' => $schemaType
            ]);
        }

        $sql = rex_sql::factory();

        $where = 'article_id = ? AND clang_id = ? AND active = 1';
        $params = [$articleId, $clangId];

        if ($schemaType) {
            $where .= ' AND schema_type = ?';
            $params[] = $schemaType;
        }

        $sql->setQuery('
            SELECT * FROM '.rex::getTable('jsonld_schemas').'
            WHERE '.$where.'
            ORDER BY priority ASC
        ', $params);

        $schemas = [];
        while ($sql->hasNext()) {
            $configRaw = $sql->getValue('config');
            $config = is_string($configRaw) ? (json_decode($configRaw, true) ?: []) : [];
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
    /** @return array<string, mixed>|null */
    // @phpstan-ignore-next-line bewusst als interne Reserve-API vorhanden
    private static function getActiveLocalBusinessBranch(int $articleId, int $clangId): ?array
    {
        // Artikel-Schema laden um LocalBusiness Branch ID zu finden
        $sql = rex_sql::factory();
        $sql->setQuery('
            SELECT config FROM '.rex::getTable('jsonld_schemas').'
            WHERE article_id = ? AND clang_id = ? AND schema_type = "WebPage" AND active = 1
            LIMIT 1
        ', [$articleId, $clangId]);

        if ($sql->getRows() === 0) {
            return null;
        }

        $configRaw = $sql->getValue('config');
        $config = is_string($configRaw) ? (json_decode($configRaw, true) ?: []) : [];
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
        $branchSql = rex_sql::factory();
        $branchSql->setQuery('
            SELECT branch_name, config FROM '.rex::getTable('jsonld_localbusiness_branches').'
            WHERE id = ? AND clang_id = ?
        ', [$branchId, (int) $clangId]);

        if ($branchSql->getRows() === 0) {
            // Branch nicht gefunden
            $isDebugMode = self::getSetting(rex_addon::get('jsonld_manager'), 'debug_mode', false);
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

        $branchConfigRaw = $branchSql->getValue('config');
        $branchConfig = is_string($branchConfigRaw) ? (json_decode($branchConfigRaw, true) ?: []) : [];

        // Prüfen ob LocalBusiness enabled ist
        if (($branchConfig['enabled'] ?? false) !== true) {
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
    /**
     * @param array<string, mixed> $branchConfig
     * @return array<string, mixed>
     */
    private static function buildLocalBusinessMappings(array $branchConfig): array
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
    /** @return array<string, mixed> */
    // @phpstan-ignore-next-line bewusst als interne Reserve-API vorhanden
    private static function getDefaultWebPageSchema(rex_article $article): array
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
    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $additionalData
     * @return array<string, mixed>|null
     */
    // @phpstan-ignore-next-line bewusst als interne Reserve-API vorhanden
    private static function generateSchemaData(array $schema, rex_article $article, array $additionalData = []): ?array
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
                    $value = DataSourceExtended::getValue(
                        $mapping['source'], 
                        $article, 
                        $additionalData
                    );

                    // Fallback
                    if (empty($value) && isset($mapping['fallback'])) {
                        $value = DataSourceExtended::getValue(
                            $mapping['fallback'], 
                            $article, 
                            $additionalData
                        );
                    }

                    // Transform
                    if (!empty($value) && isset($mapping['transform'])) {
                        $value = DataSourceExtended::transformValue($value, $mapping['transform']);
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
     * @param array<string, mixed> $mapping Mapping-Konfiguration
     * @param rex_article $article REDAXO Artikel
     * @param array<string, mixed> $additionalData Zusätzliche Daten
     * @return array<string, mixed>|null Verarbeitete Daten
     */
    private static function processNestedMapping(array $mapping, rex_article $article, array $additionalData = []): ?array
    {
        $data = [];

        foreach ($mapping as $key => $value) {
            if (is_array($value) && isset($value['source'])) {
                $resolvedValue = DataSourceExtended::getValue($value['source'], $article, $additionalData);

                if (empty($resolvedValue) && isset($value['fallback'])) {
                    $resolvedValue = DataSourceExtended::getValue($value['fallback'], $article, $additionalData);
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
    public static function clearCache(?int $articleId = null): void
    {
        if ($articleId) {
            if (class_exists('\rex_cache')) {
                rex_cache::delete('jsonld_manager', 'article_'.$articleId);
            }
        } else {
            if (class_exists('\rex_cache')) {
                rex_cache::deleteNamespace('jsonld_manager');
            }
        }

        self::$cache = [];
    }

    /**
     * Debug-Information hinzufügen
     * 
     * @param string $type Debug-Typ
     * @param string $message Debug-Nachricht
    * @param array<string, mixed> $data Zusätzliche Daten
     */
    private static function addDebugInfo(string $type, string $message, array $data = []): void
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
     * @param array<string, mixed> $data Zusätzliche Daten
     */
    private static function outputConsoleDebug(string $message, array $data = []): void
    {
        if (!rex::isDebugMode()) {
            return;
        }

        \rex_logger::factory()->log(\Psr\Log\LogLevel::DEBUG, $message, [
            'jsonld_renderer_data' => json_encode($data, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * Debug-Modal ausgeben (sollte vor </body> eingebunden werden)
     * 
     * @return string Debug-Modal HTML
     */
    public static function renderDebugModal(): string
    {
        if (empty(self::$debugInfo) || !self::getSetting(rex_addon::get('jsonld_manager'), 'debug_mode', false)) {
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
            $timestamp = (float) $info['timestamp'];
            $time = date('H:i:s', (int) $timestamp);
            $timeString = (string) $timestamp;
            $dotPos = strpos($timeString, '.');
            if (false !== $dotPos) {
                $time .= substr($timeString, $dotPos, 4);
            }
            $typeColor = self::getDebugTypeColor($info['type']);

            $html .= '
                <div style="margin-bottom: 8px; padding-bottom: 8px; border-bottom: 1px solid #444;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                        <span style="color: ' . $typeColor . '; font-weight: bold;">' . htmlspecialchars($info['type']) . '</span>
                        <span style="color: #999; font-size: 10px;">' . $time . '</span>
                    </div>
                    <div style="color: #ddd; margin-bottom: 4px;">' . htmlspecialchars($info['message']) . '</div>';

            if (!empty($info['data'])) {
                $encodedData = json_encode($info['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                $html .= '<details style="color: #aaa; font-size: 10px;">
                    <summary style="cursor: pointer; color: #888;">Details</summary>
                    <pre style="margin: 4px 0; padding: 4px; background: #1a1a1a; border-radius: 3px; overflow-x: auto;">' . 
                    htmlspecialchars(is_string($encodedData) ? $encodedData : '', ENT_QUOTES) . 
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
    private static function getDebugTypeColor(string $type): string
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
    * @param \rex_addon_interface $addon
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    private static function getSetting(\rex_addon_interface $addon, string $key, $default = null)
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
     * @return array<int, int>
     */
    // @phpstan-ignore-next-line bewusst als interne Reserve-API vorhanden
    private static function resolveBranchIdsForArticle(int $articleId, int $clangId = 1): array
    {
        return JsonLdGenerator::resolveBranchIdsForArticle($articleId, $clangId);
    }

    /**
     * Einheitliches JSON-LD Payload bauen.
     *
    * @param array<int, array<string, mixed>> $jsonLdItems
    * @return array<string, mixed>
     */
    // @phpstan-ignore-next-line bewusst als interne Reserve-API vorhanden
    private static function normalizeOutputPayload(array $jsonLdItems): array
    {
        return JsonLdGenerator::buildPayload($jsonLdItems);
    }
}

\class_alias(__NAMESPACE__ . '\\Renderer', 'JsonldManager\\Frontend\\Renderer');
