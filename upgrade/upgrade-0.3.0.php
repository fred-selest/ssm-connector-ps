<?php
/**
 * Mise à jour vers 0.3.0 : valeurs par défaut, nouveaux hooks, réparation
 * de la file d'événements. Idempotent.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_3_0($module)
{
    return $module->setupDefaults();
}
