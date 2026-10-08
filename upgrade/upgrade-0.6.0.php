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

if (version_compare(_PS_VERSION_, '1.5', '<=')) {
    return;
}

Configuration::updateValue('SSM_UPDATE_RESULTS', '');
