<?php
/**
 * JSON-LD Manager - Organization Schema
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

$organizationAction = rex_post('organization_action', 'string', '');
$addon = rex_addon::get('jsonld_manager');
$activeClangId = LanguageConfig::getActiveClangId();
$activeDomainId = DomainConfig::getActiveDomainId();
$csrfToken = rex_csrf_token::factory('jsonld_manager_schemas');
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

if ($organizationAction === 'save') {
    if (!$csrfToken->isValid()) {
        echo rex_view::error('Sicherheitsprüfung fehlgeschlagen (CSRF). Bitte Seite neu laden.');
    } else {
    $sameAs = jsonld_manager_parse_list_input(rex_post('org_same_as', 'string', ''));
    
    $customRaw = rex_post('org_custom_jsonld_raw', 'string', '');
    $customParse = CustomJsonLdHelper::parseCustomObject($customRaw);

    if (!empty($customParse['errors'])) {
        echo rex_view::error(implode(' ', $customParse['errors']));
    } else {
    $config = [
        'name' => rex_post('org_name', 'string', ''),
        'url' => rex_post('org_url', 'string', ''),
        'logo' => rex_post('org_logo', 'string', ''),
        'description' => rex_post('org_description', 'string', ''),
        'sameAs' => $sameAs,
        'address' => [
            'streetAddress' => rex_post('org_street', 'string', ''),
            'addressLocality' => rex_post('org_city', 'string', ''),
            'postalCode' => rex_post('org_postal', 'string', ''),
            'addressCountry' => rex_post('org_country', 'string', 'DE'),
        ],
        'contactPoint' => [
            'telephone' => rex_post('org_phone', 'string', ''),
            'email' => rex_post('org_email', 'string', ''),
            'contactType' => rex_post('org_contact_type', 'string', ''),
        ],
        'custom_jsonld_raw' => $customParse['raw'],
        'custom_jsonld' => $customParse['data'],
    ];

    // Domain + Sprach-spezifische Konfiguration speichern
    if (DomainConfig::isMultiDomain()) {
        $configKey = 'organization_schema_domain_' . $activeDomainId . '_clang_' . $activeClangId;
        $addon->setConfig($configKey, $config);
    } else {
        LanguageConfig::setLocalizedConfig($addon, 'organization_schema', $activeClangId, $config);
    }
    
    echo rex_view::success('Organization Schema wurde gespeichert.');
    if (!empty($customParse['warnings'])) {
        echo rex_view::warning(implode('<br>', array_map('htmlspecialchars', $customParse['warnings'])));
    }
    }
    }
}

// Domain + Sprach-spezifische Konfiguration laden
if (DomainConfig::isMultiDomain()) {
    $configKey = 'organization_schema_domain_' . $activeDomainId . '_clang_' . $activeClangId;
    $organizationConfig = $addon->getConfig($configKey, []);
    // Fallback zu sprachspezifischer Konfiguration wenn domain-spezifische nicht existiert
    if (empty($organizationConfig)) {
        $organizationConfig = LanguageConfig::getLocalizedConfig($addon, 'organization_schema', $activeClangId, []);
    }
} else {
    $organizationConfig = LanguageConfig::getLocalizedConfig($addon, 'organization_schema', $activeClangId, []);
}

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
function updateOrganizationPreview() {
    const name = document.getElementById("org_name")?.value || "";
    const url = document.getElementById("org_url")?.value || "";
    const logo = document.getElementById("org_logo")?.value || "";
    const description = document.getElementById("org_description")?.value || "";
    const sameAsRaw = document.getElementById("org_same_as")?.value || "";
    const customRaw = document.getElementById("org_custom_jsonld_raw")?.value || "";

    const street = document.getElementById("org_street")?.value || "";
    const city = document.getElementById("org_city")?.value || "";
    const postal = document.getElementById("org_postal")?.value || "";
    const country = document.getElementById("org_country")?.value || "";

    const phone = document.getElementById("org_phone")?.value || "";
    const email = document.getElementById("org_email")?.value || "";
    const contactType = document.getElementById("org_contact_type")?.value || "";

    const sameAs = sameAsRaw.split(/[\n,]+/).map(function(v) { return v.trim(); }).filter(Boolean);

    const jsonld = {
        "@context": "https://schema.org",
        "@type": "Organization"
    };

    if (name) jsonld.name = name;
    if (url) jsonld.url = url;
    if (logo) jsonld.logo = logo;
    if (description) jsonld.description = description;
    if (sameAs.length) jsonld.sameAs = sameAs;

    if (street || city || postal || country) {
        jsonld.address = {"@type": "PostalAddress"};
        if (street) jsonld.address.streetAddress = street;
        if (city) jsonld.address.addressLocality = city;
        if (postal) jsonld.address.postalCode = postal;
        if (country) jsonld.address.addressCountry = country;
    }

    if (phone || email || contactType) {
        jsonld.contactPoint = {"@type": "ContactPoint"};
        if (phone) jsonld.contactPoint.telephone = phone;
        if (email) jsonld.contactPoint.email = email;
        if (contactType) jsonld.contactPoint.contactType = contactType;
    }

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
    const help = document.getElementById("org_custom_jsonld_help");
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
    const form = document.getElementById("organization-main-form");
    if (form) {
        form.addEventListener("input", updateOrganizationPreview);
        form.addEventListener("change", updateOrganizationPreview);
    }

    updateOrganizationPreview();
    initPreviewFloating();
});
</script>';

echo '<div class="row">';
echo '  <div class="col-md-6">';

echo '    <form method="post" id="organization-main-form">';
echo          $csrfTokenField;
echo '      <input type="hidden" name="organization_action" value="save">';
echo '      <input type="hidden" name="domain_id" value="' . $activeDomainId . '">';

echo '      <div class="panel panel-primary">';
echo '        <header class="panel-heading"><h1 class="panel-title">Organisation</h1></header>';
echo '        <div class="panel-body">';

echo '          <div class="form-group">';
echo '            <label for="org_name">Firmenname:</label>';
echo '            <input type="text" name="org_name" id="org_name" class="form-control" value="' . htmlspecialchars($organizationConfig['name'] ?? '') . '" placeholder="Meine Firma GmbH" required>';
echo '          </div>';

echo '          <div class="form-group">';
echo '            <label for="org_url">Website:</label>';
echo '            <input type="url" name="org_url" id="org_url" class="form-control" value="' . htmlspecialchars($organizationConfig['url'] ?? getWebsiteUrlForDomain($activeDomainId)) . '" placeholder="https://example.com" required>';
echo '          </div>';

echo '          <div class="form-group">';
echo '            <label for="org_logo">Logo URL (absolut):</label>';
echo '            <input type="url" name="org_logo" id="org_logo" class="form-control" value="' . htmlspecialchars($organizationConfig['logo'] ?? '') . '" placeholder="https://example.com/logo.png">';
echo '            <small class="help-block">Empfehlung: PNG oder JPG im Quadratformat oder Querformat. Ideal 1200×600 oder 800×800, mindestens 600×315 für optimale Darstellung.</small>';
echo '          </div>';

echo '          <div class="form-group">';
echo '            <label for="org_description">Kurze Firmenbeschreibung:</label>';
echo '            <textarea name="org_description" id="org_description" class="form-control" rows="3" placeholder="Was macht Ihr Unternehmen?">' . htmlspecialchars($organizationConfig['description'] ?? '') . '</textarea>';
echo '          </div>';

echo '          <div class="form-group">';
echo '            <label for="org_same_as">Profile/Links (sameAs):</label>';
echo '            <textarea name="org_same_as" id="org_same_as" class="form-control" rows="4" placeholder="https://www.instagram.com/...&#10;https://www.facebook.com/...">' . htmlspecialchars(implode("\n", (array) ($organizationConfig['sameAs'] ?? []))) . '</textarea>';
echo '            <small class="help-block">Eine URL pro Zeile oder kommagetrennt.</small>';
echo '          </div>';

echo '        </div>';
echo '      </div>';

echo '      <div class="panel panel-primary">';
echo '        <header class="panel-heading"><h1 class="panel-title">Kontaktstelle (ContactPoint)</h1></header>';
echo '        <div class="panel-body">';

echo '          <div class="form-group">';
echo '            <label for="org_phone">Telefon:</label>';
echo '            <input type="tel" name="org_phone" id="org_phone" class="form-control" value="' . htmlspecialchars($organizationConfig['contactPoint']['telephone'] ?? '') . '" placeholder="+49 123 456789">';
echo '          </div>';

echo '          <div class="form-group">';
echo '            <label for="org_email">E-Mail:</label>';
echo '            <input type="email" name="org_email" id="org_email" class="form-control" value="' . htmlspecialchars($organizationConfig['contactPoint']['email'] ?? '') . '" placeholder="info@example.com">';
echo '          </div>';

echo '          <div class="form-group">';
echo '            <label for="org_contact_type">Kontakt-Art:</label>';
echo '            <select name="org_contact_type" id="org_contact_type" class="form-control selectpicker" data-live-search="true" data-size="8">';
$contactType = $organizationConfig['contactPoint']['contactType'] ?? '';
echo '              <option value=""' . ($contactType === '' ? ' selected' : '') . '>Bitte wählen</option>';
echo '              <option value="customer service"' . ($contactType === 'customer service' ? ' selected' : '') . '>Kundenservice</option>';
echo '              <option value="sales"' . ($contactType === 'sales' ? ' selected' : '') . '>Vertrieb</option>';
echo '              <option value="support"' . ($contactType === 'support' ? ' selected' : '') . '>Support</option>';
echo '            </select>';
echo '          </div>';

echo '        </div>';
echo '      </div>';

echo '      <div class="panel panel-primary">';
echo '        <header class="panel-heading"><h1 class="panel-title">Adresse</h1></header>';
echo '        <div class="panel-body">';

echo '          <div class="form-group">';
echo '            <label for="org_street">Straße & Hausnummer:</label>';
echo '            <input type="text" name="org_street" id="org_street" class="form-control" value="' . htmlspecialchars($organizationConfig['address']['streetAddress'] ?? '') . '" placeholder="Musterstraße 123">';
echo '          </div>';

echo '          <div class="form-group">';
echo '            <label for="org_postal">PLZ:</label>';
echo '            <input type="text" name="org_postal" id="org_postal" class="form-control" value="' . htmlspecialchars($organizationConfig['address']['postalCode'] ?? '') . '" placeholder="12345">';
echo '          </div>';

echo '          <div class="form-group">';
echo '            <label for="org_city">Stadt:</label>';
echo '            <input type="text" name="org_city" id="org_city" class="form-control" value="' . htmlspecialchars($organizationConfig['address']['addressLocality'] ?? '') . '" placeholder="Musterstadt">';
echo '          </div>';

echo '          <div class="form-group">';
echo '            <label for="org_country">Land:</label>';
echo '            <select name="org_country" id="org_country" class="form-control selectpicker" data-live-search="true" data-size="8">';
$country = $organizationConfig['address']['addressCountry'] ?? 'DE';
echo '              <option value="DE"' . ($country === 'DE' ? ' selected' : '') . '>Deutschland</option>';
echo '              <option value="AT"' . ($country === 'AT' ? ' selected' : '') . '>Österreich</option>';
echo '              <option value="CH"' . ($country === 'CH' ? ' selected' : '') . '>Schweiz</option>';
echo '            </select>';
echo '          </div>';

echo '        </div>';
echo '      </div>';

echo '      <div class="panel panel-primary">';
echo '        <header class="panel-heading"><h1 class="panel-title">Custom Angaben</h1></header>';
echo '        <div class="panel-body">';
echo '          <div class="form-group">';
echo '            <label for="org_custom_jsonld_raw">Zusätzliche JSON-LD Felder (JSON-Objekt):</label>';
echo '            <textarea name="org_custom_jsonld_raw" id="org_custom_jsonld_raw" class="form-control" rows="8" placeholder="{&#10;  &quot;keywords&quot;: [&quot;jsonld&quot;, &quot;seo&quot;],&#10;  &quot;additionalType&quot;: &quot;https://example.com/types/custom&quot;&#10;}">' . htmlspecialchars((string) ($organizationConfig['custom_jsonld_raw'] ?? '')) . '</textarea>';
echo '            <small id="org_custom_jsonld_help" class="help-block" style="color: #999;">Optionales JSON-Objekt mit Zusatzfeldern. @context, @type und @id werden ignoriert.</small>';
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
echo '    <button type="submit" form="organization-main-form" name="organization_save" class="btn btn-apply" value="1">Speichern</button>';
echo '  </div>';
echo '</div>';
echo '</div>';

$content = ob_get_clean();

$fragment = new rex_fragment();
$fragment->setVar('title', 'Organization Schema', false);
$fragment->setVar('body', $content, false);
echo $fragment->parse('core/page/section.php');
