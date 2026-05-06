<?php
/**
 * JSON-LD Manager - Artikel JSON-LD Vorschau
 * 
 * Zeigt für jeden Artikel den generierten JSON-LD Output an
 */

use FriendsOfRedaxo\JsonLdManager\DomainConfig;

$addon = rex_addon::get('jsonld_manager');
$activeClangId = \FriendsOfRedaxo\JsonLdManager\LanguageConfig::getActiveClangId();
$activeDomainId = DomainConfig::getActiveDomainId();
$csrfToken = rex_csrf_token::factory('jsonld_manager');
$csrfTokenField = $csrfToken->getHiddenField();

function jsonld_manager_article_branch_key(int $articleId, int $clangId): string {
    if (DomainConfig::isMultiDomain()) {
        $domainId = DomainConfig::getActiveDomainId();
        return 'article_branch_' . $articleId . '_clang_' . $clangId . '_domain_' . $domainId;
    }
    return 'article_branch_' . $articleId . '_clang_' . $clangId;
}

function jsonld_manager_custom_json_key(int $articleId, int $clangId): string {
    if (DomainConfig::isMultiDomain()) {
        $domainId = DomainConfig::getActiveDomainId();
        return 'custom_json_' . $articleId . '_clang_' . $clangId . '_domain_' . $domainId;
    }
    return 'custom_json_' . $articleId . '_clang_' . $clangId;
}

function jsonld_manager_disable_json_key(int $articleId, int $clangId): string {
    if (DomainConfig::isMultiDomain()) {
        $domainId = DomainConfig::getActiveDomainId();
        return 'disable_json_' . $articleId . '_clang_' . $clangId . '_domain_' . $domainId;
    }
    return 'disable_json_' . $articleId . '_clang_' . $clangId;
}

function jsonld_manager_get_article_branch_id(int $articleId, ?int $clangId = null): int {
    $clangId = $clangId ?? \FriendsOfRedaxo\JsonLdManager\LanguageConfig::getActiveClangId();
    $localizedKey = jsonld_manager_article_branch_key($articleId, $clangId);
    return (int) rex_config::get('jsonld_manager', $localizedKey, 0);
}

