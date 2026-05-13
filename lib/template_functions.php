<?php
    /**
     * Liefert den Branch-Key für einen Artikel (inkl. Multi-Domain-Unterstützung)
     */
    function jsonld_manager_article_branch_key($articleId, $clangId = null) {
        $clangId = $clangId ?? (int) (\rex_clang::getCurrentId());
        if (class_exists('FriendsOfRedaxo\\JsonLdManager\\DomainConfig') && \FriendsOfRedaxo\JsonLdManager\DomainConfig::isMultiDomain()) {
            $domainId = \FriendsOfRedaxo\JsonLdManager\DomainConfig::getActiveDomainId();
            return 'article_branch_' . $articleId . '_clang_' . $clangId . '_domain_' . $domainId;
        }
        return 'article_branch_' . $articleId . '_clang_' . $clangId;
    }

    /**
     * Normalisiert Branch-IDs aus allen möglichen Formaten (Array, JSON, Komma-getrennt, int)
     */
    function jsonld_manager_normalize_branch_ids($value) {
        if (is_array($value)) {
            $branchIds = $value;
        } elseif (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $branchIds = $decoded;
            } else {
                $branchIds = explode(',', $value);
            }
        } elseif ($value) {
            $branchIds = [$value];
        } else {
            $branchIds = [];
        }

        $branchIds = array_map('intval', $branchIds);
        $branchIds = array_values(array_unique(array_filter($branchIds, static function ($id) {
            return $id > 0;
        })));
        return $branchIds;
    }

    /**
     * Liefert den Config-Key zum Deaktivieren von JSON-LD pro Artikel
     * (inkl. Multi-Domain-Unterstützung)
     */
    function jsonld_manager_disable_json_key($articleId, $clangId = null) {
        $clangId = $clangId ?? (int) (\rex_clang::getCurrentId());
        if (class_exists('FriendsOfRedaxo\\JsonLdManager\\DomainConfig') && \FriendsOfRedaxo\JsonLdManager\DomainConfig::isMultiDomain()) {
            $domainId = \FriendsOfRedaxo\JsonLdManager\DomainConfig::getActiveDomainId();
            return 'disable_json_' . $articleId . '_clang_' . $clangId . '_domain_' . $domainId;
        }
        return 'disable_json_' . $articleId . '_clang_' . $clangId;
    }

    /**
     * Lädt die für einen Artikel gespeicherten LocalBusiness-Branch-IDs
     * und normalisiert das Ergebnis auf ein Integer-Array.
     */
    function jsonld_manager_get_article_branch_ids($articleId, $clangId = null) {
        $clangId = $clangId ?? (int) (\rex_clang::getCurrentId());
        $branchKey = jsonld_manager_article_branch_key($articleId, $clangId);
        $storedBranchConfig = rex_config::get('jsonld_manager', $branchKey, []);
        $branchIds = jsonld_manager_normalize_branch_ids($storedBranchConfig);
        if (!empty($branchIds)) {
            return $branchIds;
        }

        try {
            $sql = rex_sql::factory();
            $clangCandidates = array_values(array_unique(array_filter([
                (int) $clangId,
                (int) \rex_clang::getStartId(),
            ], static function ($id) {
                return $id > 0;
            })));

            foreach ($clangCandidates as $candidateClangId) {
                $sql->setQuery(
                    'SELECT config FROM ' . rex::getTable('jsonld_schemas') . ' WHERE article_id = ? AND clang_id = ? AND schema_type = "WebPage" AND active = 1 LIMIT 1',
                    [(int) $articleId, (int) $candidateClangId]
                );

                if ($sql->getRows() === 0) {
                    continue;
                }

                $config = json_decode((string) $sql->getValue('config'), true) ?: [];
                $schemaBranchIds = $config['localbusiness_branch_ids'] ?? [];
                if (empty($schemaBranchIds) && !empty($config['localbusiness_branch_id'])) {
                    $schemaBranchIds = [$config['localbusiness_branch_id']];
                }

                $schemaBranchIds = jsonld_manager_normalize_branch_ids($schemaBranchIds);
                if (!empty($schemaBranchIds)) {
                    return $schemaBranchIds;
                }
            }
        } catch (Exception $e) {
            // Fallback unten greift
        }

        return [];
    }


