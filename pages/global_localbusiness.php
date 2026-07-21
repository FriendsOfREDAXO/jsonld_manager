<?php
/**
 * JSON-LD Manager - LocalBusiness Schema (Multi-Branch Support)
 */
use FriendsOfRedaxo\JsonLdManager\LanguageConfig;
use FriendsOfRedaxo\JsonLdManager\DomainConfig;
use FriendsOfRedaxo\JsonLdManager\CustomJsonLdHelper;

$func = rex_request('func', 'string', '');
$lbAction = rex_post('lb_action', 'string', '');
$branchId = rex_request('branch_id', 'int', 0);
$activeClangId = LanguageConfig::getActiveClangId();
$activeDomainId = DomainConfig::getActiveDomainId();
$csrfToken = rex_csrf_token::factory('jsonld_manager_global_localbusiness');
$csrfTokenField = $csrfToken->getHiddenField();

// Website-URL basierend auf aktiver Domain ermitteln
function getWebsiteUrlForDomain(?int $domainId = null): string {
    if (DomainConfig::isMultiDomain() && $domainId) {
        $activeDomain = DomainConfig::getActiveDomain();
        if ($activeDomain && isset($activeDomain['domain'])) {
            $domain = (string) $activeDomain['domain'];
            // Prüfen ob Domain bereits Protokoll enthält
            if (strpos($domain, 'http') !== 0) {
                // Prüfen ob https oder http
                $protocol = (strpos($domain, 'local') !== false) ? 'http://' : 'https://';
                return rtrim($protocol . $domain, '/');
            }
            return rtrim($domain, '/');
        }
    }
    return rex::getServer();
}

$selectedBranch = null;
$message = '';

// Neue Branch-Actions
if ($lbAction === 'create_branch') {
    if (!$csrfToken->isValid()) {
        $message .= rex_view::error('Sicherheitsprüfung fehlgeschlagen (CSRF). Bitte Seite neu laden.');
    } else {
    $branchName = trim(rex_post('branch_name', 'string', ''));
    if (!empty($branchName)) {
        $sql = rex_sql::factory();
        $sql->setTable(rex::getTable('jsonld_localbusiness_branches'));
        $branchValues = [
            'branch_name' => $branchName,
            'clang_id' => $activeClangId,
            'is_main_branch' => 0,
            'sort_order' => 100,
            'config' => '{}',
            'created' => date('Y-m-d H:i:s'),
            'modified' => date('Y-m-d H:i:s')
        ];
        
        // Nur domain_id setzen wenn Spalte existiert
        $checkDomainColumn = rex_sql::factory();
        $checkDomainColumn->setQuery('SHOW COLUMNS FROM ' . rex::getTable('jsonld_localbusiness_branches') . ' LIKE "domain_id"');
        if ($checkDomainColumn->getRows() > 0 && DomainConfig::isMultiDomain()) {
            $branchValues['domain_id'] = $activeDomainId;
        }
        
        $sql->setValues($branchValues);
        $sql->insert();
        $message .= rex_view::success('Neuer Standort "' . htmlspecialchars($branchName) . '" wurde erstellt.');
        $branchId = $sql->getLastId();
    }
    }
} elseif ($lbAction === 'delete_branch' && $branchId > 0) {
    if (!$csrfToken->isValid()) {
        $message .= rex_view::error('Sicherheitsprüfung fehlgeschlagen (CSRF). Bitte Seite neu laden.');
    } else {
    $sql = rex_sql::factory();
    $whereClause = DomainConfig::isMultiDomain() 
        ? 'WHERE id = ? AND clang_id = ? AND domain_id = ?'
        : 'WHERE id = ? AND clang_id = ?';
    $params = DomainConfig::isMultiDomain() 
        ? [$branchId, $activeClangId, $activeDomainId]
        : [$branchId, $activeClangId];
    
    $sql->setQuery('SELECT branch_name, is_main_branch FROM ' . rex::getTable('jsonld_localbusiness_branches') . ' ' . $whereClause, $params);
    if ($sql->getRows() > 0) {
        if ($sql->getValue('is_main_branch')) {
            $message .= rex_view::error('Der Hauptstandort kann nicht gelöscht werden. Setzen Sie zuerst einen anderen Standort als Hauptstandort.');
        } else {
            $branchName = $sql->getValue('branch_name');
            $deleteSql = rex_sql::factory();
            $whereClause = DomainConfig::isMultiDomain() 
                ? 'WHERE id = ? AND clang_id = ? AND domain_id = ?'
                : 'WHERE id = ? AND clang_id = ?';
            $params = DomainConfig::isMultiDomain() 
                ? [$branchId, $activeClangId, $activeDomainId]
                : [$branchId, $activeClangId];
            
            $deleteSql->setQuery('DELETE FROM ' . rex::getTable('jsonld_localbusiness_branches') . ' ' . $whereClause, $params);
            $message .= rex_view::success('Standort "' . htmlspecialchars((string) $branchName) . '" wurde gelöscht.');
            $branchId = 0;
        }
    }
    }
} elseif ($lbAction === 'set_main_branch' && $branchId > 0) {
    if (!$csrfToken->isValid()) {
        $message .= rex_view::error('Sicherheitsprüfung fehlgeschlagen (CSRF). Bitte Seite neu laden.');
    } else {
    $sql = rex_sql::factory();

    $checkDomainColumnSetMain = rex_sql::factory();
    $checkDomainColumnSetMain->setQuery('SHOW COLUMNS FROM ' . rex::getTable('jsonld_localbusiness_branches') . ' LIKE "domain_id"');
    $hasDomainColumnSetMain = $checkDomainColumnSetMain->getRows() > 0;

    if ($hasDomainColumnSetMain && DomainConfig::isMultiDomain()) {
        // Alle als Nicht-Hauptstandort setzen (nur aktive Domain)
        $sql->setQuery('UPDATE ' . rex::getTable('jsonld_localbusiness_branches') . ' SET is_main_branch = 0 WHERE clang_id = ? AND (domain_id = ? OR domain_id IS NULL)', [$activeClangId, $activeDomainId]);
        // Gewählte als Hauptstandort setzen (nur aktive Domain)
        $sql->setQuery('UPDATE ' . rex::getTable('jsonld_localbusiness_branches') . ' SET is_main_branch = 1 WHERE id = ? AND clang_id = ? AND (domain_id = ? OR domain_id IS NULL)', [$branchId, $activeClangId, $activeDomainId]);
    } else {
        // Alle als Nicht-Hauptstandort setzen
        $sql->setQuery('UPDATE ' . rex::getTable('jsonld_localbusiness_branches') . ' SET is_main_branch = 0 WHERE clang_id = ?', [$activeClangId]);
        // Gewählte als Hauptstandort setzen
        $sql->setQuery('UPDATE ' . rex::getTable('jsonld_localbusiness_branches') . ' SET is_main_branch = 1 WHERE id = ? AND clang_id = ?', [$branchId, $activeClangId]);
    }

    $message .= rex_view::success('Hauptstandort wurde aktualisiert.');
    }
}

// Migration: Bestehende LocalBusiness Config in ersten Standort übertragen
function migrateExistingLocalBusinessToFirstBranch(int $activeClangId): bool {
    $existingConfig = rex_config::get('jsonld_manager', 'localbusiness_schema', []);
    if (!is_array($existingConfig)) {
        return false;
    }
    if (!empty($existingConfig['name'])) {
        $sql = rex_sql::factory();
        $sql->setQuery('SELECT COUNT(*) as count FROM ' . rex::getTable('jsonld_localbusiness_branches') . ' WHERE clang_id = ?', [$activeClangId]);
        if ($sql->getValue('count') == 0) {
            // Ersten Standort mit bestehenden Daten anlegen
            $sql->setTable(rex::getTable('jsonld_localbusiness_branches'));
            $sql->setValues([
                'branch_name' => 'Hauptstandort ' . ($existingConfig['streetAddress'] ?: $existingConfig['name']),
                'clang_id' => $activeClangId,
                'is_main_branch' => 1,
                'sort_order' => 1,
                'config' => json_encode($existingConfig),
                'created' => date('Y-m-d H:i:s'),
                'modified' => date('Y-m-d H:i:s')
            ]);
            $sql->insert();
            return true;
        }
    }
    return false;
}
migrateExistingLocalBusinessToFirstBranch($activeClangId);

