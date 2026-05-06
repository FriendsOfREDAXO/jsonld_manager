<?php

use FriendsOfRedaxo\JsonLdManager\DomainConfig;

$version = rex_addon::get('jsonld_manager')->getVersion();
$domainDisplay = DomainConfig::renderDomainDisplay();

echo rex_view::title('JSON-LD Manager <small style="opacity:.7;font-size:.6em;">v' . rex_escape($version) . $domainDisplay . '</small>');

rex_be_controller::includeCurrentPageSubPath();