// Funktion zum Laden der verfügbaren LocalBusiness-Filialen
function getLocalBusinessBranches() {
    $activeClangId = \FriendsOfRedaxo\JsonLdManager\LanguageConfig::getActiveClangId();
    $activeDomainId = DomainConfig::getActiveDomainId();
    
    try {
        $sql = rex_sql::factory();
        
        // Prüfe ob domain_id Spalte existiert
        $checkDomainColumn = rex_sql::factory();
        $checkDomainColumn->setQuery('SHOW COLUMNS FROM ' . rex::getTable('jsonld_localbusiness_branches') . ' LIKE "domain_id"');
        $hasDomainColumn = $checkDomainColumn->getRows() > 0;
        
        if ($hasDomainColumn && DomainConfig::isMultiDomain()) {
            $sql->setQuery('SELECT id, branch_name, is_main_branch FROM ' . rex::getTable('jsonld_localbusiness_branches') . ' 
                           WHERE clang_id = ? AND (domain_id = ? OR domain_id IS NULL)
                           AND branch_name IS NOT NULL AND branch_name != "" 
                           AND JSON_UNQUOTE(JSON_EXTRACT(config, "$.name")) IS NOT NULL 
                           AND JSON_UNQUOTE(JSON_EXTRACT(config, "$.name")) != ""
                           ORDER BY is_main_branch DESC, branch_name ASC', [$activeClangId, $activeDomainId]);
        } else {
            $sql->setQuery('SELECT id, branch_name, is_main_branch FROM ' . rex::getTable('jsonld_localbusiness_branches') . ' 
                           WHERE clang_id = ?
                           AND branch_name IS NOT NULL AND branch_name != "" 
                           AND JSON_UNQUOTE(JSON_EXTRACT(config, "$.name")) IS NOT NULL 
                           AND JSON_UNQUOTE(JSON_EXTRACT(config, "$.name")) != ""
                           ORDER BY is_main_branch DESC, branch_name ASC', [$activeClangId]);
        }
        $branches = [];
        while ($sql->hasNext()) {
            $branches[] = [
                'id' => $sql->getValue('id'),
                'name' => $sql->getValue('branch_name'),
                'is_main' => (bool) $sql->getValue('is_main_branch')
            ];
            $sql->next();
        }
        return $branches;
    } catch (Exception $e) {
        return [];
    }
}

// AJAX Handler für Filial-basiertes JSON-LD
// URL-Parameter extrahieren (für Page-Reload Feature)
$branchId = rex_request('branch_id', 'int', 0);

if (rex_request('ajax', 'string') === 'update_branch_json') {
    if (!$csrfToken->isValid()) {
        rex_response::sendJson(['success' => false, 'error' => 'csrf']);
        exit;
    }
    $articleId = rex_request('article_id', 'int', 0);
    $branchIdAjax = rex_request('branch_id', 'int', 0);
    
    if ($articleId > 0) {
        // Branch-Auswahl für diesen Artikel speichern
        rex_config::set('jsonld_manager', jsonld_manager_article_branch_key($articleId, \FriendsOfRedaxo\JsonLdManager\LanguageConfig::getActiveClangId()), $branchIdAjax);
        
        // JSON-LD mit gewählter Filiale generieren (ohne leere Werte)
        $jsonld = generateArticleJsonLd($articleId, $addon, $branchIdAjax);
        $jsonld = jsonld_manager_build_payload($jsonld);
        
        rex_response::sendJson([
            'success' => true,
            'jsonld' => $jsonld,
            'branch_id' => $branchIdAjax
        ]);
        exit;
    }
    
    rex_response::sendJson(['success' => false]);
    exit;
}

// Ausgewählten Artikel ID aus Request holen (GET oder POST)
$selectedArticleId = rex_request('article_id', 'int', 0);
if ($selectedArticleId === 0) {
    // Fallback: aus POST holen (nach Form-Submit)
    $selectedArticleId = rex_post('article_id', 'int', 0);
}

// Branch-Zuordnung bei Page-Reload speichern (wenn URL-Parameter vorhanden)
if ($branchId > 0 && $selectedArticleId > 0) {
    // Speichere die Branch-Zuordnung permanent
    rex_config::set('jsonld_manager', jsonld_manager_article_branch_key($selectedArticleId, $activeClangId), $branchId);
}

// Funktion für hierarchische Struktur-Verarbeitung (mit Ebenen)
function addArticlesFromCategoryHierarchical($category, &$articles, &$addedIds = [], $level = 0, $parentCategory = null) {
    // Startartikel der Kategorie hinzufügen (nur wenn noch nicht vorhanden)
    if ($category && !in_array($category->getId(), $addedIds)) {
        $articles[] = [
            'id' => $category->getId(),
            'name' => $category->getName(),
            'yrewrite_title' => \rex_addon::get('yrewrite')->isAvailable() ? $category->getValue('yrewrite_title') : '',
            'category' => $parentCategory ? $parentCategory->getName() : 'Hauptebene',
            'parent_id' => $category->getParentId(),
            'url' => $category->getUrl(),
            'createdate' => $category->getCreateDate(),
            'updatedate' => $category->getUpdateDate(),
            'yrewrite_description' => \rex_addon::get('yrewrite')->isAvailable() ? $category->getValue('yrewrite_description') : '',
            'yrewrite_image' => \rex_addon::get('yrewrite')->isAvailable() ? $category->getValue('yrewrite_image') : '',
            'status' => $category->isOnline() ? 'online' : 'offline',
            'level' => $level,
            'priority' => $category->getPriority()
        ];
        $addedIds[] = $category->getId();
    }
    
    // Normale Artikel der Kategorie hinzufügen (sortiert nach Priorität)
    $categoryArticles = $category ? $category->getArticles() : []; // Alle Artikel, auch offline
    foreach ($categoryArticles as $article) {
        if (!$article->isStartArticle() && !in_array($article->getId(), $addedIds)) {
            $articles[] = [
                'id' => $article->getId(),
                'name' => $article->getName(),
                'yrewrite_title' => \rex_addon::get('yrewrite')->isAvailable() ? $article->getValue('yrewrite_title') : '',
                'category' => $category ? $category->getName() : 'Keine Kategorie',
                'parent_id' => $article->getCategoryId(),
                'url' => $article->getUrl(),
                'createdate' => $article->getCreateDate(),
                'updatedate' => $article->getUpdateDate(),
                'yrewrite_description' => \rex_addon::get('yrewrite')->isAvailable() ? $article->getValue('yrewrite_description') : '',
                'yrewrite_image' => \rex_addon::get('yrewrite')->isAvailable() ? $article->getValue('yrewrite_image') : '',
                'status' => $article->isOnline() ? 'online' : 'offline',
                'level' => $level + 1, // Artikel sind eine Ebene tiefer als ihre Kategorie
                'priority' => $article->getPriority()
            ];
            $addedIds[] = $article->getId();
        }
    }
    
    // Rekursiv für Unterkategorien (sortiert nach Priorität)
    $subCategories = $category ? $category->getChildren() : [];
    usort($subCategories, function($a, $b) {
        return $a->getPriority() - $b->getPriority();
    });
    
    foreach ($subCategories as $subCategory) {
        addArticlesFromCategoryHierarchical($subCategory, $articles, $addedIds, $level + 1, $category);
    }
}

// Funktion für rekursive Struktur-Verarbeitung
function addArticlesFromCategory($category, &$articles, &$addedIds = []) {
    // Startartikel der Kategorie hinzufügen (nur wenn noch nicht vorhanden)
    if ($category && !in_array($category->getId(), $addedIds)) {
        $articles[] = [
            'id' => $category->getId(),
            'name' => $category->getName(),
            'yrewrite_title' => \rex_addon::get('yrewrite')->isAvailable() ? $category->getValue('yrewrite_title') : '',
            'category' => $category->getParent() ? $category->getParent()->getName() : 'Hauptebene',
            'parent_id' => $category->getParentId(),
            'url' => $category->getUrl(),
            'createdate' => $category->getCreateDate(),
            'updatedate' => $category->getUpdateDate(),
            'yrewrite_description' => \rex_addon::get('yrewrite')->isAvailable() ? $category->getValue('yrewrite_description') : '',
            'yrewrite_image' => \rex_addon::get('yrewrite')->isAvailable() ? $category->getValue('yrewrite_image') : '',
            'status' => $category->isOnline() ? 'online' : 'offline',
            'level' => 0, // Level hinzufügen für Kompatibilität
            'priority' => $category->getPriority()
        ];
        $addedIds[] = $category->getId();
    }
    
    // Normale Artikel der Kategorie hinzufügen (nur wenn noch nicht vorhanden)
    $categoryArticles = $category ? $category->getArticles() : []; // Alle Artikel, auch offline
    foreach ($categoryArticles as $article) {
        if (!$article->isStartArticle() && !in_array($article->getId(), $addedIds)) {
            $articles[] = [
                'id' => $article->getId(),
                'name' => $article->getName(),
                'yrewrite_title' => \rex_addon::get('yrewrite')->isAvailable() ? $article->getValue('yrewrite_title') : '',
                'category' => $category ? $category->getName() : 'Keine Kategorie',
                'parent_id' => $article->getCategoryId(),
                'url' => $article->getUrl(),
                'createdate' => $article->getCreateDate(),
                'updatedate' => $article->getUpdateDate(),
                'yrewrite_description' => \rex_addon::get('yrewrite')->isAvailable() ? $article->getValue('yrewrite_description') : '',
                'yrewrite_image' => \rex_addon::get('yrewrite')->isAvailable() ? $article->getValue('yrewrite_image') : '',
                'status' => $article->isOnline() ? 'online' : 'offline',
                'level' => 1, // Level hinzufügen für Kompatibilität
                'priority' => $article->getPriority()
            ];
            $addedIds[] = $article->getId();
        }
    }
    
    // Rekursiv für Unterkategorien
    foreach ($category ? $category->getChildren() : [] as $subCategory) {
        addArticlesFromCategory($subCategory, $articles, $addedIds);
    }
}

// Alle Artikel laden - Domain-spezifisch bei Multi-Domain Setup
$articles = [];
$addedIds = []; // Array um Duplikate zu vermeiden

if (DomainConfig::isMultiDomain()) {
    // Bei Multi-Domain: Nur Artikel der gewählten Domain laden
    $activeDomain = DomainConfig::getActiveDomain();
    if ($activeDomain && isset($activeDomain['start_id']) && $activeDomain['start_id'] > 0) {
        $startId = (int) $activeDomain['start_id'];
        $mountId = isset($activeDomain['mount_id']) ? (int) $activeDomain['mount_id'] : 0;
        
        // Startartikel der Domain hinzufügen
        $startArticle = rex_article::get($startId);
        if ($startArticle) { // Auch offline Artikel
            $articles[] = [
                'id' => $startArticle->getId(),
                'name' => $startArticle->getName(),
                'yrewrite_title' => \rex_addon::get('yrewrite')->isAvailable() ? $startArticle->getValue('yrewrite_title') : '',
                'category' => 'Domain-Startseite',
                'parent_id' => 0,
                'url' => $startArticle->getUrl(),
                'createdate' => $startArticle->getCreateDate(),
                'updatedate' => $startArticle->getUpdateDate(),
                'yrewrite_description' => \rex_addon::get('yrewrite')->isAvailable() ? $startArticle->getValue('yrewrite_description') : '',
                'yrewrite_image' => \rex_addon::get('yrewrite')->isAvailable() ? $startArticle->getValue('yrewrite_image') : '',
                'status' => $startArticle->isOnline() ? 'online' : 'offline',
                'level' => 0, // Domain-Root-Level
                'priority' => $startArticle->getPriority()
            ];
            $addedIds[] = $startArticle->getId(); // Als bereits hinzugefügt markieren
        }
        
        // Unterkategorien der Domain-Startseite laden (hierarchisch)
        if ($mountId > 0) {
            $startCategory = rex_category::get($mountId);
            if ($startCategory) {
                addArticlesFromCategoryHierarchical($startCategory, $articles, $addedIds, 0);
            }
        } else {
            // Fallback: Unterkategorien der Startseite (hierarchisch)
            $startCategory = rex_category::get($startId);
            if ($startCategory) {
                foreach ($startCategory->getChildren() as $category) {
                    addArticlesFromCategoryHierarchical($category, $articles, $addedIds, 1);
                }
            }
        }
    }
} else {
    // Single-Domain: Alle Root-Kategorien laden (hierarchisch)
    $categories = rex_category::getRootCategories();
    
    // Kategorien nach Priorität sortieren
    usort($categories, function($a, $b) {
        return $a->getPriority() - $b->getPriority();
    });
    
    // Root-Artikel hinzufügen (ohne Kategorie) - auch offline
    $rootArticles = rex_article::getRootArticles(); // Auch offline Artikel
    foreach ($rootArticles as $article) {
        if (!in_array($article->getId(), $addedIds)) {
            $articles[] = [
                'id' => $article->getId(),
                'name' => $article->getName(),
                'yrewrite_title' => \rex_addon::get('yrewrite')->isAvailable() ? $article->getValue('yrewrite_title') : '',
                'category' => 'Hauptebene',
                'parent_id' => 0,
                'url' => $article->getUrl(),
                'createdate' => $article->getCreateDate(),
                'updatedate' => $article->getUpdateDate(),
                'yrewrite_description' => \rex_addon::get('yrewrite')->isAvailable() ? $article->getValue('yrewrite_description') : '',
                'yrewrite_image' => \rex_addon::get('yrewrite')->isAvailable() ? $article->getValue('yrewrite_image') : '',
                'status' => $article->isOnline() ? 'online' : 'offline',
                'level' => 0, // Root-Level
                'priority' => $article->getPriority()
            ];
            $addedIds[] = $article->getId();
        }
    }
    
    // Kategorien und ihre Artikel hierarchisch hinzufügen
    foreach ($categories as $category) {
        addArticlesFromCategoryHierarchical($category, $articles, $addedIds, 0);
    }
}

// Wenn kein Artikel ausgewählt, ersten nehmen
if ($selectedArticleId === 0 && !empty($articles)) {
    $selectedArticleId = $articles[0]['id'];
}

// Custom JSON Form-Verarbeitung
$func = rex_request('func', 'string', '');
if ($func === 'save_custom_json') {
    if (!$csrfToken->isValid()) {
        echo rex_view::error('Sicherheitsprüfung fehlgeschlagen (CSRF). Bitte Seite neu laden und erneut speichern.');
    } else {
    $customJson = rex_post('custom_json', 'string', '');
    $articleId = rex_post('article_id', 'int', 0);
    
    if ($articleId > 0) {
        if (empty(trim($customJson))) {
            // Leeres JSON = Custom JSON löschen
            rex_config::remove('jsonld_manager', jsonld_manager_custom_json_key($articleId, $activeClangId));
            echo rex_view::success('Custom JSON wurde entfernt.');
        } else {
            // JSON validieren
            json_decode($customJson);
            if (json_last_error() === JSON_ERROR_NONE) {
                rex_config::set('jsonld_manager', jsonld_manager_custom_json_key($articleId, $activeClangId), $customJson);
                echo rex_view::success('Custom JSON wurde gespeichert.');
            } else {
                echo rex_view::error('JSON-Syntax-Fehler: ' . json_last_error_msg());
            }
        }
    }
    }
} elseif ($func === 'disable_json') {
    if (!$csrfToken->isValid()) {
        echo rex_view::error('Sicherheitsprüfung fehlgeschlagen (CSRF). Bitte Seite neu laden und erneut speichern.');
    } else {
    $articleId = rex_post('article_id', 'int', 0);
    
    if ($articleId > 0) {
        rex_config::set('jsonld_manager', jsonld_manager_disable_json_key($articleId, $activeClangId), true);
        echo rex_view::success('JSON-LD wurde für diesen Artikel deaktiviert.');
        // Sicherstellen dass die richtige article_id nach POST verwendet wird
        $selectedArticleId = $articleId;
    }
    }
} elseif ($func === 'enable_json') {
    if (!$csrfToken->isValid()) {
        echo rex_view::error('Sicherheitsprüfung fehlgeschlagen (CSRF). Bitte Seite neu laden und erneut speichern.');
    } else {
    $articleId = rex_post('article_id', 'int', 0);
    
    if ($articleId > 0) {
        rex_config::remove('jsonld_manager', jsonld_manager_disable_json_key($articleId, $activeClangId));
        echo rex_view::success('JSON-LD wurde für diesen Artikel wieder aktiviert.');
        // Sicherstellen dass die richtige article_id nach POST verwendet wird
        $selectedArticleId = $articleId;
    }
    }
}

// Ausgewählten Artikel finden
$selectedArticle = null;
foreach ($articles as $article) {
    if ($article['id'] == $selectedArticleId) {
        $selectedArticle = $article;
        break;
    }
}

// JSON-LD für ausgewählten Artikel generieren (nutzt zentrale Klasse)
function generateArticleJsonLd($articleId, $addon, $branchId = null) {
    return \FriendsOfRedaxo\JsonLdManager\JsonLdGenerator::generateForArticle($articleId, $branchId, true, \FriendsOfRedaxo\JsonLdManager\LanguageConfig::getActiveClangId());
}

function jsonld_manager_build_payload(array $jsonLdItems)
{
    if (count($jsonLdItems) === 1) {
        $payload = $jsonLdItems[0];
        if (function_exists('jsonld_prune_empty_values')) {
            $payload = jsonld_prune_empty_values($payload);
        }
        return $payload;
    }

    $payload = [
        '@context' => 'https://schema.org',
        '@graph' => array_values($jsonLdItems)
    ];

    if (function_exists('jsonld_prune_empty_values')) {
        $payload = jsonld_prune_empty_values($payload);
    }

    return $payload;
}

// Artikel-Liste vorab generieren
$articleListRows = '';

foreach ($articles as $article) {
    $isActive = $article['id'] == $selectedArticleId;
    $articlePath = '/';
    if (!empty($article['url'])) {
        $parsedPath = parse_url((string) $article['url'], PHP_URL_PATH);
        if (is_string($parsedPath) && $parsedPath !== '') {
            $articlePath = $parsedPath;
        }
    }
    
    // Hierarchie-Einrückung basierend auf Level
    $level = isset($article['level']) ? (int) $article['level'] : 0;
    $indent = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $level); // 4 Leerzeichen pro Level
    $levelClass = $level > 0 ? ' article-level-' . $level : '';
    
    // Prüfen ob Custom JSON für diesen Artikel existiert
    $hasCustomJson = !empty(trim(rex_config::get('jsonld_manager', jsonld_manager_custom_json_key((int) $article['id'], $activeClangId), '')));
    $isJsonDisabled = rex_config::get('jsonld_manager', jsonld_manager_disable_json_key((int) $article['id'], $activeClangId), false);
    
    // Prüfen ob Non-Hauptfiliale zugeordnet ist
    $assignedBranchId = jsonld_manager_get_article_branch_id((int) $article['id'], $activeClangId);
    $hasNonMainBranch = false;
    if ($assignedBranchId > 0) {
        // Prüfen ob die zugeordnete Filiale NICHT die Hauptfiliale ist
        try {
            $branchSql = rex_sql::factory();
            $branchSql->setQuery(
                'SELECT is_main_branch FROM ' . rex::getTable('jsonld_localbusiness_branches') . ' WHERE id = ? AND clang_id = ?',
                [$assignedBranchId, $activeClangId]
            );
            if ($branchSql->hasNext() && !$branchSql->getValue('is_main_branch')) {
                $hasNonMainBranch = true;
            }
        } catch (Exception $e) {
            // Fehler ignorieren
        }
    }
    
    // Icon-Farben je nach aktivem Status anpassen
    $iconColorClass = $isActive ? 'text-white' : '';
    
    $statusIndicators = '';
    
    // Online/Offline Status hinzufügen
    if ($article['status'] === 'offline') {
        $statusIndicators .= '<span class="article-status status-offline" title="Artikel ist offline"><i class="fa fa-eye-slash"></i></span>';
    }
    
    if ($isJsonDisabled) {
        $statusIndicators .= '<span class="article-status status-disabled" title="JSON-LD deaktiviert"><i class="fa fa-power-off"></i></span>';
    } elseif ($hasCustomJson) {
        $statusIndicators .= '<span class="article-status status-custom" title="Custom JSON aktiv"><i class="fa fa-sliders"></i></span>';
    }
    
    // Icon für Non-Hauptfiliale hinzufügen
    if ($hasNonMainBranch) {
        $statusIndicators .= '<span class="article-status status-branch" title="Eigene Filiale zugeordnet"><i class="fa fa-map-marker"></i></span>';
    }
    
    $articleListRows .= '<tr class="article-row' . ($isActive ? ' active' : '') . $levelClass . '" onclick="window.location.href=\'?page=jsonld_manager/article&clang=' . (int) $activeClangId . '&article_id=' . $article['id'] . '\'">
                            <td>
                                <span class="article-name">' . $indent . htmlspecialchars($article['yrewrite_title'] ?: $article['name']) . '</span>
                                <span class="article-path">' . $indent . '[ID: ' . (int) $article['id'] . '] ' . htmlspecialchars($articlePath) . '</span>
                            </td>
                            <td class="text-right">' . ($statusIndicators ? '<span class="article-icons">' . $statusIndicators . '</span>' : '') . '</td>
                        </tr>';
}

if (empty($articles)) {
    $articleListRows = '<tr><td colspan="2"><em>Keine Artikel gefunden</em></td></tr>';
}

// HTML in String sammeln für Fragment-System
$content = \FriendsOfRedaxo\JsonLdManager\LanguageConfig::renderClangTabs($activeClangId) . '';

$jsonLdOutput = '';
$isCustomJson = false;
$isJsonDisabled = false;
$customJsonRaw = '';

if ($selectedArticle) {
    // JETZT prüfen ob JSON für diesen Artikel deaktiviert ist (nach POST-Verarbeitung)
    $isJsonDisabled = rex_config::get('jsonld_manager', jsonld_manager_disable_json_key((int) $selectedArticleId, $activeClangId), false);
    
    if ($isJsonDisabled) {
        // JSON ist deaktiviert
        $jsonLdOutput = '// JSON-LD ist für diesen Artikel deaktiviert';
    } else {
        // Prüfen ob Custom JSON existiert
        $customJsonRaw = rex_config::get('jsonld_manager', jsonld_manager_custom_json_key((int) $selectedArticleId, $activeClangId), '');
        
        if (!empty(trim($customJsonRaw))) {
            // Custom JSON verwenden
            $jsonLdOutput = $customJsonRaw;
            $isCustomJson = true;
        } else {
            // Automatisch generiertes JSON verwenden - mit Hauptfiliale falls vorhanden
            $mainBranchId = null;
            $branches = getLocalBusinessBranches();
            foreach ($branches as $branch) {
                if ($branch['is_main']) {
                    $mainBranchId = $branch['id'];
                    break;
                }
            }
            
            // Gespeicherte Branch-Auswahl für diesen Artikel prüfen
            $selectedBranchId = jsonld_manager_get_article_branch_id((int) $selectedArticleId, $activeClangId);
            
            // Priorität: URL-Parameter > gespeicherte Config > Hauptfiliale
            if ($branchId > 0) {
                // URL-Parameter hat höchste Priorität (Live-Vorschau)
                $useBranchId = $branchId;
            } elseif ($selectedBranchId > 0) {
                // Gespeicherte Config als Fallback
                $useBranchId = $selectedBranchId;
            } else {
                // Hauptfiliale als Standard
                $useBranchId = $mainBranchId;
            }
            
            $jsonLdItems = generateArticleJsonLd($selectedArticleId, $addon, $useBranchId);
            $jsonLdOutput = json_encode(jsonld_manager_build_payload($jsonLdItems), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $isCustomJson = false;
        }
    }
}

// JSON-LD Preview Content
$jsonPreviewContent = '';
if ($selectedArticle && $jsonLdOutput) {
    $jsonPreviewContent = htmlspecialchars($jsonLdOutput);
} else {
    if (!$selectedArticle) {
        $jsonPreviewContent = 'Bitte wählen Sie einen Artikel aus der Liste links aus.';
    } else {
        $jsonPreviewContent = 'Kein JSON-LD für diesen Artikel generiert.' . "\n" . 'Überprüfen Sie die Schema-Konfigurationen.';
    }
}

$content = \FriendsOfRedaxo\JsonLdManager\LanguageConfig::renderClangTabs($activeClangId) . '

<div class="row">
    <div class="col-md-6">
        <div class="panel panel-primary">
            <header class="panel-heading">
                <h1 class="panel-title">Artikel auswählen (' . count($articles) . ')</h1>
            </header>
            <div class="panel-body">
                <table class="table table-striped article-table">
                    <thead>
                        <tr>
                            <th>Artikel</th>
                            <th class="text-right">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        ' . $articleListRows . '
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <div class="col-md-6 jsonld-preview-col">
        <div class="jsonld-preview-sticky">
            <!-- Action Buttons -->
            ' . ($selectedArticle ? '
            <div class="json-actions">
                <!-- Alles rechtsbündig: Buttons oben, Select darunter -->
                <div style="display: flex; flex-direction: column; align-items: flex-end; margin-bottom: 15px; gap: 10px;">
                    <!-- Buttons rechtsbündig -->
                    <div style="display: flex; align-items: center; gap: 10px;">
                        ' . ($isJsonDisabled ? '
                        <form method="post" style="display: inline;">
                            ' . $csrfTokenField . '
                            <input type="hidden" name="func" value="enable_json">
                            <input type="hidden" name="article_id" value="' . $selectedArticleId . '">
                            <input type="hidden" name="domain_id" value="' . $activeDomainId . '">
                            <button type="submit" class="btn btn-success">
                                <i class="fa fa-check"></i> JSON aktivieren
                            </button>
                        </form>' : '
                        <form method="post" style="display: inline;">
                            ' . $csrfTokenField . '
                            <input type="hidden" name="func" value="disable_json">
                            <input type="hidden" name="article_id" value="' . $selectedArticleId . '">
                            <input type="hidden" name="domain_id" value="' . $activeDomainId . '">
                            <button type="submit" class="btn btn-default">
                                <i class="fa fa-power-off"></i> JSON deaktivieren
                            </button>
                        </form>') . '
                        <button type="button" 
                                class="btn btn-warning" 
                                onclick="toggleCustomJsonEditor()">
                            <i class="fa fa-sliders"></i> Custom JSON ' . ($isCustomJson ? '✓' : '') . '
                        </button>
                        <a href="https://search.google.com/test/rich-results?url=" 
                           onclick="this.href += encodeURIComponent(\'' . (\rex_addon::get('yrewrite')->isAvailable() 
                               ? rex_yrewrite::getFullUrlByArticleId($selectedArticleId) 
                               : rex_url::frontendController() . '?article_id=' . $selectedArticleId) . '\')" 
                           target="_blank" 
                           class="btn btn-primary">
                            <i class="fa fa-external-link"></i> Google Rich Results Test
                        </a>
                    </div>
                    
                    <!-- LocalBusiness Select darunter, auch rechtsbündig -->
                    ' . (function() use ($selectedArticle, $addon, $branchId, $activeClangId) {
                        $branches = getLocalBusinessBranches();
                        if (!empty($branches) && count($branches) > 1) {
                            // Gespeicherte Branch-Auswahl für diesen Artikel laden
                            $savedBranchId = 0;
                            if ($selectedArticle) {
                                $savedBranchId = jsonld_manager_get_article_branch_id((int) $selectedArticleId, $activeClangId);
                            }
                            
                            // Priorität: URL-Parameter > gespeicherte Config > Hauptfiliale
                            $effectiveBranchId = 0;
                            if ($branchId > 0) {
                                // URL-Parameter hat höchste Priorität (Live-Vorschau)
                                $effectiveBranchId = $branchId;
                            } elseif ($savedBranchId > 0) {
                                // Gespeicherte Config als Fallback
                                $effectiveBranchId = $savedBranchId;
                            }
                            
                            $branchOptions = '';
                            foreach ($branches as $branch) {
                                $isMain = $branch['is_main'] ? ' (Hauptfiliale)' : '';
                                
                                // Auswahl-Logik basierend auf effectiveBranchId
                                $selected = '';
                                if ($effectiveBranchId > 0 && $branch['id'] == $effectiveBranchId) {
                                    $selected = ' selected';
                                } elseif ($effectiveBranchId == 0 && $branch['is_main']) {
                                    $selected = ' selected';
                                }
                                
                                $branchOptions .= '<option value="' . $branch['id'] . '"' . $selected . '>' . htmlspecialchars($branch['name']) . $isMain . '</option>';
                            }
                            return '<div style="display: flex; align-items: center; gap: 8px;">
                                        <label for="branch-selector" style="margin: 0; font-weight: bold; font-size: 12px; white-space: nowrap;">LocalBusiness Filiale:</label>
                                        <select id="branch-selector" class="form-control selectpicker" data-live-search="true" data-size="10" style="width: auto; min-width: 200px;" onchange="updateJsonWithBranch()">
                                            ' . $branchOptions . '
                                        </select>
                                    </div>';
                        }
                        return '';
                    })() . '
                </div>
            </div>
            ' . ($isCustomJson ? '
            <div class="alert alert-warning" style="padding: 8px 12px; margin-bottom: 15px;">
                <strong><i class="fa fa-exclamation-triangle"></i> Custom JSON aktiv:</strong> 
                Ihr eigenes JSON überschreibt die automatische Generierung.
            </div>' : '') . '
            ' . ($isJsonDisabled ? '
            <div class="alert alert-danger" style="padding: 8px 12px; margin-bottom: 15px;">
                <strong><i class="fa fa-ban"></i> JSON-LD deaktiviert:</strong> 
                Für diesen Artikel wird kein JSON-LD ausgegeben.
            </div>' : '') . '
            
            <!-- JSON-LD Output -->
            ' . ($selectedArticle ? '
            <!-- Custom JSON Editor (initially hidden) -->
            <div id="custom-json-editor" style="display: none; margin-bottom: 15px;">
                <div class="panel panel-warning">
                    <div class="panel-heading">
                        <strong>Custom JSON-LD Override</strong>
                        <button type="button" class="close" onclick="toggleCustomJsonEditor()" style="float: right;">×</button>
                    </div>
                    <div class="panel-body">
                        ' . ($isJsonDisabled ? '
                        <div class="alert alert-info">JSON-LD ist für diesen Artikel deaktiviert. Aktivieren Sie es zuerst, um Custom JSON zu verwenden.</div>' : '
                        <form id="custom-json-form" method="post">
                            ' . $csrfTokenField . '
                            <input type="hidden" name="func" value="save_custom_json">
                            <input type="hidden" name="article_id" value="' . $selectedArticleId . '">
                            <input type="hidden" name="domain_id" value="' . $activeDomainId . '">
                            <textarea name="custom_json" 
                                      id="custom-json-textarea" 
                                      class="form-control" 
                                      rows="8" 
                                      placeholder="Geben Sie hier Ihr eigenes JSON-LD ein..."
                                      style="font-family: Monaco, Menlo, monospace; font-size: 12px;">' . htmlspecialchars($customJsonRaw) . '</textarea>
                            <div style="margin-top: 10px; text-align: right;">
                                <button type="button" class="btn btn-default btn-sm" onclick="toggleCustomJsonEditor()">Abbrechen</button>
                                ' . ($isCustomJson ? '<button type="button" class="btn btn-danger btn-sm" onclick="deleteCustomJson()">Löschen</button>' : '') . '
                                <button type="submit" class="btn btn-warning btn-sm">Speichern</button>
                            </div>
                        </form>') . '
                    </div>
                </div>
            </div>' : '') . '
            
            <pre id="json-preview">' . $jsonPreviewContent . '</pre>
        </div>
    </div>