/**
 * Öffnungszeiten aus be_table-Zeilen in Schema.org-Format umwandeln.
 *
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, array<string, mixed>>
 */
function jsonld_manager_normalize_opening_hours(array $rows): array
{
    $dayMap = [
        'monday' => 'Monday',
        'montag' => 'Monday',
        'mo' => 'Monday',
        'tuesday' => 'Tuesday',
        'dienstag' => 'Tuesday',
        'di' => 'Tuesday',
        'wednesday' => 'Wednesday',
        'mittwoch' => 'Wednesday',
        'mi' => 'Wednesday',
        'thursday' => 'Thursday',
        'donnerstag' => 'Thursday',
        'do' => 'Thursday',
        'friday' => 'Friday',
        'freitag' => 'Friday',
        'fr' => 'Friday',
        'saturday' => 'Saturday',
        'samstag' => 'Saturday',
        'sa' => 'Saturday',
        'sunday' => 'Sunday',
        'sonntag' => 'Sunday',
        'so' => 'Sunday',
    ];
    $result = [];

    foreach ($rows as $row) {
        $dayRaw = trim((string) ($row['day_of_week'] ?? ''));
        $opens = trim((string) ($row['opens'] ?? ''));
        $closes = trim((string) ($row['closes'] ?? ''));

        if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', $opens, $m)) {
            $opens = sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
        } else {
            $opens = '';
        }

        if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', $closes, $m)) {
            $closes = sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
        } else {
            $closes = '';
        }

        if ($dayRaw === '' || $opens === '' || $closes === '') {
            continue;
        }

        $daysInput = array_values(array_filter(array_map('trim', explode(',', $dayRaw))));
        $days = [];
        foreach ($daysInput as $dayInput) {
            $key = mb_strtolower($dayInput);
            $days[] = $dayMap[$key] ?? $dayInput;
        }
        if (count($days) === 0) {
            continue;
        }

        $result[] = [
            '@type' => 'OpeningHoursSpecification',
            'dayOfWeek' => count($days) === 1 ? $days[0] : $days,
            'opens' => $opens,
            'closes' => $closes,
        ];
    }

    return $result;
}

/**
 * Öffnungszeiten direkt aus be_table-POST-Feldern extrahieren.
 *
 * Erwartete Feldnamen-Muster:
 * - day_of_week{row}{col}
 * - opens{row}{col}
 * - closes{row}{col}
 *
 * @param array<string, mixed> $postData
 * @return array<int, array{day_of_week: string, opens: string, closes: string}>
 */
function jsonld_manager_extract_opening_hours_rows_from_post(array $postData): array
{
    $flat = [];

    $walker = static function ($value, $key) use (&$flat): void {
        if (is_scalar($value) || $value === null) {
            $flat[(string) $key] = (string) $value;
        }
    };
    array_walk_recursive($postData, $walker);

    $rows = [];
    foreach ($flat as $key => $value) {
        if (preg_match('/^(day_of_week|opens|closes)(\d+)(?:[0-2])?$/', $key, $m)) {
            $field = $m[1];
            $rowIndex = (int) $m[2];
        } elseif (preg_match('/^(day_of_week|opens|closes).*?(\d+)$/', $key, $m)) {
            $field = $m[1];
            $rowIndex = (int) $m[2];
        } else {
            continue;
        }

        if (!isset($rows[$rowIndex])) {
            $rows[$rowIndex] = [
                'day_of_week' => '',
                'opens' => '',
                'closes' => '',
            ];
        }

        $rows[$rowIndex][$field] = trim($value);
    }

    ksort($rows);
    return array_values($rows);
}

/**
 * Öffnungszeiten direkt aus der YForm-POST-Struktur extrahieren.
 *
 * Erwartet Daten unter:
 * $_POST['FORM']['jsonld_lb_opening_hours'][<fieldId>][<rowIndex>][<colIndex>]
 *
 * @param array<string, mixed> $postData
 * @return array<int, array{day_of_week: string, opens: string, closes: string}>
 */
function jsonld_manager_extract_opening_hours_rows_from_yform_post(array $postData): array
{
    $root = $postData['FORM']['jsonld_lb_opening_hours'] ?? null;
    if (!is_array($root)) {
        return [];
    }

    foreach ($root as $candidate) {
        if (!is_array($candidate)) {
            continue;
        }

        $rows = [];
        foreach ($candidate as $row) {
            if (!is_array($row)) {
                continue;
            }

            $parsed = [
                'day_of_week' => '',
                'opens' => '',
                'closes' => '',
            ];

            foreach ($row as $k => $v) {
                if (!is_scalar($v) && $v !== null) {
                    continue;
                }
                $key = (string) $k;
                $value = trim((string) $v);
                if (str_contains($key, 'day_of_week')) {
                    $parsed['day_of_week'] = $value;
                } elseif (str_contains($key, 'opens')) {
                    $parsed['opens'] = $value;
                } elseif (str_contains($key, 'closes')) {
                    $parsed['closes'] = $value;
                }
            }

            if ($parsed['day_of_week'] === '' && $parsed['opens'] === '' && $parsed['closes'] === '') {
                $values = array_values($row);
                $parsed = [
                    'day_of_week' => trim((string) ($values[0] ?? '')),
                    'opens' => trim((string) ($values[1] ?? '')),
                    'closes' => trim((string) ($values[2] ?? '')),
                ];
            }

            if ($parsed['day_of_week'] !== '' || $parsed['opens'] !== '' || $parsed['closes'] !== '') {
                $rows[] = $parsed;
            }
        }

        if (count($rows) > 0) {
            return $rows;
        }
    }

    return [];
}

/**
 * Fallback: rekursiv mögliche Öffnungszeiten-Zeilen aus beliebiger FORM-Struktur extrahieren.
 *
 * @param array<string, mixed> $postData
 * @return array<int, array{day_of_week: string, opens: string, closes: string}>
 */
function jsonld_manager_extract_opening_hours_rows_from_form_deep(array $postData): array
{
    $rows = [];

    $addRow = static function (array $row) use (&$rows): void {
        $candidate = [
            'day_of_week' => trim((string) ($row['day_of_week'] ?? '')),
            'opens' => trim((string) ($row['opens'] ?? '')),
            'closes' => trim((string) ($row['closes'] ?? '')),
        ];

        if ($candidate['day_of_week'] === '' && $candidate['opens'] === '' && $candidate['closes'] === '') {
            return;
        }

        $rows[] = $candidate;
    };

    $walker = null;
    $walker = static function ($node) use (&$walker, $addRow): void {
        if (!is_array($node)) {
            return;
        }

        // Fall 1: Assoziative Struktur mit direkten Keys
        if (array_key_exists('day_of_week', $node) || array_key_exists('opens', $node) || array_key_exists('closes', $node)) {
            $addRow($node);
        }

        // Fall 2: Numerische Struktur wie [0 => day, 1 => opens, 2 => closes]
        $values = array_values($node);
        if (count($values) >= 3 && !is_array($values[0]) && !is_array($values[1]) && !is_array($values[2])) {
            $addRow([
                'day_of_week' => (string) $values[0],
                'opens' => (string) $values[1],
                'closes' => (string) $values[2],
            ]);
        }

        foreach ($node as $child) {
            if (is_array($child)) {
                $walker($child);
            }
        }
    };

    if (isset($postData['FORM']) && is_array($postData['FORM'])) {
        $walker($postData['FORM']);
    } else {
        $walker($postData);
    }

    // Duplikate entfernen
    $unique = [];
    foreach ($rows as $row) {
        $encodedRow = json_encode($row, JSON_UNESCAPED_UNICODE);
        if (!is_string($encodedRow)) {
            continue;
        }
        $key = md5($encodedRow);
        $unique[$key] = $row;
    }

    return array_values($unique);
}

