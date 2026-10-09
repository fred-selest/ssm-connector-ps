<?php
/**
 * Mise à jour vers 0.6.0 : le connecteur sait exécuter les mises à jour de modules demandées par
 * SSM Core. Aucun réglage n'est ajouté et rien n'est activé : une sauvegarde n'est prise, et une
 * mise à jour n'est exécutée, que si SSM les demande — c'est-à-dire si le prestataire a cliqué sur
 * « Demander », ou si la boutique est en politique automatique (le défaut est « manuel »).
 *
 * La file de comptes rendus en attente est vidée : elle ne contient que des messages destinés à la
 * version précédente du connecteur, qui ne savait pas les lire.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// PrestaShop appelle upgrade_module_<version>() : sans cette fonction, le script n'était pas
// appliqué comme une mise à niveau (les scripts 0.3.0 à 0.5.0 la définissent déjà).
function upgrade_module_0_6_0($module)
{
    Configuration::updateValue('SSM_UPDATE_RESULTS', '');
    return true;
}
