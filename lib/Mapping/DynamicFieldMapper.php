<?php

namespace FriendsOfRedaxo\JsonLdManager\Mapping;

use FriendsOfRedaxo\JsonLdManager\SchemaHelper;

/**
 * Strukturierte (verschachtelte) Feld-Zuordnungen für dynamische URL-Profile.
 *
 * Neben den flachen Mappings {"type":"field"|"static","value":...} werden zwei
 * strukturierte Formate unterstützt:
 *
 *   {"type":"nested","fields":{"price":{"type":"field","value":"preis"},"priceCurrency":{"type":"static","value":"EUR"}}}
 *   {"type":"opening_hours","rows":[{"days":["Monday","Friday"],"opens":{"type":"field","value":"mo_von"},"closes":{"type":"static","value":"18:00"}}]}
 *
 * Welches Schema.org-Objekt aus einem "nested"-Mapping entsteht, legt die
 * Property-Definition in getNestedPropertyDefinitions() fest.
 */
final class DynamicFieldMapper
{
    public const TYPE_NESTED = 'nested';
    public const TYPE_OPENING_HOURS = 'opening_hours';

    /**
     * Definitionen der strukturiert abbildbaren Properties (für Backend-UI und Auflösung).
     *
     * @return array<string, array{type: string, label: string, fields: array<string, string>}>
     */
    public static function getNestedPropertyDefinitions(): array
    {
        $addressFields = [
            'streetAddress' => 'Straße und Hausnummer',
            'postalCode' => 'PLZ',
            'addressLocality' => 'Ort',
            'addressRegion' => 'Region/Bundesland',
            'addressCountry' => 'Land (ISO-Code, z. B. DE)',
        ];
        $organizationFields = [
            'name' => 'Name',
            'url' => 'Website-URL',
        ];

        return [
            'offers' => [
                'type' => 'Offer',
                'label' => 'Angebot',
                'fields' => [
                    'price' => 'Preis (z. B. 12,50)',
                    'priceCurrency' => 'Währung (ISO-Code, z. B. EUR)',
                    'availability' => 'Verfügbarkeit (InStock/OutOfStock oder Ja/Nein-Feld)',
                    'priceValidUntil' => 'Preis gültig bis (YYYY-MM-DD)',
                    'url' => 'Angebots-URL',
                ],
            ],
            'brand' => [
                'type' => 'Brand',
                'label' => 'Marke',
                'fields' => [
                    'name' => 'Markenname',
                ],
            ],
            'aggregateRating' => [
                'type' => 'AggregateRating',
                'label' => 'Bewertung',
                'fields' => [
                    'ratingValue' => 'Durchschnittsbewertung (z. B. 4.5)',
                    'reviewCount' => 'Anzahl Bewertungen',
                    'bestRating' => 'Beste mögliche Bewertung (Standard 5)',
                ],
            ],
            'address' => [
                'type' => 'PostalAddress',
                'label' => 'Adresse',
                'fields' => $addressFields,
            ],
            'contactPoint' => [
                'type' => 'ContactPoint',
                'label' => 'Kontakt',
                'fields' => [
                    'telephone' => 'Telefon',
                    'email' => 'E-Mail',
                    'contactType' => 'Kontaktart (z. B. customer service)',
                ],
            ],
            'location' => [
                'type' => 'Place',
                'label' => 'Veranstaltungsort',
                'fields' => array_merge(['name' => 'Name des Ortes'], $addressFields),
            ],
            'organizer' => [
                'type' => 'Organization',
                'label' => 'Veranstalter',
                'fields' => $organizationFields,
            ],
            'provider' => [
                'type' => 'Organization',
                'label' => 'Anbieter',
                'fields' => $organizationFields,
            ],
            'author' => [
                'type' => 'Person',
                'label' => 'Autor',
                'fields' => [
                    'name' => 'Name',
                    'url' => 'Profil-URL',
                ],
            ],
            'openingHoursSpecification' => [
                'type' => 'OpeningHoursSpecification',
                'label' => 'Öffnungszeiten',
                'fields' => [
                    'opens' => 'Öffnet (HH:MM)',
                    'closes' => 'Schließt (HH:MM)',
                ],
            ],
        ];
    }

    /**
     * @param mixed $mapping
     */
    public static function isStructuredMapping(mixed $mapping): bool
    {
        if (!is_array($mapping) || !isset($mapping['type']) || !is_string($mapping['type'])) {
            return false;
        }

        return in_array($mapping['type'], [self::TYPE_NESTED, self::TYPE_OPENING_HOURS], true);
    }

