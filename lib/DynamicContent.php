<?php

namespace FriendsOfRedaxo\JsonLdManager;

use FriendsOfRedaxo\JsonLdManager\Mapping\DynamicFieldMapper;
use InvalidArgumentException;
use rex;
use rex_addon;
use rex_clang;
use rex_sql;
use rex_url;
use rex_yrewrite;

/**
 * Fasst mehrere YForm-Datensätze zu einem gemeinsamen Schema zusammen
 * (FAQPage mit mainEntity-Array, ItemList/CollectionPage für Übersichtsseiten).
 *
 * Diese Klasse wird explizit aus Templates/Modulen aufgerufen, da
 * Übersichts- und FAQ-Seiten keine 1:1-Zuordnung zu einem einzelnen
 * URL-Profil-Datensatz haben.
 */
final class DynamicContent
{
    private const IDENTIFIER_PATTERN = '/^[A-Za-z0-9_]+$/';

    /**
     * Baut ein FAQPage-Schema aus allen passenden Zeilen einer YForm-Tabelle.
     *
     * @param string $tableName YForm-Tabelle inkl. Prefix (z. B. "rex_faq")
     * @param array<string, mixed> $filter Spalte => Wert (Gleichheit), Array => IN (...), null => IS NULL
     * @param array<string, mixed> $options order_by (z. B. "prio ASC"), limit, name, description, url
     * @return array<string, mixed> Leeres Array, wenn keine gültigen Fragen gefunden wurden
     */
    public static function faqPage(string $tableName, string $questionField, string $answerField, array $filter = [], array $options = []): array
    {
        self::assertIdentifier($tableName, 'Tabellenname');
        self::assertIdentifier($questionField, 'Frage-Feld');
        self::assertIdentifier($answerField, 'Antwort-Feld');

        $pairs = [];
        foreach (self::fetchRows($tableName, $filter, $options) as $row) {
            $question = $row[$questionField] ?? null;
            $answer = $row[$answerField] ?? null;
            $pairs[] = [
                'question' => is_scalar($question) ? (string) $question : '',
                'answer' => is_scalar($answer) ? self::normalizeAnswerText((string) $answer, $options) : '',
            ];
        }

        $extra = array_intersect_key($options, array_flip(['name', 'description', 'url', 'inLanguage']));
        if (!isset($extra['inLanguage'])) {
            $extra['inLanguage'] = self::currentLanguageCode();
        }

        return SchemaHelper::faqPage($pairs, $extra);
    }

    /**
     * Baut ein ItemList- oder CollectionPage-Schema aus YForm-Zeilen.
     *
     * @param string $schemaType Schema-Typ der einzelnen Einträge (z. B. "Product", "Event")
     * @param array<string, mixed> $filter Siehe faqPage()
     * @param array<string, mixed> $fieldMappings Schema-Property => Spaltenname | callable(array $row): mixed | ['type' => 'field'|'static'|'nested'|'opening_hours', ...]
     * @param array<string, mixed> $options order_by, limit, list_type ("ItemList"|"CollectionPage"), name, description, url,
     *                                      url_namespace (URL-AddOn-Namespace für Detail-URLs), url_callback (callable(array $row): ?string)
     * @return array<string, mixed> Leeres Array ohne Einträge
     */
    public static function itemList(string $tableName, string $schemaType, array $filter = [], array $fieldMappings = [], array $options = []): array
    {
        self::assertIdentifier($tableName, 'Tabellenname');
        if (preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $schemaType) !== 1) {
            throw new InvalidArgumentException('Ungültiger Schema-Typ: ' . $schemaType);
        }

        $listType = ($options['list_type'] ?? 'ItemList') === 'CollectionPage' ? 'CollectionPage' : 'ItemList';
        $items = [];

