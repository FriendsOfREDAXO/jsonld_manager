<?php

namespace FriendsOfRedaxo\JsonLdManager;

use rex_config;
use rex_addon;
use rex_clang;
use rex_file;
use rex_path;
use rex_response;
use rex_yrewrite;

final class LlmsTxt
{
    private const CONFIG_NAMESPACE = 'jsonld_manager';
    private const LEGACY_CONTENT_KEY = 'llms_txt_content';
    private const LEGACY_FILE_BACKUP_KEY = 'llms_txt_legacy_file_content';

    public static function getContent(int $domainId, int $clangId): string
    {
        return self::stripBom((string) rex_config::get(self::CONFIG_NAMESPACE, self::getContentKey($domainId, $clangId), ''));
    }

    public static function setContent(int $domainId, int $clangId, string $content): void
    {
        rex_config::set(self::CONFIG_NAMESPACE, self::getContentKey($domainId, $clangId), self::stripBom($content));
    }

    public static function deleteContent(int $domainId, int $clangId): void
    {
        rex_config::set(self::CONFIG_NAMESPACE, self::getContentKey($domainId, $clangId), '');
    }

    public static function hasContent(int $domainId, int $clangId): bool
    {
        return trim(self::getContent($domainId, $clangId)) !== '';
    }

    public static function getInitialTemplateShownKey(int $domainId, int $clangId): string
    {
        return 'llms_txt_initial_template_shown_domain_' . max(1, $domainId) . '_clang_' . max(1, $clangId);
    }

    /**
     * @return array{domain_id: int, clang_id: int}|null
     */
    public static function resolveEndpointRequest(string $requestUri): ?array
    {
        $path = parse_url($requestUri, PHP_URL_PATH);
        if (!is_string($path)) {
            return null;
        }

        $isLanguagePathCandidate = str_ends_with($path, '/llms.txt');
        $filename = basename($path);
        $isFallbackFilenameCandidate = str_starts_with($filename, 'llms_')
            && str_ends_with($filename, '.txt');
        if (!$isLanguagePathCandidate && !$isFallbackFilenameCandidate) {
            return null;
        }

        if (!rex_addon::get('yrewrite')->isAvailable() || DomainConfig::getDomains() === []) {
            $startClangId = rex_clang::getStartId();
            if ($path === '/llms.txt') {
                return ['domain_id' => 1, 'clang_id' => $startClangId];
            }

            foreach (rex_clang::getAll(true) as $clang) {
                $clangId = (int) $clang->getId();
                if ($clangId === $startClangId) {
                    continue;
                }
                if ($path === '/llms_' . rawurlencode((string) $clang->getCode()) . '.txt') {
                    return ['domain_id' => 1, 'clang_id' => $clangId];
                }
            }

            return null;
        }

        $domain = DomainConfig::getCurrentFrontendDomain();
        if (!is_object($domain) || !method_exists($domain, 'getId')) {
            return null;
        }

        $domainId = (int) $domain->getId();
        foreach (self::getSupportedOnlineClangs($domain) as $clang) {
            $clangId = (int) $clang->getId();
            if ($path === self::getYRewriteEndpointPath($domain, $clangId)) {
                return ['domain_id' => $domainId, 'clang_id' => $clangId];
            }
        }

        return null;
    }

    /** @return never */
    public static function sendResponse(int $domainId, int $clangId): never
    {
        $content = self::getContent($domainId, $clangId);

        rex_response::setHeader('X-Content-Type-Options', 'nosniff');
        if (trim($content) === '') {
            rex_response::setStatus(rex_response::HTTP_NOT_FOUND);
            rex_response::sendContent('', 'text/plain; charset=UTF-8');
            exit;
        }

        rex_response::setStatus(rex_response::HTTP_OK);
        rex_response::sendContent($content, 'text/plain; charset=UTF-8');
        exit;
    }

    public static function getPublicPath(int $domainId, int $clangId): string
    {
        if (rex_addon::get('yrewrite')->isAvailable() && class_exists('rex_yrewrite')) {
            $domain = rex_yrewrite::getDomainById($domainId);
            if (is_object($domain)) {
                return self::getYRewriteEndpointPath($domain, $clangId);
            }
        }

        if ($clangId === rex_clang::getStartId()) {
            return '/llms.txt';
        }

        $clang = rex_clang::get($clangId);
        return $clang instanceof rex_clang
            ? '/llms_' . rawurlencode((string) $clang->getCode()) . '.txt'
            : '/llms.txt';
    }

    /** @return array<int, int> */
    public static function getSupportedClangIds(int $domainId): array
    {
        $onlineIds = array_map(
            static fn (rex_clang $clang): int => (int) $clang->getId(),
            rex_clang::getAll(true)
        );

        if (!rex_addon::get('yrewrite')->isAvailable() || !class_exists('rex_yrewrite')) {
            return $onlineIds;
        }

        $domain = rex_yrewrite::getDomainById($domainId);
        if (!is_object($domain) || !method_exists($domain, 'getClangs')) {
            return $onlineIds;
        }

        $domainIds = array_map('intval', (array) $domain->getClangs());
        return array_values(array_intersect($onlineIds, $domainIds));
    }

