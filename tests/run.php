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
const CORE_TOP = ['cms_version' => 50, 'php_version' => 20, 'db_version' => 50, 'web_server' => 50,
    'hostname' => 255, 'site_path' => 500, 'shop_url' => 255, 'cms' => 20,
    'connector_version' => 20, 'latest_connector_version' => 20];
const CORE_ITEM = ['slug' => 255, 'name' => 255, 'version' => 50, 'latest_version' => 50, 'parent_theme' => 255];
// SiteStats (app/schemas.py) : chaque compteur est borné à 0..2 000 000 000.
const CORE_COUNTER_MAX = 2000000000;

// Crochets que PrestaShop déclenche réellement (install-dev/data/xml/hook.xml et code source du cœur, branche develop).
const REAL_HOOKS = ['actionAuthenticationBefore', 'actionAuthentication', 'actionValidateOrder', 'actionProductUpdate',
    'actionModuleInstallAfter', 'actionModuleUninstallAfter', 'displayBackOfficeTop', 'displayHeader'];

function fresh()
{
    Configuration::$v = [];
    Tools::$values = [];
    Tools::$submitted = [];
    Tools::$shop_domain = 'https://boutique.exemple.fr';
    Module::$hooks = [];
    Module::$unregistered = [];
    Module::$by_name = [];
    Module::$upgraded = [];
    Module::$upgrade_ok = true;
    SsmConnector::$disk_check = null;
    ssm_reset_modules();
    Db::$counts = null;
    Db::$modules = [
        ['name' => 'ps_emailsubscription', 'version' => '3.0.0', 'active' => '1'],
        ['name' => 'blockreassurance', 'version' => '5.1.2', 'active' => '1'],
        ['name' => 'ps_legacy_demo', 'version' => '1.0.0', 'active' => '0'],
    ];
    // Chaque module de la base existe aussi comme instance : c'est par là que passe une mise à jour.
    foreach (Db::$modules as $ligne) {
        $mod = new Module();
        $mod->name = $ligne['name'];
        $mod->version = $ligne['version'];
        Module::$by_name[$ligne['name']] = $mod;
    }
    $theme = new Theme();
    Theme::$list = [$theme];
    Configuration::updateValue('SSM_UPDATE_CACHE', json_encode(['checked_at' => time(), 'error' => false, 'latest' => '0.5.0', 'url' => null, 'download' => null]));
    return new Ssmconnector();
}

/** Variante de fresh() avec l'envoi d'événements activé (désactivé par défaut depuis la 0.5.0). */
function freshWithEvents()
{
    $m = fresh();
    Configuration::updateValue('SSM_SEND_EVENTS', 1);
    return $m;
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
    foreach (['cms_version', 'php_version', 'db_version', 'web_server', 'hostname', 'site_path', 'extensions', 'themes', 'results'] as $key) {
        check(array_key_exists($key, $inv), "clé lue par SSM Core : $key");
    }
    check(!array_key_exists('modules', $inv) && !array_key_exists('mysql_version', $inv), "plus de clés ignorées par SSM Core (modules, mysql_version)");
    same(count($inv['extensions']), 3, 'trois modules dans « extensions »');
    same(array_column($inv['extensions'], 'slug'), ['ps_emailsubscription', 'blockreassurance', 'ps_legacy_demo'], 'identifiants');
    same(array_column($inv['extensions'], 'is_active'), [true, true, false], 'état actif / inactif');
    check(!isset($inv['extensions'][0]['type']), 'pas de champ superflu');
    same($inv['db_version'], '8.0.36', 'version de la base');
    check(preg_match('/^\d+\.\d+\.\d+$/', $inv['php_version']) === 1 && strlen($inv['php_version']) <= 20, 'version de PHP au format X.Y.Z');
    same($inv['site_path'], _PS_ROOT_DIR_, 'chemin');
    same(count($inv['themes']), 1, 'un thème');
    same($inv['themes'][0]['slug'], 'classic', 'identifiant du thème');
    same($inv['module_count'], 3, 'compteur');
    same($inv['module_active_count'], 2, 'compteur de modules actifs');
});

test("l'état de la boutique lu par SSM Core 2.7.0 est bien envoyé", function () {
    $m = fresh();
    $inv = priv($m, 'collectInventory');
    // lus par apply_heartbeat (app/connector.py, REPORTED_SITE_FIELDS) depuis la 2.7.0
    foreach (['shop_url', 'multistore', 'ssl_enabled', 'debug_mode', 'maintenance_mode',
        'connector_version', 'latest_connector_version', 'connector_update_available'] as $key) {
        check(array_key_exists($key, $inv), "champ d'état lu par SSM Core : $key");
    }
    // compteurs métier (COUNTER_FIELDS : enregistrés chaque jour depuis la 2.7.0)
    foreach (['customers', 'products', 'orders', 'employees'] as $key) {
        check(isset($inv['stats'][$key]), "compteur métier lu par SSM Core : $key");
    }
    same($inv['connector_version'], $m->version, 'version du connecteur transmise');
    same($inv['cms'], 'prestashop', 'CMS identifié');
    same($inv['shop_url'], 'https://boutique.exemple.fr', 'URL de la boutique');
});

