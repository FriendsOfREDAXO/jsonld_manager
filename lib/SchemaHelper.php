<?php

namespace FriendsOfRedaxo\JsonLdManager;

/**
 * Wiederverwendbare Helfer zum Bau valider Schema.org-Teilobjekte.
 *
 * Alle Methoden liefern fertige Arrays mit "@type", bereinigt um leere Werte.
 * Liefert ein Objekt außer "@type" keine Angaben, wird ein leeres Array
 * zurückgegeben, damit es beim Zusammenbau einfach weggelassen werden kann.
 */
final class SchemaHelper
{
    /** @var array<int, string> */
    public const AVAILABILITY_VALUES = [
        'InStock',
        'OutOfStock',
        'PreOrder',
        'PreSale',
        'BackOrder',
        'Discontinued',
        'SoldOut',
        'LimitedAvailability',
        'OnlineOnly',
        'InStoreOnly',
    ];

    /** @var array<int, string> */
    public const DAYS_OF_WEEK = [
        'Monday',
        'Tuesday',
        'Wednesday',
        'Thursday',
        'Friday',
        'Saturday',
        'Sunday',
    ];

    /** @var array<string, string> */
    private const DAY_ALIASES = [
        'mo' => 'Monday', 'mon' => 'Monday', 'montag' => 'Monday', 'monday' => 'Monday',
        'di' => 'Tuesday', 'tue' => 'Tuesday', 'dienstag' => 'Tuesday', 'tuesday' => 'Tuesday',
        'mi' => 'Wednesday', 'wed' => 'Wednesday', 'mittwoch' => 'Wednesday', 'wednesday' => 'Wednesday',
        'do' => 'Thursday', 'thu' => 'Thursday', 'donnerstag' => 'Thursday', 'thursday' => 'Thursday',
        'fr' => 'Friday', 'fri' => 'Friday', 'freitag' => 'Friday', 'friday' => 'Friday',
        'sa' => 'Saturday', 'sat' => 'Saturday', 'samstag' => 'Saturday', 'sonnabend' => 'Saturday', 'saturday' => 'Saturday',
        'so' => 'Sunday', 'sun' => 'Sunday', 'sonntag' => 'Sunday', 'sunday' => 'Sunday',
        'feiertag' => 'PublicHolidays', 'feiertage' => 'PublicHolidays', 'publicholidays' => 'PublicHolidays',
    ];

    /** @var array<string, string> */
    private const ADDRESS_ALIASES = [
        'street' => 'streetAddress',
        'strasse' => 'streetAddress',
        'straße' => 'streetAddress',
        'zip' => 'postalCode',
        'plz' => 'postalCode',
        'postal_code' => 'postalCode',
        'city' => 'addressLocality',
        'ort' => 'addressLocality',
        'stadt' => 'addressLocality',
        'region' => 'addressRegion',
        'bundesland' => 'addressRegion',
        'country' => 'addressCountry',
        'land' => 'addressCountry',
    ];

    /**
     * Generischer Aufbau eines typisierten Objekts. Leere Werte werden entfernt.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public static function withType(string $type, array $fields): array
    {
        $object = JsonLdGenerator::pruneEmptyValues(array_merge(['@type' => $type], $fields));
        if (!is_array($object) || count($object) <= 1) {
            return [];
        }

        return $object;
    }

    /**
     * @param array<string, mixed> $extra Weitere Offer-Properties (z. B. priceValidUntil, itemCondition)
     * @return array<string, mixed>
     */
    public static function offer(string|float|int|null $price, string $currency = 'EUR', ?string $availability = null, ?string $url = null, array $extra = []): array
    {
        $normalizedPrice = self::normalizePrice($price);
        if ($normalizedPrice === null) {
            return [];
        }

        $fields = [
            'price' => $normalizedPrice,
            'priceCurrency' => strtoupper(trim($currency)) !== '' ? strtoupper(trim($currency)) : 'EUR',
            'availability' => self::normalizeAvailability($availability),
            'url' => $url,
        ];

        return self::withType('Offer', array_merge($extra, $fields));
    }