/**
 * Komma- und/oder zeilengetrennte Eingabe in eindeutiges Array umwandeln.
 *
 * @param string $input
 * @return array<int, string>
 */
function jsonld_manager_parse_list_input(string $input): array
{
    $parts = preg_split('/[\r\n,]+/', $input) ?: [];
    $parts = array_values(array_filter(array_map('trim', $parts)));
    return array_values(array_unique($parts));
}

/**
 * be_table-Konfiguration für Öffnungszeiten erzeugen.
 *
 * @param array<int, array{day_of_week: string, opens: string, closes: string}> $rows
 * @param bool $withFixdata
 * @return rex_yform
 */
function jsonld_manager_create_opening_hours_yform(array $rows, bool $withFixdata = true): rex_yform
{
    $yform = new rex_yform();
    $yform->setObjectparams('form_name', 'jsonld_lb_opening_hours');
    $yform->setObjectparams('form_action', rex_url::currentBackendPage());
    $yform->setObjectparams('submit_btn_show', false);
    $yform->setObjectparams('form_showformafterupdate', 1);

    if ($withFixdata) {
        $yform->setObjectparams('fixdata', [
            'opening_hours_spec' => json_encode($rows, JSON_UNESCAPED_UNICODE),
        ]);
    }

    $yform->setValueField('be_table', [
        'opening_hours_spec',
        'Öffnungszeiten',
        'text|day_of_week|Wochentag(e) ​(EN, kommasepariert oder einzeln),text|opens|Öffnet (HH:MM),text|closes|Schließt (HH:MM)',
        'Beispiele Wochentage: Monday, Tuesday, Wednesday, Thursday, Friday, Saturday, Sunday. Beispiel Eingabe: Monday,Tuesday | Öffnet: 11:30 | Schließt: 22:00 (Format HH:MM).',
    ]);

    return $yform;
}

// === SPEICHERN (Branch-spezifisch) ===
if ($lbAction === 'save' && $branchId <= 0) {
    $message .= rex_view::error('Bitte legen Sie zuerst einen Standort an oder wählen Sie einen Standort aus, bevor Sie speichern.');
} elseif ($lbAction === 'save' && $branchId > 0) {
    if (!$csrfToken->isValid()) {
        $message .= rex_view::error('Sicherheitsprüfung fehlgeschlagen (CSRF). Bitte Seite neu laden.');
    } else {
    // Branch-spezifisches Speichern
    $coordinates = rex_post('lb_coordinates_sync', 'string', rex_post('lb_coordinates', 'string', ''));
    $latitude = '';
    $longitude = '';
    
    if (!empty($coordinates)) {
        $coords = array_map('trim', explode(',', $coordinates));
        if (count($coords) === 2) {
            $latitude = $coords[0];
            $longitude = $coords[1];
        }
    }
    
    // Branch-Name aktualisieren falls geändert
    $branchName = rex_post('branch_name', 'string', '');
    if (!empty($branchName)) {
        $branchUpdateSql = rex_sql::factory();
        $branchUpdateSql->setQuery('UPDATE ' . rex::getTable('jsonld_localbusiness_branches') . ' SET branch_name = ?, modified = ? WHERE id = ? AND clang_id = ?', [$branchName, date('Y-m-d H:i:s'), $branchId, $activeClangId]);
    }
    
    $name = rex_post('lb_name', 'string', '');
    $imagesRaw = rex_post('lb_images', 'string', '');
    $images = implode(',', array_filter(array_map('trim', explode(',', $imagesRaw))));
    $knowsLanguage = jsonld_manager_parse_list_input(rex_post('lb_knows_language', 'string', ''));
    $contactPointAreaServedList = jsonld_manager_parse_list_input(rex_post('lb_contactpoint_area_served', 'string', ''));
    $contactPointLanguageList = jsonld_manager_parse_list_input(rex_post('lb_contactpoint_language', 'string', ''));
    $contactPointAreaServed = '';
    $contactPointLanguage = '';
    if (count($contactPointAreaServedList) === 1) {
        $contactPointAreaServed = $contactPointAreaServedList[0];
    } elseif (count($contactPointAreaServedList) > 1) {
        $contactPointAreaServed = $contactPointAreaServedList;
    }

    if (count($contactPointLanguageList) === 1) {
        $contactPointLanguage = $contactPointLanguageList[0];
    } elseif (count($contactPointLanguageList) > 1) {
        $contactPointLanguage = $contactPointLanguageList;
    }
    $contactPoint = [
        'telephone' => rex_post('lb_contactpoint_phone', 'string', ''),
        'email' => rex_post('lb_contactpoint_email', 'string', ''),
        'contactType' => rex_post('lb_contactpoint_type', 'string', ''),
        'availableLanguage' => $contactPointLanguage,
        'areaServed' => $contactPointAreaServed,
    ];
    $contactPoint = array_filter($contactPoint, static function ($value): bool {
        if (is_array($value)) {
            return true;
        }

        return trim($value) !== '';
    });

    $customRaw = rex_post('lb_custom_jsonld_raw', 'string', '');
    $customParse = CustomJsonLdHelper::parseCustomObject($customRaw);
    if (!empty($customParse['errors'])) {
        $message .= rex_view::error(implode(' ', $customParse['errors']));
    }

    // Bestehende Konfiguration der Standort laden
    $branchSql = rex_sql::factory();
    $branchSql->setQuery('SELECT config FROM ' . rex::getTable('jsonld_localbusiness_branches') . ' WHERE id = ? AND clang_id = ?', [$branchId, $activeClangId]);
    $configBeforeSave = [];
    if ($branchSql->getRows() > 0) {
        $configRaw = $branchSql->getValue('config');
        $configBeforeSave = is_string($configRaw) ? (json_decode($configRaw, true) ?: []) : [];
    }
    
    $openingHoursSpecBeforeSave = $configBeforeSave['openingHoursSpecification'] ?? [];
    if (is_string($openingHoursSpecBeforeSave)) {
        $openingHoursSpecBeforeSave = json_decode($openingHoursSpecBeforeSave, true) ?: [];
    }
    if (!is_array($openingHoursSpecBeforeSave)) {
        $openingHoursSpecBeforeSave = [];
    }
    $openingHoursRowsBeforeSave = [];
    foreach ($openingHoursSpecBeforeSave as $specRow) {
        $days = $specRow['dayOfWeek'] ?? '';
        if (is_array($days)) {
            $days = implode(',', $days);
        }
        $openingHoursRowsBeforeSave[] = [
            'day_of_week' => (string) $days,
            'opens' => (string) ($specRow['opens'] ?? ''),
            'closes' => (string) ($specRow['closes'] ?? ''),
        ];
    }

    $openingHoursRows = [];
    $openingHoursJsonFallback = isset($_POST['lb_opening_hours_json']) ? (string) $_POST['lb_opening_hours_json'] : '';
    if ($openingHoursJsonFallback !== '') {
        $decodedRows = json_decode($openingHoursJsonFallback, true);
        if (is_array($decodedRows)) {
            $openingHoursRows = $decodedRows;
        }
    }

    if (count($openingHoursRows) === 0) {
        $openingHoursRowsFromYformPost = jsonld_manager_extract_opening_hours_rows_from_yform_post($_POST);
        if (count($openingHoursRowsFromYformPost) > 0) {
            $openingHoursRows = $openingHoursRowsFromYformPost;
        } else {
            $openingHoursRowsFromPost = jsonld_manager_extract_opening_hours_rows_from_post($_POST);
            if (count($openingHoursRowsFromPost) > 0) {
                $openingHoursRows = $openingHoursRowsFromPost;
            } else {
                $openingHoursRowsFromDeepForm = jsonld_manager_extract_opening_hours_rows_from_form_deep($_POST);
                if (count($openingHoursRowsFromDeepForm) > 0) {
                    $openingHoursRows = $openingHoursRowsFromDeepForm;
                } else {
                    $openingHoursYformSave = jsonld_manager_create_opening_hours_yform($openingHoursRowsBeforeSave, false);
                    $openingHoursYformSave->getForm();
                    $openingHoursJson = (string) ($openingHoursYformSave->objparams['value_pool']['sql']['opening_hours_spec'] ?? '[]');
                    $openingHoursRows = json_decode($openingHoursJson, true) ?: [];
                }
            }
        }
    }
    
    $normalizedOpeningHours = jsonld_manager_normalize_opening_hours((array) $openingHoursRows);

    $config = [
        // 'enabled' => $enabled, // Entfernt da Status-Funktion nicht mehr benötigt
        'name' => $name,
        'businessType' => rex_post('lb_type', 'string', ''),
        'images' => $images,
        'priceRange' => rex_post('lb_price_range', 'string', ''),
        'telephone' => rex_post('lb_contactpoint_phone', 'string', ''),
        'slogan' => rex_post('lb_slogan', 'string', ''),
        'streetAddress' => rex_post('lb_street_address', 'string', ''),
        'postalCode' => rex_post('lb_postal_code', 'string', ''),
        'addressLocality' => rex_post('lb_address_locality', 'string', ''),
        'addressCountry' => rex_post('lb_address_country', 'string', ''),
        'hasMap' => rex_post('lb_has_map', 'string', ''),
        'areaServed' => rex_post('lb_area_served', 'string', ''),
        'paymentAccepted' => rex_post('lb_payment_accepted', 'string', ''),
        'currenciesAccepted' => rex_post('lb_currencies_accepted', 'string', ''),
        'knowsLanguage' => $knowsLanguage,
        'contactPoint' => $contactPoint,
        'geo' => [
            'latitude' => $latitude,
            'longitude' => $longitude
        ],
        'openingHoursSpecification' => $normalizedOpeningHours,
        'coordinates' => $coordinates,
        'custom_jsonld_raw' => $customParse['raw'],
        'custom_jsonld' => $customParse['data'],
    ];

    if (empty($customParse['errors'])) {
        // In Branch-Datenbank speichern
        $saveSql = rex_sql::factory();
        $saveSql->setQuery('UPDATE ' . rex::getTable('jsonld_localbusiness_branches') . ' SET config = ?, modified = ? WHERE id = ? AND clang_id = ?', [
            json_encode($config),
            date('Y-m-d H:i:s'),
            $branchId,
            $activeClangId
        ]);

        $message .= rex_view::success('LocalBusiness Schema für Standort wurde gespeichert.');
        if (!empty($customParse['warnings'])) {
            $message .= rex_view::warning(implode('<br>', array_map('htmlspecialchars', $customParse['warnings'])));
        }
    }
    }
}

