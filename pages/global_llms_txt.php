<?php
/**
 * JSON-LD Manager - llms.txt
 *
 * Verwaltung der Datei llms.txt im Webroot.
 */

$csrfToken = rex_csrf_token::factory('jsonld_manager_llms_txt');
$csrfTokenField = $csrfToken->getHiddenField();
$func = rex_request('func', 'string', '');

$llmsFilePath = rex_path::base('llms.txt');
$message = '';
$maxLength = 50000;
$maxUploadBytes = 100000;
$llmsContentOverride = null;
$allowedUploadExtensions = ['txt', 'md'];
$allowedUploadMimeTypes = ['text/plain', 'text/markdown', 'text/x-markdown', 'inode/x-empty'];
$initialTemplateShownConfigKey = 'llms_txt_initial_template_shown';

if (!function_exists('jsonld_manager_llms_load_content')) {
    function jsonld_manager_llms_load_content(string $llmsFilePath): string
    {
        $content = '';

        if (is_file($llmsFilePath) && is_readable($llmsFilePath)) {
            try {
                $content = rex_file::get($llmsFilePath);
            } catch (Throwable $e) {
                $content = '';
            }
        }

        if ($content === '') {
            $content = (string) rex_config::get('jsonld_manager', 'llms_txt_content', '');
        }

        return $content;
    }
}

if (!function_exists('jsonld_manager_llms_default_template')) {
    function jsonld_manager_llms_default_template(): string
    {
        return implode("\n", [
            '# llms.txt',
            '',
            '## Projektüberblick',
            '- Kurzbeschreibung der Website',
            '- Zielgruppen und Hauptnutzen',
            '',
            '## Inhalte und Prioritäten',
            '- Wichtigste Themenbereiche',
            '- Relevante Produkt-/Leistungsgruppen',
            '- Begriffe, die bevorzugt genutzt werden sollen',
            '',
            '## Qualitätsregeln',
            '- Antworten klar, präzise und faktenbasiert formulieren',
            '- Keine unbelegten Behauptungen',
            '- Bei Unsicherheit transparent auf fehlende Daten hinweisen',
            '',
            '## Kontakt und nächste Schritte',
            '- Gewünschte Handlungsoptionen für Nutzer',
            '- Relevante Kontaktkanäle',
            '',
            '## Ausschlüsse',
            '- Themen, die nicht beantwortet werden sollen',
            '- Rechtliche oder medizinische Beratung ausschließen (falls zutreffend)',
            '',
            '## Stand',
            '- Letzte Aktualisierung: ' . date('d.m.Y'),
        ]) . "\n";
    }
}

