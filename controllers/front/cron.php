<?php
/**
 * Point d'entrée cron (facultatif) : envoie le heartbeat si l'intervalle
 * configuré est écoulé.
 *
 * Authentification : token du module dans l'en-tête X-SSM-Token uniquement
 * (jamais dans l'adresse, qui finit dans les journaux du serveur).
 * `force=1` ignore l'intervalle. Après 10 échecs depuis la même adresse IP,
 * l'accès est bloqué 15 minutes (HTTP 429).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SsmconnectorCronModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        $token = isset($_SERVER['HTTP_X_SSM_TOKEN']) ? (string) $_SERVER['HTTP_X_SSM_TOKEN'] : '';
        $auth = $this->module->authenticateCron($token, Tools::getRemoteAddr());

        if ($auth === 'blocked') {
            $this->respond(429, ['error' => 'too_many_attempts'], ['Retry-After: 900']);
        }
        if ($auth !== 'ok') {
            $this->respond(403, ['error' => 'forbidden']);
        }

        $this->respond(200, $this->module->sendScheduledHeartbeat((bool) Tools::getValue('force')));
    }

    private function respond($code, array $data, array $headers = [])
    {
        http_response_code($code);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');
        foreach ($headers as $header) {
            header($header);
        }
        echo json_encode($data);
        exit;
    }
}