    /**
     * Übernimmt alte globale/physische Inhalte in die primäre Domain und
     * entfernt die physische Datei erst nach verifizierter Sicherung.
     *
     * @return array<int, string>
     */
    public static function migrateLegacyStorage(): array
    {
        $messages = [];
        $domainId = DomainConfig::getPrimaryDomainId();
        $clangId = DomainConfig::getPrimaryDomainClangId();
        $config = rex_config::get(self::CONFIG_NAMESPACE);
        if (!is_array($config)) {
            $config = [];
        }

        $contentKey = self::getContentKey($domainId, $clangId);
        $targetExists = array_key_exists($contentKey, $config);
        $legacyDomainContentKey = 'llms_txt_content_domain_' . max(1, $domainId);
        $legacyDomainContent = array_key_exists($legacyDomainContentKey, $config)
            ? (string) $config[$legacyDomainContentKey]
            : '';
        $legacyConfigContent = array_key_exists(self::LEGACY_CONTENT_KEY, $config)
            ? (string) $config[self::LEGACY_CONTENT_KEY]
            : '';

        $filePath = rex_path::base('llms.txt');
        $fileExists = is_file($filePath);
        $fileContent = '';
        if ($fileExists && is_readable($filePath)) {
            try {
                $fileContent = rex_file::get($filePath);
            } catch (\Throwable $e) {
                $messages[] = '⚠️ Vorhandene llms.txt konnte nicht gelesen werden und bleibt unverändert';
                return $messages;
            }
        } elseif ($fileExists) {
            $messages[] = '⚠️ Vorhandene llms.txt ist nicht lesbar und bleibt unverändert';
            return $messages;
        }

        $legacyContent = self::stripBom(
            trim($fileContent) !== ''
                ? $fileContent
                : (trim($legacyDomainContent) !== '' ? $legacyDomainContent : $legacyConfigContent)
        );
        if (!$targetExists && trim($legacyContent) !== '') {
            self::setContent($domainId, $clangId, $legacyContent);
            if (self::getContent($domainId, $clangId) !== $legacyContent) {
                $messages[] = '⚠️ llms.txt-Migration konnte nicht verifiziert werden';
                return $messages;
            }
            $messages[] = '✅ Bestehenden llms.txt-Inhalt Domain ' . $domainId . ', Sprache ' . $clangId . ' zugeordnet';
            $targetExists = true;
        }

        if (!$fileExists) {
            return $messages;
        }

        $fileContentPreserved = trim($fileContent) === ''
            || ($targetExists && self::getContent($domainId, $clangId) === self::stripBom($fileContent));

        if (!$fileContentPreserved) {
            $backupExists = array_key_exists(self::LEGACY_FILE_BACKUP_KEY, $config);
            if (!$backupExists) {
                rex_config::set(self::CONFIG_NAMESPACE, self::LEGACY_FILE_BACKUP_KEY, $fileContent);
                $fileContentPreserved = (string) rex_config::get(
                    self::CONFIG_NAMESPACE,
                    self::LEGACY_FILE_BACKUP_KEY,
                    ''
                ) === $fileContent;
            } else {
                $fileContentPreserved = (string) $config[self::LEGACY_FILE_BACKUP_KEY] === $fileContent;
            }
        }

        if (!$fileContentPreserved) {
            $messages[] = '⚠️ Physische llms.txt bleibt bestehen, da ihr Inhalt nicht verlustfrei gesichert werden konnte';
            return $messages;
        }

        try {
            if (!rex_file::delete($filePath) && is_file($filePath)) {
                $messages[] = '⚠️ Gesicherte physische llms.txt konnte nicht entfernt werden';
                return $messages;
            }
            $messages[] = '✅ Alte physische llms.txt nach verifizierter Sicherung entfernt';
        } catch (\Throwable $e) {
            $messages[] = '⚠️ Gesicherte physische llms.txt konnte nicht entfernt werden';
        }

        return $messages;
    }

    private static function getContentKey(int $domainId, int $clangId): string
    {
        return 'llms_txt_content_domain_' . max(1, $domainId) . '_clang_' . max(1, $clangId);
    }

    /** @return array<int, rex_clang> */
    private static function getSupportedOnlineClangs(object $domain): array
    {
        $domainId = method_exists($domain, 'getId') ? (int) $domain->getId() : 0;
        $supportedIds = self::getSupportedClangIds($domainId);
        return array_values(array_filter(
            rex_clang::getAll(true),
            static fn (rex_clang $clang): bool => in_array((int) $clang->getId(), $supportedIds, true)
        ));
    }

    private static function getYRewriteEndpointPath(object $domain, int $clangId): string
    {
        $domainName = method_exists($domain, 'getName') ? (string) $domain->getName() : '';
        $startId = method_exists($domain, 'getStartId') ? (int) $domain->getStartId() : 0;
        $domainPath = method_exists($domain, 'getPath') ? (string) $domain->getPath() : '/';
        $startPaths = (array) (rex_yrewrite::$paths['paths'][$domainName][$startId] ?? []);

        if (array_key_exists($clangId, $startPaths)) {
            $clangPath = (string) $startPaths[$clangId];
            return self::joinEndpointPath($domainPath . $clangPath, 'llms.txt');
        }

        $startClangId = method_exists($domain, 'getStartClang') ? (int) $domain->getStartClang() : 0;
        if ($clangId === $startClangId) {
            return self::joinEndpointPath($domainPath, 'llms.txt');
        }

        $clang = rex_clang::get($clangId);
        $filename = $clang instanceof rex_clang
            ? 'llms_' . rawurlencode((string) $clang->getCode()) . '.txt'
            : 'llms.txt';

        return self::joinEndpointPath($domainPath, $filename);
    }

    private static function joinEndpointPath(string $basePath, string $filename): string
    {
        $basePath = trim($basePath, '/');
        return '/' . ($basePath !== '' ? $basePath . '/' : '') . $filename;
    }

    private static function stripBom(string $content): string
    {
        return str_starts_with($content, "\xEF\xBB\xBF") ? substr($content, 3) : $content;
    }
}

\class_alias(__NAMESPACE__ . '\\LlmsTxt', 'JsonldManager\\LlmsTxt');