/**
 * JSON-LD Manager - Template Functions
 * 
 * Einfache Funktionen für die Verwendung in REDAXO Templates
 */

if (!function_exists('jsonld_render')) {
    if (!function_exists('jsonld_prune_empty_values')) {
        /**
         * Entfernt rekursiv leere Werte aus JSON-LD Daten.
         * Leere Strings, null und leere Arrays/Objekte werden entfernt.
         * "0", 0 und false bleiben erhalten.
         *
         * @param mixed $value
         * @return mixed
         */
        function jsonld_prune_empty_values($value)
        {
            if (is_array($value)) {
                $clean = [];
                foreach ($value as $key => $item) {
                    $pruned = jsonld_prune_empty_values($item);
                    $isEmptyString = is_string($pruned) && trim($pruned) === '';
                    $isEmptyArray = is_array($pruned) && count($pruned) === 0;
                    if ($pruned === null || $isEmptyString || $isEmptyArray) {
                        continue;
                    }
                    $clean[$key] = $pruned;
                }
                return $clean;
            }

            if (is_string($value)) {
                return trim($value);
            }

            return $value;
        }
    }

    if (!function_exists('jsonld_normalize_output_payload')) {
        /**
         * Einheitliches JSON-LD Payload bauen.
         *
         * @param array $jsonLdData
         * @return array
         */
        function jsonld_normalize_output_payload(array $jsonLdData)
        {
            if (count($jsonLdData) === 1) {
                return $jsonLdData[0];
            }

            return [
                '@context' => 'https://schema.org',
                '@graph' => array_values($jsonLdData)
            ];
        }
    }

    if (!function_exists('jsonld_is_debug_enabled')) {
        if (!function_exists('jsonld_is_backend_user_logged_in')) {
            /**
             * Prüft, ob der aktuelle Besucher im REDAXO-Backend eingeloggt ist.
             *
             * @return bool
             */
            function jsonld_is_backend_user_logged_in()
            {
                return class_exists('rex_backend_login') && \rex_backend_login::hasSession();
            }
        }

        /**
         * Prüft, ob Debug-Modus im Addon aktiviert ist (domain-spezifisch).
         *
         * @return bool
         */
        function jsonld_is_debug_enabled()
        {
            // Debug-Ausgabe nur für eingeloggte Backend-Benutzer erlauben
            if (!jsonld_is_backend_user_logged_in()) {
                return false;
            }

            $addon = rex_addon::get('jsonld_manager');
            
            // Domain-spezifische Konfiguration prüfen (wenn Multi-Domain)
            if (class_exists('\FriendsOfRedaxo\JsonLdManager\DomainConfig')) {
                if (\FriendsOfRedaxo\JsonLdManager\DomainConfig::isMultiDomain()) {
                    $activeDomainId = \FriendsOfRedaxo\JsonLdManager\DomainConfig::getActiveDomainId();
                    $configKey = 'global_settings_domain_' . $activeDomainId;
                    $domainConfig = $addon->getConfig($configKey, []);
                    
                    if (isset($domainConfig['settings']) && array_key_exists('debug_mode', $domainConfig['settings'])) {
                        return (bool) $domainConfig['settings']['debug_mode'];
                    }
                }
            }
            
            // Fallback zu globaler Konfiguration
            $global = $addon->getConfig('global_settings', []);
            if (isset($global['settings']) && array_key_exists('debug_mode', $global['settings'])) {
                return (bool) $global['settings']['debug_mode'];
            }
            
            return (bool) $addon->getConfig('settings.debug_mode', false);
        }
    }

    if (!function_exists('jsonld_is_template_output_allowed')) {
        /**
         * Prüft, ob für das aktuelle Template JSON-LD ausgegeben werden darf.
         * Ohne Template-Auswahl in den Einstellungen wird nichts ausgegeben.
         *
         * @param rex_article $article
         * @return bool
         */
        function jsonld_is_template_output_allowed(\rex_article $article): bool
        {
            $addon = rex_addon::get('jsonld_manager');
            $activeDomainId = 1;

            if (class_exists('\FriendsOfRedaxo\JsonLdManager\DomainConfig')) {
                $activeDomainId = (int) \FriendsOfRedaxo\JsonLdManager\DomainConfig::getActiveDomainId();
                $configKey = \FriendsOfRedaxo\JsonLdManager\DomainConfig::isMultiDomain()
                    ? 'global_settings_domain_' . $activeDomainId
                    : 'global_settings';
            } else {
                $configKey = 'global_settings';
            }

            $globalConfig = (array) rex_config::get('jsonld_manager', $configKey, []);
            if (empty($globalConfig) && class_exists('\FriendsOfRedaxo\JsonLdManager\DomainConfig') && \FriendsOfRedaxo\JsonLdManager\DomainConfig::isMultiDomain()) {
                $globalConfig = (array) rex_config::get('jsonld_manager', 'global_settings', []);
            }

            $settings = (array) ($globalConfig['settings'] ?? []);
            $templates = (array) ($globalConfig['templates'] ?? []);
            $autoOutput = (bool) ($settings['auto_output'] ?? true);
            $enabledIds = array_values(array_filter(array_map('intval', (array) ($templates['enabled_ids'] ?? [])), static function ($id) {
                return $id > 0;
            }));

            if (!$autoOutput || empty($enabledIds)) {
                return false;
            }

            return in_array((int) $article->getTemplateId(), $enabledIds, true);
        }
    }

    if (!function_exists('jsonld_render_debug_overlay_script')) {
        /**
         * Rendert ein benutzerfreundliches Debug-Overlay als JS (für Ausgabe im <head> geeignet).
         *
         * @param array $payload
         * @param array $meta
         * @return string
         */
        function jsonld_render_debug_overlay_script(array $payload, array $meta = [])
        {
            $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return '<script>(function(){'
                . 'var jsonldPayload=' . $payloadJson . ';'
                . 'var jsonldMeta=' . $metaJson . ';'
                . 'var copyIcon="<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"14\" height=\"14\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><rect x=\"9\" y=\"9\" width=\"13\" height=\"13\" rx=\"2\" ry=\"2\"></rect><path d=\"M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1\"></path></svg>";'
                . 'var openIcon="<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"14\" height=\"14\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><polyline points=\"6 9 12 15 18 9\"></polyline></svg>";'
                . 'var closedIcon="<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"14\" height=\"14\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><polyline points=\"18 15 12 9 6 15\"></polyline></svg>";'
                . 'function init(){'
                . 'window.__jsonldDebugStore=window.__jsonldDebugStore||{entries:[],active:0,collapsed:false};'
                . 'var store=window.__jsonldDebugStore;'
                . 'function prettyType(type){if(!type){return "Schema";}var map={"LocalBusiness":"LocalBusiness","WebPage":"WebPage","WebSite":"WebSite","BreadcrumbList":"Breadcrumb","Organization":"Organisation"};return map[type]||type;}'
                . 'function isLocalBusinessEntry(payload,type){var id=(payload&&payload["@id"])?String(payload["@id"]).toLowerCase():"";var normalized=String(type||"").toLowerCase();return normalized.indexOf("localbusiness")!==-1||id.indexOf("#localbusiness")!==-1;}'
                . 'function entryLabel(payload,meta){var type=(payload&&payload["@type"])?payload["@type"]:((meta&&meta.types&&meta.types[0])?meta.types[0]:"Schema");var label=prettyType(type);if(isLocalBusinessEntry(payload,type)){return "LocalBusiness: "+label;}if(meta&&meta.dynamic_profile_id){return "Dynamic URL - "+label;}return label;}'
                . 'function splitEntries(payload,meta){'
                . 'var out=[];'
                . 'if(payload&&payload["@graph"]&&Array.isArray(payload["@graph"])){'
                . 'for(var i=0;i<payload["@graph"].length;i++){'
                . 'var item=payload["@graph"][i]||{};'
                . 'var type=(item["@type"]||"Schema");'
                . 'var itemMeta=Object.assign({},meta,{types:[type],entry_label:entryLabel(item,meta)});'
                . 'out.push({payload:item,meta:itemMeta});'
                . '}'
                . '}else{'
                . 'var singleType=(payload&&payload["@type"])?payload["@type"]:((meta&&meta.types&&meta.types[0])?meta.types[0]:"Schema");'
                . 'out.push({payload:payload,meta:Object.assign({},meta,{types:[singleType],entry_label:entryLabel(payload,meta)})});'
                . '}'
                . 'return out;'
                . '}'
                . 'var incoming=splitEntries(jsonldPayload,jsonldMeta);'
                . 'for(var s=0;s<incoming.length;s++){store.entries.push(incoming[s]);}'
                . 'store.active=0;'
                . 'var layer=document.getElementById("jsonld-debug-layer");'
                . 'var tabs,info,body,copyBtn,toggleBtn;'
                . 'if(!layer){'
                . 'layer=document.createElement("div");'
                . 'layer.id="jsonld-debug-layer";'
                . 'layer.style.cssText="position:fixed;left:16px;bottom:16px;z-index:10000000;width:min(820px,calc(100vw - 32px));height:60vh;min-height:60vh;max-height:60vh;background:#0f172a;color:#e2e8f0;border:1px solid #334155;border-radius:10px;box-shadow:0 10px 30px rgba(2,6,23,.45);font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;overflow:hidden;";'
                . 'var head=document.createElement("div");head.style.cssText="display:flex;flex-direction:column;gap:8px;padding:10px 12px;background:#1e293b;border-bottom:1px solid #334155;";'
                . 'var rowTop=document.createElement("div");rowTop.style.cssText="display:flex;align-items:center;justify-content:space-between;gap:8px;";'
                . 'var title=document.createElement("strong");title.textContent="JSON-LD Debug";title.style.cssText="font-size:12px;letter-spacing:.2px;white-space:nowrap;";rowTop.appendChild(title);'
                . 'var actions=document.createElement("div");actions.style.cssText="display:flex;align-items:center;gap:6px;";'
                . 'toggleBtn=document.createElement("button");toggleBtn.type="button";toggleBtn.title="Ein-/ausklappen";toggleBtn.setAttribute("aria-label","Ein-/ausklappen");toggleBtn.style.cssText="border:1px solid #475569;background:#0b1220;color:#cbd5e1;padding:6px 8px;border-radius:6px;font-size:12px;cursor:pointer;line-height:1;display:inline-flex;align-items:center;justify-content:center;";toggleBtn.innerHTML=openIcon;'
                . 'copyBtn=document.createElement("button");copyBtn.type="button";copyBtn.title="JSON kopieren";copyBtn.setAttribute("aria-label","JSON kopieren");copyBtn.style.cssText="border:1px solid #475569;background:#0b1220;color:#cbd5e1;padding:6px 8px;border-radius:6px;font-size:12px;cursor:pointer;line-height:1;display:inline-flex;align-items:center;justify-content:center;";copyBtn.innerHTML=copyIcon;'
                . 'actions.appendChild(toggleBtn);actions.appendChild(copyBtn);rowTop.appendChild(actions);'
                . 'tabs=document.createElement("div");tabs.id="jsonld-debug-tabs";tabs.style.cssText="display:flex;gap:4px;min-width:0;overflow:auto;scrollbar-width:thin;";'
                . 'head.appendChild(rowTop);head.appendChild(tabs);'
                . 'info=document.createElement("div");info.id="jsonld-debug-info";info.style.cssText="padding:8px 12px;font-size:11px;color:#94a3b8;border-bottom:1px solid #334155;";'
                . 'body=document.createElement("pre");body.id="jsonld-debug-body";body.style.cssText="margin:0;padding:12px;height:calc(60vh - 82px);max-height:calc(60vh - 82px);overflow:auto;white-space:pre-wrap;word-break:break-word;font-size:12px;line-height:1.45;background:#020617;color:#e2e8f0;";'
                . 'copyBtn.addEventListener("click",function(){var active=store.entries[store.active]||{payload:{}};var txt=JSON.stringify(active.payload,null,2);if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(txt);}copyBtn.innerHTML="&#10003;";setTimeout(function(){copyBtn.innerHTML=copyIcon;},900);});'
                . 'toggleBtn.addEventListener("click",function(){store.collapsed=!store.collapsed;updateView();});'
                . 'layer.appendChild(head);layer.appendChild(info);layer.appendChild(body);document.body.appendChild(layer);'
                . '}else{'
                . 'tabs=layer.querySelector("#jsonld-debug-tabs");'
                . 'info=layer.querySelector("#jsonld-debug-info");'
                . 'body=layer.querySelector("#jsonld-debug-body");'
                . 'copyBtn=layer.querySelector("[aria-label=\"JSON kopieren\"]");'
                . 'toggleBtn=layer.querySelector("[aria-label=\"Ein-/ausklappen\"]");'
                . '}'
                . 'function labelFor(entry,idx){var t=(entry.meta&&entry.meta.entry_label)?entry.meta.entry_label:((entry.meta&&entry.meta.types&&entry.meta.types.length)?entry.meta.types.join(", "):"Schema");return (idx+1)+". "+t;}'
                . 'function metaLine(meta){var parts=[];if(meta.domain_id){parts.push("Domain: "+meta.domain_name+" (ID: "+meta.domain_id+")");}if(meta.article_id){parts.push("Artikel ID: "+meta.article_id);}if(meta.clang_id){parts.push("Sprache ID: "+meta.clang_id);}if(typeof meta.branch_id!=="undefined"&&meta.branch_id!==null){parts.push("LocalBusiness ID: "+meta.branch_id);}if(meta.dynamic_profile_id){parts.push("URL-Profil ID: "+meta.dynamic_profile_id);}if(meta.dynamic_data_id){parts.push("Datensatz ID: "+meta.dynamic_data_id);}parts.push("Aktiv nur bei Debug-Modus");return parts.join(" | ");}'
                . 'function updateView(){if(!tabs||!info||!body){return;}tabs.innerHTML="";for(var i=0;i<store.entries.length;i++){(function(index){var btn=document.createElement("button");btn.type="button";btn.textContent=labelFor(store.entries[index],index);var active=index===store.active;btn.style.cssText="border:1px solid "+(active?"#3b82f6":"#475569")+";background:"+(active?"#1d4ed8":"#0b1220")+";color:#e2e8f0;padding:3px 7px;border-radius:5px;font-size:10px;cursor:pointer;white-space:nowrap;";btn.addEventListener("click",function(){store.active=index;store.collapsed=false;updateView();});tabs.appendChild(btn);})(i);}var active=store.entries[store.active]||store.entries[0];if(!active){return;}if(toggleBtn){toggleBtn.innerHTML=store.collapsed?closedIcon:openIcon;}layer.style.height=store.collapsed?"auto":"60vh";layer.style.minHeight=store.collapsed?"0":"60vh";layer.style.maxHeight=store.collapsed?"none":"60vh";info.style.display=store.collapsed?"none":"block";body.style.display=store.collapsed?"none":"block";head.style.borderBottom=store.collapsed?"0":"1px solid #334155";info.textContent=metaLine(active.meta||{});body.textContent=JSON.stringify(active.payload||{},null,2);}'
                . 'updateView();'
                . '}'
                . 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",init);}else{init();}'
                . '})();</script>' . "\n";
        }
    }

    /**
     * JSON-LD für aktuelle Seite ausgeben
     * 
     * @param string|null $schemaType Spezifischer Schema-Type
     * @param array $additionalData Zusätzliche Daten
     * @return string JSON-LD Script Tag
     */
    function jsonld_render($schemaType = null, $additionalData = [])
    {
        try {
            // Aktuelle Artikel-ID ermitteln
            $article = rex_article::getCurrent();
            if (!$article) {
                return '';
            }
            
            $articleId = $article->getId();
            
            // Branch-ID für diesen Artikel laden
            // Branch-IDs wie im Backend robust auslesen (inkl. Multi-Domain)
            $branchKey = jsonld_manager_article_branch_key($articleId, (int) $article->getClangId());
            $storedBranchConfig = rex_config::get('jsonld_manager', $branchKey, []);
            $selectedBranchIds = jsonld_manager_normalize_branch_ids($storedBranchConfig);
            
            // Filial-Fallback ermitteln: Hauptstandort, sonst erste aktive Standort
            $fallbackBranchId = null;
            try {
                $sql = rex_sql::factory();
                $sql->setQuery('SELECT id FROM '.rex::getTable('jsonld_localbusiness_branches').' WHERE is_main_branch = 1 AND clang_id = ? LIMIT 1', [(int) $article->getClangId()]);
                if ($sql->getRows() > 0) {
                    $fallbackBranchId = (int) $sql->getValue('id');
                } else {
                    $sql->setQuery('SELECT id FROM '.rex::getTable('jsonld_localbusiness_branches').' WHERE clang_id = ? ORDER BY sort_order ASC, id ASC LIMIT 1', [(int) $article->getClangId()]);
                    if ($sql->getRows() > 0) {
                        $fallbackBranchId = (int) $sql->getValue('id');
                    }
                }
            } catch (Exception $e) {
                // Fehler ignorieren
            }
            
            $useBranchIds = !empty($selectedBranchIds) ? $selectedBranchIds : ($fallbackBranchId ? [$fallbackBranchId] : []);
            
            // Zentrale Generator-Klasse verwenden
            $jsonLdData = \FriendsOfRedaxo\JsonLdManager\JsonLdGenerator::generateForArticle($articleId, $useBranchIds, true, (int) $article->getClangId());
            
            if (!empty($jsonLdData) && is_array($jsonLdData)) {
                $payload = jsonld_normalize_output_payload($jsonLdData);
                $payload = jsonld_prune_empty_values($payload);
                $jsonString = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                
                // JSON-Validierung
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return '<!-- JSON-LD Encoding Error: ' . json_last_error_msg() . ' -->';
                }
                
                $output = '<script type="application/ld+json">' . "\n" . $jsonString . "\n" . '</script>' . "\n";

                if (jsonld_is_debug_enabled()) {
                    $types = [];
                    foreach ($jsonLdData as $item) {
                        if (is_array($item) && isset($item['@type'])) {
                            $types[] = (string) $item['@type'];
                        }
                    }
                    
                    $meta = [
                        'article_id' => (int) $articleId,
                        'clang_id' => (int) $article->getClangId(),
                        'branch_ids' => $useBranchIds,
                        'types' => array_values(array_unique($types))
                    ];
                    
                    // Domain-Information hinzufügen (wenn Multi-Domain)
                    if (class_exists('\FriendsOfRedaxo\JsonLdManager\DomainConfig') && \FriendsOfRedaxo\JsonLdManager\DomainConfig::isMultiDomain()) {
                        $activeDomain = \FriendsOfRedaxo\JsonLdManager\DomainConfig::getActiveDomain();
                        if ($activeDomain) {
                            $meta['domain_id'] = (int) $activeDomain['id'];
                            $meta['domain_name'] = (string) $activeDomain['domain'];
                        }
                    }
                    
                    $output .= jsonld_render_debug_overlay_script($payload, $meta);
                }

                return $output;
            }
        } catch (\Exception $e) {
            if (rex::isDebugMode()) {
                return '<!-- JSON-LD Error: ' . htmlspecialchars($e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()) . ' -->';
            }
        }
        
        return '';
    }
}
