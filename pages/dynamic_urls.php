<?php

if (!rex_addon::get('url')->isAvailable()) {
    rex_response::sendRedirect(rex_url::backendPage('jsonld_manager/article'));
}

// Konfigurationsmodus prüfen
$func = rex_get('func', 'string');
$profileId = rex_get('profile_id', 'int');

if ($func === 'edit' && $profileId > 0) {
    // Konfigurationsseite für spezifisches URL-Profil
    include __DIR__ . '/dynamic_urls_edit.php';
    return;
}

// Hauptübersicht der URL-Profile
$profiles = rex_sql::factory()->getArray('SELECT id, namespace, table_name FROM ' . rex::getTable('url_generator_profile') . ' ORDER BY namespace');

if (count($profiles) > 0) {
    
    // Tabelle der URL-Profile
    $content = '<table class="table table-striped">';
    $content .= '<thead><tr>';
    $content .= '<th>Namespace</th>';
    $content .= '<th>YForm Tabelle</th>';
    $content .= '<th class="text-right">JSON-LD Status</th>';
    $content .= '<th class="text-right">Aktion</th>';
    $content .= '</tr></thead><tbody>';
    
    foreach ($profiles as $profile) {
        // Korrigiere Tabellenname (entferne 1_xxx_ Prefix)
        $realTableName = str_replace('1_xxx_', '', $profile['table_name']);
        
        // Prüfe ob Mapping existiert
        $mappingExists = rex_sql::factory()->getArray(
            'SELECT id FROM ' . rex::getTable('jsonld_url_profile_mappings') . ' WHERE url_profile_id = ?',
            [$profile['id']]
        );
        
        $status = count($mappingExists) > 0 
            ? '<span class="label label-success">Konfiguriert</span>'
            : '<span class="label label-warning">Nicht konfiguriert</span>';
            
        $editUrl = rex_url::currentBackendPage([
            'func' => 'edit',
            'profile_id' => $profile['id']
        ]);
        
        $content .= '<tr>';
        $content .= '<td>' . rex_escape($profile['namespace']) . '</td>';
        $content .= '<td>' . rex_escape($realTableName) . '</td>';
        $content .= '<td class="text-right">' . $status . '</td>';
        $content .= '<td class="text-right"><a href="' . $editUrl . '" class="btn btn-sm btn-default"><i class="rex-icon rex-icon-edit"></i> Konfigurieren</a></td>';
        $content .= '</tr>';
    }
    
    $content .= '</tbody></table>';

    $fragment = new rex_fragment();
    $fragment->setVar('title', rex_i18n::msg('jsonld_manager_dynamic_urls_profiles'), false);
    $fragment->setVar('content', $content, false);
    echo $fragment->parse('core/page/section.php');
} else {
    echo rex_view::info(rex_i18n::msg('jsonld_manager_dynamic_urls_no_profiles'));
}
