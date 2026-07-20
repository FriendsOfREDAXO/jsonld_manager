<?php
/**
 * JSON-LD Manager - Person Schema
 */
use FriendsOfRedaxo\JsonLdManager\LanguageConfig;
use FriendsOfRedaxo\JsonLdManager\DomainConfig;
use FriendsOfRedaxo\JsonLdManager\CustomJsonLdHelper;

/**
 * Liste aus Eingabefeld parsen (Zeilen oder kommagetrennt).
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

$personAction = rex_post('person_action', 'string', '');
$addon = rex_addon::get('jsonld_manager');
$activeClangId = LanguageConfig::getActiveClangId();
$activeDomainId = DomainConfig::getActiveDomainId();
$csrfToken = rex_csrf_token::factory('jsonld_manager_global_person');
$csrfTokenField = $csrfToken->getHiddenField();

// Website-URL basierend auf aktiver Domain ermitteln
function getWebsiteUrlForDomain(?int $domainId = null): string {
    if (DomainConfig::isMultiDomain() && $domainId) {
        $activeDomain = DomainConfig::getActiveDomain();
        if ($activeDomain && isset($activeDomain['domain'])) {
            $domain = (string) $activeDomain['domain'];
            if (strpos($domain, 'http') !== 0) {
                $protocol = (strpos($domain, 'local') !== false) ? 'http://' : 'https://';
                return rtrim($protocol . $domain, '/');
            }
            return rtrim($domain, '/');
        }
    }
    return rex::getServer();
}

if ($personAction === 'save') {
    if (!$csrfToken->isValid()) {
        echo rex_view::error('Sicherheitsprüfung fehlgeschlagen (CSRF). Bitte Seite neu laden.');
    } else {
    $sameAs = jsonld_manager_parse_list_input(rex_post('person_same_as', 'string', ''));

    $customRaw = rex_post('person_custom_jsonld_raw', 'string', '');
    $customParse = CustomJsonLdHelper::parseCustomObject($customRaw);

    if (!empty($customParse['errors'])) {
        echo rex_view::error(implode(' ', $customParse['errors']));
    } else {
    $config = [
        'name' => rex_post('person_name', 'string', ''),
        'jobTitle' => rex_post('person_job_title', 'string', ''),
        'url' => rex_post('person_url', 'string', ''),
        'image' => rex_post('person_image', 'string', ''),
        'sameAs' => $sameAs,
        'custom_jsonld_raw' => $customParse['raw'],
        'custom_jsonld' => $customParse['data'],
    ];

    // Domain + Sprach-spezifische Konfiguration speichern
    if (DomainConfig::isMultiDomain()) {
        $configKey = 'person_schema_domain_' . $activeDomainId . '_clang_' . $activeClangId;
        $addon->setConfig($configKey, $config);
    } else {
        LanguageConfig::setLocalizedConfig($addon, 'person_schema', $activeClangId, $config);
    }

    echo rex_view::success('Person Schema wurde gespeichert.');
    if (!empty($customParse['warnings'])) {
        echo rex_view::warning(implode('<br>', array_map('htmlspecialchars', $customParse['warnings'])));
    }
    }
    }
}

// Domain + Sprach-spezifische Konfiguration laden
if (DomainConfig::isMultiDomain()) {
    $configKey = 'person_schema_domain_' . $activeDomainId . '_clang_' . $activeClangId;
    $personConfig = $addon->getConfig($configKey, []);
    if (empty($personConfig)) {
        $personConfig = LanguageConfig::getLocalizedConfig($addon, 'person_schema', $activeClangId, []);
    }
} else {
    $personConfig = LanguageConfig::getLocalizedConfig($addon, 'person_schema', $activeClangId, []);
}

$mediaBaseUrl = rtrim(getWebsiteUrlForDomain($activeDomainId), '/') . '/media/';

ob_start();

echo LanguageConfig::renderClangTabs($activeClangId);

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
    background: #f8f9fa;
    color: #333;
    border: 1px solid #ddd;
    padding: 15px;
    min-height: 260px;
    max-height: calc(100vh - 170px);
    overflow: auto;
    font-size: 12px;
    font-family: Monaco, Menlo, monospace;
    margin-bottom: 12px;
    border-radius: 4px;
}
</style>';

echo '<script>
const personMediaBaseUrl = ' . json_encode($mediaBaseUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';

function personImageToUrl(file) {
    const value = (file || "").trim();
    if (!value) {
        return "";
    }
    if (/^https?:\/\//i.test(value)) {
        return value;
    }
    return personMediaBaseUrl + encodeURIComponent(value).replace(/%2F/g, "/");
}

function updatePersonPreview() {
    const name = document.getElementById("person_name")?.value || "";
    const jobTitle = document.getElementById("person_job_title")?.value || "";
    const url = document.getElementById("person_url")?.value || "";
    const imageRaw = document.querySelector("input[name=person_image]") ? document.querySelector("input[name=person_image]").value : "";
    const sameAsRaw = document.getElementById("person_same_as")?.value || "";
    const customRaw = document.getElementById("person_custom_jsonld_raw")?.value || "";

    const sameAs = sameAsRaw.split(/[\n,]+/).map(function(v) { return v.trim(); }).filter(Boolean);

    const jsonld = {
        "@context": "https://schema.org",
        "@type": "Person"
    };

    if (name) jsonld.name = name;
    if (jobTitle) jsonld.jobTitle = jobTitle;
    if (url) jsonld.url = url;
    const imageUrl = personImageToUrl(imageRaw);
    if (imageUrl) jsonld.image = imageUrl;
    if (sameAs.length) jsonld.sameAs = sameAs;

    try {
        const custom = parseCustomJsonObject(customRaw);
        if (custom) {
            mergeCustomIntoSchema(jsonld, custom);
        }
        setCustomJsonHint("");
    } catch (err) {
        setCustomJsonHint(err.message || "Ungültiges Custom-JSON.");
    }

    const preview = document.getElementById("json-preview");
    if (preview) {
        preview.textContent = JSON.stringify(jsonld, null, 2);
    }
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
    const help = document.getElementById("person_custom_jsonld_help");
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
    const form = document.getElementById("person-main-form");
    if (form) {
        form.addEventListener("input", updatePersonPreview);
        form.addEventListener("change", updatePersonPreview);
    }

    updatePersonPreview();
    initPreviewFloating();
});
</script>';

echo '<div class="row">';
echo '  <div class="col-md-6">';

echo '    <form method="post" id="person-main-form">';
echo          $csrfTokenField;
echo '      <input type="hidden" name="person_action" value="save">';
echo '      <input type="hidden" name="domain_id" value="' . $activeDomainId . '">';

echo '      <div class="panel panel-primary">';
echo '        <header class="panel-heading"><h1 class="panel-title">Person</h1></header>';
echo '        <div class="panel-body">';

echo '          <div class="form-group">';
echo '            <label for="person_name">Name:</label>';
echo '            <input type="text" name="person_name" id="person_name" class="form-control" value="' . htmlspecialchars($personConfig['name'] ?? '') . '" placeholder="Max Mustermann" required>';
echo '          </div>';

echo '          <div class="form-group">';
echo '            <label for="person_job_title">Berufsbezeichnung (jobTitle):</label>';
echo '            <input type="text" name="person_job_title" id="person_job_title" class="form-control" value="' . htmlspecialchars($personConfig['jobTitle'] ?? '') . '" placeholder="Bildender Künstler">';
echo '          </div>';

echo '          <div class="form-group">';
echo '            <label for="person_url">Website/URL:</label>';
echo '            <input type="url" name="person_url" id="person_url" class="form-control" value="' . htmlspecialchars($personConfig['url'] ?? getWebsiteUrlForDomain($activeDomainId)) . '" placeholder="https://example.at">';
echo '          </div>';

echo '        </div>';
echo '      </div>';

echo '      <div class="panel panel-primary">';
echo '        <header class="panel-heading"><h1 class="panel-title">Portrait/Bild</h1></header>';
echo '        <div class="panel-body">';

$personImageWidget = rex_var_media::getWidget(
    3101,
    'person_image',
    (string) ($personConfig['image'] ?? ''),
    ['types' => 'jpg,jpeg,png,gif,webp,avif', 'preview' => true]
);

echo '          <div class="form-group">';
echo '            <label for="person_image">Portrait:</label>';
echo            $personImageWidget;
echo '            <small class="help-block" style="color: #999;">Ausgabe in JSON-LD als <code>image</code> (absolute URL). Empfehlung: Quadrat oder Hochformat, mindestens 400×400.</small>';
echo '          </div>';

echo '        </div>';
echo '      </div>';

echo '      <div class="panel panel-primary">';
echo '        <header class="panel-heading"><h1 class="panel-title">Profile/Links (sameAs)</h1></header>';
echo '        <div class="panel-body">';

echo '          <div class="form-group">';
echo '            <label for="person_same_as">Profile/Links:</label>';
echo '            <textarea name="person_same_as" id="person_same_as" class="form-control" rows="4" placeholder="https://www.instagram.com/...&#10;https://www.wikidata.org/wiki/...">' . htmlspecialchars(implode("\n", (array) ($personConfig['sameAs'] ?? []))) . '</textarea>';
echo '            <small class="help-block" style="color: #999;">Eine URL pro Zeile oder kommagetrennt (z. B. Social-Media, Wikidata).</small>';
echo '          </div>';

echo '        </div>';
echo '      </div>';

echo '      <div class="panel panel-primary">';
echo '        <header class="panel-heading"><h1 class="panel-title">Custom Angaben</h1></header>';
echo '        <div class="panel-body">';
echo '          <div class="form-group">';
echo '            <label for="person_custom_jsonld_raw">Zusätzliche JSON-LD Felder (JSON-Objekt):</label>';
echo '            <textarea name="person_custom_jsonld_raw" id="person_custom_jsonld_raw" class="form-control" rows="10" placeholder="{&#10;  &quot;knowsAbout&quot;: [&quot;Malerei&quot;, &quot;zeitgenössische Kunst&quot;],&#10;  &quot;alumniOf&quot;: {&#10;    &quot;@type&quot;: &quot;EducationalOrganization&quot;,&#10;    &quot;name&quot;: &quot;Akademie der bildenden Künste Wien&quot;&#10;  }&#10;}">' . htmlspecialchars((string) ($personConfig['custom_jsonld_raw'] ?? '')) . '</textarea>';
echo '            <small id="person_custom_jsonld_help" class="help-block" style="color: #999;">Optionales JSON-Objekt mit Zusatzfeldern (z. B. knowsAbout, alumniOf, memberOf, award, nationality). @context, @type und @id werden ignoriert.</small>';
echo '          </div>';
echo '        </div>';
echo '      </div>';
echo '    </form>';

echo '  </div>';
echo '  <div class="col-md-6 jsonld-preview-col">';
echo '    <div class="jsonld-preview-sticky">';
echo '      <pre id="json-preview">Geben Sie Daten ein um eine Vorschau zu sehen...</pre>';
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
echo '    <button type="submit" form="person-main-form" name="person_save" class="btn btn-apply" value="1">Speichern</button>';
echo '  </div>';
echo '</div>';
echo '</div>';

$content = ob_get_clean();

$fragment = new rex_fragment();
$fragment->setVar('title', 'Person Schema', false);
$fragment->setVar('body', $content, false);
echo $fragment->parse('core/page/section.php');
