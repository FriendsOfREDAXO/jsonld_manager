<?php

namespace FriendsOfRedaxo\JsonLdManager\Mapping;

/**
 * DataSource Extensions - Erweiterte Datenquellen für JSON-LD Mapping
 * 
 * Erweitert die DataSource Klasse um die fehlenden Schema-Properties
 * für primaryImageOfPage, datePublished und author_name
 * 
 * @package JsonldManager\Mapping
 * @version 1.0.1
 * @author  REDAXO Developer
 */
class DataSourceExtended extends DataSource
{
    /**
     * Erweiterte getValue Methode mit neuen Schema-Properties
     * 
     * @param string $source Datenquelle
     * @param rex_article $article REDAXO Artikel
     * @param array $additionalData Zusätzliche Daten
     * @return mixed|null Aufgelöster Wert
     */
    public static function getValue($source, $article, $additionalData = [])
    {
        // Neue Cases für fehlende Properties
        if (strpos($source, ':') !== false) {
            list($type, $parameter) = explode(':', $source, 2);
        } else {
            $type = $source;
            $parameter = null;
        }
        
        switch ($type) {
            
            // === ERWEITERTE DATUM/ZEIT ===
            case 'date_published':
                return date('Y-m-d', $article->getCreateDate());
                
            case 'date_modified':
                return date('Y-m-d', $article->getUpdateDate());
                
            case 'iso_date_published':
                return date('c', $article->getCreateDate());
                
            case 'iso_date_modified':
                return date('c', $article->getUpdateDate());
                
            // === AUTHOR & PUBLISHER ===
            case 'author_name':
                $authorField = $article->getValue('author');
                if ($authorField) {
                    return $authorField;
                } else {
                    $createUser = rex_user::get($article->getCreateUser());
                    return $createUser ? $createUser->getValue('name') : null;
                }
                
            // === PRIMÄRES BILD ===
            case 'primary_image':
            case 'featured_image':
            case 'page_image':
                // Primäres Bild aus verschiedenen Quellen
                $imageField = $parameter ?: 'image'; // Standard: 'image' Feld
                $imageFile = $article->getValue($imageField);
                if (!$imageFile) {
                    // Fallback: Erstes Bild im Content oder Metainfo
                    $imageFile = $article->getValue('meta_image') ?: $article->getValue('bild');
                }
                if ($imageFile) {
                    $value = [
                        '@type' => 'ImageObject',
                        'url' => self::getMediaUrl($imageFile)
                    ];
                    // Optional: Bild-Metadaten hinzufügen
                    $media = rex_media::get($imageFile);
                    if ($media) {
                        $value['width'] = $media->getWidth();
                        $value['height'] = $media->getHeight();
                        if ($media->getValue('med_description')) {
                            $value['description'] = $media->getValue('med_description');
                        }
                    }
                    return $value;
                }
                break;
                
            default:
                // Fallback zur parent getValue Methode
                return parent::getValue($source, $article, $additionalData);
        }
    }
    
    /**
     * Media-URL generieren (vereinfacht)
     * 
     * @param string $filename Dateiname
     * @param string|null $type Media-Manager Type
     * @return string|null URL oder null
     */
    protected static function getMediaUrl($filename, $type = null)
    {
        if (!$filename) return null;
        
        $media = \rex_media::get($filename);
        if (!$media) return null;
        
        if ($type && \rex_addon::get('media_manager')->isAvailable()) {
            return \rex::getServer() . \rex_url::media($filename, $type);
        }
        
        return \rex::getServer() . \rex_url::media($filename);
    }
}

\class_alias(__NAMESPACE__ . '\\DataSourceExtended', 'JsonldManager\\Mapping\\DataSourceExtended');