if (!function_exists('jsonld_manager_llms_validate')) {
    /**
     * @return array{errors: array<int, string>, warnings: array<int, string>}
     */
    function jsonld_manager_llms_validate(string $content, int $maxLength): array
    {
        $errors = [];
        $warnings = [];

        if (mb_strlen($content) > $maxLength) {
            $errors[] = 'Der Inhalt ist zu lang. Erlaubt sind maximal ' . $maxLength . ' Zeichen.';
        }

        if (!preg_match('/^#\s+.+/m', $content)) {
            $warnings[] = 'Es wurde keine Hauptüberschrift gefunden (Zeile mit # Titel).';
        }

        if (!preg_match('/^##\s+.+/m', $content)) {
            $warnings[] = 'Es wurden keine Abschnittsüberschriften gefunden (Zeilen mit ## Titel).';
        }

        $sectionChecks = [
            'Projektüberblick' => '/^##\s+projektüberblick\b/mi',
            'Inhalte und Prioritäten' => '/^##\s+inhalte\s+und\s+prioritäten\b/mi',
            'Qualitätsregeln' => '/^##\s+qualitätsregeln\b/mi',
            'Kontakt und nächste Schritte' => '/^##\s+kontakt\s+und\s+nächste\s+schritte\b/mi',
            'Ausschlüsse' => '/^##\s+ausschlüsse\b/mi',
        ];

        foreach ($sectionChecks as $label => $pattern) {
            if (!preg_match($pattern, $content)) {
                $warnings[] = 'Empfohlener Abschnitt fehlt: ' . $label . '.';
            }
        }

        $lines = preg_split('/\r\n|\r|\n/', $content);
        if (is_array($lines)) {
            foreach ($lines as $idx => $line) {
                if (mb_strlen($line) > 220) {
                    $warnings[] = 'Sehr lange Zeile erkannt (Zeile ' . ((int) $idx + 1) . ', über 220 Zeichen).';
                    break;
                }
            }
        }

        if (trim($content) === '') {
            $warnings[] = 'Die Datei ist aktuell leer.';
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }
}

if (!function_exists('jsonld_manager_llms_detect_executable_code')) {
    /**
     * @return array<int, string>
     */
    function jsonld_manager_llms_detect_executable_code(string $content): array
    {
        $matches = [];

        // Binär- oder Steuerzeichen (außer erlaubten Whitespace-Steuerzeichen) blockieren.
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $content)) {
            $matches[] = 'Die Datei enthält unzulässige Steuerzeichen/Binärdaten.';
        }

        $blockedPatterns = [
            '/<\?(php|=)?/i' => 'PHP-Tags sind nicht erlaubt.',
            '/<script\b/i' => 'Script-Tags sind nicht erlaubt.',
            '/^\s*#!.+$/m' => 'Shebang-Zeilen sind nicht erlaubt.',
            '/```[\s\S]*?```/m' => 'Codeblöcke mit ``` sind nicht erlaubt.',
            '/\b(?:eval|exec|shell_exec|passthru|system|proc_open|popen)\s*\(/i' => 'Ausführungsfunktionen sind nicht erlaubt.',
            '/\b(?:javascript:|vbscript:|powershell|cmd\.exe|bash\s+-c|sh\s+-c)\b/i' => 'Skriptausführung ist nicht erlaubt.',
        ];

        foreach ($blockedPatterns as $pattern => $errorMessage) {
            if (preg_match($pattern, $content)) {
                $matches[] = $errorMessage;
            }
        }

        return $matches;
    }
}

if ($func === 'download_llms_txt') {
    if (!$csrfToken->isValid()) {
        $message .= rex_view::error('Sicherheitsprüfung fehlgeschlagen (CSRF). Bitte Seite neu laden.');
    } else {
        $downloadContent = jsonld_manager_llms_load_content($llmsFilePath);
        if (trim($downloadContent) === '') {
            $downloadContent = jsonld_manager_llms_default_template();
        }

        header('Content-Type: text/plain; charset=UTF-8');
        header('Content-Disposition: attachment; filename="llms.txt"');
        header('Content-Length: ' . strlen($downloadContent));
        echo $downloadContent;
        exit;
    }
}

