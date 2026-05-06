<?php

/**
 * JSON-LD Manager AddOn - Deinstallation
 * 
 * Entfernt alle Datenbank-Tabellen und Konfigurationen
 * des JSON-LD Manager AddOns.
 * 
 * @package JsonldManager
 * @version 1.0.0
 * @author  getaweb GmbH
 */

// Datenbank-Tabellen löschen mit REDAXO API (in korrekter Reihenfolge wegen Foreign Keys)
rex_sql_table::get(rex::getTable('jsonld_url_profile_mappings'))->drop();
rex_sql_table::get(rex::getTable('jsonld_localbusiness_branches'))->drop();
rex_sql_table::get(rex::getTable('jsonld_url_rules'))->drop(); 
rex_sql_table::get(rex::getTable('jsonld_schemas'))->drop();

// AddOn-Konfiguration komplett löschen
rex_config::removeNamespace('jsonld_manager');