// Alle Standorte laden (domain-spezifisch)
$branchesQuery = rex_sql::factory();

// Prüfe ob domain_id Spalte existiert
$checkDomainColumn = rex_sql::factory();
$checkDomainColumn->setQuery('SHOW COLUMNS FROM ' . rex::getTable('jsonld_localbusiness_branches') . ' LIKE "domain_id"');
$hasDomainColumn = $checkDomainColumn->getRows() > 0;

if ($hasDomainColumn && DomainConfig::isMultiDomain()) {
    $branchesQuery->setQuery('SELECT * FROM ' . rex::getTable('jsonld_localbusiness_branches') . ' WHERE clang_id = ? AND (domain_id = ? OR domain_id IS NULL) ORDER BY is_main_branch DESC, sort_order ASC, branch_name ASC', [$activeClangId, $activeDomainId]);
} else {
    $branchesQuery->setQuery('SELECT * FROM ' . rex::getTable('jsonld_localbusiness_branches') . ' WHERE clang_id = ? ORDER BY is_main_branch DESC, sort_order ASC, branch_name ASC', [$activeClangId]);
}
$branches = $branchesQuery->getArray();

// Standard: Ersten Standort auswählen wenn keine spezifische Auswahl vorliegt
if ($branchId === 0 && !empty($branches)) {
    // Hauptstandort als Standard wählen
    foreach ($branches as $branch) {
        if ($branch['is_main_branch']) {
            $branchId = $branch['id'];
            break;
        }
    }
    // Fallback: Erster verfügbarer Standort
    if ($branchId === 0) {
        $branchId = $branches[0]['id'];
    }
}

// Ausgewählten Standort laden
$localBusinessConfig = [];
if ($branchId > 0) {
    foreach ($branches as $branch) {
        if ($branch['id'] == $branchId) {
            $selectedBranch = $branch;
            $branchConfigRaw = $branch['config'] ?? null;
            $localBusinessConfig = is_string($branchConfigRaw) ? (json_decode($branchConfigRaw, true) ?: []) : [];
            break;
        }
    }
}

$isFormLocked = $selectedBranch === null;
if (empty($localBusinessConfig['images']) && !empty($localBusinessConfig['image'])) {
    $localBusinessConfig['images'] = (string) $localBusinessConfig['image'];
}

// Öffnungszeiten (be_table) vorbereiten
$openingHoursSpec = $localBusinessConfig['openingHoursSpecification'] ?? [];
if (is_string($openingHoursSpec)) {
    $openingHoursSpec = json_decode($openingHoursSpec, true) ?: [];
}
if (!is_array($openingHoursSpec)) {
    $openingHoursSpec = [];
}

$openingHoursRows = [];
foreach ($openingHoursSpec as $specRow) {
    $days = $specRow['dayOfWeek'] ?? '';
    if (is_array($days)) {
        $days = implode(',', $days);
    }
    $openingHoursRows[] = [
        'day_of_week' => (string) $days,
        'opens' => (string) ($specRow['opens'] ?? ''),
        'closes' => (string) ($specRow['closes'] ?? ''),
    ];
}

$openingHoursYform = jsonld_manager_create_opening_hours_yform($openingHoursRows, true);
$openingHoursForm = $openingHoursYform->getForm();
$openingHoursForm = preg_replace('~</?form\b[^>]*>~i', '', (string) $openingHoursForm);

$openingHoursSpecPreview = $localBusinessConfig['openingHoursSpecification'] ?? [];
if (is_string($openingHoursSpecPreview)) {
    $openingHoursSpecPreview = json_decode($openingHoursSpecPreview, true) ?: [];
}
if (!is_array($openingHoursSpecPreview)) {
    $openingHoursSpecPreview = [];
}

// Message ausgeben falls vorhanden
echo $message;

ob_start();

echo LanguageConfig::renderClangTabs($activeClangId);

$baseUrlJs = htmlspecialchars(getWebsiteUrlForDomain($activeDomainId), ENT_QUOTES);

// Live JSON-LD Generator JavaScript
echo '<style>
.jsonld-preview-col {
    position: relative;
}
.jsonld-preview-sticky {
    position: -webkit-sticky;
    position: sticky;
    top: 20px;
    box-sizing: border-box;
    max-height: calc(100vh - 40px);
    overflow-y: auto;
    overflow-x: hidden;
    overscroll-behavior: contain;
    padding-right: 8px;
}
#json-preview {
    min-height: 260px !important;
    max-height: calc(100vh - 170px);
    overflow: auto !important;
    margin-bottom: 12px;
}
</style>
<script>
// Domain-spezifische Base-URL für JavaScript
const baseUrl = "' . $baseUrlJs . '";
const mediaBaseUrl = baseUrl + "/media/";
const hasEditableBranch = ' . ($isFormLocked ? 'false' : 'true') . ';

