<?php
/**
 * Mise à jour vers 0.4.1 : le token peut désormais être saisi (celui fourni par
 * SSM Core). Réaffirme les réglages par défaut. Idempotent.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_4_1($module)
{
    return $module->setupDefaults();
}