</div>
<!-- Ende der Bootstrap Row -->

<script>
function toggleCustomJsonEditor() {
    var editor = document.getElementById("custom-json-editor");
    if (editor.style.display === "none") {
        editor.style.display = "block";
        // Fokus auf Textarea
        document.getElementById("custom-json-textarea").focus();
    } else {
        editor.style.display = "none";
    }
}

function deleteCustomJson() {
    if (confirm("Möchten Sie das Custom JSON wirklich löschen? Die automatische JSON-LD Generierung wird wieder aktiviert.")) {
        // Textarea leeren und Form absenden
        document.getElementById("custom-json-textarea").value = "";
        document.getElementById("custom-json-form").submit();
    }
}

function updateJsonWithBranch() {
    const branchSelect = document.getElementById("branch-selector");
    const selectedBranchId = branchSelect ? branchSelect.value : 0;
    const articleId = ' . ($selectedArticleId ?: 0) . ';
    const activeClangId = ' . (int) $activeClangId . ';
    
    if (articleId > 0) {
        // Seite neu laden mit branch_id Parameter für sofortige Icon-Aktualisierung
        const url = new URL(window.location);
        if (selectedBranchId && selectedBranchId != 0) {
            url.searchParams.set("branch_id", selectedBranchId);
        } else {
            url.searchParams.delete("branch_id");
        }
        url.searchParams.set("clang", activeClangId);
        url.searchParams.set("article_id", articleId);
        window.location.href = url.toString();
    }
}

// Sticky Preview Funktionalität
function initPreviewFloating() {
    const previewCol = document.querySelector(".jsonld-preview-col");
    const previewSticky = document.querySelector(".jsonld-preview-sticky");
    if (!previewCol || !previewSticky) return;

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

// DOMContentLoaded Event für Sticky-Funktionalität
document.addEventListener("DOMContentLoaded", function() {
    initPreviewFloating();
});

</script>' : '') . '';

// Fragment mit Section-Header erzeugen
$fragment = new rex_fragment();
$fragment->setVar('title', 'Artikel', false);
$fragment->setVar('body', $content, false);
echo $fragment->parse('core/page/section.php');