if ($func === 'save_llms_txt') {
    if (!$csrfToken->isValid()) {
        $message .= rex_view::error('Sicherheitsprüfung fehlgeschlagen (CSRF). Bitte Seite neu laden.');
    } else {
        $llmsContent = rex_post('llms_txt_content', 'string', '');

        if (trim($llmsContent) === '') {
            try {
                if (is_file($llmsFilePath)) {
                    rex_file::delete($llmsFilePath);
                }

                // Backup ebenfalls leeren, damit kein alter Inhalt wiederhergestellt wird.
                rex_config::set('jsonld_manager', 'llms_txt_content', '');
                rex_config::set('jsonld_manager', $initialTemplateShownConfigKey, true);
                $message .= rex_view::success('Leerer Inhalt gespeichert: Die Datei llms.txt im Webroot wurde gelöscht.');
            } catch (Throwable $e) {
                $message .= rex_view::error('llms.txt konnte nicht gelöscht werden: ' . htmlspecialchars($e->getMessage()));
            }

            $llmsContentOverride = '';
        } else {
            $validation = jsonld_manager_llms_validate($llmsContent, $maxLength);
            if (!empty($validation['errors'])) {
                $message .= rex_view::error(implode('<br>', array_map('htmlspecialchars', $validation['errors'])));
            } else {
                try {
                    rex_file::put($llmsFilePath, $llmsContent);
                    // Optionaler Spiegel in rex_config als Backup für Import/Export-nahe Workflows.
                    rex_config::set('jsonld_manager', 'llms_txt_content', $llmsContent);
                    rex_config::set('jsonld_manager', $initialTemplateShownConfigKey, true);
                    $message .= rex_view::success('llms.txt wurde erfolgreich gespeichert.');
                } catch (Throwable $e) {
                    $message .= rex_view::error('llms.txt konnte nicht gespeichert werden: ' . htmlspecialchars($e->getMessage()));
                }

                if (!empty($validation['warnings'])) {
                    $message .= rex_view::warning(implode('<br>', array_map('htmlspecialchars', $validation['warnings'])));
                }
            }
        }
    }
} elseif ($func === 'import_llms_txt') {
    if (!$csrfToken->isValid()) {
        $message .= rex_view::error('Sicherheitsprüfung fehlgeschlagen (CSRF). Bitte Seite neu laden.');
    } else {
        $upload = $_FILES['llms_txt_upload'] ?? null;

        if (!is_array($upload) || !array_key_exists('error', $upload)) {
            $message .= rex_view::warning('Bitte wähle zuerst eine Datei für den Import aus.');
        } elseif ((int) $upload['error'] !== UPLOAD_ERR_OK) {
            $message .= rex_view::error('Datei-Upload fehlgeschlagen. Bitte erneut versuchen.');
        } elseif (!isset($upload['size']) || (int) $upload['size'] > $maxUploadBytes) {
            $message .= rex_view::error('Die Import-Datei ist zu groß. Erlaubt sind maximal ' . $maxUploadBytes . ' Bytes.');
        } elseif (!isset($upload['tmp_name']) || !is_uploaded_file((string) $upload['tmp_name'])) {
            $message .= rex_view::error('Ungültiger Upload erkannt.');
        } elseif (!isset($upload['name']) || !is_string($upload['name'])) {
            $message .= rex_view::error('Dateiname fehlt oder ist ungültig.');
        } else {
            $canImport = true;
            $extension = strtolower((string) pathinfo((string) $upload['name'], PATHINFO_EXTENSION));
            if (!in_array($extension, $allowedUploadExtensions, true)) {
                $message .= rex_view::error('Nur Dateien mit den Endungen .txt oder .md sind erlaubt.');
                $canImport = false;
            } else {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = $finfo ? (string) finfo_file($finfo, (string) $upload['tmp_name']) : '';
                if ($finfo) {
                    finfo_close($finfo);
                }

                if ($mimeType !== '' && !in_array($mimeType, $allowedUploadMimeTypes, true)) {
                    $message .= rex_view::error('Ungültiger Dateityp erkannt (' . htmlspecialchars($mimeType) . '). Erlaubt sind nur reine Textdateien (.txt/.md).');
                    $canImport = false;
                }
            }

            if (!$canImport) {
                $llmsContentOverride = jsonld_manager_llms_load_content($llmsFilePath);
            } else {
                $importContentRaw = file_get_contents((string) $upload['tmp_name']);
                if ($importContentRaw === false) {
                    $message .= rex_view::error('Die Upload-Datei konnte nicht gelesen werden.');
                } else {
                    $importContent = preg_replace('/^\xEF\xBB\xBF/', '', (string) $importContentRaw);
                    $securityIssues = jsonld_manager_llms_detect_executable_code((string) $importContent);

                    if (!empty($securityIssues)) {
                        $message .= rex_view::error(implode('<br>', array_map('htmlspecialchars', $securityIssues)));
                    } else {
                        $validation = jsonld_manager_llms_validate((string) $importContent, $maxLength);

                        if (!empty($validation['errors'])) {
                            $message .= rex_view::error(implode('<br>', array_map('htmlspecialchars', $validation['errors'])));
                        } else {
                            try {
                                rex_file::put($llmsFilePath, (string) $importContent);
                                rex_config::set('jsonld_manager', 'llms_txt_content', (string) $importContent);
                                rex_config::set('jsonld_manager', $initialTemplateShownConfigKey, true);
                                $message .= rex_view::success('llms.txt wurde aus der Upload-Datei importiert.');
                            } catch (Throwable $e) {
                                $message .= rex_view::error('Import fehlgeschlagen: ' . htmlspecialchars($e->getMessage()));
                            }

                            if (!empty($validation['warnings'])) {
                                $message .= rex_view::warning(implode('<br>', array_map('htmlspecialchars', $validation['warnings'])));
                            }
                        }
                    }
                }
            }
        }
    }
} elseif ($func === 'restore_llms_txt') {
    if (!$csrfToken->isValid()) {
        $message .= rex_view::error('Sicherheitsprüfung fehlgeschlagen (CSRF). Bitte Seite neu laden.');
    } else {
        $backupContent = (string) rex_config::get('jsonld_manager', 'llms_txt_content', '');

        if (trim($backupContent) === '') {
            $message .= rex_view::warning('Kein gespeicherter Backup-Inhalt in der Konfiguration gefunden.');
        } else {
            try {
                rex_file::put($llmsFilePath, $backupContent);
                rex_config::set('jsonld_manager', $initialTemplateShownConfigKey, true);
                $message .= rex_view::success('llms.txt wurde aus der Konfiguration wiederhergestellt.');
            } catch (Throwable $e) {
                $message .= rex_view::error('Wiederherstellung fehlgeschlagen: ' . htmlspecialchars($e->getMessage()));
            }
        }
    }
} elseif ($func === 'load_llms_template') {
    if (!$csrfToken->isValid()) {
        $message .= rex_view::error('Sicherheitsprüfung fehlgeschlagen (CSRF). Bitte Seite neu laden.');
    } else {
        $llmsContentOverride = jsonld_manager_llms_default_template();
        $message .= rex_view::success('Grundstruktur wurde in den Editor geladen. Zum Übernehmen bitte speichern.');
    }
}

