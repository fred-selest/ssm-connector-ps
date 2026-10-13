<?php
if (!defined('_PS_VERSION_')) {
    exit;
}
/**
 * 0.10.0 — Transfert de fichiers (SSM Core 2.27).
 *
 * Aucune donnee stockee ne change : les nouvelles capacites sont annoncees au heartbeat et chaque
 * operation est traitee comme une commande. La declaration reste obligatoire — sans elle, PrestaShop
 * reclame une mise a jour a chaque chargement du back-office.
 */
function upgrade_module_0_10_0($module)
{
    return $module->announceNewVersion();
}