function updateJSON() {
    if (!hasEditableBranch) {
        document.getElementById("json-preview").textContent = "Bitte zuerst einen Standort anlegen. Danach werden Formular und Vorschau aktiviert.";
        return;
    }

    const name = document.getElementById("lb_name").value;
    
    // Wenn kein Geschäftsname angegeben ist, JSON leer lassen
    if (!name || name.trim() === "") {
        document.getElementById("json-preview").textContent = "Geben Sie einen Geschäftsnamen ein, um das JSON-LD Schema zu generieren...";
        return;
    }
    
    const type = document.getElementById("lb_type").value;
    const imagesRaw = document.querySelector("input[name=lb_images]") ? document.querySelector("input[name=lb_images]").value : "";
    const imageFiles = imagesRaw.split(",").map(function(file) { return file.trim(); }).filter(Boolean);
    const priceRange = document.getElementById("lb_price_range").value;
    const coordinates = document.getElementById("lb_coordinates").value;
    const knowsLanguageRaw = document.getElementById("lb_knows_language") ? document.getElementById("lb_knows_language").value : "";
    const slogan = document.getElementById("lb_slogan") ? document.getElementById("lb_slogan").value : "";
    const hasMap = document.getElementById("lb_has_map") ? document.getElementById("lb_has_map").value : "";
    const areaServed = document.getElementById("lb_area_served") ? document.getElementById("lb_area_served").value : "";
    const paymentAccepted = document.getElementById("lb_payment_accepted") ? document.getElementById("lb_payment_accepted").value : "";
    const currenciesAccepted = document.getElementById("lb_currencies_accepted") ? document.getElementById("lb_currencies_accepted").value : "";
    const contactPointPhone = document.getElementById("lb_contactpoint_phone") ? document.getElementById("lb_contactpoint_phone").value : "";
    const contactPointEmail = document.getElementById("lb_contactpoint_email") ? document.getElementById("lb_contactpoint_email").value : "";
    const contactPointType = document.getElementById("lb_contactpoint_type") ? document.getElementById("lb_contactpoint_type").value : "";
    const contactPointLanguage = document.getElementById("lb_contactpoint_language") ? document.getElementById("lb_contactpoint_language").value : "";
    const contactPointAreaServed = document.getElementById("lb_contactpoint_area_served") ? document.getElementById("lb_contactpoint_area_served").value : "";
    const customRaw = document.getElementById("lb_custom_jsonld_raw") ? document.getElementById("lb_custom_jsonld_raw").value : "";
    const contactPointLanguageList = contactPointLanguage.split(/[\n,]+/).map(function(v) { return v.trim(); }).filter(Boolean);
    const contactPointAreaServedList = contactPointAreaServed.split(/[\n,]+/).map(function(v) { return v.trim(); }).filter(Boolean);
    
    // Adressfelder für LocalBusiness
    const streetAddress = document.getElementById("lb_street_address") ? document.getElementById("lb_street_address").value : "";
    const postalCode = document.getElementById("lb_postal_code") ? document.getElementById("lb_postal_code").value : "";
    const addressLocality = document.getElementById("lb_address_locality") ? document.getElementById("lb_address_locality").value : "";
    const addressCountry = document.getElementById("lb_address_country") ? document.getElementById("lb_address_country").value : "";
    
    let jsonld = {
        "@context": "https://schema.org",
        "@type": type || "LocalBusiness",
    };
    
    if (name) jsonld.name = name;
    if (imageFiles.length) {
        jsonld.image = imageFiles.map(function(file) {
            return mediaBaseUrl + encodeURIComponent(file).replace(/%2F/g, "/");
        });
    }
    if (priceRange) jsonld.priceRange = priceRange;
    
    if (coordinates) {
        const coords = coordinates.split(",");
        if (coords.length === 2) {
            jsonld.geo = {
                "@type": "GeoCoordinates",
                "latitude": coords[0].trim(),
                "longitude": coords[1].trim()
            };
        }
    }
    
    // Telefonnummer wird zentral im ContactPoint-Feld gepflegt
    const phone = contactPointPhone;
    
    if (phone) jsonld.telephone = phone;
    if (slogan) jsonld.slogan = slogan;
    if (hasMap) jsonld.hasMap = hasMap;
    if (areaServed) jsonld.areaServed = areaServed;
    if (paymentAccepted) jsonld.paymentAccepted = paymentAccepted;
    if (currenciesAccepted) jsonld.currenciesAccepted = currenciesAccepted;

    if (contactPointPhone || contactPointEmail || contactPointType || contactPointLanguage || contactPointAreaServed) {
        jsonld.contactPoint = {
            "@type": "ContactPoint"
        };
        if (contactPointPhone) jsonld.contactPoint.telephone = contactPointPhone;
        if (contactPointEmail) jsonld.contactPoint.email = contactPointEmail;
        if (contactPointType) jsonld.contactPoint.contactType = contactPointType;
        if (contactPointLanguageList.length === 1) {
            jsonld.contactPoint.availableLanguage = contactPointLanguageList[0];
        } else if (contactPointLanguageList.length > 1) {
            jsonld.contactPoint.availableLanguage = contactPointLanguageList;
        }
        if (contactPointAreaServedList.length === 1) {
            jsonld.contactPoint.areaServed = contactPointAreaServedList[0];
        } else if (contactPointAreaServedList.length > 1) {
            jsonld.contactPoint.areaServed = contactPointAreaServedList;
        }
    }

    // Adresse hinzufügen (wenn mindestens ein Adressfeld ausgefüllt ist)
    if (streetAddress || postalCode || addressLocality || addressCountry) {
        let address = { "@type": "PostalAddress" };
        if (streetAddress) address.streetAddress = streetAddress;
        if (addressLocality) address.addressLocality = addressLocality;
        if (postalCode) address.postalCode = postalCode;
        if (addressCountry) address.addressCountry = addressCountry;
        jsonld.address = address;
    }

    const knowsLanguage = knowsLanguageRaw.split(/[\\n,]+/).map(function(v) { return v.trim(); }).filter(Boolean);

    if (knowsLanguage.length) jsonld.knowsLanguage = knowsLanguage;
    const openingHoursSpec = collectOpeningHoursSpecification();
    if (openingHoursSpec.length) jsonld.openingHoursSpecification = openingHoursSpec;
    
    // Organization Reference hinzufügen
    jsonld.publisher = {
        "@id": baseUrl + "#organization"
    };

    try {
        const custom = parseCustomJsonObject(customRaw);
        if (custom) {
            mergeCustomIntoSchema(jsonld, custom);
        }
        setCustomJsonHint("");
    } catch (err) {
        setCustomJsonHint(err.message || "Ungültiges Custom-JSON.");
    }
    
    document.getElementById("json-preview").textContent = JSON.stringify(jsonld, null, 2);
}

function parseCustomJsonObject(raw) {
    const value = (raw || "").trim();
    if (!value) {
        return null;
    }

    let parsed;
    try {
        parsed = JSON.parse(value);
    } catch (e) {
        throw new Error("Custom JSON ist ungültig.");
    }

    if (!parsed || Array.isArray(parsed) || typeof parsed !== "object") {
        throw new Error("Custom JSON muss ein Objekt sein.");
    }

    return parsed;
}

function mergeCustomIntoSchema(target, custom) {
    const protectedKeys = { "@context": true, "@type": true, "@id": true };

    Object.keys(custom).forEach(function(key) {
        if (protectedKeys[key]) {
            return;
        }

        const value = custom[key];
        if (
            value
            && typeof value === "object"
            && !Array.isArray(value)
            && target[key]
            && typeof target[key] === "object"
            && !Array.isArray(target[key])
        ) {
            mergeCustomIntoSchema(target[key], value);
            return;
        }

        target[key] = value;
    });
}

function setCustomJsonHint(message) {
    const help = document.getElementById("lb_custom_jsonld_help");
    if (!help) {
        return;
    }

    if (message) {
        help.textContent = message;
        help.style.color = "#d9534f";
    } else {
        help.textContent = "Optionales JSON-Objekt mit Zusatzfeldern. @context, @type und @id werden ignoriert.";
        help.style.color = "#999";
    }
}

// Event listeners für Live-Updates
function setHiddenField(form, name, value) {
    let input = form.querySelector("input[name=" + name + "]");
    if (!input) {
        input = document.createElement("input");
        input.type = "hidden";
        input.name = name;
        form.appendChild(input);
    }
    input.value = value;
}

function collectOpeningHoursRows() {
    const rows = [];
    const tableRows = document.querySelectorAll("#lb-main-form .formbe_table table tbody tr");

    tableRows.forEach(function(row) {
        const cells = row.querySelectorAll("td.be-value-input");
        if (cells.length < 3) {
            return;
        }

        const dayField = cells[0].querySelector("input, select, textarea");
        const opensField = cells[1].querySelector("input, select, textarea");
        const closesField = cells[2].querySelector("input, select, textarea");

        rows.push({
            day_of_week: dayField ? (dayField.value || "") : "",
            opens: opensField ? (opensField.value || "") : "",
            closes: closesField ? (closesField.value || "") : ""
        });
    });

    return rows;
}