        foreach (self::fetchRows($tableName, $filter, $options) as $row) {
            $item = self::buildItem($schemaType, $row, $fieldMappings);
            $url = self::resolveItemUrl($row, $options);
            if ($url !== null && !isset($item['url'])) {
                $item['url'] = $url;
            }
            if (count($item) <= 1) {
                continue;
            }
            $items[] = $item;
        }

        $extra = array_intersect_key($options, array_flip(['name', 'description', 'url', 'itemListOrder']));

        return SchemaHelper::itemList($items, $extra, $listType);
    }

    /**
     * Rendert ein beliebiges Schema-Array als <script type="application/ld+json">
     * inklusive Debug-Overlay (wenn Debug-Modus aktiv).
     *
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $meta Zusätzliche Debug-Informationen
     */
    public static function renderScript(array $schema, array $meta = []): string
    {
        if (count($schema) === 0) {
            return '';
        }

        try {
            $payload = JsonLdGenerator::buildPayload([$schema]);
            $json = JsonLdGenerator::encodePayload($payload);
        } catch (\Throwable $e) {
            if (rex::isDebugMode()) {
                return '<!-- JSON-LD Error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES) . ' -->' . "\n";
            }

            return '';
        }

        $html = '<script type="application/ld+json">' . "\n" . $json . "\n" . '</script>' . "\n";

        if (function_exists('jsonld_is_debug_enabled') && jsonld_is_debug_enabled() && function_exists('jsonld_render_debug_overlay_script')) {
            $type = $schema['@type'] ?? 'Schema';
            $meta = array_merge([
                'article_id' => 0,
                'clang_id' => (int) rex_clang::getCurrentId(),
                'branch_id' => null,
                'types' => [is_string($type) ? $type : 'Schema'],
            ], $meta);
            $html .= jsonld_render_debug_overlay_script($payload, $meta);
        }

        return $html;
    }

    /**
     * Lädt Zeilen einer YForm-Tabelle mit Prepared-Statement-Filter.
     *
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $options
     * @return array<int, array<string, mixed>>
     */
    public static function fetchRows(string $tableName, array $filter = [], array $options = []): array
    {
        self::assertIdentifier($tableName, 'Tabellenname');

        $where = [];
        $params = [];
        foreach ($filter as $column => $value) {
            self::assertIdentifier($column, 'Filter-Spalte');
            $quoted = '`' . $column . '`';

            if ($value === null) {
                $where[] = $quoted . ' IS NULL';
                continue;
            }
            if (is_array($value)) {
                $scalars = array_values(array_filter($value, static fn ($v) => is_scalar($v)));
                if (count($scalars) === 0) {
                    $where[] = '1 = 0';
                    continue;
                }
                $where[] = $quoted . ' IN (' . implode(', ', array_fill(0, count($scalars), '?')) . ')';
                foreach ($scalars as $scalar) {
                    $params[] = is_bool($scalar) ? (int) $scalar : $scalar;
                }
                continue;
            }
            if (!is_scalar($value)) {
                throw new InvalidArgumentException('Ungültiger Filterwert für Spalte ' . $column);
            }
            $where[] = $quoted . ' = ?';
            $params[] = is_bool($value) ? (int) $value : $value;
        }

        $query = 'SELECT * FROM `' . $tableName . '`';
        if (count($where) > 0) {
            $query .= ' WHERE ' . implode(' AND ', $where);
        }

        $orderBy = $options['order_by'] ?? null;
        if (is_string($orderBy) && trim($orderBy) !== '') {
            $query .= ' ORDER BY ' . self::sanitizeOrderBy($orderBy);
        }

        $limit = $options['limit'] ?? null;
        if (is_int($limit) && $limit > 0) {
            $query .= ' LIMIT ' . $limit;
        }

        /** @var array<int, array<string, mixed>> $rows */
        $rows = rex_sql::factory()->getArray($query, $params);

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $fieldMappings
     * @return array<string, mixed>
     */
    private static function buildItem(string $schemaType, array $row, array $fieldMappings): array
    {
        $resolveLeaf = static function (array $leaf) use ($row): mixed {
            if ($leaf['type'] === 'static') {
                return $leaf['value'];
            }
            if ($leaf['type'] === 'field' && is_string($leaf['value']) && isset($row[$leaf['value']])) {
                return $row[$leaf['value']];
            }

            return null;
        };

        $fields = [];
        foreach ($fieldMappings as $property => $mapping) {
            if (preg_match('/^[A-Za-z@][A-Za-z0-9]*$/', $property) !== 1) {
                continue;
            }

            $value = null;
            if (is_string($mapping)) {
                $value = $row[$mapping] ?? null;
            } elseif (is_callable($mapping)) {
                $value = $mapping($row);
            } elseif (DynamicFieldMapper::isStructuredMapping($mapping)) {
                $value = DynamicFieldMapper::resolveStructured($property, $mapping, $resolveLeaf);
            } elseif (is_array($mapping) && isset($mapping['type'], $mapping['value'])) {
                $value = $resolveLeaf($mapping);
            }

            if ($value === null || (is_string($value) && trim($value) === '') || (is_array($value) && count($value) === 0)) {
                continue;
            }

            if (in_array($property, ['image', 'photo', 'logo'], true) && is_string($value)) {
                $value = self::mediaUrl($value);
            }

            $fields[$property] = $value;
        }

        return array_merge(['@type' => $schemaType], $fields);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $options
     */
    private static function resolveItemUrl(array $row, array $options): ?string
    {
        $callback = $options['url_callback'] ?? null;
        if (is_callable($callback)) {
            $url = $callback($row);

            return is_string($url) && $url !== '' ? $url : null;
        }

        $namespace = $options['url_namespace'] ?? null;
        if (is_string($namespace) && $namespace !== '' && isset($row['id']) && rex_addon::get('url')->isAvailable()) {
            $url = rex_getUrl('', '', [$namespace => (int) $row['id']]);
            if ($url !== '' && !str_starts_with($url, 'http')) {
                $url = rtrim(DomainConfig::getBaseUrl(), '/') . '/' . ltrim($url, '/');
            }

            return $url !== '' ? $url : null;
        }

        return null;
    }

    private static function mediaUrl(string $value): string
    {
        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://') || str_starts_with($value, '/')) {
            return $value;
        }
        // Nur den ersten Dateinamen einer Medialiste verwenden
        $first = trim(explode(',', $value)[0]);
        if ($first === '') {
            return '';
        }
        if (rex_addon::get('yrewrite')->isAvailable() && class_exists('rex_yrewrite')) {
            return rex_yrewrite::getFullPath('/media/' . $first);
        }

        return rex_url::frontend('media/' . $first);
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function normalizeAnswerText(string $answer, array $options): string
    {
        if (($options['strip_tags'] ?? true) === true) {
            $answer = html_entity_decode(strip_tags($answer), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $answer = preg_replace('/\s+/u', ' ', $answer) ?? $answer;
        }

        return trim($answer);
    }

    private static function currentLanguageCode(): string
    {
        $clang = rex_clang::getCurrent();

        return $clang->getCode();
    }

    private static function sanitizeOrderBy(string $orderBy): string
    {
        $parts = [];
        foreach (explode(',', $orderBy) as $part) {
            $part = trim($part);
            if (preg_match('/^([A-Za-z0-9_]+)(?:\s+(ASC|DESC))?$/i', $part, $m) !== 1) {
                throw new InvalidArgumentException('Ungültige ORDER BY-Angabe: ' . $part);
            }
            $parts[] = '`' . $m[1] . '`' . (isset($m[2]) ? ' ' . strtoupper($m[2]) : '');
        }

        return implode(', ', $parts);
    }

    private static function assertIdentifier(string $value, string $label): void
    {
        if (preg_match(self::IDENTIFIER_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException('Ungültiger ' . $label . ': ' . $value);
        }
    }
}