$llmsContent = jsonld_manager_llms_load_content($llmsFilePath);

if (is_string($llmsContentOverride)) {
    $llmsContent = $llmsContentOverride;
} elseif (trim($llmsContent) === '' && !((bool) rex_config::get('jsonld_manager', $initialTemplateShownConfigKey, false))) {
    $llmsContent = jsonld_manager_llms_default_template();
    rex_config::set('jsonld_manager', $initialTemplateShownConfigKey, true);
}

ob_start();
?>

<?= $message ?>

<form method="post" action="" id="jsonld-llms-txt-form" enctype="multipart/form-data">
    <div class="row">
        <div class="col-md-7">
            <input type="hidden" name="func" value="save_llms_txt">
            <?= $csrfTokenField ?>

            <div class="panel panel-primary">
                <header class="panel-heading">
                    <h1 class="panel-title">llms.txt</h1>
                </header>
                <div class="panel-body">
                    <p class="help-block" style="margin-bottom: 12px;">
                        Inhalt wird als Datei <code>llms.txt</code> im Webroot gespeichert.
                    </p>
                    <p class="help-block" style="margin-bottom: 12px;">
                        Zeichen: <?= mb_strlen($llmsContent) ?> / <?= $maxLength ?>
                    </p>
                    <textarea
                        name="llms_txt_content"
                        class="form-control"
                        rows="28"
                        style="font-family:monospace; min-height:810px;"
                        placeholder="# llms.txt&#10;&#10;## Über dieses Projekt&#10;Kurze, klare Beschreibung für KI-Systeme ..."><?= htmlspecialchars($llmsContent) ?></textarea>
                </div>
            </div>
        </div>

        <div class="col-md-5">
            <div class="panel panel-info">
                <header class="panel-heading">
                    <h1 class="panel-title">Hinweise zu Inhalt, Tipps und Datei-Verhalten</h1>
                </header>
                <div class="panel-body">
                    <p>
                        <strong>Ziel:</strong> KI-Systemen eine knappe, klare Orientierung zu Inhalt,
                        Tonalität und Grenzen der Website geben.
                    </p>
                    <hr>
                    <p>
                        <strong>Speichern und Löschen:</strong><br>
                        Beim Speichern mit Inhalt wird die Datei <code>llms.txt</code> im Webroot neu geschrieben.
                        Wenn du einen leeren Inhalt speicherst, wird genau diese Datei <code>llms.txt</code> im Webroot gelöscht.
                        Es wird dabei keine andere Datei gelöscht.
                    </p>
                    <hr>
                    <p><strong>Empfohlene Abschnitte:</strong></p>
                    <ul>
                        <li><strong>Projektüberblick:</strong> Was ist die Website, für wen ist sie?</li>
                        <li><strong>Inhaltsbereiche:</strong> Welche Themen sind wichtig, welche nicht?</li>
                        <li><strong>Qualitätsregeln:</strong> Sprache, Faktenlage, Aktualität, Quellen.</li>
                        <li><strong>Kontakt & Aktionen:</strong> Gewünschte nächste Schritte für Nutzer.</li>
                        <li><strong>Ausschlüsse:</strong> Was nicht geraten oder automatisiert werden soll.</li>
                    </ul>
                    <hr>
                    <p>
                        <strong>Tipps:</strong><br>
                        Kurze Sätze, klare Überschriften, keine Marketing-Floskeln. Lieber konkret als lang.
                        <a href="https://www.markdownguide.org/basic-syntax/" target="_blank" rel="noopener noreferrer">Markdown</a> ist erlaubt und für Struktur empfohlen.
                    </p>
                    <hr>
                    <p>
                        <strong>Debug-Integration:</strong><br>
                        Bei aktiviertem JSON-LD-Debug-Modus wird der aktuelle Inhalt von <code>llms.txt</code>
                        zusätzlich im Frontend-Debug-Fenster als eigener Eintrag angezeigt.
                    </p>
                </div>
            </div>

            <div class="panel panel-default">
                <header class="panel-heading">
                    <h1 class="panel-title">Grundstruktur | Import / Export</h1>
                </header>
                <div class="panel-body">
                    <p class="help-block" style="margin-bottom: 10px;">
                        Du kannst die Grundstruktur in den Editor laden, den Inhalt als Datei herunterladen
                        oder eine vorhandene <code>llms.txt</code>/<code>.md</code>-Datei importieren.
                    </p>
                    <p class="help-block" style="margin-bottom: 10px;">
                        Sicherheitsregeln: nur <code>.txt</code> und <code>.md</code>, keine Skript-/Code-Inhalte,
                        keine ausführbaren Codeblöcke.
                    </p>
                    <div class="form-group" style="margin-bottom: 10px;">
                        <label for="llms_txt_upload" class="control-label" style="display:block; margin-bottom: 6px;">Datei importieren</label>
                        <input type="file" id="llms_txt_upload" name="llms_txt_upload" class="form-control" accept=".txt,.md,text/plain,text/markdown">
                        <small class="help-block">Maximal <?= $maxUploadBytes ?> Bytes.</small>
                    </div>
                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        <button type="submit" class="btn btn-default" name="func" value="load_llms_template" form="jsonld-llms-txt-form">Grundstruktur laden</button>
                        <button type="submit" class="btn btn-default" name="func" value="import_llms_txt" form="jsonld-llms-txt-form">Datei importieren</button>
                        <button type="submit" class="btn btn-default" name="func" value="download_llms_txt" form="jsonld-llms-txt-form">Als Datei herunterladen</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="rex-form-panel-footer" style="padding: 12px; background: rgba(0,0,0,.28); border-top: 1px solid rgba(255,255,255,.08); display: flex; justify-content: flex-end; align-items: center;">
        <button type="submit" class="btn btn-default" name="func" value="restore_llms_txt" form="jsonld-llms-txt-form" style="margin-right: 8px;">Aus Config wiederherstellen</button>
        <button type="submit" class="btn btn-apply" name="func" value="save_llms_txt" form="jsonld-llms-txt-form">Speichern</button>
    </div>
</form>

<?php
$content = ob_get_clean();
$fragment = new rex_fragment();
$fragment->setVar('title', 'llms.txt', false);
$fragment->setVar('body', $content, false);
echo $fragment->parse('core/page/section.php');