test("l'inventaire respecte les longueurs maximales de SSM Core (sinon tout est refusé en 422)", function () {
    $m = fresh();
    Db::$modules = [['name' => str_repeat('é', 400), 'version' => str_repeat('9', 80), 'active' => '1']];
    $theme = new Theme();
    $theme->name = str_repeat('T', 400);
    $theme->directory = str_repeat('d', 400);
    Theme::$list = [$theme];
    $_SERVER['SERVER_SOFTWARE'] = str_repeat('Apache/', 30);
    Tools::$shop_domain = 'https://' . str_repeat('a', 400) . '.exemple.fr';
    Configuration::updateValue('SSM_UPDATE_CACHE', json_encode(['checked_at' => time(), 'error' => false,
        'latest' => str_repeat('9', 60), 'url' => null, 'download' => null]));
    $inv = priv($m, 'collectInventory');
    unset($_SERVER['SERVER_SOFTWARE']);
    foreach (CORE_TOP as $key => $max) {
        // isset() sauterait un champ null ou absent : on contrôle la longueur quand même,
        // sinon une régression de longueur passerait inaperçue.
        check(!isset($inv[$key]) || mb_strlen((string) $inv[$key]) <= $max, "$key <= $max");
    }
    foreach (array_merge($inv['extensions'], $inv['themes']) as $item) {
        foreach (CORE_ITEM as $key => $max) {
            check(!isset($item[$key]) || mb_strlen((string) $item[$key]) <= $max, "élément.$key <= $max");
        }
    }
    same(mb_strlen($inv['extensions'][0]['name']), 255, 'nom du module tronqué à 255 caractères');
    same(mb_strlen($inv['web_server']), 50, 'web_server tronqué à 50');
    same(mb_strlen($inv['shop_url']), 255, 'shop_url tronqué à 255');
    same(mb_strlen($inv['latest_connector_version']), 20, 'latest_connector_version tronqué à 20');
    same($inv['site_path'], _PS_ROOT_DIR_, 'site_path transmis tel quel (aucune troncature)');
    same(mb_strlen($inv['hostname']), strlen(gethostname()) === 0 ? 0 : mb_strlen(gethostname()), 'hostname sous la limite de 255');
    check(mb_strlen($inv['hostname']) <= 255, 'hostname <= 255');
});

test("les champs non pilotables par le test sont bien tronqués à leur limite", function () {
    // hostname et site_path viennent de l'environnement (gethostname(), _PS_ROOT_DIR_) : ici ils
    // font 13 caractères, donc une borne abaissée serait invisible au test précédent. On vérifie
    // donc l'appel lui-même — même approche que le test qui interdit phpversion().
    $source = file_get_contents(dirname(__DIR__) . '/ssmconnector.php');
    check(strpos($source, 'gethostname(), 255)') !== false, 'hostname tronqué à 255 (limite SSM Core)');
    check(strpos($source, 'cutOrNull(_PS_ROOT_DIR_, 500)') !== false, 'site_path tronqué à 500 (limite SSM Core)');
});

test("une URL de boutique trop longue ne fait pas tout refuser en 422", function () {
    // Regression : shop_url partait sans coupure alors que SSM Core l'accepte au plus sur
    // 255 caractères (app/schemas.py). Une seule URL longue faisait refuser tout l'inventaire.
    $m = fresh();
    Tools::$shop_domain = 'https://' . str_repeat('boutique-', 40) . '.exemple.fr';
    same(mb_strlen(priv($m, 'collectInventory')['shop_url']), 255, 'shop_url tronqué à 255');
});

test("un module ou thème au nom vide est écarté, pas envoyé", function () {
    // Regression : SSM Core exige slug min_length=1. Un nom vide faisait refuser tout l'inventaire.
    $m = fresh();
    Db::$modules = [['name' => '', 'version' => '1.0', 'active' => '1'], ['name' => 'blockreassurance', 'version' => '5.1.2', 'active' => '1']];
    $theme = new Theme();
    $theme->name = '';
    $theme->directory = '';
    Theme::$list = [$theme];
    $inv = priv($m, 'collectInventory');
    same(array_column($inv['extensions'], 'slug'), ['blockreassurance'], 'module au nom vide écarté');
    foreach ($inv['extensions'] as $e) {
        check($e['slug'] !== '', 'aucun slug vide');
    }
    foreach ($inv['themes'] as $t) {
        check($t['slug'] !== '', 'aucun slug de thème vide');
    }
});

