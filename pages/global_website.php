<?php
/**
 * JSON-LD Manager - WebSite Schema
 */

use FriendsOfRedaxo\JsonLdManager\DomainConfig;

$websiteAction = rex_post('website_action', 'string', '');
$websiteSaveError = '';
$currentClang = rex_clang::getCurrent();
$autoLanguageCode = $currentClang ? (string) $currentClang->getCode() : 'de';
$addon = rex_addon::get('jsonld_manager');
$activeClangId = \FriendsOfRedaxo\JsonLdManager\LanguageConfig::getActiveClangId();
$activeDomainId = DomainConfig::getActiveDomainId();
$csrfToken = rex_csrf_token::factory('jsonld_manager_global_website');
$csrfTokenField = $csrfToken->getHiddenField();

// Website-URL basierend auf aktiver Domain ermitteln
function getWebsiteUrlForDomain($domainId = null): string {
    if (DomainConfig::isMultiDomain() && $domainId) {
        $activeDomain = DomainConfig::getActiveDomain();
        if ($activeDomain && isset($activeDomain['domain'])) {
            $domain = $activeDomain['domain'];
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

if ($websiteAction === 'save') {
    if (!$csrfToken->isValid()) {
        echo rex_view::error('Sicherheitsprüfung fehlgeschlagen (CSRF). Bitte Seite neu laden.');
    } else {
    $searchEnabled = rex_post('search_enabled', 'int', 0);
    $searchUrl = trim(rex_post('search_url', 'string', ''));

    if ($searchEnabled) {
        if ($searchUrl === '') {
            $websiteSaveError = 'Bitte eine Such-URL angeben, wenn die Suchfunktion aktiviert ist.';
        } elseif (strpos($searchUrl, '{search_term_string}') === false) {
            $websiteSaveError = 'Die Such-URL muss den Platzhalter {search_term_string} enthalten.';
        }
    }

    $config = [
        'name' => rex_post('website_name', 'string', ''),
        'url' => rex_post('website_url', 'string', ''),
        'description' => rex_post('website_description', 'string', ''),
        'potentialAction' => [
            'target' => $searchUrl,
            'enabled' => $searchEnabled,
        ],
    ];

    if ($websiteSaveError === '') {
        // Domain + Sprach-spezifische Konfiguration speichern
        if (DomainConfig::isMultiDomain()) {
            $configKey = 'website_schema_domain_' . $activeDomainId . '_clang_' . $activeClangId;
            $addon->setConfig($configKey, $config);
        } else {
            \FriendsOfRedaxo\JsonLdManager\LanguageConfig::setLocalizedConfig($addon, 'website_schema', $activeClangId, $config);
        }
        echo rex_view::success('WebSite Schema wurde gespeichert.');
    } else {
        echo rex_view::error($websiteSaveError);
    }
    }
}

// Domain + Sprach-spezifische Konfiguration laden
if (DomainConfig::isMultiDomain()) {
    $configKey = 'website_schema_domain_' . $activeDomainId . '_clang_' . $activeClangId;
    $websiteConfig = $addon->getConfig($configKey, []);
    // Fallback zu sprachspezifischer Konfiguration wenn domain-spezifische nicht existiert
    if (empty($websiteConfig)) {
        $websiteConfig = \FriendsOfRedaxo\JsonLdManager\LanguageConfig::getLocalizedConfig($addon, 'website_schema', $activeClangId, []);
    }
} else {
    $websiteConfig = \FriendsOfRedaxo\JsonLdManager\LanguageConfig::getLocalizedConfig($addon, 'website_schema', $activeClangId, []);
}

// Funktion zur Generierung der Website JSON-LD Daten
function generateWebsiteJsonLd($websiteConfig, $autoLanguageCode) {
    if (empty($websiteConfig)) {
        return null;
    }
    
    $jsonld = [
        '@context' => 'https://schema.org',
        '@type' => 'WebSite'
    ];
    
    if (!empty($websiteConfig['name'])) {
        $jsonld['name'] = $websiteConfig['name'];
    }
    if (!empty($websiteConfig['url'])) {
        $jsonld['url'] = $websiteConfig['url'];
    }
    if (!empty($websiteConfig['description'])) {
        $jsonld['description'] = $websiteConfig['description'];
    }
    if (!empty($autoLanguageCode)) {
        $jsonld['inLanguage'] = $autoLanguageCode;
    }
    
    // Suchfunktion hinzufügen wenn aktiviert
    if (!empty($websiteConfig['potentialAction']['enabled']) && !empty($websiteConfig['potentialAction']['target'])) {
        $searchUrl = $websiteConfig['potentialAction']['target'];
        if (strpos($searchUrl, '{search_term_string}') !== false) {
            $jsonld['potentialAction'] = [
                '@type' => 'SearchAction',
                'target' => $searchUrl,
                'query-input' => 'required name=search_term_string'
            ];
        }
    }
    
    return $jsonld;
}

// JSON-LD Daten für die Vorschau generieren
$currentJsonLd = generateWebsiteJsonLd($websiteConfig, $autoLanguageCode);
$initialJsonOutput = $currentJsonLd ? json_encode($currentJsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : '// Noch keine Konfiguration vorhanden\n// Füllen Sie das Formular aus, um eine JSON-LD Vorschau zu erhalten';

ob_start();

echo \FriendsOfRedaxo\JsonLdManager\LanguageConfig::renderClangTabs($activeClangId);

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
</style>';

echo '<script>
function updateWebsitePreview() {
    const name = document.getElementById("website_name")?.value || "";
    const url = document.getElementById("website_url")?.value || "";
    const description = document.getElementById("website_description")?.value || "";
    const inLanguage = ' . json_encode($autoLanguageCode, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';
    const searchEnabled = !!document.querySelector("input[name=search_enabled]:checked");
    const searchUrl = document.getElementById("search_url")?.value || "";

    const jsonld = {
        "@context": "https://schema.org",
        "@type": "WebSite"
    };

    if (name) jsonld.name = name;
    if (url) jsonld.url = url;
    if (description) jsonld.description = description;
    if (inLanguage) jsonld.inLanguage = inLanguage;

    const searchHelp = document.getElementById("search_url_help");
    let searchValid = true;
    let searchHint = "Verwende {search_term_string} als Platzhalter.";

    if (searchEnabled) {
        if (!searchUrl.trim()) {
            searchValid = false;
            searchHint = "Bitte Such-URL eintragen, wenn die Suchfunktion aktiv ist.";
        } else if (searchUrl.indexOf("{search_term_string}") === -1) {
            searchValid = false;
            searchHint = "Die Such-URL muss den Platzhalter {search_term_string} enthalten.";
        }
    }

    if (searchHelp) {
        searchHelp.textContent = searchHint;
        searchHelp.style.color = searchValid ? "#999" : "#d9534f";
    }

    if (searchEnabled && searchValid && searchUrl) {
        jsonld.potentialAction = {
            "@type": "SearchAction",
            "target": searchUrl,
            "query-input": "required name=search_term_string"
        };
    }

    const preview = document.getElementById("json-preview");
    if (preview) {
        preview.textContent = JSON.stringify(jsonld, null, 2);
    }
}

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

document.addEventListener("DOMContentLoaded", function() {
    const form = document.getElementById("website-main-form");
    if (form) {
        form.addEventListener("input", updateWebsitePreview);
        form.addEventListener("change", updateWebsitePreview);
    }

    updateWebsitePreview();
    initPreviewFloating();
});
</script>';

echo '<div class="row">';
echo '  <div class="col-md-6">';

echo '    <form method="post" id="website-main-form">';
echo          $csrfTokenField;
echo '      <input type="hidden" name="website_action" value="save">';
echo '      <input type="hidden" name="domain_id" value="' . $activeDomainId . '">';

echo '      <div class="panel panel-primary">';
echo '        <header class="panel-heading"><h1 class="panel-title">Grunddaten</h1></header>';
echo '        <div class="panel-body">';

echo '          <div class="form-group">';
echo '            <label for="website_name">Website-Name:</label>';
echo '            <input type="text" name="website_name" id="website_name" class="form-control" value="' . htmlspecialchars($websiteConfig['name'] ?? '') . '" placeholder="Meine Website" required>';
echo '          </div>';

echo '          <div class="form-group">';
echo '            <label for="website_url">Website-URL:</label>';
echo '            <input type="url" name="website_url" id="website_url" class="form-control" value="' . htmlspecialchars($websiteConfig['url'] ?? getWebsiteUrlForDomain($activeDomainId)) . '" placeholder="https://example.com" required>';
echo '          </div>';

echo '          <div class="form-group">';
echo '            <label for="website_description">Beschreibung:</label>';
echo '            <textarea name="website_description" id="website_description" class="form-control" rows="3" placeholder="Kurze Beschreibung der Website">' . htmlspecialchars($websiteConfig['description'] ?? '') . '</textarea>';
echo '          </div>';

echo '          <div class="form-group">';
echo '            <label>Sprache:</label>';
echo '            <input type="text" class="form-control" value="' . htmlspecialchars($autoLanguageCode) . '" readonly>';
echo '            <small class="help-block" style="color: #999;">Wird automatisch aus der aktuellen REDAXO-Sprache gesetzt.</small>';
echo '          </div>';

echo '          <hr style="border-color:#3d4a5a;">';

echo '          <div class="form-group">';
echo '            <label>';
echo '              <input type="checkbox" name="search_enabled" value="1"' . (($websiteConfig['potentialAction']['enabled'] ?? 0) ? ' checked' : '') . '> Website hat eine Suchfunktion';
echo '            </label>';
echo '          </div>';

echo '          <div class="form-group">';
echo '            <label for="search_url">Such-URL:</label>';
echo '            <input type="text" name="search_url" id="search_url" class="form-control" value="' . htmlspecialchars($websiteConfig['potentialAction']['target'] ?? '') . '" placeholder="https://example.com/search?q={search_term_string}">';
echo '            <small id="search_url_help" class="help-block" style="color: #999;">Verwende {search_term_string} als Platzhalter.</small>';
echo '          </div>';

echo '        </div>';
echo '      </div>';
echo '    </form>';

echo '  </div>';
echo '  <div class="col-md-6 jsonld-preview-col">';
echo '    <div class="jsonld-preview-sticky">';
echo '      <pre id="json-preview" style="background: #f8f9fa; color: #333; border: 1px solid #ddd; padding: 15px; min-height: 400px; font-size: 12px; overflow-x: auto; font-family: Monaco, Menlo, monospace; border-radius: 4px;">' . htmlspecialchars($initialJsonOutput) . '</pre>';
echo '    </div>';
echo '  </div>';
echo '</div>';

echo '<style>';
echo '#jsonld-manager .rex-form-panel-footer {';
echo '    padding: 12px !important;';
echo '    background: rgba(0, 0, 0, .28) !important;';
echo '    border-top: 1px solid rgba(255, 255, 255, .08) !important;';
echo '    display: flex !important;';
echo '    justify-content: flex-end !important;';
echo '    align-items: center !important;';
echo '}';
echo '#jsonld-manager .rex-form-panel-footer .btn-toolbar {';
echo '    margin: 0 !important;';
echo '    width: auto !important;';
echo '    float: none !important;';
echo '}';
echo '#jsonld-manager .rex-form-panel-footer .btn {';
echo '    font-size: 14px;';
echo '    line-height: 1.3;';
echo '    padding-top: 7px;';
echo '    padding-bottom: 7px;';
echo '    float: none !important;';
echo '}';
echo '</style>';

echo '<div id="jsonld-manager">';
echo '<div class="rex-form-panel-footer">';
echo '  <div class="btn-toolbar">';
echo '    <button type="submit" form="website-main-form" name="website_save" class="btn btn-apply" value="1">Speichern</button>';
echo '  </div>';
echo '</div>';
echo '</div>';

$content = ob_get_clean();

$fragment = new rex_fragment();
$fragment->setVar('title', 'WebSite Schema');
$fragment->setVar('body', $content, false);
echo $fragment->parse('core/page/section.php');