    /**
     * Löst ein strukturiertes Mapping zu einem Schema.org-Teilobjekt auf.
     *
     * @param array<string, mixed> $mapping
     * @param callable(array<string, mixed>): mixed $resolveLeaf Löst ein flaches {"type","value"}-Mapping gegen den Datensatz auf
     * @return array<mixed>|null null, wenn nichts Sinnvolles entsteht
     */
    public static function resolveStructured(string $property, array $mapping, callable $resolveLeaf): ?array
    {
        $type = $mapping['type'] ?? null;

        if ($type === self::TYPE_OPENING_HOURS) {
            return self::resolveOpeningHours($mapping, $resolveLeaf);
        }

        if ($type !== self::TYPE_NESTED) {
            return null;
        }

        $definition = self::getNestedPropertyDefinitions()[$property] ?? null;
        $objectType = $definition['type'] ?? null;
        if ($objectType === null) {
            $custom = $mapping['object_type'] ?? null;
            if (is_string($custom) && preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $custom) === 1) {
                $objectType = $custom;
            }
        }
        if ($objectType === null) {
            return null;
        }

        $fields = self::resolveLeafFields($mapping['fields'] ?? [], $resolveLeaf);
        if (count($fields) === 0) {
            return null;
        }

        $result = self::buildObject($objectType, $fields);

