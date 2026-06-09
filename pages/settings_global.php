<?php
use FriendsOfRedaxo\JsonLdManager\LanguageConfig;

/**
 * JSON-LD Manager - Allgemeine Angaben
 * Übersicht über alle globalen Schema-Einstellungen
 */

// Output Buffering für Fragment
ob_start();
$addon = rex_addon::get('jsonld_manager');
$activeClangId = LanguageConfig::getActiveClangId();

echo LanguageConfig::renderClangTabs($activeClangId);

// === CSS FÜR PROFESSIONELLES DESIGN ===
echo '<style>
.global-overview {
    background: #2b3643;
    color: #fff;
    border-radius: 6px;
    padding: 35px;
    margin: 20px 0;
}
.global-overview .card {
    background: linear-gradient(135deg, #33414e 0%, #3a4952 100%);
    border: 1px solid #4a5769;
    border-radius: 8px;
    padding: 25px;
    margin-bottom: 20px;
    transition: all 0.3s ease;
    text-decoration: none;
    display: block;
    color: #fff;
}
.global-overview .card:hover {
    background: linear-gradient(135deg, #3a4952 0%, #414f5a 100%);
    border-color: #5cb85c;
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.3);
    color: #fff;
    text-decoration: none;
}
.global-overview .card h3 {
    color: #fff;
    margin: 0 0 15px 0;
    font-weight: 600;
    font-size: 18px;
}
.global-overview .card p {
    color: #c8d7e5;
    margin: 0;
    line-height: 1.5;
}
.global-overview .card .icon {
    font-size: 24px;
    margin-right: 15px;
    color: #5cb85c;
}
.global-overview .alert {
    border-radius: 6px;
    padding: 20px 25px;
    margin-bottom: 25px;
    border: none;
    font-weight: 500;
}
.global-overview .alert-info {
    background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
    color: #fff;
}
.global-overview h2 {
    color: #fff;
    font-weight: 600;
    margin-bottom: 10px;
}
.global-overview .priority {
    background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
    color: #fff;
    padding: 3px 8px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: 600;
    margin-left: 10px;
}
.global-overview .recommended {
    background: linear-gradient(135deg, #5cb85c 0%, #449d44 100%);
    color: #fff;
    padding: 3px 8px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: 600;
    margin-left: 10px;
}
.global-overview .optional {
    background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%);
    color: #fff;
    padding: 3px 8px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: 600;
    margin-left: 10px;
}
</style>';

echo '<div class="global-overview">';

// === HEADER ===
echo '<div style="margin-bottom: 35px;">
    <h2 style="margin: 0; color: #fff; font-size: 26px; font-weight: 600;">
        <i class="rex-icon fa-cog"></i> Allgemeine Angaben - Globale Schema-Einstellungen
    </h2>
    <p style="margin: 15px 0 0 0; color: #c8d7e5; font-size: 16px;">
        Diese Einstellungen gelten für die gesamte Website und müssen nur einmal konfiguriert werden.
    </p>
</div>';

// === INFO BOX ===
echo '<div class="alert alert-info">
    <h4><i class="rex-icon fa-lightbulb-o"></i> Wichtiger Hinweis zur Reihenfolge</h4>
    <p><strong>Empfohlene Konfigurationsreihenfolge:</strong></p>
    <ol style="margin: 10px 0 10px 20px; color: #e6f7ff;">
        <li><strong>Organization Schema:</strong> Zuerst konfigurieren - ist die Basis für alle anderen</li>
        <li><strong>WebSite Schema:</strong> Für die Startseite (referenziert Organization)</li>
        <li><strong>BreadcrumbList Schema:</strong> Navigation (sehr wichtig für SEO)</li>
        <li><strong>LocalBusiness Schema:</strong> Nur wenn Sie wirklich einen physischen Standort haben</li>
    </ol>
</div>';

// Status der Konfigurationen prüfen
$organizationConfig = LanguageConfig::getLocalizedConfig($addon, 'organization_schema', $activeClangId, []);
$websiteConfig = LanguageConfig::getLocalizedConfig($addon, 'website_schema', $activeClangId, []);
// BreadcrumbList wird jetzt automatisch immer ausgegeben
$localBusinessConfig = LanguageConfig::getLocalizedConfig($addon, 'localbusiness_schema', $activeClangId, []);

// === SCHEMA KARTEN ===
echo '<div class="row">';

// Organization Schema
echo '<div class="col-md-6">
    <a href="' . rex_url::backendPage('jsonld_manager/settings_global/schemas') . '" class="card">
        <h3>
            <i class="icon rex-icon fa-building"></i>
            Organization Schema
            <span class="priority">WICHTIG</span>
        </h3>
        <p><strong>Status:</strong> ' . (!empty($organizationConfig['name']) ? '✅ Konfiguriert (' . htmlspecialchars($organizationConfig['name']) . ')' : '⚠️ Noch nicht konfiguriert') . '</p>
        <p><strong>Zweck:</strong> Zentrale Unternehmensdaten - Basis für alle anderen Schemas</p>
        <p><strong>Ausgabe:</strong> Auf der Startseite als Publisher-Referenz für alle anderen Schemas</p>
    </a>
</div>';

// WebSite Schema  
echo '<div class="col-md-6">
    <a href="' . rex_url::backendPage('jsonld_manager/settings_global/global_website') . '" class="card">
        <h3>
            <i class="icon rex-icon fa-globe"></i>
            WebSite Schema
            <span class="recommended">EMPFOHLEN</span>
        </h3>
        <p><strong>Status:</strong> ' . (!empty($websiteConfig['name']) ? '✅ Konfiguriert (' . htmlspecialchars($websiteConfig['name']) . ')' : '⚠️ Noch nicht konfiguriert') . '</p>
        <p><strong>Zweck:</strong> Globale Website-Informationen für die Startseite</p>
        <p><strong>Ausgabe:</strong> Nur auf der Startseite zusammen mit Organization Schema</p>
    </a>
</div>';

echo '</div><div class="row">';

// BreadcrumbList Schema - INFO: Wird jetzt automatisch immer ausgegeben
echo '<div class="col-md-6">
    <div class="card card-success">
        <h3>
            <i class="icon rex-icon fa-sitemap"></i>
            BreadcrumbList Schema
            <span class="badge badge-success">AUTOMATISCH</span>
        </h3>
        <p><strong>Status:</strong> ✅ Immer aktiv (automatische Ausgabe)</p>
        <p><strong>Zweck:</strong> Automatische Navigation basierend auf REDAXO-Struktur</p>
        <p><strong>Ausgabe:</strong> Auf allen Seiten - extrem wichtig für SEO!</p>
        <p><em>Keine Konfiguration erforderlich - wird automatisch generiert.</em></p>
    </div>
</div>';

// LocalBusiness Schema
echo '<div class="col-md-6">
    <a href="' . rex_url::backendPage('jsonld_manager/settings_global/global_localbusiness') . '" class="card">
        <h3>
            <i class="icon rex-icon fa-map-marker"></i>
            LocalBusiness Schema
            <span class="optional">OPTIONAL</span>
        </h3>
        <p><strong>Status:</strong> ' . (($localBusinessConfig['enabled'] ?? 0) ? '✅ Aktiviert (' . htmlspecialchars($localBusinessConfig['name'] ?? 'Unbenannt') . ')' : '❌ Deaktiviert (Standard)') . '</p>
        <p><strong>Zweck:</strong> Für lokale Geschäfte mit physischem Standort</p>
        <p><strong>Wichtig:</strong> Nur aktivieren bei echtem Kundenverkehr vor Ort!</p>
    </a>
</div>';

echo '</div>';

// === QUICK ACTIONS ===
echo '<div style="margin-top: 40px; padding: 25px 0; border-top: 1px solid #4a5769;">
    <h4 style="color: #fff; margin-bottom: 20px;">Schnellaktionen</h4>
    <div class="row">
        <div class="col-md-4">
            <a href="' . rex_url::backendPage('jsonld_manager/article') . '" class="btn btn-primary btn-block" style="padding: 12px; background: linear-gradient(135deg, #5cb85c 0%, #449d44 100%); border: none; margin-bottom: 10px;">
                <i class="rex-icon fa-list"></i> Artikel-Übersicht ansehen
            </a>
        </div>
        <div class="col-md-6">
            <a href="' . rex_url::backendPage('jsonld_manager/article_jsonld') . '" class="btn btn-default btn-block" style="padding: 12px; margin-bottom: 10px;">
                <i class="rex-icon fa-edit"></i> JSON-LD Editor öffnen
            </a>
        </div>
    </div>
</div>';

echo '</div>'; // Ende global-overview

$content = ob_get_clean();

// Fragment für einheitliches Layout
$fragment = new rex_fragment();
$fragment->setVar('title', 'Allgemeine Angaben - Globale Schema-Einstellungen', false);
$fragment->setVar('body', $content, false);
echo $fragment->parse('core/page/section.php');
?>
