<?php

namespace FriendsOfRedaxo\JsonLdManager {

/**
 * Dynamisches JSON-LD für URL-Profile generieren
 * 
 * @param int $profileId ID des URL-Profils
 * @param int $dataId ID des Datensatzes
 * @return string JSON-LD Script oder leer
 */
function generateDynamicJsonLd($profileId, $dataId) {
    try {
        // URL-Profil laden
        $profile = rex_sql::factory()->getArray(
            'SELECT * FROM ' . rex::getTable('url_generator_profile') . ' WHERE id = ?',
            [$profileId]
        );
        
        if (!$profile) {
            return '';
        }
        $profile = $profile[0];
        
        // Mapping für dieses Profil laden
        $mapping = rex_sql::factory()->getArray(
            'SELECT * FROM ' . rex::getTable('jsonld_url_profile_mappings') . ' WHERE url_profile_id = ? AND active = 1',
            [$profileId]
        );
        
        if (!$mapping) {
            return '';
        }
        $mapping = $mapping[0];
        
        // Datensatz aus YForm-Tabelle laden
        $yformTableName = null;
        if ($profile['table_parameters']) {
            $tableParams = json_decode($profile['table_parameters'], true);
            if ($tableParams && !empty($tableParams['table_name'])) {
                $yformTableName = $tableParams['table_name'];
            }
        }
        
        // Fallback: Korrigiere Tabellenname (entferne 1_xxx_ Prefix)
        if (!$yformTableName) {
            $yformTableName = str_replace('1_xxx_', '', $profile['table_name']);
        }

        if (!isValidTableName($yformTableName)) {
            return '';
        }
        
        // WICHTIG: YForm-Tabellenname bereits mit rex_ Prefix - nicht nochmal durch getTable() hinzufügen!
        $dataRow = rex_sql::factory()->getArray(
            'SELECT * FROM ' . $yformTableName . ' WHERE id = ?',
            [$dataId]
        );
        
        if (!$dataRow) {
            return '';
        }
        $dataRow = $dataRow[0];
        
        // Field-Mappings anwenden
        $fieldMappings = json_decode($mapping['field_mappings'], true);
        if (!$fieldMappings) {
            return '';
        }
        
        // Schema-Objekt erstellen
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => $mapping['schema_type']
        ];
        
        // Aktuelle URL als @id verwenden
        $requestPath = (string) rex_server('REQUEST_URI', 'string', '/');
        $requestPath = '/' . ltrim(parse_url($requestPath, PHP_URL_PATH) ?: '', '/');
        $baseUrl = DomainConfig::getBaseUrl();
        $schema['@id'] = rtrim($baseUrl, '/') . $requestPath;
        
        // URL-Property automatisch setzen falls im Mapping vorhanden
        if (isset($fieldMappings['url'])) {
            $schema['url'] = $schema['@id'];
        }
        
        // Felder mappen - KORREKTE AUFLÖSUNG DER MAPPING-OBJEKTE
        foreach ($fieldMappings as $schemaProperty => $fieldMapping) {
            if (is_array($fieldMapping)) {
                // Neues Mapping-Format: {"type":"field","value":"title"} oder {"type":"static","value":"..."}
                if (isset($fieldMapping['type']) && isset($fieldMapping['value'])) {
                    if ($fieldMapping['type'] === 'field') {
                        // Feld aus Datensatz auflösen
                        $fieldName = $fieldMapping['value'];
                        if (isset($dataRow[$fieldName])) {
                            $value = $dataRow[$fieldName];
                            
                            // Spezial-Behandlung für Bilder
                            if (in_array($schemaProperty, ['image', 'photo']) && !empty($value)) {
                                if (rex_addon::get('yrewrite')->isAvailable()) {
                                    $schema[$schemaProperty] = rex_yrewrite::getFullPath('/media/' . $value);
                                } else {
                                    $schema[$schemaProperty] = rex_url::frontend('media/' . $value);
                                }
                            } else {
                                $schema[$schemaProperty] = $value;
                            }
                        }
                    } elseif ($fieldMapping['type'] === 'static') {
                        // Statischer Wert
                        $schema[$schemaProperty] = $fieldMapping['value'];
                    }
                } else {
                    // Altes Array-Format - als statischer Wert behandeln
                    $schema[$schemaProperty] = $fieldMapping;
                }
            } elseif (is_string($fieldMapping) && isset($dataRow[$fieldMapping])) {
                // Altes einfaches Feld-Mapping
                $value = $dataRow[$fieldMapping];
                
                // Spezial-Behandlung für Bilder
                if (in_array($schemaProperty, ['image', 'photo']) && !empty($value)) {
                    if (rex_addon::get('yrewrite')->isAvailable()) {
                        $schema[$schemaProperty] = rex_yrewrite::getFullPath('/media/' . $value);
                    } else {
                        $schema[$schemaProperty] = rex_url::frontend('media/' . $value);
                    }
                } else {
                    $schema[$schemaProperty] = $value;
                }
            } elseif (is_string($fieldMapping) && $fieldMapping !== '') {
                // Statischer String-Wert
                $schema[$schemaProperty] = $fieldMapping;
            }
        }
        
        $meta = [
            'article_id' => 0,
            'clang_id' => (int) rex_clang::getCurrentId(),
            'branch_id' => null,
            'types' => [$mapping['schema_type']],
            'dynamic_profile_id' => (int) $profileId,
            'dynamic_data_id' => (int) $dataId
        ];

        return JsonLdGenerator::renderPayloadScript(
            $schema,
            function_exists('jsonld_is_debug_enabled') && jsonld_is_debug_enabled(),
            $meta
        );
        
    } catch (Exception $e) {
        if (rex::isDebugMode()) {
            return '<!-- JSON-LD Error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES) . ' -->' . "\n";
        }
        return '';
    }
}

function isValidTableName(?string $tableName): bool {
    return is_string($tableName) && preg_match('/^[A-Za-z0-9_]+$/', $tableName) === 1;
}
}

namespace {
    function generateDynamicJsonLd($profileId, $dataId) {
        return \FriendsOfRedaxo\JsonLdManager\generateDynamicJsonLd($profileId, $dataId);
    }
}
