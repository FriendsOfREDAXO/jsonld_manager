<?php

namespace FriendsOfRedaxo\JsonLdManager\Mapping;

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
    /**
     * @var array Cache für aufgelöste Werte
     */
    private static $cache = [];
    
    /**
     * Wert aus Datenquelle ermitteln
     * 
     * @param string $source Datenquelle (z.B. 'article_name', 'get_param:marke')
     * @param \rex_article $article REDAXO Artikel
     * @param array $additionalData Zusätzliche Daten
     * @return mixed|null Aufgelöster Wert
     */
    public static function getValue($source, $article, $additionalData = [])
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
                if (\rex_addon::get('yrewrite')->isAvailable()) {
                    $value = $article->getValue('yrewrite_title') ?: $article->getName();
                }
                break;
                
            case 'meta_description':
                if (\rex_addon::get('yrewrite')->isAvailable()) {
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
                $clang = \rex_clang::get($article->getClangId());
                $value = $clang ? $clang->getCode() : 'de';
                break;
                
            case 'clang_name':
                $clang = \rex_clang::get($article->getClangId());
                $value = $clang ? $clang->getName() : 'Deutsch';
                break;
                
            // === WEBSITE-DATEN ===
            case 'sitename':
                $value = \rex::getServerName();
                break;
                
            case 'base_url':
                $value = \rex::getServer();
                break;
                
            case 'server_name':
                $value = \rex::getServerName();
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
                    if ($mediaFile) {
                        $value = self::getMediaUrl($mediaFile);
                    }
                }
                break;
                
            case 'media_url':
                if ($parameter) {
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
                $template = \rex_template::get($article->getTemplateId());
                $value = $template ? $template->getName() : null;
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
    private static function getWhitelistedGetParam($paramName)
    {
        $addon = \rex_addon::get('jsonld_manager');
        $whitelist = $addon->getConfig('whitelist.get_params', []);
        
        if (!in_array($paramName, $whitelist)) {
            return null;
        }
        
        return \rex_request($paramName, 'string');
    }
    
    /**
     * Kanonische URL ermitteln
     * 
     * @param \rex_article $article
     * @return string URL
     */
    private static function getCanonicalUrl($article)
    {
        if (\rex_addon::get('yrewrite')->isAvailable()) {
            // YRewrite Full URL über REDAXO URL-System
            return \rex::getServer() . \rex_getUrl($article->getId(), $article->getClangId(), [], '&amp;');
        }
        
        return \rex::getServer() . \rex_getUrl($article->getId(), $article->getClangId());
    }
    
    /**
     * Absolute URL ermitteln
     * 
     * @param \rex_article $article
     * @return string URL
     */
    private static function getAbsoluteUrl($article)
    {
        return \rex::getServer() . \rex_getUrl($article->getId(), $article->getClangId());
    }
    
    /**
     * Media-URL generieren
     * 
     * @param string $filename Dateiname
     * @param string|null $type Media-Manager Type
     * @return string|null URL oder null
     */
    private static function getMediaUrl($filename, $type = null)
    {
        if (!$filename) return null;
        
        $media = \rex_media::get($filename);
        if (!$media) return null;
        
        if ($type && \rex_addon::get('media_manager')->isAvailable()) {
            return \rex::getServer() . \rex_url::media($filename, $type);
        }
        
        return \rex::getServer() . \rex_url::media($filename);
    }
    
    /**
     * Berechnete Werte
     * 
     * @param string $function Funktion
     * @param \rex_article $article
     * @param array $additionalData
     * @return mixed
     */
    private static function getComputedValue($function, $article, $additionalData = [])
    {
        switch ($function) {
            
            case 'breadcrumb_list':
                return self::generateBreadcrumbList($article);
                
            case 'organization':
                return self::getOrganizationData();
                
            case 'current_datetime':
                return date('c');
                
            case 'word_count':
                $content = $article->getSlice(1)->getValue(1) ?: '';
                return str_word_count(strip_tags($content));
                
            case 'reading_time':
                $content = $article->getSlice(1)->getValue(1) ?: '';
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
    private static function generateBreadcrumbList($article)
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
    private static function getOrganizationData()
    {
        $addon = \rex_addon::get('jsonld_manager');
        $config = $addon->getConfig('organization', []);
        
        return [
            '@type' => 'Organization',
            'name' => $config['name'] ?: \rex::getServerName(),
            'url' => \rex::getServer(),
            'logo' => $config['logo'] ? \rex::getServer() . \rex_url::media($config['logo']) : null
        ];
    }
    
    /**
     * Wert transformieren
     * 
     * @param mixed $value Ursprünglicher Wert
     * @param string $transform Transform-Funktion
     * @return mixed Transformierter Wert
     */
    public static function transformValue($value, $transform)
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
                return trim(preg_replace('/\s+/', ' ', strip_tags($value)));
                
            case 'price_format':
                return number_format((float)$value, 2, ',', '.') . ' EUR';
                
            case 'url_absolute':
                return \rex::getServer() . ltrim($value, '/');
                
            default:
                return $value;
        }
    }
    
    /**
     * Cache zurücksetzen
     */
    public static function clearCache()
    {
        self::$cache = [];
    }
}

\class_alias(__NAMESPACE__ . '\\DataSource', 'JsonldManager\\Mapping\\DataSource');