        return count($result) > 0 ? $result : null;
    }

    /**
     * @param array<string, mixed> $fields Bereits aufgelöste Werte je Schema-Property
     * @return array<string, mixed>
     */
    public static function buildObject(string $objectType, array $fields): array
    {
        switch ($objectType) {
            case 'Offer':
                $price = $fields['price'] ?? null;
                $currency = $fields['priceCurrency'] ?? 'EUR';
                $availability = $fields['availability'] ?? null;
                $url = $fields['url'] ?? null;
                unset($fields['price'], $fields['priceCurrency'], $fields['availability'], $fields['url']);

                return SchemaHelper::offer(
                    is_scalar($price) ? (is_bool($price) ? (int) $price : $price) : null,
                    is_scalar($currency) ? (string) $currency : 'EUR',
                    is_scalar($availability) ? (string) $availability : null,
                    is_scalar($url) ? (string) $url : null,
                    $fields
                );

            case 'PostalAddress':
                return SchemaHelper::postalAddress($fields);

            case 'ContactPoint':
                return SchemaHelper::contactPoint($fields);

            case 'Brand':
                $name = $fields['name'] ?? null;

                return SchemaHelper::brand(is_scalar($name) ? (string) $name : null);

            case 'AggregateRating':
                $ratingValue = $fields['ratingValue'] ?? null;
                $reviewCount = $fields['reviewCount'] ?? null;
                $bestRating = $fields['bestRating'] ?? 5;
                $worstRating = $fields['worstRating'] ?? null;

                return SchemaHelper::aggregateRating(
                    is_scalar($ratingValue) && !is_bool($ratingValue) ? $ratingValue : null,
                    is_scalar($reviewCount) && !is_bool($reviewCount) ? (string) $reviewCount : null,
                    is_scalar($bestRating) && !is_bool($bestRating) && is_numeric($bestRating) ? $bestRating : 5,
                    is_scalar($worstRating) && !is_bool($worstRating) && is_numeric($worstRating) ? $worstRating : null
                );

            case 'Organization':
            case 'Person':
                $name = $fields['name'] ?? null;
                $url = $fields['url'] ?? null;
                unset($fields['name'], $fields['url']);
                $nameString = is_scalar($name) ? (string) $name : null;
                $urlString = is_scalar($url) ? (string) $url : null;

                return $objectType === 'Person'
                    ? SchemaHelper::person($nameString, $urlString, $fields)
                    : SchemaHelper::organization($nameString, $urlString, $fields);

            case 'Place':
                $name = $fields['name'] ?? null;
                unset($fields['name']);
                $addressKeys = ['streetAddress', 'postalCode', 'addressLocality', 'addressRegion', 'addressCountry', 'postOfficeBoxNumber'];
                $addressFields = array_intersect_key($fields, array_flip($addressKeys));
                $extra = array_diff_key($fields, array_flip($addressKeys));

                return SchemaHelper::place(is_scalar($name) ? (string) $name : null, $addressFields, $extra);

            default:
                return SchemaHelper::withType($objectType, $fields);
        }
    }

    /**
     * @param array<string, mixed> $mapping
     * @param callable(array<string, mixed>): mixed $resolveLeaf
     * @return array<int, array<string, mixed>>|null
     */
    private static function resolveOpeningHours(array $mapping, callable $resolveLeaf): ?array
    {
        $rows = $mapping['rows'] ?? [];
        if (!is_array($rows)) {
            return null;
        }

        $resolvedRows = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $days = $row['days'] ?? $row['dayOfWeek'] ?? [];
            $leafValues = self::resolveLeafFields([
                'opens' => $row['opens'] ?? null,
                'closes' => $row['closes'] ?? null,
            ], $resolveLeaf);

            $resolvedRows[] = [
                'dayOfWeek' => is_array($days) || is_string($days) ? $days : '',
                'opens' => $leafValues['opens'] ?? null,
                'closes' => $leafValues['closes'] ?? null,
            ];
        }

        $spec = SchemaHelper::openingHoursSpecification($resolvedRows);

        return count($spec) > 0 ? $spec : null;
    }

    /**
     * @param mixed $fieldMappings
     * @param callable(array<string, mixed>): mixed $resolveLeaf
     * @return array<string, mixed> Nur nicht-leere, skalare Werte
     */
    private static function resolveLeafFields(mixed $fieldMappings, callable $resolveLeaf): array
    {
        if (!is_array($fieldMappings)) {
            return [];
        }

        $resolved = [];
        foreach ($fieldMappings as $subProperty => $leaf) {
            if (!is_string($subProperty) || preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $subProperty) !== 1) {
                continue;
            }
            if (!is_array($leaf) || !isset($leaf['type'], $leaf['value'])) {
                continue;
            }
            $value = $resolveLeaf($leaf);
            if ($value === null || (is_string($value) && trim($value) === '')) {
                continue;
            }
            if (!is_scalar($value)) {
                continue;
            }
            $resolved[$subProperty] = is_string($value) ? trim($value) : $value;
        }

        return $resolved;
    }

    /**
     * Bereinigt Mappings aus dem Backend-Formular: nur bekannte Formate,
     * gültige Property- und Spaltennamen. Unbekanntes wird verworfen.
     *
     * @param mixed $mappings
     * @return array<string, array<string, mixed>>
     */
    public static function sanitizeMappings(mixed $mappings): array
    {
        if (!is_array($mappings)) {
            return [];
        }

        $clean = [];
        foreach ($mappings as $property => $mapping) {
            if (!is_string($property) || preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $property) !== 1) {
                continue;
            }
            if (!is_array($mapping) || !isset($mapping['type']) || !is_string($mapping['type'])) {
                continue;
            }

            switch ($mapping['type']) {
                case 'field':
                case 'static':
                    $leaf = self::sanitizeLeaf($mapping);
                    if ($leaf !== null) {
                        $clean[$property] = $leaf;
                    }
                    break;

                case self::TYPE_NESTED:
                    $fields = [];
                    foreach (is_array($mapping['fields'] ?? null) ? $mapping['fields'] : [] as $subProperty => $leaf) {
                        if (!is_string($subProperty) || preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $subProperty) !== 1) {
                            continue;
                        }
                        $cleanLeaf = is_array($leaf) ? self::sanitizeLeaf($leaf) : null;
                        if ($cleanLeaf !== null) {
                            $fields[$subProperty] = $cleanLeaf;
                        }
                    }
                    if (count($fields) > 0) {
                        $nested = ['type' => self::TYPE_NESTED, 'fields' => $fields];
                        $objectType = $mapping['object_type'] ?? null;
                        if (is_string($objectType) && preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $objectType) === 1) {
                            $nested['object_type'] = $objectType;
                        }
                        $clean[$property] = $nested;
                    }
                    break;

                case self::TYPE_OPENING_HOURS:
                    $rows = [];
                    foreach (is_array($mapping['rows'] ?? null) ? $mapping['rows'] : [] as $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $days = SchemaHelper::normalizeDayOfWeek(is_array($row['days'] ?? null) || is_string($row['days'] ?? null) ? $row['days'] : null);
                        $opens = is_array($row['opens'] ?? null) ? self::sanitizeLeaf($row['opens']) : null;
                        $closes = is_array($row['closes'] ?? null) ? self::sanitizeLeaf($row['closes']) : null;
                        if (count($days) === 0 || ($opens === null && $closes === null)) {
                            continue;
                        }
                        $cleanRow = ['days' => $days];
                        if ($opens !== null) {
                            $cleanRow['opens'] = $opens;
                        }
                        if ($closes !== null) {
                            $cleanRow['closes'] = $closes;
                        }
                        $rows[] = $cleanRow;
                    }
                    if (count($rows) > 0) {
                        $clean[$property] = ['type' => self::TYPE_OPENING_HOURS, 'rows' => $rows];
                    }
                    break;
            }
        }

        return $clean;
    }

    /**
     * @param array<mixed> $leaf
     * @return array{type: string, value: string}|null
     */
    private static function sanitizeLeaf(array $leaf): ?array
    {
        $type = $leaf['type'] ?? null;
        $value = $leaf['value'] ?? null;
        if (!is_string($type) || !is_scalar($value)) {
            return null;
        }
        $value = (string) $value;

        if ($type === 'field') {
            return preg_match('/^[A-Za-z0-9_]+$/', $value) === 1 ? ['type' => 'field', 'value' => $value] : null;
        }
        if ($type === 'static') {
            return trim($value) !== '' ? ['type' => 'static', 'value' => $value] : null;
        }

        return null;
    }
}
