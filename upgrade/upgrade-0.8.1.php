<?php
/**
 * Mise à jour vers 0.8.1 : la nouvelle version est annoncée à SSM tout de suite, au lieu d'attendre l'envoi
 * planifié suivant. Jamais bloquant : un envoi manqué ne fait pas échouer la mise à jour.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_8_1($module)
{
    return $module->announceNewVersion();
}
