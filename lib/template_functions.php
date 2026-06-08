<?php
    use FriendsOfRedaxo\JsonLdManager\JsonLdGenerator;
    use FriendsOfRedaxo\JsonLdManager\DomainConfig;

    /**
     * Liefert den Branch-Key für einen Artikel (inkl. Multi-Domain-Unterstützung)
     */
    function jsonld_manager_article_branch_key(int $articleId, mixed $clangId = null): string {
        return JsonLdGenerator::getArticleBranchKey($articleId, $clangId);
    }

    /**
     * Normalisiert Branch-IDs aus allen möglichen Formaten (Array, JSON, Komma-getrennt, int)
     */
    /** @return array<int, int> */
    function jsonld_manager_normalize_branch_ids(mixed $value): array {
        return JsonLdGenerator::normalizeBranchIds($value);
    }

    /**
     * Liefert den Config-Key zum Deaktivieren von JSON-LD pro Artikel
     * (inkl. Multi-Domain-Unterstützung)
     */
    function jsonld_manager_disable_json_key(int $articleId, mixed $clangId = null): string {
        return JsonLdGenerator::getDisableJsonKey($articleId, $clangId);
    }

    /**
     * Lädt die für einen Artikel gespeicherten LocalBusiness-Branch-IDs
     * und normalisiert das Ergebnis auf ein Integer-Array.
     */
    /** @return array<int, int> */
    function jsonld_manager_get_article_branch_ids(int $articleId, mixed $clangId = null): array {
        return JsonLdGenerator::getArticleBranchIds($articleId, $clangId);
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
            return JsonLdGenerator::pruneEmptyValues($value);
        }
    }

    if (!function_exists('jsonld_normalize_output_payload')) {
        /**
         * Einheitliches JSON-LD Payload bauen.
         *
         * @param array<int, array<string, mixed>> $jsonLdData
         * @return array<string, mixed>
         */
        function jsonld_normalize_output_payload(array $jsonLdData): array
        {
            return JsonLdGenerator::buildPayload($jsonLdData);
        }
    }

    if (!function_exists('jsonld_is_debug_enabled')) {
        if (!function_exists('jsonld_is_backend_user_logged_in')) {
            /**
             * Prüft, ob der aktuelle Besucher im REDAXO-Backend eingeloggt ist.
             *
             * @return bool
             */
            function jsonld_is_backend_user_logged_in(): bool
            {
                return class_exists('rex_backend_login') && rex_backend_login::hasSession();
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
                if (DomainConfig::isMultiDomain()) {
                    $activeDomainId = DomainConfig::getActiveDomainId();
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
        function jsonld_is_template_output_allowed(rex_article $article): bool
        {
            $addon = rex_addon::get('jsonld_manager');
            $activeDomainId = 1;

            if (class_exists('\FriendsOfRedaxo\JsonLdManager\DomainConfig')) {
                $activeDomainId = (int) DomainConfig::getActiveDomainId();
                $configKey = DomainConfig::isMultiDomain()
                    ? 'global_settings_domain_' . $activeDomainId
                    : 'global_settings';
            } else {
                $configKey = 'global_settings';
            }

            $globalConfig = (array) rex_config::get('jsonld_manager', $configKey, []);
            if (empty($globalConfig) && class_exists('\FriendsOfRedaxo\JsonLdManager\DomainConfig') && DomainConfig::isMultiDomain()) {
                $globalConfig = (array) rex_config::get('jsonld_manager', 'global_settings', []);
            }

            $settings = (array) ($globalConfig['settings'] ?? []);
            $templates = (array) ($globalConfig['templates'] ?? []);
            $autoOutput = (bool) ($settings['auto_output'] ?? true);
            $enabledIds = array_values(array_filter(array_map('intval', (array) ($templates['enabled_ids'] ?? [])), static function (int $id): bool {
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
         * @param mixed $payload
         * @param mixed $meta
         * @return string
         */
        function jsonld_render_debug_overlay_script(mixed $payload, mixed $meta = []): string
        {
            // Defensive: null oder nicht-Array abfangen
            if (!is_array($payload)) {
                $payload = [];
            }
            if (!is_array($meta)) {
                $meta = [];
            }
            // Fallback: Wenn Payload leer, Platzhalter-Objekt anzeigen
            $payloadFallback = [
                '@context' => 'https://schema.org',
                '@type' => 'Thing',
                'name' => 'Keine JSON-LD Daten vorhanden',
                'description' => 'Bitte Konfiguration im Backend prüfen.'
            ];
            if (empty($payload)) {
                $payload = $payloadFallback;
            }
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
                . 'function entryLabel(payload,meta){var type=(payload&&payload["@type"])?payload["@type"]:((meta&&meta.types&&meta.types[0])?meta.types[0]:"Schema");var label=prettyType(type);if(isLocalBusinessEntry(payload,type)){try{var branchId=null;if(payload&&payload["@id"]){var m=String(payload["@id"]).match(/localbusiness-(\\d+)/);if(m){branchId=m[1];}}if(meta&&meta.branch_names&&branchId&&meta.branch_names[branchId]){return meta.branch_names[branchId];}if(meta&&meta.localbusiness_branch_names&&branchId&&meta.localbusiness_branch_names[branchId]){return meta.localbusiness_branch_names[branchId];}if(payload&&payload.name){return payload.name;}}catch(e){}return "LocalBusiness: "+label;}if(meta&&meta.dynamic_profile_id){return "Dynamic URL - "+label;}return label;}'
                . 'function splitEntries(payload,meta){'
                . 'var out=[];'
                . 'if(payload&&payload["@graph"]&&Array.isArray(payload["@graph"])){' 
                . 'for(var i=0;i<payload["@graph"].length;i++){' 
                . 'var item=payload["@graph"][i]||{};'
                . 'var type=(item["@type"]||"Schema");'
                . 'var itemMeta=Object.assign({},meta,{types:[type],entry_label:entryLabel(item,meta)});'
                . 'if(isLocalBusinessEntry(item,type)&&item.name){itemMeta.branch_name=item.name;}'
                . 'out.push({payload:item,meta:itemMeta});'
                . '}'
                . '}else{'
                . 'var singleType=(payload&&payload["@type"])?payload["@type"]:((meta&&meta.types&&meta.types[0])?meta.types[0]:"Schema");'
                . 'var itemMeta=Object.assign({},meta,{types:[singleType],entry_label:entryLabel(payload,meta)});'
                . 'if(isLocalBusinessEntry(payload,singleType)&&payload.name){itemMeta.branch_name=payload.name;}'
                . 'out.push({payload:payload,meta:itemMeta});'
                . '}'
                . 'return out;'
                . '}'
                . 'var incoming=splitEntries(jsonldPayload,jsonldMeta);'
                . 'for(var s=0;s<incoming.length;s++){store.entries.push(incoming[s]);}'
                . 'store.active=0;'
                . 'var layer=document.getElementById("jsonld-debug-layer");'
                . 'var tabs,info,body,copyBtn,toggleBtn,head;'
                . 'if(!layer){'
                . 'layer=document.createElement("div");'
                . 'layer.id="jsonld-debug-layer";'
                . 'layer.style.cssText="position:fixed;left:16px;bottom:16px;z-index:10000000;width:min(820px,calc(100vw - 32px));height:60vh;min-height:60vh;max-height:60vh;background:#0f172a;color:#e2e8f0;border:1px solid #334155;border-radius:10px;box-shadow:0 10px 30px rgba(2,6,23,.45);font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;overflow:hidden;display:flex;flex-direction:column;";'
                . 'var style=document.createElement("style");'
                . 'style.textContent="#jsonld-debug-tabs{display:flex;gap:4px;min-width:0;overflow-x:auto;scrollbar-width:thin;min-height:55px;align-items:center;flex-wrap:wrap;}#jsonld-debug-tabs .jsonld-debug-tab{padding:4px 12px;display:inline-block;font-size:11px;border-radius:5px;border:1px solid #475569;background:#0b1220;color:#e2e8f0;cursor:pointer;margin:0;margin-right:4px;margin-bottom:0;transition:background 0.15s,border 0.15s,color 0.15s;font-family:inherit;font-weight:400;line-height:1.2;white-space:nowrap;text-align:left;box-shadow:none;outline:none;max-width:none;overflow:visible;text-overflow:unset;}#jsonld-debug-tabs .jsonld-debug-tab:focus,#jsonld-debug-tabs .jsonld-debug-tab:focus-visible,#jsonld-debug-tabs .jsonld-debug-tab:active{outline:none !important;box-shadow:none !important;}#jsonld-debug-tabs .jsonld-debug-tab::-moz-focus-inner{border:0;}#jsonld-debug-tabs .jsonld-debug-tab:last-child{margin-right:0;}#jsonld-debug-tabs .jsonld-debug-tab.active,#jsonld-debug-tabs .jsonld-debug-tab[aria-selected=\"true\"]{border:1px solid #3b82f6;background:#1d4ed8;color:#e2e8f0;}#jsonld-debug-tabs .jsonld-debug-tab:hover:not(.active):not([aria-selected=\"true\"]){background:#232b3d;border-color:#64748b;color:#dbeafe;}";'
                . 'layer.appendChild(style);'
                . 'head=document.createElement("div");head.id="jsonld-debug-head";head.style.cssText="display:flex;flex-direction:column;gap:8px;padding:10px 12px;background:#1e293b;border-bottom:1px solid #334155;";'
                . 'var rowTop=document.createElement("div");rowTop.style.cssText="display:flex;align-items:center;justify-content:space-between;gap:8px;";'
                . 'var title=document.createElement("strong");title.textContent="JSON-LD Debug";title.style.cssText="font-size:12px;letter-spacing:.2px;white-space:nowrap;";rowTop.appendChild(title);'
                . 'var actions=document.createElement("div");actions.style.cssText="display:flex;align-items:center;gap:6px;";'
                . 'toggleBtn=document.createElement("button");toggleBtn.type="button";toggleBtn.title="Ein-/ausklappen";toggleBtn.setAttribute("aria-label","Ein-/ausklappen");toggleBtn.style.cssText="border:1px solid #475569;background:#0b1220;color:#cbd5e1;padding:6px 8px;border-radius:6px;font-size:12px;cursor:pointer;line-height:1;display:inline-flex;align-items:center;justify-content:center;";toggleBtn.innerHTML=openIcon;'
                . 'copyBtn=document.createElement("button");copyBtn.type="button";copyBtn.title="JSON kopieren";copyBtn.setAttribute("aria-label","JSON kopieren");copyBtn.style.cssText="border:1px solid #475569;background:#0b1220;color:#cbd5e1;padding:6px 8px;border-radius:6px;font-size:12px;cursor:pointer;line-height:1;display:inline-flex;align-items:center;justify-content:center;";copyBtn.innerHTML=copyIcon;'
                . 'actions.appendChild(toggleBtn);actions.appendChild(copyBtn);rowTop.appendChild(actions);'
                . 'tabs=document.createElement("div");tabs.id="jsonld-debug-tabs";tabs.style.cssText="display:flex;gap:4px;min-width:0;overflow:auto;scrollbar-width:thin;min-height:55px;align-items:center;";'
                . 'head.appendChild(rowTop);head.appendChild(tabs);'
                . 'info=document.createElement("div");info.id="jsonld-debug-info";info.style.cssText="padding:8px 12px;font-size:11px;color:#94a3b8;border-bottom:1px solid #334155;";'
                . 'body=document.createElement("pre");body.id="jsonld-debug-body";body.style.cssText="margin:0;padding:12px;flex:1 1 auto;min-height:0;overflow:auto;overscroll-behavior:contain;-webkit-overflow-scrolling:touch;white-space:pre-wrap;word-break:break-word;font-size:12px;line-height:1.45;background:#020617;color:#e2e8f0;";'
                . 'copyBtn.addEventListener("click",function(){var active=store.entries[store.active]||{payload:{}};var txt=JSON.stringify(active.payload,null,2);if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(txt);}copyBtn.innerHTML="&#10003;";setTimeout(function(){copyBtn.innerHTML=copyIcon;},900);});'
                . 'toggleBtn.addEventListener("click",function(){store.collapsed=!store.collapsed;updateView();});'
                . 'function stopScrollPropagation(e){if(!body){return;}e.stopPropagation();}'
                . 'body.addEventListener("wheel",stopScrollPropagation,{passive:false});'
                . 'body.addEventListener("touchmove",function(e){e.stopPropagation();},{passive:true});'
                . 'layer.appendChild(head);layer.appendChild(info);layer.appendChild(body);document.body.appendChild(layer);'
                . '}else{'
                . 'tabs=layer.querySelector("#jsonld-debug-tabs");'
                . 'info=layer.querySelector("#jsonld-debug-info");'
                . 'body=layer.querySelector("#jsonld-debug-body");'
                . 'copyBtn=layer.querySelector("[aria-label=\"JSON kopieren\"]");'
                . 'toggleBtn=layer.querySelector("[aria-label=\"Ein-/ausklappen\"]");'
                . 'head=layer.querySelector("#jsonld-debug-head");'
                . '}'
                . 'function labelFor(entry,idx){var t=(entry.meta&&entry.meta.entry_label)?entry.meta.entry_label:((entry.meta&&entry.meta.types&&entry.meta.types.length)?entry.meta.types.join(", "):"Schema");var isLocal=false;var type=(entry&&entry.payload&&entry.payload["@type"])?String(entry.payload["@type"]):"";if(type.toLowerCase().indexOf("localbusiness")!==-1){isLocal=true;}if(!isLocal&&entry&&entry.payload&&entry.payload["@id"]&&String(entry.payload["@id"]).toLowerCase().indexOf("#localbusiness")!==-1){isLocal=true;}if(entry.meta&&entry.meta.branch_name){t=entry.meta.branch_name;}if(isLocal&&t.indexOf("LocalBusiness:")!==0){t="LocalBusiness: "+t;}return(idx+1)+". "+t;}'
                . 'function metaLine(meta){var parts=[];if(meta.domain_id){parts.push("Domain: "+meta.domain_name+" (ID: "+meta.domain_id+")");}if(meta.article_id){parts.push("Artikel ID: "+meta.article_id);}if(meta.clang_id){parts.push("Sprache ID: "+meta.clang_id);}if(typeof meta.branch_id!=="undefined"&&meta.branch_id!==null){parts.push("LocalBusiness ID: "+meta.branch_id);}if(meta.dynamic_profile_id){parts.push("URL-Profil ID: "+meta.dynamic_profile_id);}if(meta.dynamic_data_id){parts.push("Datensatz ID: "+meta.dynamic_data_id);}parts.push("Aktiv nur bei Debug-Modus");return parts.join(" | ");}'
                . 'function updateView(){if(!tabs||!info||!body){return;}tabs.innerHTML="";for(var i=0;i<store.entries.length;i++){(function(index){var tab=document.createElement("div");tab.className="jsonld-debug-tab"+(index===store.active?" active":"");tab.textContent=labelFor(store.entries[index],index);tab.tabIndex=0;tab.setAttribute("role","tab");tab.setAttribute("aria-selected",index===store.active?"true":"false");tab.addEventListener("click",function(){store.active=index;store.collapsed=false;updateView();});tabs.appendChild(tab);})(i);}var active=store.entries[store.active]||store.entries[0];if(!active){return;}if(toggleBtn){toggleBtn.innerHTML=store.collapsed?closedIcon:openIcon;}layer.style.height=store.collapsed?"auto":"60vh";layer.style.minHeight=store.collapsed?"0":"60vh";layer.style.maxHeight=store.collapsed?"none":"60vh";info.style.display=store.collapsed?"none":"block";body.style.display=store.collapsed?"none":"block";head.style.borderBottom=store.collapsed?"0":"1px solid #334155";info.textContent=metaLine(active.meta||{});body.textContent=JSON.stringify(active.payload||{},null,2);}'
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
    * @param array<string, mixed> $additionalData Zusätzliche Daten
     * @return string JSON-LD Script Tag
     */
    function jsonld_render(?string $schemaType = null, array $additionalData = []): string
    {
        try {
            $article = rex_article::getCurrent();
            if (!$article) {
                return '';
            }

            return JsonLdGenerator::renderArticleScript(
                (int) $article->getId(),
                null,
                jsonld_is_debug_enabled(),
                (int) $article->getClangId()
            );
        } catch (Exception $e) {
            if (rex::isDebugMode()) {
                return '<!-- JSON-LD Error: ' . htmlspecialchars($e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()) . ' -->';
            }
        }

        return '';
    }
}
