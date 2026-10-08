<?php
/**
 * Mise à jour vers 0.7.0 : contrat 3 de SSM Core (capacités, actions, erreurs PHP, sauvegarde).
 * Aucun réglage n'est activé : une action n'est exécutée que si SSM la demande. Les nouvelles
 * files (comptes rendus d'actions, erreurs PHP, position de lecture du journal) partent vides.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_7_0($module)
{
    Configuration::updateValue('SSM_COMMAND_RESULTS', '');
    Configuration::updateValue('SSM_PHP_ERRORS', '');
    Configuration::deleteByName('SSM_LOG_OFFSET');
    return true;
}
