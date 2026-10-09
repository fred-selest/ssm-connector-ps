<?php
/**
 * Connexion directe au back-office depuis SSM (lien signé, 60 secondes, usage unique).
 *
 * Ne fait rien tant que la boutique ne l'a pas autorisée :
 * define('SSM_CONNECTOR_ALLOW_LOGIN', true); dans config/defines_custom.inc.php.
 * La vérification du lien et le choix de l'employé sont dans SsmConnector::verifyLoginToken().
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SsmconnectorLoginModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    /** Boutique en maintenance : le lien doit quand même mener au back-office. */
    protected function displayMaintenancePage()
    {
    }

    public function postProcess()
    {
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');
        header('Referrer-Policy: no-referrer');   // le jeton ne doit pas fuiter vers une autre page

        $employee = $this->module->verifyLoginToken((string) Tools::getValue('ssm_token'));
        if (is_string($employee)) {
            $this->refuse($employee);
        }
        $this->module->openBackOfficeSession($employee);
        $target = $this->module->dashboardUrl($employee);
        if ($target === null) {
            $this->refuse('dossier du back-office introuvable : ouvrez une fois le back-office à la main pour que le module le reconnaisse');
        }
        Tools::redirect($target);
        exit;
    }

    private function refuse($reason)
    {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Lien de connexion SSM refusé : ' . $reason . '. Demandez-en un nouveau depuis SSM.';
        exit;
    }
}
