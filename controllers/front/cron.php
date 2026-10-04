<?php
/**
 * Point d'entrée cron : envoie le heartbeat si l'intervalle configuré est écoulé.
 *
 * Authentification : token du module, en en-tête X-SSM-Token (recommandé)
 * ou en paramètre `token`. Paramètre `force=1` pour ignorer l'intervalle.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SsmconnectorCronModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        $expected = (string) Configuration::get('SSM_CONNECTOR_TOKEN');
        $provided = isset($_SERVER['HTTP_X_SSM_TOKEN'])
            ? (string) $_SERVER['HTTP_X_SSM_TOKEN']
            : (string) Tools::getValue('token');

        if ($expected === '' || !hash_equals($expected, $provided)) {
            $this->respond(403, ['error' => 'forbidden']);
        }

        $this->respond(200, $this->module->sendScheduledHeartbeat((bool) Tools::getValue('force')));
    }

    private function respond($code, array $data)
    {
        http_response_code($code);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode($data);
        exit;
    }
}
