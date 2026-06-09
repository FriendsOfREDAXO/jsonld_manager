<?php

namespace FriendsOfRedaxo\JsonLdManager;

use rex_clang;
use rex_addon_interface;
use rex_url;

class LanguageConfig
{
    private const SESSION_KEY = 'jsonld_manager_active_clang_id';

    /** @return array<int, rex_clang> */
    public static function getClangs(): array
    {
        return rex_clang::getAll(true);
    }

    public static function isMultilingual(): bool
    {
        return count(self::getClangs()) > 1;
    }

    public static function getActiveClangId(): int
    {
        $requested = \rex_request('clang', 'int', 0);
        if ($requested <= 0) {
            $requested = \rex_request('clang_id', 'int', 0);
        }
        if ($requested > 0 && rex_clang::exists($requested)) {
            $clang = rex_clang::get($requested);
            if ($clang && $clang->isOnline()) {
                \rex_set_session(self::SESSION_KEY, $requested);
                return $requested;
            }
        }

        $sessionClangId = (int) \rex_session(self::SESSION_KEY, 'int', 0);
        if ($sessionClangId > 0 && rex_clang::exists($sessionClangId)) {
            $sessionClang = rex_clang::get($sessionClangId);
            if ($sessionClang && $sessionClang->isOnline()) {
                return $sessionClangId;
            }
        }

        $fallbackClangId = rex_clang::getCurrentId();
        \rex_set_session(self::SESSION_KEY, $fallbackClangId);
        return $fallbackClangId;
    }

    /**
     * @param array<string, mixed> $default
     * @return array<string, mixed>
     */
    public static function getLocalizedConfig(rex_addon_interface $addon, string $baseKey, int $clangId, array $default = []): array
    {
        $localized = $addon->getConfig(self::localizedKey($baseKey, $clangId), null);
        if (is_array($localized)) {
            return $localized;
        }

        $legacy = $addon->getConfig($baseKey, null);
        if (is_array($legacy)) {
            return $legacy;
        }

        return $default;
    }

    /** @param array<string, mixed> $config */
    public static function setLocalizedConfig(rex_addon_interface $addon, string $baseKey, int $clangId, array $config): void
    {
        $addon->setConfig(self::localizedKey($baseKey, $clangId), $config);
    }

    public static function renderClangTabs(int $activeClangId): string
    {
        if (!self::isMultilingual()) {
            return '';
        }

        $html = '<style>
.jsonld-clang-group {
    margin: 0 0 14px 0;
}
.jsonld-clang-group .btn.btn-clang {
    border-radius: 0;
    border-color: #2a3948;
    background: #1f2d3a;
    color: #9fb2c2;
    padding: 8px 14px;
    line-height: 1.2;
}
.jsonld-clang-group .btn.btn-clang:hover,
.jsonld-clang-group .btn.btn-clang:focus {
    background: #283849;
    color: #d9e6ef;
}
.jsonld-clang-group .btn.btn-clang.active,
.jsonld-clang-group .btn.btn-clang.active:hover,
.jsonld-clang-group .btn.btn-clang.active:focus {
    background: #337ab7;
    border-color: #2e6da4;
    color: #ffffff;
}
.jsonld-clang-group .btn.btn-clang .rex-icon {
    margin-right: 6px;
    opacity: .9;
}
.jsonld-clang-group .btn.btn-clang.active .rex-icon {
    color: #ffffff;
    opacity: 1;
    background: #337ab7;
    border-radius: 50%;
}
</style>';
        $html .= '<div class="btn-group jsonld-clang-group" role="group" aria-label="Sprachen">';
        foreach (self::getClangs() as $clang) {
            $clangId = (int) $clang->getId();
            $isActive = $clangId === $activeClangId;
            $url = rex_url::currentBackendPage(['clang' => $clangId]);
            $label = htmlspecialchars((string) $clang->getName());
            $html .= '<a href="' . $url . '" class="btn btn-clang' . ($isActive ? ' active' : '') . '"><i class="rex-icon rex-icon-online"></i>' . $label . '</a>';
        }
        $html .= '</div>';

        return $html;
    }

    private static function localizedKey(string $baseKey, int $clangId): string
    {
        return $baseKey . '_clang_' . $clangId;
    }
}

\class_alias(__NAMESPACE__ . '\\LanguageConfig', 'JsonldManager\\LanguageConfig');
