<?php
/**
 * Mise à jour vers 0.4.0 : nouveaux réglages (envoi automatique, état de la
 * connexion, protection du point d'entrée cron) et hook de la boutique.
 * Idempotent.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_4_0($module)
{
    return $module->setupDefaults();
}
