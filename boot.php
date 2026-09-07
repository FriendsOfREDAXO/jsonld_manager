<?php

use Url\Url;
use FriendsOfRedaxo\JsonLdManager\Frontend\Renderer;
use FriendsOfRedaxo\JsonLdManager\LlmsTxt;

/**
 * JSON-LD Manager AddOn - Boot
 * 
 * Initialisierung und Extension Points für das JSON-LD Manager AddOn
 */

// Template-Funktionen laden
require_once __DIR__ . '/lib/template_functions.php';

// JSON-LD Generator-Klasse laden
require_once __DIR__ . '/lib/JsonLdGenerator.php';
require_once __DIR__ . '/lib/SchemaHelper.php';
require_once __DIR__ . '/lib/Mapping/DynamicFieldMapper.php';
require_once __DIR__ . '/lib/DynamicContent.php';
require_once __DIR__ . '/lib/LanguageConfig.php';
require_once __DIR__ . '/lib/DynamicJsonLd.php';

// Domain- und sprachabhängige llms.txt nach der YRewrite-Auflösung ausliefern.
if (!rex::isBackend() && rex_addon::get('jsonld_manager')->isAvailable()) {
    rex_extension::register('PACKAGES_INCLUDED', static function (): void {
        $route = LlmsTxt::resolveEndpointRequest(rex_request::server('REQUEST_URI', 'string', ''));
        if ($route !== null) {
            LlmsTxt::sendResponse($route['domain_id'], $route['clang_id']);
        }
    }, rex_extension::LATE);
}

// Nur im Frontend
if (!rex::isBackend() && rex_addon::get('jsonld_manager')->isAvailable()) {
    // Extension Point für automatische JSON-LD Ausgabe
    rex_extension::register('OUTPUT_FILTER', function (rex_extension_point $ep) {
        $content = $ep->getSubject();
        if (strpos($content, '</head>') !== false) {
            $article = rex_article::getCurrent();
            if (!$article || !function_exists('jsonld_is_template_output_allowed') || !jsonld_is_template_output_allowed($article)) {
                return $content;
            }

            $jsonLdOutput = '';
            $dynamicJsonLdOutput = '';

            // Prüfe ob es eine dynamische URL ist (URL-Addon)
            if (rex_addon::get('url')->isAvailable()) {
                try {
                    $urlManager = Url::resolveCurrent();

                    if ($urlManager) {
                        // Dynamische URL erkannt - JSON-LD für URL-Profil generieren
                        $profileId = $urlManager->getProfileId();
                        $dataId = $urlManager->getDatasetId();

                        if ($profileId && $dataId) {
                            $dynamicJsonLdOutput = generateDynamicJsonLd($profileId, $dataId);
                        }
                    }
                } catch (Exception $e) {
                    // Fehler beim URL-Parsing ignorieren
                }
            }

            // Standard JSON-LD immer zusätzlich ausgeben
            $jsonLdOutput .= jsonld_render();
            // Dynamisches URL-JSON-LD zusätzlich anhängen (falls vorhanden)
            $jsonLdOutput .= $dynamicJsonLdOutput;

            // Legacy-Meta-Daten ausgeben (nach letztem <meta ...> im <head>)
            $legacyMeta = trim(rex_config::get('jsonld_manager', 'legacy_meta_raw', ''));
            if ($legacyMeta !== '') {
                // Suche alle <meta ...> im <head>
                $headStart = stripos($content, '<head');
                $headEnd = stripos($content, '</head>');
                if ($headStart !== false && $headEnd !== false && $headEnd > $headStart) {
                    $headContent = substr($content, $headStart, $headEnd - $headStart);
                    // Finde alle <meta ...> Tags
                    preg_match_all('/<meta[^>]*>/i', $headContent, $metaMatches, PREG_OFFSET_CAPTURE);
                    if (!empty($metaMatches[0])) {
                        $lastMeta = end($metaMatches[0]);
                        $insertPos = $headStart + $lastMeta[1] + strlen($lastMeta[0]);
                        $content = substr($content, 0, $insertPos) . "\n" . $legacyMeta . "\n" . substr($content, $insertPos);
                    } else {
                        // Kein <head> gefunden, vor </head> einfügen
                        $content = str_replace('</head>', $legacyMeta . "\n</head>", $content);
                    }
                } else {
                    // Kein <head> gefunden, vor </head> einfügen
                    $content = str_replace('</head>', $legacyMeta . "\n</head>", $content);
                }
            }
            if (!empty($jsonLdOutput)) {
                $content = str_replace('</head>', $jsonLdOutput . '</head>', $content);
            }
        }
        return $content;
    });
}

// Extension Point für Cache-Invalidierung bei Artikel-Änderungen
rex_extension::register('ART_UPDATED', function($ep): void {
    if (class_exists('\FriendsOfRedaxo\JsonLdManager\Frontend\Renderer')) {
        $articleId = 0;
        $params = $ep->getParams();

        if (isset($params['id'])) {
            $articleId = (int) $params['id'];
        } elseif (isset($params['article_id'])) {
            $articleId = (int) $params['article_id'];
        }

        Renderer::clearCache($articleId > 0 ? $articleId : null);
    }
});

