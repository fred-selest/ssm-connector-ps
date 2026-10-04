<?php
/**
 * Mise à jour vers 0.4.2 : détache les crochets qui n'existent pas dans PrestaShop
 * (actionCustomerLoginBefore/After, actionEmployeeLoginAfter, actionOrderCreated) et attache
 * les vrais (actionAuthenticationBefore, actionAuthentication, actionValidateOrder). Idempotent.
 * Le token et la configuration existants sont conservés.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_4_2($module)
{
    return $module->setupDefaults();
}
