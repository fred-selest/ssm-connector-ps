<?php
// Faux PrestaShop minimal (Configuration, Db, Tools, Module…) : de quoi charger le module et exécuter son inventaire,
// sa page de configuration et son transport sans PrestaShop. Partagé par tests/run.php et tests/preview.php.

define('_PS_VERSION_', '8.1.5');
define('_DB_PREFIX_', 'ps_');
define('_PS_MODE_DEV_', false);
define('_PS_ROOT_DIR_', '/var/www/html');
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
