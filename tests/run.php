<?php
// Tests du module sans PrestaShop : php tests/run.php  (code de sortie 1 au moindre échec)
//
// Un faux PrestaShop (Configuration, Db, Tools, Module…) suffit à charger le module et à exécuter son inventaire,
// sa page de configuration et son transport ; les envois partent vraiment, en HTTP, vers un faux SSM Core local.
// Les tests sur une vraie boutique restent à faire à la main (installation, mise à jour, affichage du back-office).

require __DIR__ . '/bootstrap.php';

$failures = 0;
$checks = 0;
function check($cond, $label)
{
    global $failures, $checks;
    $checks++;
    if (!$cond) {
        $failures++;
        echo "  ÉCHEC : $label\n";
    }
}
function same($actual, $expected, $label)
{
    check($actual === $expected, $label . ' (attendu ' . json_encode($expected) . ', obtenu ' . json_encode($actual) . ')');
}

const TOKEN_A = 'Qx7_Lm2-9aZpR4tYv8NcB1sWe6HdKfJgU3oIyXk5MnA';   // 43 caractères, comme un token généré par SSM
const TOKEN_B = 'Zz1_Yy2-Xx3_Ww4-Vv5_Uu6-Tt7_Ss8-Rr9_Qq0-Pp1_Oo';

// Limites de SSM Core (app/schemas.py) : HeartbeatRequest, ExtensionData, ThemeData.
const CORE_TOP = ['cms_version' => 50, 'php_version' => 20, 'db_version' => 50, 'web_server' => 50, 'hostname' => 255, 'site_path' => 500];
const CORE_ITEM = ['slug' => 255, 'name' => 255, 'version' => 50, 'latest_version' => 50, 'parent_theme' => 255];

// Crochets que PrestaShop déclenche réellement (install-dev/data/xml/hook.xml et code source du cœur, branche develop).
const REAL_HOOKS = ['actionAuthenticationBefore', 'actionAuthentication', 'actionValidateOrder', 'actionProductUpdate',
    'actionModuleInstallAfter', 'actionModuleUninstallAfter', 'displayBackOfficeTop', 'displayHeader'];

function fresh()
{
    Configuration::$v = [];
    Tools::$values = [];
    Tools::$submitted = [];
    Module::$hooks = [];
    Module::$unregistered = [];
    Db::$modules = [
        ['name' => 'ps_emailsubscription', 'version' => '3.0.0', 'active' => '1'],
        ['name' => 'blockreassurance', 'version' => '5.1.2', 'active' => '1'],
        ['name' => 'ps_legacy_demo', 'version' => '1.0.0', 'active' => '0'],
    ];
    $theme = new Theme();
    Theme::$list = [$theme];
    Configuration::updateValue('SSM_UPDATE_CACHE', json_encode(['checked_at' => time(), 'error' => false, 'latest' => '0.4.2', 'url' => null, 'download' => null]));
    return new Ssmconnector();
}

function priv($obj, $name, ...$args)
{
    $m = new ReflectionMethod($obj, $name);
    $m->setAccessible(true);
    return $m->invoke($obj, ...$args);
}

function test($name, callable $fn)
{
    echo "- $name\n";
    $fn();
}

// --- faux SSM Core local ---------------------------------------------------------------------------------------------

