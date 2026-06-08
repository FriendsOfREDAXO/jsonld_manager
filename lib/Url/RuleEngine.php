<?php

namespace FriendsOfRedaxo\JsonLdManager\Url;

use rex_article;
use rex_url;
use rex_sql;
use rex;
use InvalidArgumentException;
use Exception;
use rex_addon;

/**
 * URL Rule Engine - Dynamische URL-Filterung
 * 
 * Verarbeitet URL-Regeln für dynamische Seiten mit GET-Parametern
 * und ordnet diese passenden Schema-Types zu.
 * 
 * @package JsonldManager\Url
 * @version 1.0.1
 * @author  REDAXO Developer
 */

class RuleEngine
{
    /**
     * @var array Cache für URL-Regeln
     */
    /** @var array<string, list<array<string, mixed>>>|null */
    private static ?array $rulesCache = null;

    /**
     * Aktuelle URL gegen Regeln prüfen
     * 
     * @return array|null Matching Rule Data oder null
     */
    /**
     * @return array<string, mixed>|null
     */
    public static function matchCurrentUrl(): ?array
    {
        $article = rex_article::getCurrent();
        if (!$article) {
            return null;
        }

        $rules = self::getUrlRules($article->getId());
        if (empty($rules)) {
            return null;
        }

        $currentUrl = rex_url::currentBackendPage();
        $getParams = $_GET;

        foreach ($rules as $rule) {
            if (self::matchRule($rule, $currentUrl, $getParams)) {
                return [
                    'rule_id' => $rule['id'],
                    'schema_type' => $rule['schema_type'],
                    'data' => self::extractRuleData($rule, $getParams)
                ];
            }
        }

        return null;
    }

