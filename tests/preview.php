<?php
// Produit les pages HTML des aperçus du README (docs/screenshots) à partir du vrai HTML du module, dans un faux PrestaShop.
//   php tests/preview.php <dossier-de-sortie>      puis capture d'écran des fichiers obtenus (voir README, section Développement)

require __DIR__ . '/bootstrap.php';

$out = isset($argv[1]) ? rtrim($argv[1], '/') : '.';
const PV_TOKEN = 'Qx7_Lm2-9aZpR4tYv8NcB1sWe6HdKfJgU3oIyXk5MnA';

function pv_module($state)
{
    Configuration::$v = [];
    Tools::$values = [];
    Tools::$submitted = [];
    Db::$modules = [['name' => 'ps_emailsubscription', 'version' => '3.0.0', 'active' => '1']];
    $theme = new Theme();
    Theme::$list = [$theme];
    $module = new Ssmconnector();
    Configuration::updateValue('SSM_UPDATE_CACHE', json_encode(['checked_at' => strtotime('2026-10-05 09:30:00'), 'error' => false, 'latest' => $module->version, 'url' => null, 'download' => null]));
    Configuration::updateValue('SSM_HEARTBEAT_INTERVAL', 1800);
    Configuration::updateValue('SSM_AUTO_HEARTBEAT', 1);
    if ($state !== 'initial') {
        Configuration::updateValue('SSM_SSM_URL', 'https://ssm.exemple.fr');
        Configuration::updateValue('SSM_CONNECTOR_TOKEN', PV_TOKEN);
        Configuration::updateValue('SSM_LAST_HEARTBEAT_AT', '2026-10-05 10:02:11');
    }
    if ($state === 'connecte') {
        Configuration::updateValue('SSM_HEARTBEAT_OK', 1);
    } elseif ($state === 'erreur-token') {
        Configuration::updateValue('SSM_HEARTBEAT_OK', 0);
        Configuration::updateValue('SSM_LAST_ERROR', $module->describeFailure(401, 0));
    }
    return $module;
}

foreach (['initial' => 'configuration-initiale', 'connecte' => 'connecte', 'erreur-token' => 'erreur-token'] as $state => $name) {
    $module = pv_module($state);
    $html = $module->getContent();
    file_put_contents("$out/$name.html", '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>SSM Connector</title>'
        . '<link rel="stylesheet" href="preview.css"></head><body>'
        . '<div class="crumb">Modules › Gestionnaire de modules › SSM Connector</div><h1>SSM Connector — Configurer</h1>'
        . $html . '</body></html>');
    echo "$out/$name.html\n";
}