// Bedingte Menüanzeige für Dynamische URLs
if (rex::isBackend()) {
    // CSS/JS nur auf Addon-Seiten im Backend laden
    if (rex_be_controller::getCurrentPagePart(1) === 'jsonld_manager') {
        rex_view::addCssFile(rex_url::addonAssets('jsonld_manager', 'css/jsonld_manager.css'));
        rex_view::addJsFile(rex_url::addonAssets('jsonld_manager', 'js/jsonld_manager.js'));
    }

    $hideDynamicUrlsSubpage = static function (): void {
        $filter = static function ($page): void {
            if (!$page instanceof rex_be_page) {
                return;
            }

            $subpages = $page->getSubpages();

            foreach ($subpages as $key => $subpage) {
                $subpageKey = (string) $subpage->getKey();
                $subpageFullKey = (string) $subpage->getFullKey();
                if ($subpageKey === 'dynamic_urls' || $subpageFullKey === 'jsonld_manager/dynamic_urls') {
                    unset($subpages[$key]);
                }
            }

            $page->setSubpages($subpages);
        };

        // Root-Page absichern
        $filter(rex_be_controller::getPageObject('jsonld_manager'));

        // Aktuelle Navigation (inkl. Parent) absichern
        $current = rex_be_controller::getCurrentPageObject();
        if ($current instanceof rex_be_page) {
            $filter($current);
            $filter($current->getParent());
        }
    };

    $hideLanguageCopySubpage = static function (): void {
        $filter = static function ($page): void {
            if (!$page instanceof rex_be_page) {
                return;
            }

            $subpages = $page->getSubpages();

            foreach ($subpages as $key => $subpage) {
                $subpageKey = (string) $subpage->getKey();
                $subpageFullKey = (string) $subpage->getFullKey();
                if ($subpageKey === 'language-copy' || $subpageFullKey === 'jsonld_manager/language-copy') {
                    unset($subpages[$key]);
                }
            }

            $page->setSubpages($subpages);
        };

        $filter(rex_be_controller::getPageObject('jsonld_manager'));

        $current = rex_be_controller::getCurrentPageObject();
        if ($current instanceof rex_be_page) {
            $filter($current);
            $filter($current->getParent());
        }
    };

    $shouldHideDynamicUrls = static function (): bool {
        if (!rex_addon::get('url')->isAvailable()) {
            return true;
        }
        $profileCount = rex_sql::factory()->getArray('SELECT COUNT(*) as count FROM ' . rex::getTable('url_generator_profile'));
        return !$profileCount || (int) $profileCount[0]['count'] === 0;
    };

    $shouldHideLanguageCopy = static function (): bool {
        return count(rex_clang::getAll(true)) <= 1;
    };

    // Harte UI-Fallback-Ausblendung, falls REDAXO-Navigation den Tab dennoch rendert
    if ($shouldHideDynamicUrls()) {
        rex_view::addCssFile(rex_url::addonAssets('jsonld_manager', 'css/hide_dynamic_urls_tab.css'));
    }

    rex_extension::register('PACKAGES_INCLUDED', function() use ($hideDynamicUrlsSubpage, $hideLanguageCopySubpage, $shouldHideDynamicUrls, $shouldHideLanguageCopy): void {
        if ($shouldHideDynamicUrls()) {
            $hideDynamicUrlsSubpage();
        }
        if ($shouldHideLanguageCopy()) {
            $hideLanguageCopySubpage();
        }
    });

    rex_extension::register('PAGE_PREPARED', function() use ($hideDynamicUrlsSubpage, $hideLanguageCopySubpage, $shouldHideDynamicUrls, $shouldHideLanguageCopy): void {
        if (!$shouldHideDynamicUrls()) {
            // no-op
        }

        if ($shouldHideDynamicUrls()) {
            $hideDynamicUrlsSubpage();
        }

        if ($shouldHideLanguageCopy()) {
            $hideLanguageCopySubpage();
        }

        // Direkter Aufruf der Seite verhindern, wenn sie nicht verfügbar ist
        $requestedPage = rex_request('page', 'string');
        if ($requestedPage === 'jsonld_manager/dynamic_urls') {
            rex_response::sendRedirect(rex_url::backendPage('jsonld_manager/article'));
        }
        if ($requestedPage === 'jsonld_manager/settings/language-copy') {
            rex_response::sendRedirect(rex_url::backendPage('jsonld_manager/settings'));
        }
    });
}

rex_extension::register('ART_DELETED', function($ep): void {
    if (class_exists('\FriendsOfRedaxo\JsonLdManager\Frontend\Renderer')) {
        $articleId = 0;
        $params = $ep->getParams();

        if (isset($params['id'])) {
            $articleId = (int) $params['id'];
        } elseif (isset($params['article_id'])) {
            $articleId = (int) $params['article_id'];
        }

        Renderer::clearCache($articleId > 0 ? $articleId : null);
    }
});
