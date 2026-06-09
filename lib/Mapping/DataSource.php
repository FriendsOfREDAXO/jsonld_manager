<?php

namespace FriendsOfRedaxo\JsonLdManager\Mapping;

use rex_article;
use rex_addon;
use rex_clang;
use rex;
use rex_category;
use rex_media;
use rex_media_manager;
use rex_url;
use rex_sql;
use rex_article_slice;

/**
 * DataSource - Datenquellen für JSON-LD Mapping
 * 
 * Zentrale Klasse für die Auflösung verschiedener Datenquellen
 * zu konkreten Werten für JSON-LD Properties.
 * 
 * @package JsonldManager\Mapping
 * @version 1.0.0
 * @author  REDAXO Developer
 */
class DataSource
{
    /** @var array<string, mixed> Cache für aufgelöste Werte */
    private static $cache = [];

    /**
     * @param array<string, mixed> $additionalData
     */
    public static function getValue(string $source, rex_article $article, array $additionalData = []): mixed
    {
        $cacheKey = md5($source . serialize($additionalData) . $article->getId());

        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        $value = null;

        // Source-Type ermitteln
        if (strpos($source, ':') !== false) {
            list($type, $parameter) = explode(':', $source, 2);
        } else {
            $type = $source;
            $parameter = null;
        }

        switch ($type) {

            // === ARTIKEL-DATEN ===
            case 'article_name':
                $value = $article->getName();
                break;

            case 'article_teaser':
                $value = $article->getValue('teaser');
                break;

            case 'article_description':
                $value = $article->getValue('description');
                break;

            case 'article_id':
                $value = $article->getId();
                break;

            case 'category_name':
                $category = $article->getCategory();
                $value = $category ? $category->getName() : null;
                break;

            // === SEO / YREWRITE ===
            case 'seo_title':
            case 'meta_title':
                if (rex_addon::get('yrewrite')->isAvailable()) {
                    $value = $article->getValue('yrewrite_title') ?: $article->getName();
                }
                break;

            case 'meta_description':
                if (rex_addon::get('yrewrite')->isAvailable()) {
                    $value = $article->getValue('yrewrite_description');
                }
                break;

            case 'canonical_url':
                $value = self::getCanonicalUrl($article);
                break;

            case 'absolute_url':
                $value = self::getAbsoluteUrl($article);
                break;

            // === SPRACHE ===
            case 'clang_code':
                $clang = rex_clang::get($article->getClangId());
                $value = $clang ? $clang->getCode() : 'de';
                break;

            case 'clang_name':
                $clang = rex_clang::get($article->getClangId());
                $value = $clang ? $clang->getName() : 'Deutsch';
                break;

            // === WEBSITE-DATEN ===
            case 'sitename':
                $value = rex::getServerName();
                break;

            case 'base_url':
                $value = rex::getServer();
                break;

            case 'server_name':
                $value = rex::getServerName();
                break;

            // === GET-PARAMETER ===
            case 'get_param':
                if ($parameter) {
                    $value = self::getWhitelistedGetParam($parameter);
                }
                break;

            // === MEDIEN ===
            case 'media_field':
                if ($parameter) {
                    $mediaFile = $article->getValue($parameter);
                    if (is_string($mediaFile) && '' !== $mediaFile) {
                        $value = self::getMediaUrl($mediaFile);
                    }
                }
                break;

            case 'media_url':
                if (null !== $parameter && '' !== $parameter) {
                    $value = self::getMediaUrl($parameter);
                }
                break;

            // === CUSTOM FIELDS ===
            case 'custom_field':
                if ($parameter) {
                    $value = $article->getValue($parameter);
                }
                break;

            // === STATISCHE WERTE ===
            case 'static':
                $value = $parameter;
                break;

            // === ZUSÄTZLICHE DATEN ===
            case 'additional':
                if ($parameter && isset($additionalData[$parameter])) {
                    $value = $additionalData[$parameter];
                }
                break;

            // === BERECHNET ===
            case 'computed':
                $value = self::getComputedValue($parameter, $article, $additionalData);
                break;

            // === TEMPLATE-SPEZIFISCH ===
            case 'template_name':
                $value = self::getTemplateName($article->getTemplateId());
                break;

            // === DATUM/ZEIT ===
            case 'create_date':
                $value = date('Y-m-d', $article->getCreateDate());
                break;

            case 'update_date':
                $value = date('Y-m-d', $article->getUpdateDate());
                break;

            case 'iso_date':
                $value = date('c', $article->getUpdateDate());
                break;

            // === FALLBACK ===
            default:
                // Als Custom Field versuchen
                $value = $article->getValue($source);
        }

        // Wert bereinigen
        if (is_string($value)) {
            $value = trim(strip_tags($value));
            $value = $value !== '' ? $value : null;
        }

        // Cache speichern
        self::$cache[$cacheKey] = $value;

        return $value;
    }

    /**
     * GET-Parameter mit Whitelist-Prüfung
     * 
     * @param string $paramName Parameter-Name
     * @return string|null Parameter-Wert oder null
     */
    private static function getWhitelistedGetParam(string $paramName): ?string
    {
        $addon = rex_addon::get('jsonld_manager');
        $whitelist = $addon->getConfig('whitelist.get_params', []);

        if (!in_array($paramName, $whitelist)) {
            return null;
        }

        return \rex_request($paramName, 'string');
    }

