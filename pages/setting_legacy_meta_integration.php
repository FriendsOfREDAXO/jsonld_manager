<?php
/**
 * JSON-LD Manager - Legacy Meta Integration
 *
 * Integration alter Meta-/SEO-Daten (z.B. aus Metainfo, alten AddOns, etc.)
 *
 * @package JsonldManager
 */

$csrfToken = rex_csrf_token::factory('jsonld_manager_legacy_meta');
$csrfTokenField = $csrfToken->getHiddenField();

$func = rex_request('func', 'string', '');

// Verarbeitung (optional: hier kann später Logik ergänzt werden)
if ($func === 'save_legacy_meta') {
    if (!$csrfToken->isValid()) {
        echo rex_view::error('Sicherheitsprüfung fehlgeschlagen (CSRF). Bitte Seite neu laden.');
    } else {
        $legacyMeta = rex_post('legacy_meta', 'string', '');
        // Prüfung auf "bösen" Code, aber Meta-Tags sind immer erlaubt
        $metaTagPattern = '/^\s*<meta\s/i';
        $lines = preg_split('/\r?\n/', $legacyMeta);
        if (!is_array($lines)) {
            $lines = [];
        }
        $badFound = false;
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || preg_match($metaTagPattern, $trimmed)) {
                continue; // Meta-Tags und leere Zeilen immer erlauben
            }
            $badPatterns = [
                '/<\s*\?(php)?/i', // PHP-Tags
                '/<\s*script/i',    // JS-Script-Tag
                '/javascript:/i',   // JS-URI
                '/(<|\s)on[a-z]+\s*=/i', // Event-Handler wie onclick, onload (nur als Attribut)
                '/<\s*iframe/i',    // iframe
                '/<\s*object/i',    // object
                '/<\s*embed/i',     // embed
                '/<\s*applet/i',    // applet
            ];
            foreach ($badPatterns as $pattern) {
                if (preg_match($pattern, $trimmed)) {
                    $badFound = true;
                    break 2;
                }
            }
        }
        if ($badFound) {
            echo rex_view::error('Dein Eintrag enthält unerlaubten Code (PHP, JavaScript, Event-Handler oder unsichere HTML-Tags). Bitte nur reine Meta-Tags oder statisches HTML verwenden!');
        } else {
            rex_config::set('jsonld_manager', 'legacy_meta_raw', $legacyMeta);
            echo rex_view::success('Legacy Meta-Daten wurden gespeichert.');
        }
    }
}

// Vorbelegung aus Config
$legacyMeta = rex_config::get('jsonld_manager', 'legacy_meta_raw', '');


ob_start();
?>
<form method="post" action="" id="jsonld-legacy-meta-form">
    <div class="row">
        <div class="col-md-7">
            <input type="hidden" name="func" value="save_legacy_meta">
            <?= $csrfTokenField ?>
            <div class="panel panel-primary">
                <header class="panel-heading">
                    <h1 class="panel-title">Legacy Meta-Daten</h1>
                </header>
                <div class="panel-body">
                    <textarea name="legacy_meta" class="form-control" rows="18" style="font-family:monospace; min-height:350px;" placeholder="Hier können alte Meta-/SEO-Daten eingefügt werden..."><?= htmlspecialchars($legacyMeta) ?></textarea>
                </div>
            </div>
        </div>
        <div class="col-md-5">
            <div class="panel panel-info">
                <header class="panel-heading">
                    <h1 class="panel-title">Info & Hinweise</h1>
                </header>
                <div class="panel-body">
                    <p>
                        <strong>Legacy Meta-Integration:</strong><br>
                        Hier kannst du weitere Meta-Informationen eintragen. Diese werden in den zugewiesenen Templates für die JSON-LD-Ausgabe nach dem letzten im Quellcode gefundenen Meta-Tag ausgegeben.<br><br>
                        An dieser Stelle ist keine Verwendung von PHP oder JavaScript möglich, da die Daten direkt in den HTML-Head ausgegeben werden. Es können also nur reine HTML-Meta-Tags oder andere statische HTML-Elemente verwendet werden.<br><br>
                        <hr>
                        <em>Hinweis:</em> Diese Funktion ist ein Hilfswerkzeug für Entwickler und Redakteure, die alte SEO-/Meta-Daten übernehmen möchten.
                    </p>
                </div>
            </div>
        </div>
    </div>
    <div class="rex-form-panel-footer" style="padding: 12px; background: rgba(0,0,0,.28); border-top: 1px solid rgba(255,255,255,.08); display: flex; justify-content: flex-end; align-items: center;">
        <button type="submit" class="btn btn-apply" form="jsonld-legacy-meta-form">Speichern</button>
    </div>
</form>
<?php
$content = ob_get_clean();
$fragment = new rex_fragment();
$fragment->setVar('title', 'Legacy Meta Integration', false);
$fragment->setVar('body', $content, false);
echo $fragment->parse('core/page/section.php');