function normalizeDayOfWeek(day) {
    const dayMap = {
        "monday": "Monday", "montag": "Monday", "mo": "Monday",
        "tuesday": "Tuesday", "dienstag": "Tuesday", "di": "Tuesday",
        "wednesday": "Wednesday", "mittwoch": "Wednesday", "mi": "Wednesday",
        "thursday": "Thursday", "donnerstag": "Thursday", "do": "Thursday",
        "friday": "Friday", "freitag": "Friday", "fr": "Friday",
        "saturday": "Saturday", "samstag": "Saturday", "sa": "Saturday",
        "sunday": "Sunday", "sonntag": "Sunday", "so": "Sunday"
    };

    const key = (day || "").trim().toLowerCase();
    return dayMap[key] || day.trim();
}

function normalizeTimeHHMM(value) {
    const match = (value || "").trim().match(/^([01]?\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/);
    if (!match) {
        return "";
    }

    const h = String(parseInt(match[1], 10)).padStart(2, "0");
    const m = String(parseInt(match[2], 10)).padStart(2, "0");
    return h + ":" + m;
}

function collectOpeningHoursSpecification() {
    return collectOpeningHoursRows().map(function(row) {
        const dayRaw = (row.day_of_week || "").trim();
        const opens = normalizeTimeHHMM(row.opens || "");
        const closes = normalizeTimeHHMM(row.closes || "");

        if (!dayRaw || !opens || !closes) {
            return null;
        }

        const dayList = dayRaw
            .split(",")
            .map(function(day) { return normalizeDayOfWeek(day); })
            .filter(Boolean);

        if (!dayList.length) {
            return null;
        }

        return {
            "@type": "OpeningHoursSpecification",
            "dayOfWeek": dayList.length === 1 ? dayList[0] : dayList,
            "opens": opens,
            "closes": closes
        };
    }).filter(Boolean);
}

document.addEventListener("DOMContentLoaded", function() {
    updateJSON(); // Initial load

    const form = document.getElementById("lb-main-form");
    if (form) {
        form.addEventListener("input", updateJSON);
        form.addEventListener("change", updateJSON);
        form.addEventListener("click", function(event) {
            const target = event.target;
            if (target && (target.closest(".btn-delete") || target.closest("#yform-jsonld_lb_opening_hours-opening_hours_spec-add-row") || target.closest("#yform-jsonld_lb_opening_hours-opening_hours_spec-add-mobile-row"))) {
                setTimeout(updateJSON, 0);
            }
        });
        form.addEventListener("submit", function(event) {
            if (!hasEditableBranch) {
                event.preventDefault();
                return;
            }
            const coordinates = document.getElementById("lb_coordinates");

            setHiddenField(form, "lb_coordinates_sync", coordinates ? coordinates.value : "");
            setHiddenField(form, "lb_opening_hours_json", JSON.stringify(collectOpeningHoursRows()));
        });
    }

    // Robuste Floating-Vorschau (funktioniert auch wenn CSS sticky blockiert ist)
    const previewCol = document.querySelector(".jsonld-preview-col");
    const previewSticky = document.querySelector(".jsonld-preview-sticky");
    if (previewCol && previewSticky) {
        const row = previewCol.parentElement;
        const topOffset = 20;
        const placeholder = document.createElement("div");
        placeholder.style.display = "none";
        previewSticky.parentNode.insertBefore(placeholder, previewSticky);

        function resetPreviewPosition() {
            placeholder.style.display = "none";
            previewSticky.style.position = "";
            previewSticky.style.top = "";
            previewSticky.style.left = "";
            previewSticky.style.width = "";
            previewSticky.style.right = "";
        }

        function updateFloatingPreview() {
            const isDesktop = window.innerWidth >= 992;
            if (!isDesktop || !row) {
                resetPreviewPosition();
                return;
            }

            const rowRect = row.getBoundingClientRect();
            const colRect = previewCol.getBoundingClientRect();
            const colStyle = window.getComputedStyle(previewCol);
            const padLeft = parseFloat(colStyle.paddingLeft || "0") || 0;
            const padRight = parseFloat(colStyle.paddingRight || "0") || 0;
            const stickyHeight = previewSticky.offsetHeight;
            const shouldFloat = rowRect.top <= topOffset && rowRect.bottom > (topOffset + stickyHeight);

            if (shouldFloat) {
                placeholder.style.display = "block";
                placeholder.style.height = stickyHeight + "px";
                previewSticky.style.position = "fixed";
                previewSticky.style.top = topOffset + "px";
                previewSticky.style.left = (colRect.left + padLeft) + "px";
                previewSticky.style.width = Math.max(0, colRect.width - padLeft - padRight) + "px";
                previewSticky.style.right = "auto";
            } else {
                resetPreviewPosition();
            }
        }

        window.addEventListener("scroll", updateFloatingPreview);
        window.addEventListener("resize", updateFloatingPreview);
        updateFloatingPreview();
    }
});
</script>';

echo '<div class="row">
    <div class="col-md-6">

        
        <form method="post" id="lb-main-form">
            ' . $csrfTokenField . '
            <input type="hidden" name="lb_action" value="save">
            <input type="hidden" name="domain_id" value="' . $activeDomainId . '">
            <input type="hidden" name="branch_id" value="' . $branchId . '">
            ' . ($isFormLocked ? '<div class="alert alert-warning" style="margin-bottom: 15px;">Bitte legen Sie zuerst einen Standort an. Ohne Standort sind Eingaben und Speichern deaktiviert.</div>' : '') . '
            <fieldset ' . ($isFormLocked ? 'disabled="disabled" aria-disabled="true"' : '') . '>
            
            <div class="panel panel-primary">
                <header class="panel-heading">
                    <h1 class="panel-title">Grunddaten</h1>
                </header>
                <div class="panel-body">
                
                <div class="form-group">
                    <label for="lb_name">Geschäftsname:</label>
                    <input type="text" name="lb_name" id="lb_name" class="form-control" 
                           value="' . htmlspecialchars($localBusinessConfig['name'] ?? '') . '" 
                           placeholder="Restaurant Zur Post">
                    <small class="help-block" style="color: #999;">Bei leerem Namen wird das Schema deaktiviert</small>
                </div>
                
                <div class="form-group">
                    <label for="lb_type">Geschäfts-Art:</label>
                    <input type="text" name="lb_type" id="lb_type" class="form-control" 
                           value="' . htmlspecialchars($localBusinessConfig['businessType'] ?? '') . '" 
                           placeholder="EventVenue">
                    <small class="help-block" style="color: #999;">
                        Beispiele: AutoDealer, AutoRepair, Bakery, BarOrPub, BedAndBreakfast, BeautySalon, BookStore, Brewery, CafeOrCoffeeShop, ClothingStore, Dentist, Distillery, EducationalOrganization, ElectronicsStore, EventVenue, FastFoodRestaurant, Florist, FoodEstablishment, FurnitureStore, GasStation, HairSalon, HardwareStore, HealthClub, Hostel, Hotel, IceCreamShop, JewelryStore, MedicalBusiness, MedicalClinic, Motel, NailSalon, PetStore, Pharmacy, Restaurant, ShoeStore, Spa, SportsClub, Store, TouristInformationCenter, TravelAgency, VeterinaryCare, Winery.
                    </small>
                </div>
                
                <div class="form-group">
                    <label for="lb_price_range">Preisklasse:</label>
                    <select name="lb_price_range" id="lb_price_range" class="form-control selectpicker" data-live-search="true" data-size="8">
                        <option value=""' . (empty($localBusinessConfig['priceRange']) ? ' selected' : '') . '>-- Nicht angeben --</option>
                        <option value="$"' . (($localBusinessConfig['priceRange'] ?? '') === '$' ? ' selected' : '') . '>$ (Günstig)</option>
                        <option value="$$"' . (($localBusinessConfig['priceRange'] ?? '') === '$$' ? ' selected' : '') . '>$$ (Moderat)</option>
                        <option value="$$$"' . (($localBusinessConfig['priceRange'] ?? '') === '$$$' ? ' selected' : '') . '>$$$ (Gehobene Preise)</option>
                        <option value="$$$$"' . (($localBusinessConfig['priceRange'] ?? '') === '$$$$' ? ' selected' : '') . '>$$$$ (Sehr teuer)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="lb_slogan">Slogan:</label>
                    <input type="text" name="lb_slogan" id="lb_slogan" class="form-control"
                           value="' . htmlspecialchars($localBusinessConfig['slogan'] ?? '') . '"
                           placeholder="z. B. Ihr zuverlässiger Partner vor Ort">
                </div>
                </div>
            </div>

            <div class="panel panel-primary">
                <header class="panel-heading">
                    <h1 class="panel-title">Bild/Bilder</h1>
                </header>
                <div class="panel-body">';

$mediaWidget = rex_var_medialist::getWidget(
    3001,
    'lb_images',
    (string) ($localBusinessConfig['images'] ?? ''),
    ['types' => 'jpg,jpeg,png,gif,webp,avif', 'preview' => true]
);

echo '          <div class="form-group">
                        <label for="lb_images">LocalBusiness Bilder:</label>
                        ' . $mediaWidget . '
                        <small class="help-block" style="color: #999;">
                            Mehrfachauswahl erlaubt. Ausgabe in JSON-LD als <code>image</code>-Array. Empfehlung: JPG oder WEBP im Querformat (ideal 1200×900, mindestens 800×600).
                        </small>
                    </div>
                </div>
            </div>
            
            <div class="panel panel-primary">
                <header class="panel-heading">
                    <h1 class="panel-title">Standort <small style="color: #999;">(optional)</small></h1>
                </header>
                <div class="panel-body">
                
                <div class="form-group">
                    <label for="lb_street_address">Straße &amp; Hausnummer:</label>
                    <input type="text" name="lb_street_address" id="lb_street_address" class="form-control" 
                           value="' . htmlspecialchars($localBusinessConfig['streetAddress'] ?? '') . '" 
                           placeholder="Musterstraße 123">
                    <small class="help-block" style="color: #999;">Adresse des Geschäftsstandorts (kann von der Organisation abweichen)</small>
                </div>
                
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label for="lb_postal_code">PLZ:</label>
                            <input type="text" name="lb_postal_code" id="lb_postal_code" class="form-control" 
                                   value="' . htmlspecialchars($localBusinessConfig['postalCode'] ?? '') . '" 
                                   placeholder="12345">
                        </div>
                    </div>
                    <div class="col-md-8">
                        <div class="form-group">
                            <label for="lb_address_locality">Stadt:</label>
                            <input type="text" name="lb_address_locality" id="lb_address_locality" class="form-control" 
                                   value="' . htmlspecialchars($localBusinessConfig['addressLocality'] ?? '') . '" 
                                   placeholder="München">
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="lb_address_country">Land:</label>
                    <input type="text" name="lb_address_country" id="lb_address_country" class="form-control" 
                           value="' . htmlspecialchars($localBusinessConfig['addressCountry'] ?? '') . '" 
                           placeholder="DE">
                    <small class="help-block" style="color: #999;">ISO 3166-1 Alpha-2 Code (z.B. DE, AT, CH)</small>
                </div>
                
                <hr style="margin: 25px 0;">
                
                <div class="form-group">
                    <label for="lb_coordinates">Koordinaten:</label>
                    <input type="text" name="lb_coordinates" id="lb_coordinates" class="form-control" 
                           value="' . htmlspecialchars($localBusinessConfig['coordinates'] ?? '') . '" 
                           placeholder="52.5200, 13.4050">
                    <small class="help-block" style="color: #999;">Format: Breitengrad, Längengrad (Google Maps)</small>
                </div>

                <div class="form-group">
                    <label for="lb_has_map">Karten-URL (hasMap):</label>
                    <input type="url" name="lb_has_map" id="lb_has_map" class="form-control"
                           value="' . htmlspecialchars($localBusinessConfig['hasMap'] ?? '') . '"
                           placeholder="https://maps.google.com/?q=...">
                </div>

                <div class="form-group">
                    <label for="lb_area_served">Servicegebiet des Standorts (LocalBusiness areaServed):</label>
                    <input type="text" name="lb_area_served" id="lb_area_served" class="form-control"
                           value="' . htmlspecialchars($localBusinessConfig['areaServed'] ?? '') . '"
                           placeholder="München, Dachau, Fürstenfeldbruck">
                    <small class="help-block" style="color: #999;">Beschreibt das Servicegebiet des Standorts selbst (nicht der Kontaktstelle).</small>
                </div>
                </div>
            </div>

            <div class="panel panel-primary">
                <header class="panel-heading">
                    <h1 class="panel-title">Öffnungszeiten-Spezifikation</h1>
                </header>
                <div class="panel-body">
                    ' . $openingHoursForm . '
                </div>
            </div>

            <div class="panel panel-primary">
                <header class="panel-heading">
                    <h1 class="panel-title">ContactPoint</h1>
                </header>
                <div class="panel-body">
                <div class="form-group">
                    <label for="lb_contactpoint_phone">Telefon:</label>
                    <input type="tel" name="lb_contactpoint_phone" id="lb_contactpoint_phone" class="form-control"
                           value="' . htmlspecialchars($localBusinessConfig['contactPoint']['telephone'] ?? ($localBusinessConfig['telephone'] ?? '')) . '"
                           placeholder="+43 (0) 1 330 46 59">
                </div>

                <div class="form-group">
                    <label for="lb_contactpoint_email">E-Mail:</label>
                    <input type="email" name="lb_contactpoint_email" id="lb_contactpoint_email" class="form-control"
                           value="' . htmlspecialchars($localBusinessConfig['contactPoint']['email'] ?? '') . '"
                           placeholder="wien@myshop.com">
                </div>

                <div class="form-group">
                    <label for="lb_contactpoint_type">Kontakt-Art:</label>
                    <select name="lb_contactpoint_type" id="lb_contactpoint_type" class="form-control selectpicker" data-live-search="true" data-size="8">';
$contactPointType = $localBusinessConfig['contactPoint']['contactType'] ?? '';
echo '              <option value=""' . ($contactPointType === '' ? ' selected' : '') . '>Bitte wählen</option>
                        <option value="customer service"' . ($contactPointType === 'customer service' ? ' selected' : '') . '>Kundenservice</option>
                        <option value="sales"' . ($contactPointType === 'sales' ? ' selected' : '') . '>Vertrieb</option>
                        <option value="support"' . ($contactPointType === 'support' ? ' selected' : '') . '>Support</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="lb_contactpoint_language">Sprache (availableLanguage):</label>
                    <input type="text" name="lb_contactpoint_language" id="lb_contactpoint_language" class="form-control"
                           value="' . htmlspecialchars(is_array($localBusinessConfig['contactPoint']['availableLanguage'] ?? null) ? implode(', ', $localBusinessConfig['contactPoint']['availableLanguage']) : ($localBusinessConfig['contactPoint']['availableLanguage'] ?? '')) . '"
                           placeholder="de, en">
                    <small class="help-block" style="color: #999;">Mehrere Werte kommagetrennt oder je Zeile, z. B. de, en.</small>
                </div>

                <div class="form-group">
                    <label for="lb_contactpoint_area_served">Servicegebiet der Kontaktstelle (areaServed):</label>
                    <input type="text" name="lb_contactpoint_area_served" id="lb_contactpoint_area_served" class="form-control"
                           value="' . htmlspecialchars(is_array($localBusinessConfig['contactPoint']['areaServed'] ?? null) ? implode(', ', $localBusinessConfig['contactPoint']['areaServed']) : ($localBusinessConfig['contactPoint']['areaServed'] ?? '')) . '"
                           placeholder="Bayern, München, DE, AT">
                    <small class="help-block" style="color: #999;">Optional separat vom Standort-Servicegebiet pflegen. Mehrere Werte kommagetrennt oder je Zeile.</small>
                </div>
                </div>
            </div>

            <div class="panel panel-primary">
                <header class="panel-heading">
                    <h1 class="panel-title">Erweiterte Angaben</h1>
                </header>
                <div class="panel-body">
                    <div class="form-group">
                        <label for="lb_payment_accepted">Zahlungsarten (paymentAccepted):</label>
                        <input type="text" name="lb_payment_accepted" id="lb_payment_accepted" class="form-control"
                               value="' . htmlspecialchars($localBusinessConfig['paymentAccepted'] ?? '') . '"
                               placeholder="Cash, EC, Visa, Mastercard">
                    </div>

                    <div class="form-group">
                        <label for="lb_currencies_accepted">Akzeptierte Währungen (currenciesAccepted):</label>
                        <input type="text" name="lb_currencies_accepted" id="lb_currencies_accepted" class="form-control"
                               value="' . htmlspecialchars($localBusinessConfig['currenciesAccepted'] ?? '') . '"
                               placeholder="EUR">
                    </div>

                    <div class="form-group">
                        <label for="lb_knows_language">Sprachen (knowsLanguage):</label>
                        <input type="text" name="lb_knows_language" id="lb_knows_language" class="form-control"
                               value="' . htmlspecialchars(implode(', ', (array) ($localBusinessConfig['knowsLanguage'] ?? []))) . '"
                               placeholder="de, en, it">
                        <small class="help-block" style="color: #999;">Kommagetrennt, z. B. de, en.</small>
                    </div>
                </div>
            </div>

            <div class="panel panel-primary">
                <header class="panel-heading">
                    <h1 class="panel-title">Custom Angaben</h1>
                </header>
                <div class="panel-body">
                    <div class="form-group">
                        <label for="lb_custom_jsonld_raw">Zusätzliche JSON-LD Felder (JSON-Objekt):</label>
                        <textarea name="lb_custom_jsonld_raw" id="lb_custom_jsonld_raw" class="form-control" rows="8" placeholder="{&#10;  &quot;keywords&quot;: [&quot;jsonld&quot;, &quot;seo&quot;],&#10;  &quot;additionalType&quot;: &quot;https://example.com/types/custom&quot;&#10;}">' . htmlspecialchars((string) ($localBusinessConfig['custom_jsonld_raw'] ?? '')) . '</textarea>
                        <small id="lb_custom_jsonld_help" class="help-block" style="color: #999;">Optionales JSON-Objekt mit Zusatzfeldern. @context, @type und @id werden ignoriert.</small>
                    </div>
                </div>
            </div>

            </fieldset>

        </form>
    </div>
    
    <div class="col-md-6 jsonld-preview-col">
        
        <div class="panel panel-primary" style="margin-bottom: 20px;">
            <header class="panel-heading">
                <h1 class="panel-title">Standorte verwalten</h1>
            </header>
            <div class="panel-body">
                
                <div class="row" style="margin-bottom: 15px;">
                    <div class="col-md-12">
                        <form method="post" style="display: flex; gap: 10px; align-items: center;">
                            ' . $csrfTokenField . '
                            <input type="hidden" name="lb_action" value="create_branch">
                            <input type="text" name="branch_name" class="form-control" placeholder="Standortname (z.B. Standort Musterstraße 15)" style="flex: 1;" required>
                            <button type="submit" class="btn btn-success">Neuen Standort anlegen</button>
                        </form>
                    </div>
                </div>';

// Liste aller Standorte
if (!empty($branches)) {
    echo '<table class="table table-striped">';
    echo '<thead><tr><th style="font-size: 12px; font-weight: normal;">Name</th><th style="width: 150px; text-align: right; font-size: 12px; font-weight: normal;">Aktionen</th></tr></thead>';
    echo '<tbody>';
    
    foreach ($branches as $branch) {
        $branchConfigRaw = $branch['config'] ?? null;
        $config = is_string($branchConfigRaw) ? (json_decode($branchConfigRaw, true) ?: []) : [];
        $isSelected = ($branch['id'] == $branchId);
        
        echo '<tr>';
        if ($isSelected) {
            echo '<td><strong style="font-size: 17px !important;">' . htmlspecialchars((string) ($branch['branch_name'] ?? '')) . '</strong></td>';
        } else {
            echo '<td>' . htmlspecialchars((string) ($branch['branch_name'] ?? '')) . '</td>';
        }
        echo '<td style="text-align: right;">';
        
        // Hauptstandort-Icon
        if ($branch['is_main_branch']) {
            // Aktives Hauptstandort-Icon (gold/warning)
            echo '<button type="button" class="btn btn-warning" disabled title="Ist Hauptstandort"><i class="fa fa-star"></i></button> ';
        } else {
            // Inaktives Icon - Button zum Setzen als Hauptstandort
            echo '<form method="post" style="display: inline;">';
            echo $csrfTokenField;
            echo '<input type="hidden" name="lb_action" value="set_main_branch">';
            echo '<input type="hidden" name="branch_id" value="' . $branch['id'] . '">';
            echo '<button type="submit" class="btn btn-default" title="Als Hauptstandort setzen"><i class="fa fa-star-o"></i></button>';
            echo '</form> ';
        }
        
        // Löschen-Button
        if ($branch['is_main_branch']) {
            // Deaktivierter Löschen-Button für Hauptstandort
            echo '<button type="button" class="btn btn-default" disabled title="Hauptstandort kann nicht gelöscht werden"><i class="fa fa-trash"></i></button> ';
        } else {
            // Aktiver Löschen-Button für normale Standorte
            echo '<form method="post" style="display: inline;" onsubmit="return confirm(\'Standort wirklich löschen?\')">';
            echo $csrfTokenField;
            echo '<input type="hidden" name="lb_action" value="delete_branch">';
            echo '<input type="hidden" name="branch_id" value="' . $branch['id'] . '">';
            echo '<button type="submit" class="btn btn-danger"><i class="fa fa-trash"></i></button>';
            echo '</form> ';
        }
        
        // Bearbeiten-Button
        echo '<a href="' . rex_url::currentBackendPage(['branch_id' => $branch['id']]) . '" class="btn btn-info"><i class="fa fa-edit"></i></a>';
        echo '</td>';
        echo '</tr>';
    }
    
    echo '</tbody></table>';
} else {
    echo '<div class="alert alert-info">Noch keine Standorte angelegt. Erstellen Sie zuerst einen Standort oben. Erst danach sind Formular und Speichern verfügbar.</div>';
}



echo '            </div>
        </div>
        
        <div class="jsonld-preview-sticky">
        <pre id="json-preview" style="background: #f8f9fa; color: #333; border: 1px solid #ddd; padding: 15px; min-height: 400px; font-size: 12px; overflow-x: auto; font-family: Monaco, Menlo, monospace; border-radius: 4px;">
Geben Sie Daten ein um eine Vorschau zu sehen...
        </pre>
        </div>
    </div>
</div>

<style>
#jsonld-manager .rex-form-panel-footer {
    padding: 12px !important;
    background: rgba(0, 0, 0, .28) !important;
    border-top: 1px solid rgba(255, 255, 255, .08) !important;
    display: flex !important;
    justify-content: flex-end !important;
    align-items: center !important;
}
#jsonld-manager .rex-form-panel-footer .btn-toolbar {
    margin: 0 !important;
    width: auto !important;
    float: none !important;
}
#jsonld-manager .rex-form-panel-footer .btn {
    font-size: 14px;
    line-height: 1.3;
    padding-top: 7px;
    padding-bottom: 7px;
    float: none !important;
}
</style>

<div id="jsonld-manager">
<div class="rex-form-panel-footer">
  <div class="btn-toolbar">
        <button type="submit" form="lb-main-form" name="localbusiness_save" class="btn btn-apply" value="1" ' . ($isFormLocked ? 'disabled="disabled" title="Bitte zuerst einen Standort anlegen"' : '') . '>Speichern</button>
  </div>
</div>
</div>';

$content = ob_get_clean();

$fragment = new rex_fragment();
$fragment->setVar('title', 'LocalBusiness Schema', false);
$fragment->setVar('body', $content, false);
echo $fragment->parse('core/page/section.php');
?>
