<?php

namespace FriendsOfRedaxo\JsonLdManager;

class DomainConfig
{
    private const SESSION_KEY = 'jsonld_manager_active_domain_id';

    public static function getDomains(): array
    {
        // Prüfen ob YRewrite installiert und aktiv ist
        if (!\rex_addon::get('yrewrite')->isAvailable()) {
            return [];
        }
        
        try {
            $sql = \rex_sql::factory();
            $sql->setQuery('SELECT id, domain, mount_id, start_id FROM ' . \rex::getTable('yrewrite_domain') . ' ORDER BY domain ASC');
            return $sql->getArray();
        } catch (\rex_sql_exception $e) {
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
            if (\rex::isBackend()) {
                \rex_set_session(self::SESSION_KEY, $requested);
            }
            return $requested;
        }

        // Frontend: ohne Session arbeiten (verhindert Fehler bei nicht eingeloggten Besuchern)
        if (!\rex::isBackend()) {
            if (\rex_addon::get('yrewrite')->isAvailable() && class_exists('rex_yrewrite')) {
                $currentDomain = \rex_yrewrite::getCurrentDomain();
                if ($currentDomain && method_exists($currentDomain, 'getId')) {
                    $currentDomainId = (int) $currentDomain->getId();
                    if ($currentDomainId > 0) {
                        return $currentDomainId;
                    }
                }
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
            if (\rex::isBackend()) {
                \rex_set_session(self::SESSION_KEY, $fallbackDomainId);
            }
            return $fallbackDomainId;
        }

        return 1; // Notfall-Fallback
    }

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
        if (!\rex_addon::get('yrewrite')->isAvailable()) {
            return false;
        }

        $sql = \rex_sql::factory();
        try {
            $sql->setQuery('SELECT id FROM ' . \rex::getTable('yrewrite_domain') . ' WHERE id = ?', [$domainId]);
            return $sql->getRows() > 0;
        } catch (\rex_sql_exception $e) {
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

        $currentUrl = \rex_url::currentBackendPage();
        
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

        if (\rex_addon::get('yrewrite')->isAvailable()) {
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

        return rtrim((string) \rex::getServer(), '/');
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
}

\class_alias(__NAMESPACE__ . '\\DomainConfig', 'JsonldManager\\DomainConfig');
