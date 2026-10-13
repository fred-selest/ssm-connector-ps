<?php
if (!defined('_PS_VERSION_')) {
    exit;
}
/**
 * 0.9.0 — Explorateur de fichiers (SSM Core 2.27).
 *
 * Aucune donnée stockee ne change : les nouvelles capacites (`read_files`, `write_files`) sont
 * annoncees au heartbeat, et chaque operation est traitee comme une commande. Il n'y a donc rien
 * a migrer en base — mais la declaration reste obligatoire : sans elle, PrestaShop considere le
 * module comme needing upgrade a chaque chargement, et le bandeau d'alerte revient sur chaque
 * page du back-office.
 */
function upgrade_module_0_9_0($module)
{
    return $module->announceNewVersion();
}