$tmp = sys_get_temp_dir() . '/ssm-ps-tests-' . getmypid();
mkdir($tmp);
$sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
$port = (int) substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
fclose($sock);
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/fake_ssm.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, ['SSM_FAKE_DIR' => $tmp]);
register_shutdown_function(function () use ($server, $tmp) {
    proc_terminate($server);
    foreach (glob($tmp . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($tmp);
});
for ($i = 0; $i < 50; $i++) {
    $c = @fsockopen('127.0.0.1', $port);
    if ($c) { fclose($c); break; }
    usleep(100000);
}
define('SSM_URL', "http://127.0.0.1:$port");

function core_replies($status, $body = '')
{
    global $tmp;
    file_put_contents($tmp . '/status', (string) $status);
    file_put_contents($tmp . '/body', is_array($body) ? json_encode($body) : $body);
    @unlink($tmp . '/requests.jsonl');
}
function core_requests()
{
    global $tmp;
    $lines = @file($tmp . '/requests.jsonl', FILE_IGNORE_NEW_LINES) ?: [];
    return array_map(function ($l) { return json_decode($l, true); }, $lines);
}
function configure($module, $url = null, $token = TOKEN_A)
{
    Configuration::updateValue('SSM_SSM_URL', $url === null ? SSM_URL : $url);
    Configuration::updateValue('SSM_CONNECTOR_TOKEN', $token);
}

// ---------------------------------------------------------------------------------------------------------------

test('versions cohérentes (module, config.xml, ssmconnector.txt, upgrade)', function () {
    $root = dirname(__DIR__);
    $module = (fresh())->version;
    preg_match('/<version><!\[CDATA\[([0-9.]+)\]\]><\/version>/', file_get_contents($root . '/config.xml'), $m);
    same($m[1], $module, 'config.xml');
    check(strpos(file_get_contents($root . '/ssmconnector.txt'), "Version $module") !== false, 'ssmconnector.txt');
    check(is_file($root . '/upgrade/upgrade-' . $module . '.php'), 'script upgrade-' . $module . '.php');
    check(strpos(file_get_contents($root . '/ssmconnector.php'), '@version ' . $module) !== false, 'en-tête @version');
});

test('crochets : uniquement des crochets que PrestaShop déclenche vraiment', function () {
    $m = fresh();
    check($m->install(), 'installation');
    $registered = array_keys(Module::$hooks);
    sort($registered);
    $expected = REAL_HOOKS;
    sort($expected);
    same($registered, $expected, 'crochets attachés');
    foreach (['actionCustomerLoginBefore', 'actionCustomerLoginAfter', 'actionEmployeeLoginAfter', 'actionOrderCreated'] as $old) {
        check(!isset(Module::$hooks[$old]), "ancien crochet inexistant non attaché : $old");
    }
    check(in_array('actionCustomerLoginBefore', Module::$unregistered, true) && in_array('actionOrderCreated', Module::$unregistered, true), 'les anciens crochets déjà attachés sont détachés (mise à jour)');
    foreach ($registered as $hook) {
        check(method_exists($m, 'hook' . ucfirst($hook)), "méthode hook$hook présente");
    }
    $source = file_get_contents(dirname(__DIR__) . '/ssmconnector.php');
    preg_match_all('/public function hook([A-Za-z]+)\(/', $source, $found);
    foreach ($found[1] as $method) {
        check(in_array(lcfirst($method), REAL_HOOKS, true), "la méthode hook$method correspond à un crochet réel");
    }
});

test("l'installation n'invente plus de token (il vient de SSM Core)", function () {
    $m = fresh();
    $m->install();
    check(Configuration::get('SSM_CONNECTOR_TOKEN') === false, 'aucun token créé');
});

test("l'inventaire respecte le contrat de SSM Core", function () {
    $m = fresh();
    $inv = priv($m, 'collectInventory');
    foreach (['cms_version', 'php_version', 'db_version', 'web_server', 'hostname', 'site_path', 'extensions', 'themes'] as $key) {
        check(array_key_exists($key, $inv), "clé lue par SSM Core : $key");
    }
    check(!array_key_exists('modules', $inv) && !array_key_exists('mysql_version', $inv), "plus de clés ignorées par SSM Core (modules, mysql_version)");
    same(count($inv['extensions']), 3, 'trois modules dans « extensions »');
    same(array_column($inv['extensions'], 'slug'), ['ps_emailsubscription', 'blockreassurance', 'ps_legacy_demo'], 'identifiants');
    same(array_column($inv['extensions'], 'is_active'), [true, true, false], 'état actif / inactif');
    check(!isset($inv['extensions'][0]['type']), 'pas de champ superflu');
    same($inv['db_version'], '8.0.36', 'version de la base');
    check(preg_match('/^\d+\.\d+\.\d+$/', $inv['php_version']) === 1 && strlen($inv['php_version']) <= 20, 'version de PHP au format X.Y.Z');
    same($inv['site_path'], '/var/www/html', 'chemin');
    same(count($inv['themes']), 1, 'un thème');
    same($inv['themes'][0]['slug'], 'classic', 'identifiant du thème');
    same($inv['module_count'], 3, 'compteur');
    same($inv['module_active_count'], 2, 'compteur de modules actifs');
});

test("l'inventaire respecte les longueurs maximales de SSM Core (sinon tout est refusé en 422)", function () {
    $m = fresh();
    Db::$modules = [['name' => str_repeat('é', 400), 'version' => str_repeat('9', 80), 'active' => '1']];
    $theme = new Theme();
    $theme->name = str_repeat('T', 400);
    $theme->directory = str_repeat('d', 400);
    Theme::$list = [$theme];
    $_SERVER['SERVER_SOFTWARE'] = str_repeat('Apache/', 30);
    $inv = priv($m, 'collectInventory');
    unset($_SERVER['SERVER_SOFTWARE']);
    foreach (CORE_TOP as $key => $max) {
        if (isset($inv[$key])) {
            check(mb_strlen($inv[$key]) <= $max, "$key <= $max");
        }
    }
    foreach (array_merge($inv['extensions'], $inv['themes']) as $item) {
        foreach (CORE_ITEM as $key => $max) {
            if (isset($item[$key])) {
                check(mb_strlen($item[$key]) <= $max, "élément.$key <= $max");
            }
        }
    }
    same(mb_strlen($inv['extensions'][0]['name']), 255, 'nom du module tronqué à 255 caractères');
    same(mb_strlen($inv['web_server']), 50, 'web_server tronqué à 50');
});

test("trop de modules : plafonné à 1000", function () {
    $m = fresh();
    Db::$modules = [];
    for ($i = 0; $i < 1200; $i++) {
        Db::$modules[] = ['name' => "mod$i", 'version' => '1.0', 'active' => '1'];
    }
    same(count(priv($m, 'collectInventory')['extensions']), 1000, '1000 au maximum');
});

test("trop de thèmes : plafonné à 200 ; la version de PHP vient des trois nombres, jamais de la version complète", function () {
    $m = fresh();
    Theme::$list = [];
    for ($i = 0; $i < 250; $i++) {
        $t = new Theme();
        $t->directory = "theme$i";
        $t->name = "Thème $i";
        Theme::$list[] = $t;
    }
    same(count(priv($m, 'collectInventory')['themes']), 200, '200 au maximum');
    // sur un PHP « Debian » (ex. 8.2.7-1+0~2023...), phpversion() dépasse les 20 caractères acceptés par SSM Core
    $source = file_get_contents(dirname(__DIR__) . '/ssmconnector.php');
    check(strpos($source, 'phpversion()') === false && strpos($source, 'PHP_VERSION') === false, 'phpversion() / PHP_VERSION non utilisés');
});

test("adresse de SSM : nettoyée, https obligatoire hors machine locale", function () {
    $m = fresh();
    foreach ([
        'https://ssm.exemple.fr' => 'https://ssm.exemple.fr',
        'ssm.exemple.fr' => 'https://ssm.exemple.fr',
        ' https://SSM.exemple.fr/ ' => 'https://ssm.exemple.fr',
        'https://ssm.exemple.fr:8443' => 'https://ssm.exemple.fr:8443',
        'http://localhost:8000' => 'http://localhost:8000',
        'http://127.0.0.1:8000' => 'http://127.0.0.1:8000',
    ] as $in => $out) {
        same($m->normalizeUrl($in)[0], $out, 'acceptée : ' . $in);
    }
    foreach (['', 'ftp://x.fr', 'http://ssm.exemple.fr', 'http://192.168.1.10', 'https://u:p@ssm.exemple.fr', 'https://ssm.exemple.fr/?a=1', 'https://ssm.exemple.fr/#x', 'javascript:alert(1)'] as $bad) {
        same($m->normalizeUrl($bad)[0], null, 'refusée : ' . json_encode($bad));
    }
});

test("token : 32 à 256 caractères visibles, collé proprement", function () {
    $m = fresh();
    foreach ([TOKEN_A, " " . TOKEN_A . "\n", '"' . TOKEN_A . '"', 'Bearer ' . TOKEN_A, 'X-SSM-Token: ' . TOKEN_A, substr(TOKEN_A, 0, 20) . "\n" . substr(TOKEN_A, 20)] as $in) {
        same($m->normalizeToken($in)[0], TOKEN_A, 'accepté : ' . json_encode($in));
    }
    foreach (['', '   ', 'court', str_repeat('a', 31), str_repeat('a', 257), str_repeat('é', 40), "abc\x01" . str_repeat('a', 40)] as $bad) {
        same($m->normalizeToken($bad)[0], null, 'refusé : ' . json_encode(substr($bad, 0, 20)));
    }
    same($m->normalizeToken(str_repeat('a', 32))[0], str_repeat('a', 32), '32 caractères : limite basse');
    same($m->normalizeToken(str_repeat('a', 256))[0], str_repeat('a', 256), '256 caractères : limite haute');
    check(!$m->isValidToken(TOKEN_A . "\n"), 'un saut de ligne final n\'est pas accepté ($ strict)');
    check(strpos($m->normalizeToken('court')[1], '32') !== false, 'le message donne la longueur attendue');
});

test("envoi réel vers un faux SSM Core : en-têtes, corps, événements", function () {
    $m = fresh();
    configure($m);
    core_replies(200);
    $m->hookActionAuthentication(['customer' => (object) ['id' => 42]]);
    $r = priv($m, 'sendHeartbeat');
    check($r['ok'], 'succès');
    $reqs = core_requests();
    same(count($reqs), 1, 'une requête');
    $req = $reqs[0];
    same([$req['method'], $req['uri']], ['POST', '/api/v1/heartbeat'], 'POST /api/v1/heartbeat');
    same($req['headers']['x-ssm-token'], TOKEN_A, 'token dans X-SSM-Token');
    check(!isset($req['headers']['x-ssm-signature']), 'plus de fausse signature');
    check(strpos($req['headers']['content-type'], 'application/json') === 0, 'JSON');
    check(strpos($req['body'], TOKEN_A) === false, 'le token n\'est pas dans le corps');
    $body = json_decode($req['body'], true);
    same(count($body['extensions']), 3, 'extensions dans le corps');
    same($body['pending_events'][0]['type'], 'customer_login', 'événement envoyé');
    same(Configuration::get('SSM_HEARTBEAT_OK'), 1, 'état OK enregistré');
    same(json_decode(Configuration::get('SSM_PENDING_EVENTS'), true), [], 'file vidée après un envoi accepté');
});

test("envoi : échec = événements conservés, message clair, jamais le token", function () {
    $m = fresh();
    configure($m);
    $m->hookActionValidateOrder(['order' => (object) ['id' => 9]]);
    foreach ([
        [401, '', 'Token refusé'], [403, '', '403'], [404, '', 'introuvable'], [429, '', 'limite'], [502, '', 'Erreur côté SSM Core'],
        [302, '', 'redirige'],
        [422, ['detail' => [['loc' => ['body', 'php_version'], 'msg' => 'String should have at most 20 characters']]], 'php_version'],
    ] as [$status, $body, $needle]) {
        core_replies($status, $body);
        $r = priv($m, 'sendHeartbeat');
        check(!$r['ok'] && strpos($r['hint'], $needle) !== false, "HTTP $status : {$r['hint']}");
        check(strpos($r['hint'], TOKEN_A) === false, "le token n'apparaît pas dans le message $status");
        same(count(json_decode(Configuration::get('SSM_PENDING_EVENTS'), true)), 1, "événement conservé après l'échec $status");
    }
    same(count(core_requests()), 1, 'la redirection n\'est pas suivie (une seule requête reçue)');
    core_replies(200);
    check(priv($m, 'sendHeartbeat')['ok'], 'et ça repart dès que SSM répond');
    same(json_decode(Configuration::get('SSM_PENDING_EVENTS'), true), [], 'événement retiré après l\'envoi accepté');
});

test("envoi : SSM injoignable, adresse refusée, token manquant", function () {
    $m = fresh();
    configure($m, 'http://127.0.0.1:1');
    $r = priv($m, 'sendHeartbeat', 2);
    check(!$r['ok'] && strpos($r['hint'], 'injoignable') !== false, 'injoignable : ' . $r['hint']);
    configure($m, 'http://ssm.exemple.fr');
    core_replies(200);
    $r = priv($m, 'sendHeartbeat');
    check(!$r['ok'] && strpos($r['hint'], 'HTTPS') !== false && core_requests() === [], 'http public : refusé, rien envoyé');
    configure($m, SSM_URL, '');
    $r = priv($m, 'sendHeartbeat');
    check(!$r['ok'] && strpos($r['hint'], 'Token manquant') !== false && core_requests() === [], 'sans token : rien envoyé');
});

test("describeFailure : erreurs réseau", function () {
    $m = fresh();
    check(strpos($m->describeFailure(0, 6), 'introuvable') !== false, 'DNS');
    check(strpos($m->describeFailure(0, 7), 'injoignable') !== false && strpos($m->describeFailure(0, 28), 'injoignable') !== false, 'connexion / délai');
    check(strpos($m->describeFailure(0, 60), 'Certificat') !== false, 'certificat');
    check(strpos($m->describeFailure(422, 0, 'pas du json'), '422') !== false, '422 sans détail lisible');
});

test("file d'événements : plafonnée, sans doublon rapproché, sans donnée personnelle", function () {
    $m = fresh();
    for ($i = 0; $i < 130; $i++) {
        $m->hookActionProductUpdate(['product' => (object) ['id' => $i]]);
    }
    $events = json_decode(Configuration::get('SSM_PENDING_EVENTS'), true);
    same(count($events), 100, 'au plus 100 événements');
    same(end($events)['payload']['id_product'], 129, 'les plus récents sont gardés');

    $m = fresh();
    $m->hookActionProductUpdate(['product' => (object) ['id' => 5]]);
    $m->hookActionProductUpdate(['product' => (object) ['id' => 5]]);
    same(count(json_decode(Configuration::get('SSM_PENDING_EVENTS'), true)), 1, 'la même mise à jour répétée n\'écrit qu\'une fois');
    $m->hookActionProductUpdate(['product' => (object) ['id' => 6]]);
    same(count(json_decode(Configuration::get('SSM_PENDING_EVENTS'), true)), 2, 'un autre produit s\'ajoute');

    $m = fresh();
    for ($i = 0; $i < 50; $i++) {
        $m->hookActionAuthenticationBefore([]);   // force brute : une écriture par minute au plus
    }
    same(count(json_decode(Configuration::get('SSM_PENDING_EVENTS'), true)), 1, 'tentatives de connexion regroupées');
    $json = Configuration::get('SSM_PENDING_EVENTS');
    check(strpos($json, 'email') === false && strpos($json, 'hash') === false, 'aucune adresse e-mail, même hachée');

    $m = fresh();
    $m->hookActionValidateOrder(['order' => (object) ['id' => 3]]);
    $m->hookActionModuleInstallAfter(['module' => (object) ['name' => 'monmodule']]);
    $m->hookActionModuleUninstallAfter(['module' => (object) ['name' => 'monmodule']]);
    same(array_column(json_decode(Configuration::get('SSM_PENDING_EVENTS'), true), 'type'), ['order_created', 'module_install', 'module_uninstall'], 'types d\'événements');
});

test("tâche cron : token en en-tête, blocage après 10 échecs, adresse IP jamais en clair", function () {
    $m = fresh();
    configure($m);
    same($m->authenticateCron(TOKEN_A, '198.51.100.1'), 'ok', 'bon token');
    same($m->authenticateCron('mauvais', '198.51.100.2'), 'forbidden', 'mauvais token');
    same($m->authenticateCron('', '198.51.100.2'), 'forbidden', 'token vide');
    for ($i = 0; $i < 10; $i++) {
        same($m->authenticateCron('mauvais', '198.51.100.3'), 'forbidden', 'échec ' . ($i + 1) . ' : refusé');
    }
    same($m->authenticateCron('mauvais', '198.51.100.3'), 'blocked', 'bloqué après 10 échecs');
    same($m->authenticateCron(TOKEN_A, '198.51.100.3'), 'blocked', 'même avec le bon token pendant le blocage');
    same($m->authenticateCron(TOKEN_A, '198.51.100.9'), 'ok', 'une autre adresse n\'est pas touchée');
    check(strpos((string) Configuration::get('SSM_CRON_FAILS'), '198.51.100') === false, 'l\'adresse IP n\'est pas stockée en clair');
    configure($m, SSM_URL, '');
    same($m->authenticateCron('', '198.51.100.20'), 'forbidden', 'sans token configuré, rien n\'est accepté');
});

test("envoi automatique : seulement une fois connecté, et attente réduite après un échec", function () {
    $m = fresh();
    check(!priv($m, 'isHeartbeatDue'), 'rien d\'à envoyer sans configuration');
    Configuration::updateValue('SSM_SSM_URL', SSM_URL);
    check(!priv($m, 'isHeartbeatDue'), 'adresse sans token : rien à envoyer');
    configure($m);
    Configuration::updateValue('SSM_HEARTBEAT_INTERVAL', 3600);
    check(priv($m, 'isHeartbeatDue'), 'jamais envoyé : dû');
    Configuration::updateValue('SSM_LAST_HEARTBEAT_AT', date('Y-m-d H:i:s', time() - 600));
    Configuration::updateValue('SSM_HEARTBEAT_OK', 1);
    check(!priv($m, 'isHeartbeatDue'), 'envoyé il y a 10 min avec un intervalle d\'1 h : pas dû');
    Configuration::updateValue('SSM_HEARTBEAT_OK', 0);
    check(priv($m, 'isHeartbeatDue'), 'après un échec, nouvel essai au bout de 5 min');
});

test("page de configuration : le token n'est jamais écrit dans la page", function () {
    $m = fresh();
    configure($m);
    Configuration::updateValue('SSM_HEARTBEAT_OK', 1);
    Configuration::updateValue('SSM_LAST_HEARTBEAT_AT', '2026-10-05 10:00:00');
    $html = $m->getContent();
    check(strpos($html, TOKEN_A) === false, 'token absent de la page');
    check(strpos($html, '•••• ' . substr(TOKEN_A, -4)) !== false, '4 derniers caractères seulement');
    check(preg_match('/name="SSM_CONNECTOR_TOKEN"[^>]*value=""/', $html) === 1, 'champ token jamais prérempli');
    check(strpos($html, 'type="password"') !== false, 'champ masqué');
    check(strpos($html, 'VOTRE_TOKEN') !== false, 'commande cron avec un marqueur');
    check(strpos($html, 'Connecté à SSM Core') !== false, 'état connecté');
    check(strpos($html, 'Générer un nouveau token ici') === false && strpos($html, 'submitSSMRegenerateToken') === false, 'plus de génération locale de token');
    check(strpos($html, 'ssmToggle') === false, 'plus de bouton pour afficher le token');
});

test("page de configuration : guide quand rien n'est configuré, texte échappé", function () {
    $m = fresh();
    $html = $m->getContent();
    check(strpos($html, 'Pas encore connecté') !== false && strpos($html, 'bouton') !== false, 'guide en trois étapes');
    check(strpos($html, 'Non connecté') === false, 'pas d\'erreur rouge avant toute configuration');
    Configuration::updateValue('SSM_SSM_URL', '"><script>alert(1)</script>');
    Configuration::updateValue('SSM_CONNECTOR_TOKEN', TOKEN_A);
    Configuration::updateValue('SSM_LAST_ERROR', '<img src=x onerror=alert(1)>');
    $html = $m->getContent();
    check(strpos($html, '<script>alert(1)') === false && strpos($html, '<img src=x') === false, 'adresse et erreur échappées');
});

test("page de configuration : token trop court signalé", function () {
    $m = fresh();
    configure($m, SSM_URL, 'abcdefghijklmnop');
    check(strpos($m->getContent(), 'trop court') !== false, 'avertissement');
});

test("enregistrement : formulaire protégé, contrôles, test aussitôt", function () {
    $m = fresh();
    core_replies(200);
    $nonce = priv($m, 'nonce');
    $submit = function (array $values) use ($m) {
        Tools::$submitted = ['submitSSMConfig'];
        Tools::$values = $values;
        return $m->getContent();
    };
    $out = $submit(['SSM_SSM_URL' => SSM_URL, 'SSM_CONNECTOR_TOKEN' => TOKEN_A, 'ssm_nonce' => 'faux']);
    check(strpos($out, 'Session expirée') !== false && Configuration::get('SSM_CONNECTOR_TOKEN') === false, 'sans jeton anti-CSRF valide : refusé, rien enregistré');
    $out = $submit(['SSM_SSM_URL' => 'http://ssm.exemple.fr', 'SSM_CONNECTOR_TOKEN' => TOKEN_A, 'ssm_nonce' => $nonce]);
    check(strpos($out, 'HTTPS') !== false && Configuration::get('SSM_CONNECTOR_TOKEN') === false, 'http public refusé, rien enregistré');
    $out = $submit(['SSM_SSM_URL' => SSM_URL, 'SSM_CONNECTOR_TOKEN' => 'court', 'ssm_nonce' => $nonce]);
    check(strpos($out, 'Token invalide') !== false && Configuration::get('SSM_CONNECTOR_TOKEN') === false, 'token trop court refusé');
    $out = $submit(['SSM_SSM_URL' => SSM_URL, 'SSM_CONNECTOR_TOKEN' => " Bearer " . TOKEN_A . "\n", 'SSM_HEARTBEAT_INTERVAL' => 3600, 'SSM_AUTO_HEARTBEAT' => 1, 'ssm_nonce' => $nonce]);
    check(strpos($out, 'Configuration enregistrée') !== false && strpos($out, 'Connexion réussie') !== false, 'enregistré et testé : ' . substr(strip_tags($out), 0, 120));
    same(Configuration::get('SSM_CONNECTOR_TOKEN'), TOKEN_A, 'token nettoyé avant d\'être enregistré');
    same(Configuration::get('SSM_HEARTBEAT_INTERVAL'), 3600, 'fréquence enregistrée');
    same(core_requests()[0]['headers']['x-ssm-token'], TOKEN_A, 'le test utilise le token enregistré');
    $out = $submit(['SSM_SSM_URL' => SSM_URL, 'SSM_CONNECTOR_TOKEN' => '', 'ssm_nonce' => $nonce]);
    same(Configuration::get('SSM_CONNECTOR_TOKEN'), TOKEN_A, 'champ token vide : token conservé');
    core_replies(401);
    $out = $submit(['SSM_SSM_URL' => SSM_URL, 'SSM_CONNECTOR_TOKEN' => TOKEN_B, 'ssm_nonce' => $nonce]);
    check(strpos($out, 'Token refusé') !== false && strpos($out, TOKEN_B) === false, 'token refusé par SSM : message clair, token non répété');
});

test("mise à jour du connecteur : numéro de version strict, liens limités au dépôt", function () {
    $m = fresh();
    $release = function (array $o = []) {
        return array_merge([
            'tag_name' => 'v0.5.0', 'draft' => false, 'prerelease' => false,
            'html_url' => 'https://github.com/fred-selest/ssm-connector-ps/releases/tag/v0.5.0',
            'assets' => [['name' => 'ssmconnector.zip', 'browser_download_url' => 'https://github.com/fred-selest/ssm-connector-ps/releases/download/v0.5.0/ssmconnector.zip']],
        ], $o);
    };
    $r = priv($m, 'parseRelease', $release());
    same($r['latest'], '0.5.0', 'version');
    check($r['download'] !== null && $r['url'] !== null, 'liens du dépôt acceptés');
    foreach (['latest', 'v0.5', 'v1.0.0-beta', "v0.5.0\n", ' v0.5.0', ''] as $bad) {
        same(priv($m, 'parseRelease', $release(['tag_name' => $bad])), null, 'tag refusé : ' . json_encode($bad));
    }
    same(priv($m, 'parseRelease', $release(['prerelease' => true])), null, 'préversion ignorée');
    $evil = priv($m, 'parseRelease', $release(['html_url' => 'https://evil.example/x', 'assets' => [['name' => 'ssmconnector.zip', 'browser_download_url' => 'https://evil.example/ssmconnector.zip']]]));
    check($evil['url'] === null && $evil['download'] === null, 'liens hors du dépôt écartés');
});

test("aucune fausse protection : pas de signature, pas de token dans l'adresse", function () {
    $source = file_get_contents(dirname(__DIR__) . '/ssmconnector.php') . file_get_contents(dirname(__DIR__) . '/controllers/front/cron.php');
    check(strpos($source, 'X-SSM-Signature') === false && strpos($source, 'hash_hmac(\'sha256\', $payload') === false, 'plus de signature HMAC factice');
    check(strpos($source, "Tools::getValue('token')") === false, 'le token n\'est pas lu dans l\'adresse');
    check(strpos($source, 'CURLOPT_FOLLOWLOCATION => false') !== false && strpos($source, 'CURLOPT_SSL_VERIFYPEER => true') !== false, 'redirections non suivies, certificat vérifié');
});

echo "\n$checks vérifications, $failures échec(s)\n";
exit($failures === 0 ? 0 : 1);
