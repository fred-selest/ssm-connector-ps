<?php
// Faux PrestaShop minimal (Configuration, Db, Tools, Module…) : de quoi charger le module et exécuter son inventaire,
// sa page de configuration et son transport sans PrestaShop. Partagé par tests/run.php et tests/preview.php.

define('_PS_VERSION_', '8.1.5');
define('_DB_PREFIX_', 'ps_');
define('_PS_MODE_DEV_', false);
define('_PS_ROOT_DIR_', sys_get_temp_dir() . '/ssm-fake-ps/');
define('_PS_MODULE_DIR_', _PS_ROOT_DIR_ . '/modules');
define('_COOKIE_KEY_', 'cle-de-test');

class Configuration
{
    public static $v = [];
    public static function get($k) { return array_key_exists($k, self::$v) ? self::$v[$k] : false; }
    public static function updateValue($k, $val) { self::$v[$k] = $val; return true; }
    public static function deleteByName($k) { unset(self::$v[$k]); return true; }
}

class Db
{
    public static $modules = [];
    public static $counts = null;   // valeurs renvoyées par getValue(), dans l'ordre des COUNT(*) testés
    private $count_index = 0;
    public static function getInstance() { return new self(); }
    public function executeS($sql) { return self::$modules; }
    public function getValue($sql)
    {
        if (self::$counts === null) {
            return '12';
        }
        $v = self::$counts[$this->count_index] ?? 0;
        $this->count_index++;
        return $v;
    }
    public function getVersion() { return '8.0.36'; }
}

class Shop { public static function isFeatureActive() { return false; } }

class Tools
{
    public static $values = [];
    public static $submitted = [];
    public static $shop_domain = 'https://boutique.exemple.fr';
    public static function getShopDomain($ssl = false) { return self::$shop_domain; }
    public static function substr($s, $a, $b) { return substr($s, $a, $b); }
    public static function getValue($k) { return isset(self::$values[$k]) ? self::$values[$k] : null; }
    public static function isSubmit($k) { return in_array($k, self::$submitted, true); }
    public static function getRemoteAddr() { return '203.0.113.9'; }
}

class Theme
{
    public $directory = 'classic';
    public $name = 'classic';
    public static $list = [];
    public static function getThemes() { return self::$list; }
    public function getVersion() { return '1.7.2'; }
}

class FakeLink { public function getModuleLink($module, $controller) { return 'https://boutique.exemple.fr/module/' . $module . '/' . $controller; } }

class Module
{
    public $name; public $tab; public $version; public $author; public $need_instance; public $ps_versions_compliancy; public $bootstrap;
    public $displayName; public $description; public $context;
    public static $hooks = [];          // crochets attachés
    public static $unregistered = [];   // crochets détachés
    public static $by_name = [];        // modules instanciés, par nom
    public static $upgraded = [];       // modules dont upgrade() a été appelé
    public static $upgrade_ok = true;   // upgrade() réussit-il ?
    public static function getInstanceByName($name)
    {
        return isset(self::$by_name[$name]) ? self::$by_name[$name] : null;
    }
    public function upgrade()
    {
        self::$upgraded[] = $this->name;
        if (!self::$upgrade_ok) {
            throw new RuntimeException('échec simulé de mise à jour du module.');
        }
        $this->version = (string) (floatval($this->version) + 0.1);
        return true;
    }
    public function clearCache() {}
    public function __construct()
    {
        $this->context = new stdClass();
        $this->context->shop = (object) ['theme_name' => 'classic'];
        $this->context->employee = (object) ['id' => 7];
        $this->context->link = new FakeLink();
    }
    public function l($s) { return $s; }
    public function install() { return true; }
    public function uninstall() { return true; }
    public function registerHook($h) { self::$hooks[$h] = true; return true; }
    public function unregisterHook($h) { unset(self::$hooks[$h]); self::$unregistered[] = $h; return true; }
    public function isRegisteredInHook($h) { return in_array($h, ['actionCustomerLoginBefore', 'actionOrderCreated'], true); }
    public function displayError($m) { return '[ERREUR] ' . $m; }
    public function displayConfirmation($m) { return '[OK] ' . $m; }
}

require dirname(__DIR__) . '/ssmconnector.php';

/**
 * (Re)crée un arbre de modules minimal sur le disque : la sauvegarde et la restauration ont de
 * vrais dossiers à copier. Le contenu importe peu ; le fait qu'un dossier existe, beaucoup.
 */
function ssm_reset_modules()
{
    ssm_rmtree(_PS_ROOT_DIR_);
    mkdir(_PS_MODULE_DIR_, 0755, true);
    mkdir(_PS_ROOT_DIR_ . '/' . SsmConnector::BACKUP_DIR, 0755, true);
    foreach (['ps_emailsubscription', 'blockreassurance', 'ps_legacy_demo'] as $slug) {
        mkdir(_PS_MODULE_DIR_ . '/' . $slug, 0755, true);
        file_put_contents(_PS_MODULE_DIR_ . '/' . $slug . '/' . $slug . '.php', "<?php\n");
        file_put_contents(_PS_MODULE_DIR_ . '/' . $slug . '/config.xml',
            "<?xml version=\"1.0\"?><module><version>1.0.0</version></module>\n");
    }
}

function ssm_rmtree($dir)
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        is_dir($path) ? ssm_rmtree($path) : @unlink($path);
    }
    @rmdir($dir);
}