test("les compteurs restent dans les bornes de SSM Core", function () {
    // SiteStats est borné à 0..2 000 000 000 : hors de ces bornes, c'est tout l'inventaire qui est refusé.
    $m = fresh();
    $stats = priv($m, 'collectInventory')['stats'];
    foreach ($stats as $key => $value) {
        check(is_int($value) && $value >= 0 && $value <= CORE_COUNTER_MAX, "compteur $key dans les bornes");
    }
    Db::$counts = [PHP_INT_MAX, -5, 0, 12];
    $stats = priv($m, 'collectInventory')['stats'];
    check($stats['customers'] === CORE_COUNTER_MAX, 'compteur trop grand ramené au plafond');
    check($stats['products'] === 0, 'compteur négatif ramené à zéro');
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
    $m = freshWithEvents();
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

test("le site reconnu par SSM Core est enregistré et affiché", function () {
    // HeartbeatResponse renvoie site_id : c'est la confirmation que le token collé est bien
    // celui de CETTE boutique (et pas celui d'une autre, collé par erreur).
    $m = fresh();
    configure($m);
    core_replies(200);
    $r = priv($m, 'sendHeartbeat');
    same($r['site_id'], 42, 'site_id lu dans la réponse');
    same(Configuration::get('SSM_SITE_ID'), 42, 'site enregistré');
    $html = $m->getContent();
    check(strpos($html, 'site <strong>n° 42</strong>') !== false, 'site n° 42 affiché');
});

test("envoi : échec = événements conservés, message clair, jamais le token", function () {
    $m = freshWithEvents();
    configure($m);
    $m->hookActionValidateOrder(['order' => (object) ['id' => 9]]);
    foreach ([
        [401, '', 'Token refusé'], [403, '', '403'], [404, '', 'introuvable'], [429, '', 'limite'], [502, '', 'Erreur côté SSM Core'],
        [302, '', 'redirige'],
        [409, '', 'déjà en cours'],
        [413, '', 'trop volumineux'],
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
    $m = freshWithEvents();
    for ($i = 0; $i < 130; $i++) {
        $m->hookActionProductUpdate(['product' => (object) ['id' => $i]]);
    }
    $events = json_decode(Configuration::get('SSM_PENDING_EVENTS'), true);
    same(count($events), 100, 'au plus 100 événements');
    same(end($events)['payload']['id_product'], 129, 'les plus récents sont gardés');

    $m = freshWithEvents();
    $m->hookActionProductUpdate(['product' => (object) ['id' => 5]]);
    $m->hookActionProductUpdate(['product' => (object) ['id' => 5]]);
    same(count(json_decode(Configuration::get('SSM_PENDING_EVENTS'), true)), 1, 'la même mise à jour répétée n\'écrit qu\'une fois');
    $m->hookActionProductUpdate(['product' => (object) ['id' => 6]]);
    same(count(json_decode(Configuration::get('SSM_PENDING_EVENTS'), true)), 2, 'un autre produit s\'ajoute');

    $m = freshWithEvents();
    for ($i = 0; $i < 50; $i++) {
        $m->hookActionAuthenticationBefore([]);   // force brute : une écriture par minute au plus
    }
    same(count(json_decode(Configuration::get('SSM_PENDING_EVENTS'), true)), 1, 'tentatives de connexion regroupées');
    $json = Configuration::get('SSM_PENDING_EVENTS');
    check(strpos($json, 'email') === false && strpos($json, 'hash') === false, 'aucune adresse e-mail, même hachée');

    $m = freshWithEvents();
    $m->hookActionValidateOrder(['order' => (object) ['id' => 3]]);
    $m->hookActionModuleInstallAfter(['module' => (object) ['name' => 'monmodule']]);
    $m->hookActionModuleUninstallAfter(['module' => (object) ['name' => 'monmodule']]);
    same(array_column(json_decode(Configuration::get('SSM_PENDING_EVENTS'), true), 'type'), ['order_created', 'module_install', 'module_uninstall'], 'types d\'événements');
});

test("les événements sont désactivés par défaut (SSM Core ne les lit pas)", function () {
    // app/schemas.py : `pending_events` n'est volontairement pas déclaré, SSM ne stocke rien de
    // ce que le connecteur déclare là-dessus. La file contient des identifiants de personnes
    // (connexions clients, commandes) : l'écrire n'a donc aucun intérêt tant que ce n'est pas consommé.
    $m = fresh();
    $m->install();
    same(Configuration::get('SSM_SEND_EVENTS'), 0, 'désactivé par défaut, installation comprise');
    $m->hookActionProductUpdate(['product' => (object) ['id' => 5]]);
    $m->hookActionValidateOrder(['order' => (object) ['id' => 3]]);
    $m->hookActionAuthentication(['customer' => (object) ['id' => 42]]);
    $m->hookActionAuthenticationBefore([]);
    // setupDefaults répare la file à l'installation : elle existe mais doit rester vide.
    same(json_decode((string) Configuration::get('SSM_PENDING_EVENTS'), true), [], 'rien n\'est écrit en base');
});

test("désactivés, les événements ne sont pas envoyés", function () {
    $m = fresh();
    configure($m);
    core_replies(200);
    $m->hookActionValidateOrder(['order' => (object) ['id' => 9]]);
    check(priv($m, 'sendHeartbeat')['ok'], 'envoi accepté');
    $body = json_decode(core_requests()[0]['body'], true);
    check(!array_key_exists('pending_events', $body), 'aucun pending_events dans le corps');
});

test("la page de configuration expose le réglage d'événements", function () {
    $m = fresh();
    $html = $m->getContent();
    check(strpos($html, 'name="SSM_SEND_EVENTS"') !== false, 'case présente');
    check(strpos($html, 'ne les exploite pas encore') !== false, 'la raison du réglage est expliquée');
    // activé par le formulaire
    $m = fresh();
    $nonce = priv($m, 'nonce');
    core_replies(200);
    Tools::$submitted = ['submitSSMConfig'];
    Tools::$values = ['SSM_SSM_URL' => SSM_URL, 'SSM_CONNECTOR_TOKEN' => TOKEN_A, 'SSM_SEND_EVENTS' => 1, 'ssm_nonce' => $nonce];
    $m->getContent();
    same(Configuration::get('SSM_SEND_EVENTS'), 1, 'case cochée : activé');
    // décochée
    $m = fresh();
    core_replies(200);
    Tools::$submitted = ['submitSSMConfig'];
    Tools::$values = ['SSM_SSM_URL' => SSM_URL, 'SSM_CONNECTOR_TOKEN' => TOKEN_A, 'ssm_nonce' => $nonce];
    $m->getContent();
    same(Configuration::get('SSM_SEND_EVENTS'), 0, 'case décochée : désactivé');
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

test("la mise à jour 0.4.2 → 0.5.0 préserve la boutique et est idempotente", function () {
    // C'est l'opération la plus risquée pour un marchand : elle tourne sur une boutique en
    // production, avec un token et une fréquence déjà configurés. Elle n'est pas réversible.
    require_once dirname(__DIR__) . '/upgrade/upgrade-0.5.0.php';
    $m = fresh();
    // état d'une boutique déjà en 0.4.2
    Configuration::updateValue('SSM_SSM_URL', 'https://ssm.exemple.fr');
    Configuration::updateValue('SSM_CONNECTOR_TOKEN', TOKEN_A);
    Configuration::updateValue('SSM_HEARTBEAT_INTERVAL', 3600);
    Configuration::updateValue('SSM_AUTO_HEARTBEAT', 1);
    Configuration::updateValue('SSM_SEND_EVENTS', 0);
    Configuration::updateValue('SSM_PENDING_EVENTS', json_encode([
        ['type' => 'customer_login', 'payload' => ['id_customer' => 7], 'timestamp' => '2026-10-05T10:00:00+02:00'],
    ]));

    for ($passe = 1; $passe <= 2; $passe++) {
        check(upgrade_module_0_5_0($m), "passe $passe : la mise à jour réussit");
        same(Configuration::get('SSM_SSM_URL'), 'https://ssm.exemple.fr', "passe $passe : adresse conservée");
        same(Configuration::get('SSM_CONNECTOR_TOKEN'), TOKEN_A, "passe $passe : token conservé");
        same((int) Configuration::get('SSM_HEARTBEAT_INTERVAL'), 3600, "passe $passe : fréquence conservée");
        same((int) Configuration::get('SSM_AUTO_HEARTBEAT'), 1, "passe $passe : envoi automatique conservé");
        same((int) Configuration::get('SSM_SEND_EVENTS'), 0, "passe $passe : événements désactivés");
        same(json_decode((string) Configuration::get('SSM_PENDING_EVENTS'), true), [], "passe $passe : file d'événements vidée");
        same((int) Configuration::get('SSM_SITE_ID'), 0, "passe $passe : site_id initialisé");
    }
});

test("l'installation ne réécrit pas une configuration existante", function () {
    $m = fresh();
    Configuration::updateValue('SSM_SSM_URL', 'https://ssm.exemple.fr');
    Configuration::updateValue('SSM_CONNECTOR_TOKEN', TOKEN_A);
    Configuration::updateValue('SSM_HEARTBEAT_INTERVAL', 3600);
    $m->setupDefaults();
    same(Configuration::get('SSM_SSM_URL'), 'https://ssm.exemple.fr', 'adresse conservée');
    same(Configuration::get('SSM_CONNECTOR_TOKEN'), TOKEN_A, 'token conservé');
    same((int) Configuration::get('SSM_HEARTBEAT_INTERVAL'), 3600, 'fréquence conservée');
});

test("décocher les événements vide la file et arrête tout envoi", function () {
    // Régression : le drapeau ne governsait que la mise en file, pas l'envoi — une file déjà
    // pleine continuait de partir, identifiants de personnes compris.
    $m = freshWithEvents();
    $m->hookActionValidateOrder(['order' => (object) ['id' => 4242]]);
    same(count(json_decode((string) Configuration::get('SSM_PENDING_EVENTS'), true)), 1, 'file pleine');

    // décoché par le formulaire
    core_replies(200);
    Tools::$submitted = ['submitSSMConfig'];
    Tools::$values = ['SSM_SSM_URL' => SSM_URL, 'SSM_CONNECTOR_TOKEN' => TOKEN_A, 'ssm_nonce' => priv($m, 'nonce')];
    $m->getContent();
    same((int) Configuration::get('SSM_SEND_EVENTS'), 0, 'désactivé');
    same(json_decode((string) Configuration::get('SSM_PENDING_EVENTS'), true), [], 'file vidée au décochement');

    // et même en forçant l'envoi : plus rien ne part
    configure($m);
    core_replies(200);
    check(priv($m, 'sendHeartbeat')['ok'], 'envoi accepté');
    $body = json_decode(core_requests()[0]['body'], true);
    check(!array_key_exists('pending_events', $body), 'aucun événement sur le réseau');
    check(strpos(core_requests()[0]['body'], '4242') === false, 'aucun identifiant de commande transmis');
});

test("une file non vide n'est jamais envoyée quand les événements sont éteints", function () {
    // Défense en profondeur : décocher vide la file, mais une file peut être pleine pendant que
    // le drapeau est éteint (reglage modifié hors du formulaire, boutique reprise, etc.).
    // Seule la condition à l'envoi garantit qu'aucun identifiant ne part dans ce cas.
    $m = fresh();
    Configuration::updateValue('SSM_SEND_EVENTS', 0);
    Configuration::updateValue('SSM_PENDING_EVENTS', json_encode([
        ['type' => 'order_created', 'payload' => ['id_order' => 4242], 'timestamp' => '2026-10-05T10:00:00+02:00'],
    ]));
    configure($m);
    core_replies(200);
    check(priv($m, 'sendHeartbeat')['ok'], 'envoi accepté');
    $raw = core_requests()[0]['body'];
    check(strpos($raw, 'pending_events') === false, 'aucun pending_events dans le corps');
    check(strpos($raw, '4242') === false, 'aucun identifiant de commande transmis');
    // ...et il repartirait à chaque nouvel envoi. On n'appelle PAS core_replies() ici : cette
    // fonction purge le journal, et core_requests()[1] n'existerait pas — l'assertion passerait
    // sans rien vérifier. Le statut 200 est déjà sur le disque, les requêtes s'accumulent.
    check(priv($m, 'sendHeartbeat')['ok'], 'deuxième envoi accepté');
    $reqs = core_requests();
    same(count($reqs), 2, 'deux requêtes reçues par le faux SSM');
    check(strpos($reqs[1]['body'], '4242') === false, 'toujours aucun identifiant au deuxième envoi');
    same(count(json_decode((string) Configuration::get('SSM_PENDING_EVENTS'), true)), 1, 'file conservée pour un éventuel envoi ultérieur');
});

test("un message d'erreur distant ne peut pas injecter de balisage", function () {
    // Le détail d'un 422 vient du serveur SSM, pas de la boutique, et displayError() rend du
    // HTML : une instance SSM hostile ne doit pas pouvoir exécuter de script dans le back-office.
    $m = fresh();
    $hint = $m->describeFailure(422, 0, json_encode(['detail' => [[
        'loc' => ['body', 'x'],
        'msg' => '<script>alert(1)</script><img src=x onerror=alert(2)>',
    ]]]));
    check(strpos($hint, '<script') === false, 'pas de balise script');
    check(strpos($hint, '<img') === false, 'pas de balise img');
    check(strpos($hint, 'onerror') === false, 'pas de gestionnaire d\'événement');
    check(strpos($hint, '422') !== false, 'le message reste utile');
    // caractères de contrôle : un retour chariot isolé peut réécrire la ligne dans certains
    // terminaux d'administration, et un octet non UTF-8 casse l'affichage de la page
    $hint = $m->describeFailure(422, 0, json_encode(['detail' => [[
        'loc' => ['body', 'x'], 'msg' => "champ\ninattendu\r\0",
    ]]]));
    check(strpos($hint, "\n") === false && strpos($hint, "\r") === false, 'pas de saut de ligne');
    check(strpos($hint, "\0") === false, 'pas d\'octet nul');
});

test("un réglage absent est rapporté « non rapporté », pas « désactivé »", function () {
    // PS_SSL_ENABLED peut ne pas exister sur une installation fraîche : affirming « SSL
    // désactivé » serait une déclaration que personne n'a faite (distinction de la 2.7.0).
    $m = fresh();
    check(!array_key_exists('PS_SSL_ENABLED', Configuration::$v), 'clé absente par défaut');
    same(priv($m, 'collectInventory')['ssl_enabled'], null, 'null = non rapporté');
    Configuration::updateValue('PS_SSL_ENABLED', '1');
    same(priv($m, 'collectInventory')['ssl_enabled'], true, '1 → actif');
    Configuration::updateValue('PS_SSL_ENABLED', '0');
    same(priv($m, 'collectInventory')['ssl_enabled'], false, '0 → désactivé');
});

test("les compteurs module_count et module_active_count décrivent la même liste", function () {
    $m = fresh();
    Db::$modules = [];
    for ($i = 0; $i < 1200; $i++) {
        Db::$modules[] = ['name' => "mod$i", 'version' => '1.0', 'active' => '1'];
    }
    $inv = priv($m, 'collectInventory');
    same($inv['module_count'], 1000, 'plafond appliqué');
    check($inv['module_active_count'] <= $inv['module_count'], 'actifs ≤ total, même au-delà du plafond');
});


// === Mises à jour demandées par SSM Core ===

test("mise a jour : rien n'est exécuté sans commande", function () {
    $m = fresh();
    same(priv($m, 'collectInventory')['results'], [], 'aucun compte rendu au repos');
    same(Module::$upgraded, [], "aucun module touché sans commande");
});

test("mise a jour : un compte rendu part, puis il ne repart plus", function () {
    $m = fresh();
    $m->queueResult(7, 'success', null, '2.0.0');
    same(priv($m, 'collectInventory')['results'], [['update_id' => 7, 'status' => 'success', 'version' => '2.0.0']], 'compte rendu transmis');
    same(priv($m, 'collectInventory')['results'], [], "un compte rendu ne repart pas deux fois");
});

test("mise a jour : le compte rendu ne contient aucune donnée personnelle", function () {
    $m = fresh();
    $m->queueResult(7, 'failed', 'permission refusée sur modules/ps_emailsubscription');
    $json = json_encode(priv($m, 'collectInventory')['results']);
    foreach (['customer', 'panier', 'commande', 'password', 'token', 'cookie'] as $interdit) {
        check(stripos($json, $interdit) === false, "pas de « $interdit » dans un compte rendu");
    }
});

test("mise a jour : un nom de module qui sort du dossier est refusé", function () {
    $m = fresh();
    $m->applyCommands([['id' => 1, 'kind' => 'update_extension', 'slug' => '../../app/config', 'to_version' => '2.0']]);
    $r = priv($m, 'takePendingResults')[0];
    same($r['status'], 'failed', 'nom hostile refusé');
    check(strpos($r['error'], 'refusé') !== false, 'la raison est explicite');
});

test("mise a jour : une commande inconnue est refusée", function () {
    $m = fresh();
    $m->applyCommands([['id' => 2, 'kind' => 'drop_table', 'slug' => 'ps_emailsubscription', 'to_version' => '2.0']]);
    $r = priv($m, 'takePendingResults')[0];
    same($r['status'], 'failed', 'commande inconnue refusée');
});

test("mise a jour : un module absent est signalé, pas deviné", function () {
    $m = fresh();
    $m->applyCommands([['id' => 3, 'kind' => 'update_extension', 'slug' => 'module_inexistant', 'to_version' => '2.0']]);
    $r = priv($m, 'takePendingResults')[0];
    same($r['status'], 'failed', 'module absent');
    check(stripos($r['error'], 'introuvable') !== false, 'la raison dit ce qui manque');
});

test("mise a jour : déjà à jour, on ne dégrade rien", function () {
    $m = fresh();
    $m->applyCommands([['id' => 4, 'kind' => 'update_extension', 'slug' => 'ps_emailsubscription', 'to_version' => '1.0.0']]);
    $r = priv($m, 'takePendingResults')[0];
    same($r['status'], 'success', 'déjà à jour');
    same(Module::$upgraded, [], "le module n'a pas été touché");
});

test("mise a jour : sauvegarde avant modification, restauration si ça échoue", function () {
    $m = fresh();
    Module::$upgrade_ok = false;
    $m->applyCommands([['id' => 5, 'kind' => 'update_extension', 'slug' => 'ps_emailsubscription', 'to_version' => '9.9.9']]);
    $sauvegardes = glob(_PS_ROOT_DIR_ . '/' . SsmConnector::BACKUP_DIR . '/ps_emailsubscription-*', GLOB_ONLYDIR);
    check(count($sauvegardes) >= 1, 'une sauvegarde existe avant toute modification');
    $r = priv($m, 'takePendingResults')[0];
    same($r['status'], 'failed', "l'échec est rapporté, pas masqué");
    check(stripos($r['error'], 'remis') !== false || stripos($r['error'], 'restauration') !== false,
        "le compte rendu dit ce qu'est devenu le module");
    Module::$upgrade_ok = true;
});

test("mise a jour : sans sauvegarde possible, on n'essaie pas", function () {
    $m = fresh();
    SsmConnector::$disk_check = function ($path) { return false; };
    $m->applyCommands([['id' => 6, 'kind' => 'update_extension', 'slug' => 'ps_emailsubscription', 'to_version' => '9.9.9']]);
    $r = priv($m, 'takePendingResults')[0];
    same($r['status'], 'failed', 'refus sans sauvegarde');
    check(stripos($r['error'], 'annul') !== false, 'la mise à jour est explicitement annulée');
    same(Module::$upgraded, [], "l'upgrade n'a pas été appelé");
    SsmConnector::$disk_check = null;
});

test("mise a jour : la version installée est relue après coup", function () {
    // Se fier au retour d'upgrade() ferait croire à une réussite même si rien n'a bougé sur le
    // disque : c'est ce que voit le test en simulant un upgrade() qui ne change rien.
    $m = fresh();
    Module::$upgrade_ok = true;
    $m->applyCommands([['id' => 7, 'kind' => 'update_extension', 'slug' => 'ps_emailsubscription', 'to_version' => '9.9.9']]);
    $r = priv($m, 'takePendingResults')[0];
    // ps_emailsubscription est en 3.0.0 ; upgrade() le passe à 3.1, la cible 9.9.9 n'est pas
    // atteinte. Dire « appliquée » quand la version demandée n'est pas là serait un mensonge.
    same($r['status'], 'failed', 'cible non atteinte, donc échec');
    check(stripos($r['error'], '9.9.9') !== false, 'la raison cite la version attendue');
});

test("mise a jour : cible atteinte, le compte rendu est positif", function () {
    $m = fresh();
    $m->applyCommands([['id' => 8, 'kind' => 'update_extension', 'slug' => 'ps_emailsubscription', 'to_version' => '3.1']]);
    $r = priv($m, 'takePendingResults')[0];
    same($r['status'], 'success', 'mise à jour effective');
    same($r['version'], '3.1', 'la version réellement installée est rapportée');
    same(Module::$upgraded, ['ps_emailsubscription'], "le module visé est bien celui qui a été mis à jour");
});

test("mise a jour : les sauvegardes ne s'accumulent pas", function () {
    for ($i = 0; $i < 6; $i++) {
        $m = fresh();
        Module::$upgrade_ok = false;
        $m->applyCommands([['id' => 100 + $i, 'kind' => 'update_extension', 'slug' => 'ps_emailsubscription', 'to_version' => '9.0.' . $i]]);
    }
    Module::$upgrade_ok = true;
    $garde = glob(_PS_ROOT_DIR_ . '/' . SsmConnector::BACKUP_DIR . '/ps_emailsubscription-*', GLOB_ONLYDIR);
    check(count($garde) <= SsmConnector::BACKUPS_KEPT + 1, 'les anciennes sauvegardes sont purgées ('
        . count($garde) . ' conservées)');
});

// --- 0.7.0 : contrat 3 (capacités, actions, erreurs PHP, sauvegarde) ---

test("contrat 3 : capacités annoncées, pas de connexion directe", function () {
    $inv = priv(fresh(), 'collectInventory');
    foreach (['plugin_activate', 'plugin_deactivate', 'php_errors'] as $c) {
        check(in_array($c, $inv['capabilities'], true), "capacité $c");
    }
    check(!in_array('update_extension', $inv['capabilities'], true), "pas de mise à jour annoncée : Module::upgrade() n'existe pas dans PrestaShop");
    same($inv['login_enabled'], false, 'pas de connexion directe pour PrestaShop');
    same($inv['command_results'], [], 'aucun compte rendu au départ');
});

test("actions : désactiver puis réactiver un module, refus pour le connecteur et pour l'inconnu", function () {
    $m = fresh();
    $m->applyCommands([
        ['id' => 1, 'ref' => 'command', 'kind' => 'plugin_deactivate', 'slug' => 'blockreassurance'],
        ['id' => 2, 'ref' => 'command', 'kind' => 'plugin_activate', 'slug' => 'ps_legacy_demo'],
        ['id' => 3, 'ref' => 'command', 'kind' => 'plugin_deactivate', 'slug' => 'ssmconnector'],
        ['id' => 4, 'ref' => 'command', 'kind' => 'plugin_deactivate', 'slug' => '../x'],
        ['id' => 5, 'ref' => 'command', 'kind' => 'plugin_install', 'slug' => 'x'],
    ]);
    $r = $m->takeCommandResults();
    same(array_column($r, 'status'), ['success', 'success', 'failed', 'failed', 'failed'], 'statuts');
    same(Module::$by_name['blockreassurance']->active, false, 'module désactivé');
    same(priv($m, 'takePendingResults'), [], "aucun compte rendu pris pour une mise à jour");
});

test("erreurs PHP : journal lu par morceaux, chemins relatifs", function () {
    $m = fresh();
    $log = _PS_ROOT_DIR_ . '/php.log';
    file_put_contents($log, "[08-Oct-2026 10:00:00 UTC] PHP Fatal error:  Uncaught Error: x() in " . _PS_ROOT_DIR_ . "modules/a/a.php:9\nStack trace:\n");
    ini_set('error_log', $log);
    Configuration::updateValue('SSM_LOG_OFFSET', 0);
    $e = $m->takePhpErrors();
    same([$e[0]['level'], $e[0]['message'], $e[0]['file'], $e[0]['line']], ['fatal', 'Uncaught Error: x()', 'modules/a/a.php', 9], 'erreur lue');
    same($m->takePhpErrors(), [], 'rien de neuf ensuite');
    ini_restore('error_log');
});

test("sauvegarde : base et fichiers dans l'archive, caches exclus, dossier de travail nettoyé", function () {
    $m = fresh();
    @mkdir(_PS_ROOT_DIR_ . '/var/cache/prod', 0755, true);
    file_put_contents(_PS_ROOT_DIR_ . '/var/cache/prod/x.php', 'cache');
    @mkdir(_PS_ROOT_DIR_ . '/img/p', 0755, true);
    file_put_contents(_PS_ROOT_DIR_ . '/img/p/1.jpg', 'jpeg');
    $vu = [];
    SsmConnector::$uploader = function ($url, $path, $size) use (&$vu) {
        $z = new ZipArchive();
        $z->open($path);
        for ($i = 0; $i < $z->numFiles; $i++) {
            $vu['names'][] = $z->getNameIndex($i);
        }
        $vu['sql'] = $z->getFromName('database.sql');
        $z->close();
        return ['ok' => true, 'error' => null];
    };
    $m->applyCommands([['id' => 9, 'ref' => 'command', 'kind' => 'backup_site',
        'params' => ['upload_url' => 'https://s3.example/k.zip?sig=x', 'include_uploads' => false]]]);
    $r = $m->takeCommandResults()[0];
    same($r['status'], 'success', 'sauvegarde réussie');
    same($r['data']['tables'], 2, 'deux tables');
    check(in_array('boutique/modules/blockreassurance/config.xml', $vu['names'], true), 'fichiers de la boutique');
    check(!in_array('boutique/var/cache/prod/x.php', $vu['names'], true), 'cache exclu');
    check(!in_array('boutique/img/p/1.jpg', $vu['names'], true), 'images exclues sur demande');
    check(strpos($vu['sql'], "'o\\'brien@exemple.fr'") !== false && strpos($vu['sql'], 'NULL') !== false, 'SQL échappé');
    check(!is_dir(_PS_ROOT_DIR_ . '/' . SsmConnector::BACKUP_TMP), 'dossier de travail supprimé');
    SsmConnector::$uploader = function () { return ['ok' => false, 'error' => 'HTTP 403']; };
    $m->applyCommands([['id' => 10, 'ref' => 'command', 'kind' => 'backup_site', 'params' => ['upload_url' => 'https://s3.example/k.zip']]]);
    same($m->takeCommandResults()[0]['status'], 'failed', 'dépôt refusé = échec');
    SsmConnector::$uploader = null;
});

test("script de mise à niveau 0.6.0 et 0.7.0 : fonctions appelées par PrestaShop", function () {
    require_once dirname(__DIR__) . '/upgrade/upgrade-0.6.0.php';
    require_once dirname(__DIR__) . '/upgrade/upgrade-0.7.0.php';
    $m = fresh();
    check(function_exists('upgrade_module_0_6_0') && upgrade_module_0_6_0($m), 'upgrade_module_0_6_0');
    check(function_exists('upgrade_module_0_7_0') && upgrade_module_0_7_0($m), 'upgrade_module_0_7_0');
});

echo "\n$checks vérifications, $failures échec(s)\n";
exit($failures === 0 ? 0 : 1);
