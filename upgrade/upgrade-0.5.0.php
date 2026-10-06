<?php
/**
 * Mise à jour vers 0.5.0 : ajoute le réglage d'envoi des événements (désactivé par défaut)
 * et l'identifiant du site reconnu par SSM Core. Idempotent.
 *
 * Le token, l'adresse et la fréquence existants sont conservés. La file d'événements
 * éventuelle est vidée : elle n'est plus alimentée tant que le réglage n'est pas activé,
 * et elle contient des identifiants de personnes.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_5_0($module)
{
    if (!$module->setupDefaults()) {
        return false;
    }
    // La file existante n'est plus utilisée : on la vide plutôt que de garder des
    // identifiants clients et commandes que personne ne lit.
    Configuration::updateValue('SSM_PENDING_EVENTS', '[]');
    return true;
}