    /**
     * Normalisiert Preisangaben wie "12,50", "1.234,56 €" oder 12.5 auf "12.50".
     */
    public static function normalizePrice(string|float|int|null $price): ?string
    {
        if ($price === null) {
            return null;
        }
        if (is_int($price) || is_float($price)) {
            return number_format((float) $price, 2, '.', '');
        }

        $raw = trim($price);
        if ($raw === '') {
            return null;
        }

        $cleaned = preg_replace('/[^0-9,.\-]/', '', $raw) ?? '';
        if ($cleaned === '' || $cleaned === '-') {
            return null;
        }

        $lastComma = strrpos($cleaned, ',');
        $lastDot = strrpos($cleaned, '.');
        if ($lastComma !== false && ($lastDot === false || $lastComma > $lastDot)) {
            // Dezimaltrennzeichen ist das Komma, Punkte sind Tausendertrenner
            $cleaned = str_replace('.', '', $cleaned);
            $cleaned = str_replace(',', '.', $cleaned);
        } else {
            $cleaned = str_replace(',', '', $cleaned);
        }

        if (!is_numeric($cleaned)) {
            return null;
        }

        return number_format((float) $cleaned, 2, '.', '');
    }

    /**
     * Akzeptiert "InStock", "https://schema.org/InStock", "in_stock" sowie
     * boolesche Werte ("1"/"0", "ja"/"nein", true/false) und liefert die
     * vollständige Schema.org-URL oder null bei unbekannten Werten.
     */
    public static function normalizeAvailability(string|bool|int|null $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value) || is_int($value)) {
            return 'https://schema.org/' . ((bool) $value ? 'InStock' : 'OutOfStock');
        }

        $raw = trim($value);
        if ($raw === '') {
            return null;
        }

        if (preg_match('#^https?://schema\.org/(\w+)$#i', $raw, $m)) {
            $raw = $m[1];
        }

        $normalized = strtolower(str_replace(['_', '-', ' '], '', $raw));
        foreach (self::AVAILABILITY_VALUES as $candidate) {
            if (strtolower($candidate) === $normalized) {
                return 'https://schema.org/' . $candidate;
            }
        }

        if (in_array($normalized, ['1', 'true', 'ja', 'yes', 'y', 'verfuegbar', 'verfügbar', 'lieferbar', 'available'], true)) {
            return 'https://schema.org/InStock';
        }
        if (in_array($normalized, ['0', 'false', 'nein', 'no', 'n', 'nichtverfuegbar', 'nichtverfügbar', 'ausverkauft', 'unavailable'], true)) {
            return 'https://schema.org/OutOfStock';
        }

        return null;
    }

    /**
     * @param array<int, mixed> $rows Liste von ['dayOfWeek' => string|array, 'opens' => 'HH:MM', 'closes' => 'HH:MM']
     * @return array<int, array<string, mixed>>
     */
    public static function openingHoursSpecification(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $days = self::normalizeDayOfWeek($row['dayOfWeek'] ?? $row['day_of_week'] ?? '');
            $opens = self::normalizeTime($row['opens'] ?? null);
            $closes = self::normalizeTime($row['closes'] ?? null);

            if (count($days) === 0 || ($opens === null && $closes === null)) {
                continue;
            }

            $entry = [
                '@type' => 'OpeningHoursSpecification',
                'dayOfWeek' => count($days) === 1 ? $days[0] : $days,
                'opens' => $opens,
                'closes' => $closes,
            ];
            foreach (['validFrom', 'validThrough'] as $optional) {
                if (isset($row[$optional]) && is_string($row[$optional]) && trim($row[$optional]) !== '') {
                    $entry[$optional] = trim($row[$optional]);
                }
            }

            $pruned = JsonLdGenerator::pruneEmptyValues($entry);
            if (is_array($pruned)) {
                $result[] = $pruned;
            }
        }

        return $result;
    }

    /**
     * Normalisiert Wochentagsangaben ("Mo", "Montag", "Monday", "Mo,Di" oder Arrays)
     * auf die englischen Schema.org-Bezeichner.
     *
     * @param string|array<int, mixed>|null $days
     * @return array<int, string>
     */
    public static function normalizeDayOfWeek(string|array|null $days): array
    {
        if ($days === null) {
            return [];
        }
        if (is_string($days)) {
            $days = explode(',', $days);
        }

        $result = [];
        foreach ($days as $day) {
            if (!is_string($day)) {
                continue;
            }
            $day = trim($day);
            if ($day === '') {
                continue;
            }
            if (preg_match('#^https?://schema\.org/(\w+)$#i', $day, $m)) {
                $day = $m[1];
            }
            $key = mb_strtolower(str_replace(['.', ' '], '', $day));
            $normalized = self::DAY_ALIASES[$key] ?? null;
            if ($normalized === null) {
                continue;
            }
            if (!in_array($normalized, $result, true)) {
                $result[] = $normalized;
            }
        }

        return $result;
    }

    /**
     * Liefert "HH:MM" oder null, wenn der Wert keine Uhrzeit ist.
     */
    public static function normalizeTime(mixed $time): ?string
    {
        if (!is_string($time) && !is_int($time)) {
            return null;
        }
        $time = trim((string) $time);
        if (preg_match('/^([01]?\d|2[0-4])[:.]([0-5]\d)(?::[0-5]\d)?$/', $time, $m)) {
            return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
        }
        if (preg_match('/^([01]?\d|2[0-4])$/', $time, $m)) {
            return sprintf('%02d:00', (int) $m[1]);
        }

        return null;
    }

    /**
     * Baut eine PostalAddress. Neben den Schema.org-Namen werden gängige
     * Aliase (street, zip, city, country, …) akzeptiert.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public static function postalAddress(array $fields): array
    {
        $mapped = [];
        foreach ($fields as $key => $value) {
            $property = self::ADDRESS_ALIASES[strtolower($key)] ?? $key;
            if ($property === '@type') {
                continue;
            }
            // Explizite Schema.org-Namen haben Vorrang vor Alias-Werten
            if (isset($mapped[$property]) && $property !== $key) {
                continue;
            }
            $mapped[$property] = $value;
        }

        return self::withType('PostalAddress', $mapped);
    }

    /**
     * @param array<string, mixed> $fields z. B. telephone, email, contactType, areaServed, availableLanguage
     * @return array<string, mixed>
     */
    public static function contactPoint(array $fields): array
    {
        unset($fields['@type']);

        return self::withType('ContactPoint', $fields);
    }

    /**
     * @param array<string, mixed> $extra Weitere GeoCoordinates-Properties (z. B. elevation)
     * @return array<string, mixed>
     */
    public static function geoCoordinates(string|float|int|null $latitude, string|float|int|null $longitude, array $extra = []): array
    {
        $latitude = is_string($latitude) ? trim($latitude) : $latitude;
        $longitude = is_string($longitude) ? trim($longitude) : $longitude;
        if ($latitude === null || $longitude === null || $latitude === '' || $longitude === '') {
            return [];
        }
        if (!is_numeric($latitude) || !is_numeric($longitude) || (float) $latitude === 0.0 || (float) $longitude === 0.0) {
            return [];
        }
        unset($extra['@type']);

        return array_merge(
            ['@type' => 'GeoCoordinates'],
            $extra,
            [
                'latitude' => $latitude,
                'longitude' => $longitude,
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function aggregateRating(string|float|int|null $ratingValue, string|int|null $reviewCount, string|float|int $bestRating = 5, string|float|int|null $worstRating = null): array
    {
        $ratingValue = is_string($ratingValue) ? str_replace(',', '.', trim($ratingValue)) : $ratingValue;
        $reviewCount = is_string($reviewCount) ? trim($reviewCount) : $reviewCount;

        if ($ratingValue === null || $ratingValue === '' || !is_numeric($ratingValue)) {
            return [];
        }
        if ($reviewCount === null || $reviewCount === '' || !is_numeric($reviewCount) || (int) $reviewCount <= 0) {
            return [];
        }

        $fields = [
            'ratingValue' => (float) $ratingValue,
            'reviewCount' => (int) $reviewCount,
            'bestRating' => is_numeric($bestRating) ? (float) $bestRating : 5.0,
        ];
        if ($worstRating !== null && is_numeric($worstRating)) {
            $fields['worstRating'] = (float) $worstRating;
        }

        return self::withType('AggregateRating', $fields);
    }

    /**
     * @return array<string, mixed>
     */
    public static function brand(?string $name): array
    {
        return self::withType('Brand', ['name' => $name]);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public static function organization(?string $name, ?string $url = null, array $extra = []): array
    {
        unset($extra['@type']);

        return self::withType('Organization', array_merge($extra, ['name' => $name, 'url' => $url]));
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public static function person(?string $name, ?string $url = null, array $extra = []): array
    {
        unset($extra['@type']);

        return self::withType('Person', array_merge($extra, ['name' => $name, 'url' => $url]));
    }

    /**
     * @param array<string, mixed> $addressFields Felder für postalAddress()
     * @param array<string, mixed> $extra Weitere Place-Properties (z. B. url, telephone, geo)
     * @return array<string, mixed>
     */
    public static function place(?string $name, array $addressFields = [], array $extra = []): array
    {
        unset($extra['@type']);
        $fields = array_merge($extra, ['name' => $name]);
        $address = self::postalAddress($addressFields);
        if (count($address) > 0) {
            $fields['address'] = $address;
        }

        return self::withType('Place', $fields);
    }

    /**
     * @return array<string, mixed>
     */
    public static function question(?string $question, ?string $answer): array
    {
        $question = trim((string) $question);
        $answer = trim((string) $answer);
        if ($question === '' || $answer === '') {
            return [];
        }

        return [
            '@type' => 'Question',
            'name' => $question,
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text' => $answer,
            ],
        ];
    }

    /**
     * @param array<int, mixed> $pairs Liste von ['question' => ..., 'answer' => ...]
     * @param array<string, mixed> $extra Weitere FAQPage-Properties (name, description, url, …)
     * @return array<string, mixed> Leeres Array, wenn keine gültigen Paare vorhanden sind
     */
    public static function faqPage(array $pairs, array $extra = []): array
    {
        $questions = [];
        foreach ($pairs as $pair) {
            if (!is_array($pair)) {
                continue;
            }
            $questionText = $pair['question'] ?? $pair['name'] ?? null;
            $answerText = $pair['answer'] ?? $pair['text'] ?? null;
            $question = self::question(is_scalar($questionText) ? (string) $questionText : null, is_scalar($answerText) ? (string) $answerText : null);
            if (count($question) > 0) {
                $questions[] = $question;
            }
        }
        if (count($questions) === 0) {
            return [];
        }

        unset($extra['@type'], $extra['@context'], $extra['mainEntity']);
        $pruned = JsonLdGenerator::pruneEmptyValues($extra);

        return array_merge(
            ['@context' => 'https://schema.org', '@type' => 'FAQPage'],
            is_array($pruned) ? $pruned : [],
            ['mainEntity' => $questions]
        );
    }

    /**
     * @param array<string, mixed>|string $item Vollständiges Objekt oder URL
     * @return array<string, mixed>
     */
    public static function listItem(int $position, array|string $item): array
    {
        $listItem = [
            '@type' => 'ListItem',
            'position' => $position,
        ];
        if (is_string($item)) {
            $listItem['url'] = $item;
        } else {
            $listItem['item'] = $item;
        }

        return $listItem;
    }

    /**
     * @param array<int, array<string, mixed>|string> $items Objekte oder URLs in Listenreihenfolge
     * @param array<string, mixed> $extra Weitere Properties (name, description, url, numberOfItems, itemListOrder)
     * @param string $type "ItemList" oder "CollectionPage"
     * @return array<string, mixed> Leeres Array, wenn keine Einträge vorhanden sind
     */
    public static function itemList(array $items, array $extra = [], string $type = 'ItemList'): array
    {
        $elements = [];
        $position = 1;
        foreach ($items as $item) {
            if (is_array($item) && count($item) === 0) {
                continue;
            }
            if (is_string($item) && trim($item) === '') {
                continue;
            }
            $elements[] = self::listItem($position, $item);
            ++$position;
        }
        if (count($elements) === 0) {
            return [];
        }

        unset($extra['@type'], $extra['@context'], $extra['itemListElement'], $extra['mainEntity']);
        $pruned = JsonLdGenerator::pruneEmptyValues($extra);
        $schema = array_merge(
            ['@context' => 'https://schema.org', '@type' => $type],
            is_array($pruned) ? $pruned : []
        );

        if ($type === 'CollectionPage') {
            $schema['mainEntity'] = [
                '@type' => 'ItemList',
                'numberOfItems' => count($elements),
                'itemListElement' => $elements,
            ];
        } else {
            $schema['numberOfItems'] = count($elements);
            $schema['itemListElement'] = $elements;
        }

        return $schema;
    }
}
