<?php

namespace FriendsOfRedaxo\JsonLdManager;

use rex_addon;
use rex_sql;
use rex;
use rex_sql_exception;
use rex_yrewrite;
use rex_url;

class DomainConfig
{
    private const SESSION_KEY = 'jsonld_manager_active_domain_id';
    private const MAX_REDIRECT_CHAIN = 20;

    /** @return array<int, array<string, mixed>> */
    public static function getDomains(): array
    {
        // Prüfen ob YRewrite installiert und aktiv ist
        if (!rex_addon::get('yrewrite')->isAvailable()) {
            return [];
        }

        try {
            $sql = rex_sql::factory();
            $sql->setQuery('SELECT id, domain, mount_id, start_id, clang_start FROM ' . rex::getTable('yrewrite_domain') . ' ORDER BY domain ASC');
            return $sql->getArray();
        } catch (rex_sql_exception $e) {
            // Fallback wenn Tabelle nicht existiert oder leer ist
            return [];
        }
    }

    public static function isMultiDomain(): bool
    {
        return count(self::getDomains()) > 1;
    }

    public static function getActiveDomainId(): int
    {
        // Prüfe URL-Parameter
        $requested = \rex_request('domain_id', 'int', 0);
        if ($requested > 0 && self::domainExists($requested)) {
            if (rex::isBackend()) {
                \rex_set_session(self::SESSION_KEY, $requested);
            }
            return $requested;
        }

        // Frontend: ohne Session arbeiten (verhindert Fehler bei nicht eingeloggten Besuchern)
        if (!rex::isBackend()) {
            $currentDomainId = self::getCurrentFrontendDomainId();
            if ($currentDomainId !== null) {
                return $currentDomainId;
            }
        } else {
            // Backend: Session-Auswahl berücksichtigen
            $sessionDomainId = (int) \rex_session(self::SESSION_KEY, 'int', 0);
            if ($sessionDomainId > 0 && self::domainExists($sessionDomainId)) {
                return $sessionDomainId;
            }
        }

        // Fallback zur ersten Domain
        $domains = self::getDomains();
        if (!empty($domains)) {
            $fallbackDomainId = (int) $domains[0]['id'];
            if (rex::isBackend()) {
                \rex_set_session(self::SESSION_KEY, $fallbackDomainId);
            }
            return $fallbackDomainId;
        }

        return 1; // Notfall-Fallback
    }

    /**
     * Liefert die aktuelle Frontend-Domain ohne Fallback auf eine andere
     * konfigurierte YRewrite-Domain.
     */
    public static function getCurrentFrontendDomainId(): ?int
    {
        $domains = self::getDomains();
        if (!rex_addon::get('yrewrite')->isAvailable() || $domains === []) {
            return 1;
        }

        if (rex::isBackend() || !class_exists('rex_yrewrite')) {
            return null;
        }

        $currentDomain = self::getCurrentFrontendDomain();
        if (!is_object($currentDomain) || !method_exists($currentDomain, 'getId')) {
            return null;
        }

        $currentDomainId = (int) $currentDomain->getId();
        foreach ($domains as $domain) {
            if ((int) ($domain['id'] ?? 0) === $currentDomainId) {
                return $currentDomainId;
            }
        }

        return null;
    }

    /**
     * Liefert die von YRewrite direkt dem Request-Host zugeordnete Domain.
     * Ein Fallback anhand des aktuellen Artikels wird bewusst vermieden.
     */
    public static function getCurrentFrontendDomain(): ?object
    {
        if (rex::isBackend() || !rex_addon::get('yrewrite')->isAvailable() || !class_exists('rex_yrewrite')) {
            return null;
        }

        $host = rex_yrewrite::getHost();
        if (!is_string($host) || trim($host) === '') {
            return null;
        }

        $domain = rex_yrewrite::getDomainByName($host);
        return is_object($domain) ? $domain : null;
    }

