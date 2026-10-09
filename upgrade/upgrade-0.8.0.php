<?php
/**
 * Mise à jour vers 0.8.0 : adresse réelle du back-office et connexion directe depuis SSM.
 * La connexion directe reste fermée : elle ne s'ouvre que par la constante
 * SSM_CONNECTOR_ALLOW_LOGIN (config/defines_custom.inc.php). Aucune clé n'existe encore.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_8_0($module)
{
    Configuration::deleteByName('SSM_LOGIN_KEY');
    Configuration::deleteByName('SSM_LOGIN_NONCES');
    return true;
}
