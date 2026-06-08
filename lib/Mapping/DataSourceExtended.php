<?php

namespace FriendsOfRedaxo\JsonLdManager\Mapping;

use rex_article;
use rex_user;
use rex_media;
use rex_addon;
use rex;
use rex_media_manager;
use rex_url;

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
    /**
     * @param array<string, mixed> $additionalData
     */
    public static function getValue(string $source, rex_article $article, array $additionalData = []): mixed
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
                    $createUser = rex_user::get((int) $article->getCreateUser());
                    return $createUser ? $createUser->getValue('name') : null;
                }

            // === PRIMÄRES BILD ===
            case 'primary_image':
            case 'featured_image':
            case 'page_image':
                // Primäres Bild aus verschiedenen Quellen
                $imageField = $parameter ?: 'image'; // Standard: 'image' Feld
                $imageFile = $article->getValue($imageField);
                if (!is_string($imageFile) || '' === $imageFile) {
                    // Fallback: Erstes Bild im Content oder Metainfo
                    $imageFile = $article->getValue('meta_image') ?: $article->getValue('bild');
                }
                if (is_string($imageFile) && '' !== $imageFile) {
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

        return null;
    }

    /**
     * Media-URL generieren (vereinfacht)
     * 
     * @param string $filename Dateiname
     * @param string|null $type Media-Manager Type
     * @return string|null URL oder null
     */
    protected static function getMediaUrl(string $filename, ?string $type = null): ?string
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
}

\class_alias(__NAMESPACE__ . '\\DataSourceExtended', 'JsonldManager\\Mapping\\DataSourceExtended');