    /**
     * Liefert die zuerst angelegte YRewrite-Domain (kleinste ID).
     * Ohne YRewrite wird die Standard-ID 1 verwendet.
     */
    public static function getPrimaryDomainId(): int
    {
        $primaryDomain = self::getPrimaryDomainConfig();
        return $primaryDomain !== null ? (int) $primaryDomain['id'] : 1;
    }

    public static function getPrimaryDomainClangId(): int
    {
        $primaryDomain = self::getPrimaryDomainConfig();
        if ($primaryDomain !== null) {
            $clangId = (int) ($primaryDomain['clang_start'] ?? 0);
            if ($clangId > 0) {
                return $clangId;
            }
        }

        return \rex_clang::getStartId();
    }

    /** @return array<string, mixed>|null */
    private static function getPrimaryDomainConfig(): ?array
    {
        $primaryDomain = null;
        foreach (self::getDomains() as $domain) {
            if ($primaryDomain === null || (int) $domain['id'] < (int) $primaryDomain['id']) {
                $primaryDomain = $domain;
            }
        }

        return $primaryDomain;
    }

    /** @return array<string, mixed>|null */
    public static function getActiveDomain(): ?array
    {
        $activeDomainId = self::getActiveDomainId();
        $domains = self::getDomains();

        foreach ($domains as $domain) {
            if ((int) $domain['id'] === $activeDomainId) {
                return $domain;
            }
        }

        return null;
    }

    public static function domainExists(int $domainId): bool
    {
        if (!rex_addon::get('yrewrite')->isAvailable()) {
            return false;
        }

        $sql = rex_sql::factory();
        try {
            $sql->setQuery('SELECT id FROM ' . rex::getTable('yrewrite_domain') . ' WHERE id = ?', [$domainId]);
            return $sql->getRows() > 0;
        } catch (rex_sql_exception $e) {
            return false;
        }
    }

    public static function renderDomainTabs(int $activeDomainId): string
    {
        if (!self::isMultiDomain()) {
            return '';
        }

        return self::renderDomainSelect($activeDomainId);
    }

    /**
     * @deprecated Nicht mehr verwendet - Domain wird automatisch in den Settings verwaltet
     */
    public static function renderDomainSelect(int $activeDomainId): string
    {
        if (!self::isMultiDomain()) {
            return '';
        }

        $options = '';
        foreach (self::getDomains() as $domain) {
            $domainId = (int) $domain['id'];
            $selected = $domainId === $activeDomainId ? ' selected' : '';
            $label = htmlspecialchars((string) $domain['domain']);
            $options .= '<option value="' . $domainId . '"' . $selected . '>' . $label . '</option>';
        }

        $currentUrl = rex_url::currentBackendPage();

        return '
        <div class="form-group">
            <label class="col-sm-3 control-label">Domain auswählen</label>
            <div class="col-sm-9">
                <select class="form-control" id="jsonld-domain-select" onchange="jsonldChangeDomain(this.value)">
                    ' . $options . '
                </select>
                <small class="help-block">Alle Konfigurationen werden domain-spezifisch verwaltet.</small>
            </div>
        </div>
        <script>
        function jsonldChangeDomain(domainId) {
            if (domainId) {
                var url = "' . $currentUrl . '";
                var separator = url.indexOf("?") > -1 ? "&" : "?";
                window.location.href = url + separator + "domain_id=" + domainId;
            }
        }
        </script>';
    }

    public static function renderDomainDisplay(): string
    {
        if (!self::isMultiDomain()) {
            return '';
        }

        $activeDomain = self::getActiveDomain();
        if (!$activeDomain) {
            return '';
        }

        return ' <span style="color: #ffffff; font-size: 1.1em; font-weight: bold;">(' . htmlspecialchars($activeDomain['domain']) . ')</span>';
    }