    /**
     * URL-Regeln für Artikel laden
     * 
     * @param int $articleId Artikel-ID
     * @return array Array von URL-Regeln
     */
    /**
     * @return list<array<string, mixed>>
     */
    private static function getUrlRules(int $articleId): array
    {
        $cacheKey = 'rules_' . $articleId;

        if (self::$rulesCache === null || !isset(self::$rulesCache[$cacheKey])) {
            $sql = rex_sql::factory();
            $sql->setQuery('
                SELECT * FROM '.rex::getTable('jsonld_url_rules').' 
                WHERE article_id = ? AND active = 1 
                ORDER BY priority ASC
            ', [$articleId]);

            $rules = [];
            while ($sql->hasNext()) {
                $configRaw = $sql->getValue('schema_config');
                $getParamsRaw = $sql->getValue('get_params');

                $config = is_string($configRaw) ? (json_decode($configRaw, true) ?: []) : [];
                $getParams = is_string($getParamsRaw) ? (json_decode($getParamsRaw, true) ?: []) : [];

                $rules[] = [
                    'id' => $sql->getValue('id'),
                    'name' => $sql->getValue('name'),
                    'url_pattern' => $sql->getValue('url_pattern'),
                    'get_params' => $getParams,
                    'schema_type' => $config['schema_type'] ?? 'WebPage',
                    'schema_config' => $config,
                    'priority' => $sql->getValue('priority')
                ];
                $sql->next();
            }

            self::$rulesCache[$cacheKey] = $rules;
        }

        return self::$rulesCache[$cacheKey];
    }

    /**
     * Regel gegen aktuelle URL/Parameter prüfen
     * 
     * @param array $rule URL-Regel
     * @param string $currentUrl Aktuelle URL
     * @param array $getParams GET-Parameter
     * @return bool Match gefunden
     */
    /**
     * @param array<string, mixed> $rule
     * @param array<string, mixed> $getParams
     */
    private static function matchRule(array $rule, string $currentUrl, array $getParams): bool
    {
        // URL-Pattern prüfen (falls definiert)
        if (!empty($rule['url_pattern'])) {
            if (!preg_match($rule['url_pattern'], $currentUrl)) {
                return false;
            }
        }

        // Required GET-Parameter prüfen
        if (!empty($rule['get_params']['required'])) {
            foreach ($rule['get_params']['required'] as $param) {
                if (empty($getParams[$param])) {
                    return false;
                }
            }
        }

        // Optional: GET-Parameter Werte validieren
        if (!empty($rule['get_params']['validation'])) {
            foreach ($rule['get_params']['validation'] as $param => $validation) {
                if (isset($getParams[$param])) {
                    if (!self::validateParamValue($getParams[$param], $validation)) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * Parameter-Wert validieren
     * 
     * @param mixed $value Parameter-Wert
     * @param array $validation Validierungs-Regeln
     * @return bool Validierung erfolgreich
     */
    /**
     * @param array<string, mixed> $validation
     */
    private static function validateParamValue(mixed $value, array $validation): bool
    {
        // Whitelist-Validierung
        if (!empty($validation['whitelist'])) {
            return in_array($value, $validation['whitelist']);
        }

        // Regex-Validierung
        if (!empty($validation['pattern'])) {
            return 1 === preg_match((string) $validation['pattern'], (string) $value);
        }

        // Typ-Validierung
        if (!empty($validation['type'])) {
            switch ($validation['type']) {
                case 'integer':
                    return is_numeric($value) && (int)$value == $value;
                case 'string':
                    return is_string($value);
                case 'email':
                    return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
                default:
                    return true;
            }
        }

        return true;
    }

    /**
     * Daten aus Regel und GET-Parametern extrahieren
     * 
     * @param array $rule URL-Regel
     * @param array $getParams GET-Parameter
     * @return array Extrahierte Daten
     */
    /**
     * @param array<string, mixed> $rule
     * @param array<string, mixed> $getParams
     * @return array<string, mixed>
     */
    private static function extractRuleData(array $rule, array $getParams): array
    {
        $data = [];

        // GET-Parameter zu Daten mappen
        if (!empty($rule['get_params']['mapping'])) {
            foreach ($rule['get_params']['mapping'] as $source => $target) {
                if (isset($getParams[$source])) {
                    $data[$target] = $getParams[$source];
                }
            }
        }

        // Schema-spezifische Daten hinzufügen
        if (!empty($rule['schema_config']['additional_data'])) {
            $data = array_merge($data, $rule['schema_config']['additional_data']);
        }

        return $data;
    }

    /**
     * URL-Regel erstellen oder aktualisieren
     * 
     * @param int $articleId Artikel-ID
     * @param array $ruleData Regel-Daten
     * @return int Regel-ID
     */
    /**
     * @param array<string, mixed> $ruleData
     */
    public static function createOrUpdateRule(int $articleId, array $ruleData): int
    {
        $sql = rex_sql::factory();

        // Validierung
        if (empty($ruleData['name'])) {
            throw new InvalidArgumentException('Regel-Name ist erforderlich');
        }

        $data = [
            'article_id' => $articleId,
            'name' => $ruleData['name'],
            'url_pattern' => $ruleData['url_pattern'] ?? '',
            'get_params' => json_encode($ruleData['get_params'] ?? []),
            'schema_config' => json_encode($ruleData['schema_config'] ?? []),
            'active' => $ruleData['active'] ?? 1,
            'priority' => $ruleData['priority'] ?? 100,
            'modified' => date('Y-m-d H:i:s')
        ];

        if (!empty($ruleData['id'])) {
            // Update
            $sql->setTable(rex::getTable('jsonld_url_rules'));
            $sql->setWhere(['id' => $ruleData['id']]);
            $sql->setValues($data);
            $sql->update();
            $ruleId = $ruleData['id'];
        } else {
            // Create
            $data['created'] = date('Y-m-d H:i:s');
            $sql->setTable(rex::getTable('jsonld_url_rules'));
            $sql->setValues($data);
            $sql->insert();
            $ruleId = $sql->getLastId();
        }

        // Cache leeren
        self::clearCache();

        return $ruleId;
    }

    /**
     * URL-Regel löschen
     * 
     * @param int $ruleId Regel-ID
     * @return bool Erfolgreich gelöscht
     */
    public static function deleteRule(int $ruleId): bool
    {
        $sql = rex_sql::factory();
        $sql->setTable(rex::getTable('jsonld_url_rules'));
        $sql->setWhere(['id' => $ruleId]);

        try {
            $sql->delete();
            self::clearCache();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Alle URL-Regeln für Artikel abrufen
     * 
     * @param int $articleId Artikel-ID
     * @return array Array von URL-Regeln
     */
    /**
     * @return list<array<string, mixed>>
     */
    public static function getArticleRules(int $articleId): array
    {
        $sql = rex_sql::factory();
        $sql->setQuery('
            SELECT * FROM '.rex::getTable('jsonld_url_rules').' 
            WHERE article_id = ?
            ORDER BY priority ASC, name ASC
        ', [$articleId]);

        $rules = [];
        while ($sql->hasNext()) {
            $rules[] = [
                'id' => $sql->getValue('id'),
                'name' => $sql->getValue('name'),
                'url_pattern' => $sql->getValue('url_pattern'),
                'get_params' => is_string($sql->getValue('get_params')) ? (json_decode((string) $sql->getValue('get_params'), true) ?: []) : [],
                'schema_config' => is_string($sql->getValue('schema_config')) ? (json_decode((string) $sql->getValue('schema_config'), true) ?: []) : [],
                'active' => (bool)$sql->getValue('active'),
                'priority' => $sql->getValue('priority'),
                'created' => $sql->getValue('created'),
                'modified' => $sql->getValue('modified')
            ];
            $sql->next();
        }

        return $rules;
    }

    /**
     * URL-Regel-Validierung
     * 
     * @param array $ruleData Regel-Daten
     * @return array Validierungs-Fehler (leer = valide)
     */
    /**
     * @param array<string, mixed> $ruleData
     * @return array<string, string>
     */
    public static function validateRule(array $ruleData): array
    {
        $errors = [];

        // Name erforderlich
        if (empty($ruleData['name'])) {
            $errors['name'] = 'Regel-Name ist erforderlich';
        }

        // URL-Pattern Syntax prüfen
        if (!empty($ruleData['url_pattern'])) {
            if (@preg_match($ruleData['url_pattern'], '') === false) {
                $errors['url_pattern'] = 'Ungültiges Regex-Pattern';
            }
        }

        // GET-Parameter Whitelist prüfen
        if (!empty($ruleData['get_params']['required'])) {
            $addon = rex_addon::get('jsonld_manager');
            $whitelist = $addon->getConfig('whitelist.get_params', []);

            foreach ($ruleData['get_params']['required'] as $param) {
                if (!in_array($param, $whitelist)) {
                    $errors['get_params'] = "Parameter '$param' ist nicht in der Whitelist";
                }
            }
        }

        // Schema-Config validieren
        if (!empty($ruleData['schema_config'])) {
            if (empty($ruleData['schema_config']['schema_type'])) {
                $errors['schema_config'] = 'Schema-Type ist erforderlich';
            }
        }

        return $errors;
    }

    /**
     * Test URL gegen Regel
     * 
     * @param array $ruleData Regel-Daten
     * @param string $testUrl Test-URL
     * @param array $testParams Test-Parameter
     * @return array Test-Ergebnis
     */
    /**
     * @param array<string, mixed> $ruleData
     * @param array<string, mixed> $testParams
     * @return array<string, mixed>
     */
    public static function testRule(array $ruleData, string $testUrl, array $testParams = []): array
    {
        $result = [
            'match' => false,
            'errors' => [],
            'extracted_data' => []
        ];

        try {
            // Rule Mock erstellen
            $rule = [
                'url_pattern' => $ruleData['url_pattern'] ?? '',
                'get_params' => $ruleData['get_params'] ?? [],
                'schema_config' => $ruleData['schema_config'] ?? []
            ];

            // Test durchführen
            if (self::matchRule($rule, $testUrl, $testParams)) {
                $result['match'] = true;
                $result['extracted_data'] = self::extractRuleData($rule, $testParams);
            }

        } catch (Exception $e) {
            $result['errors'][] = $e->getMessage();
        }

        return $result;
    }

    /**
     * Cache zurücksetzen
     */
    public static function clearCache(): void
    {
        self::$rulesCache = null;
    }
}

\class_alias(__NAMESPACE__ . '\\RuleEngine', 'JsonldManager\\Url\\RuleEngine');