    private static function getCanonicalUrl(rex_article|rex_category $article): string
    {
        if (rex_addon::get('yrewrite')->isAvailable()) {
            // YRewrite Full URL über REDAXO URL-System
            return rex::getServer() . \rex_getUrl($article->getId(), $article->getClangId(), [], '&amp;');
        }

        return rex::getServer() . \rex_getUrl($article->getId(), $article->getClangId());
    }

    /**
     * Absolute URL ermitteln
     *
     * @param rex_article $article
     * @return string URL
     */
    private static function getAbsoluteUrl(rex_article $article): string
    {
        return rex::getServer() . \rex_getUrl($article->getId(), $article->getClangId());
    }

    /**
     * Media-URL generieren
     * 
     * @param string $filename Dateiname
     * @param string|null $type Media-Manager Type
     * @return string|null URL oder null
     */
    private static function getMediaUrl(string $filename, ?string $type = null): ?string
    {
        if ('' === $filename) {
            return null;
        }

        $media = rex_media::get($filename);
        if (!$media) return null;

        if (null !== $type && '' !== $type && rex_addon::get('media_manager')->isAvailable()) {
            return rex::getServer() . rex_media_manager::getUrl($type, $filename);
        }

        return rex::getServer() . rex_url::media($filename);
    }

    /**
     * @param array<string, mixed> $additionalData
     *
     * @return array<string, mixed>|float|int|string|null
     */
    private static function getComputedValue(?string $function, rex_article $article, array $additionalData = []): array|string|int|float|null
    {
        switch ($function) {

            case 'breadcrumb_list':
                return self::generateBreadcrumbList($article);

            case 'organization':
                return self::getOrganizationData();

            case 'current_datetime':
                return date('c');

            case 'word_count':
                $content = self::getArticleTextContent($article);
                return str_word_count(strip_tags($content));

            case 'reading_time':
                $content = self::getArticleTextContent($article);
                $wordCount = str_word_count(strip_tags($content));
                return max(1, round($wordCount / 200)); // 200 Wörter/Minute

            default:
                return null;
        }
    }

    /**
     * Breadcrumb-Liste generieren
     * 
     * @param \rex_article $article
     * @return array
     */
    /**
     * @return array<string, mixed>
     */
    private static function generateBreadcrumbList(rex_article $article): array
    {
        $breadcrumbs = [];
        $path = $article->getParentTree();
        $path[] = $article; // Aktuelle Seite hinzufügen

        $position = 1;
        foreach ($path as $item) {
            $breadcrumbs[] = [
                '@type' => 'ListItem',
                'position' => $position++,
                'name' => $item->getName(),
                'item' => self::getCanonicalUrl($item)
            ];
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $breadcrumbs
        ];
    }

    /**
     * Standard-Organisationsdaten
     * 
     * @return array
     */
    /**
     * @return array<string, mixed>
     */
    private static function getOrganizationData(): array
    {
        $addon = rex_addon::get('jsonld_manager');
        $config = $addon->getConfig('organization', []);

        $organizationName = $config['name'] ?? '';
        $logoFile = $config['logo'] ?? '';

        return [
            '@type' => 'Organization',
            'name' => is_string($organizationName) && '' !== trim($organizationName) ? trim($organizationName) : rex::getServerName(),
            'url' => rex::getServer(),
            'logo' => is_string($logoFile) && '' !== $logoFile ? rex::getServer() . rex_url::media($logoFile) : null,
        ];
    }

    /**
     * Wert transformieren
     * 
     * @param mixed $value Ursprünglicher Wert
     * @param string $transform Transform-Funktion
     * @return mixed Transformierter Wert
     */
    public static function transformValue($value, string $transform)
    {
        switch ($transform) {

            case 'uppercase':
                return strtoupper($value);

            case 'lowercase':
                return strtolower($value);

            case 'ucfirst':
                return ucfirst($value);

            case 'truncate_100':
                return mb_substr($value, 0, 100) . (mb_strlen($value) > 100 ? '...' : '');

            case 'truncate_160':
                return mb_substr($value, 0, 160) . (mb_strlen($value) > 160 ? '...' : '');

            case 'strip_html':
                return strip_tags($value);

            case 'clean_text':
                $cleanValue = preg_replace('/\s+/', ' ', strip_tags((string) $value));
                return trim(is_string($cleanValue) ? $cleanValue : '');

            case 'price_format':
                return number_format((float)$value, 2, ',', '.') . ' EUR';

            case 'url_absolute':
                return rex::getServer() . ltrim((string) $value, '/');

            default:
                return $value;
        }
    }

    /**
     * Cache zurücksetzen
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    private static function getTemplateName(int $templateId): ?string
    {
        $sql = rex_sql::factory();
        $sql->setQuery('SELECT name FROM ' . rex::getTable('template') . ' WHERE id = ? LIMIT 1', [$templateId]);

        if (0 === $sql->getRows()) {
            return null;
        }

        $name = $sql->getValue('name');

        return is_string($name) && '' !== trim($name) ? trim($name) : null;
    }

    private static function getArticleTextContent(rex_article $article): string
    {
        $slices = rex_article_slice::getSlicesForArticle($article->getId(), $article->getClangId());
        $parts = [];

        foreach ($slices as $slice) {
            for ($i = 1; $i <= 20; ++$i) {
                $value = $slice->getValue('value' . $i);
                if (is_string($value) && '' !== trim($value)) {
                    $parts[] = $value;
                }
            }
        }

        return implode(' ', $parts);
    }
}

\class_alias(__NAMESPACE__ . '\\DataSource', 'JsonldManager\\Mapping\\DataSource');