    public static function getBaseUrl(?int $domainId = null): string
    {
        $domain = null;

        if (rex_addon::get('yrewrite')->isAvailable()) {
            $domains = self::getDomains();

            if ($domainId !== null) {
                foreach ($domains as $item) {
                    if ((int) ($item['id'] ?? 0) === $domainId) {
                        $domain = $item['domain'] ?? null;
                        break;
                    }
                }
            } elseif (self::isMultiDomain()) {
                $activeDomain = self::getActiveDomain();
                $domain = $activeDomain['domain'] ?? null;
            } elseif (!empty($domains)) {
                $domain = $domains[0]['domain'] ?? null;
            }
        }

        if (is_string($domain) && trim($domain) !== '') {
            return self::normalizeBaseUrl($domain);
        }

        return rtrim((string) rex::getServer(), '/');
    }

    private static function normalizeBaseUrl(string $domain): string
    {
        $domain = trim($domain);

        if (!preg_match('~^https?://~i', $domain)) {
            $scheme = str_contains($domain, '.local') ? 'http://' : 'https://';
            $domain = $scheme . $domain;
        }

        return rtrim($domain, '/');
    }

    /**
     * Prüft die "Weiterleitung intern"-Kette (yrewrite_url_type/yrewrite_redirection)
     * eines Artikels auf Zyklen bzw. übermäßige Länge, BEVOR rex_yrewrite aufgerufen wird.
     *
     * Hintergrund: rex_yrewrite::rewrite() löst interne Weiterleitungen rekursiv auf.
     * Zeigt eine Weiterleitungskette (z.B. nach dem Zusammenlegen zweier Domains) wieder
     * auf sich selbst, läuft rex_yrewrite in eine Endlosrekursion und reißt das PHP
     * Memory-Limit ab (siehe FriendsOfREDAXO/jsonld_manager#16). Diese Prüfung läuft
     * komplett unabhängig von yrewrite und ist durch MAX_REDIRECT_CHAIN hart begrenzt.
     */
    public static function isRedirectChainSafe(int $articleId, int $clangId): bool
    {
        $visited = [];
        $currentId = $articleId;
        $currentClang = $clangId;

        for ($i = 0; $i < self::MAX_REDIRECT_CHAIN; ++$i) {
            $key = $currentId . '_' . $currentClang;
            if (isset($visited[$key])) {
                // Zyklus entdeckt
                return false;
            }
            $visited[$key] = true;

            try {
                $sql = rex_sql::factory();
                $sql->setQuery(
                    'SELECT yrewrite_url_type, yrewrite_redirection FROM ' . rex::getTable('article') . ' WHERE id = ? AND clang_id = ?',
                    [$currentId, $currentClang]
                );
            } catch (rex_sql_exception $e) {
                // Spalten/Tabelle nicht verfügbar -> keine Aussage möglich, konservativ als sicher werten
                return true;
            }

            if ($sql->getRows() === 0) {
                return true;
            }

            $urlType = (string) $sql->getValue('yrewrite_url_type');
            if ('REDIRECTION_INTERNAL' !== $urlType) {
                // Kette endet in einem echten Artikel oder externer Weiterleitung
                return true;
            }

            $target = (int) $sql->getValue('yrewrite_redirection');
            if ($target <= 0) {
                return true;
            }

            $currentId = $target;
            // yrewrite behält die Sprache der Weiterleitung bei
        }

        // Kette länger als MAX_REDIRECT_CHAIN -> als potenziell gefährlich behandeln
        return false;
    }

    /**
     * Liefert die volle Artikel-URL über rex_yrewrite, sofern die interne
     * Weiterleitungskette des Artikels als sicher geprüft wurde. Andernfalls
     * wird auf eine einfache article_id-URL zurückgefallen, um eine
     * Endlosrekursion in rex_yrewrite::rewrite() zu vermeiden.
     */
    public static function getSafeArticleUrl(int $articleId, ?int $clangId = null): string
    {
        $clangId ??= \rex_clang::getStartId();

        if (
            rex_addon::get('yrewrite')->isAvailable()
            && self::isRedirectChainSafe($articleId, $clangId)
        ) {
            return \rex_yrewrite::getFullUrlByArticleId($articleId, $clangId);
        }

        return rex_url::frontendController() . '?article_id=' . $articleId;
    }
}

\class_alias(__NAMESPACE__ . '\\DomainConfig', 'JsonldManager\\DomainConfig');
