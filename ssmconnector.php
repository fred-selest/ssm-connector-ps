<?php
/**
 * SSM Connector — module natif PrestaShop 8/9
 *
 * @author  Selest Informatique
 * @license MIT
 * @version 0.8.1
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class Ssmconnector extends Module
{
    const MAX_PENDING_EVENTS = 100;
    const MIN_INTERVAL = 300;
    const MAX_INTERVAL = 86400;
    const DEFAULT_INTERVAL = 1800;
    const LOCK_TTL = 120;
    const CRON_MAX_FAILURES = 10;
    const CRON_WINDOW = 900;
    const TOKEN_MIN = 32;           // minimum exigé par SSM Core
    const TOKEN_MAX = 256;
    const AUTO_TIMEOUT = 5;         // secondes : envoi automatique (ne doit jamais retenir longtemps une page)
    const EVENT_THROTTLE = 60;      // secondes : un même événement n'est pas répété plus vite
    const MAX_COUNTER = 2000000000; // plafond des compteurs métier (app/schemas.py, SiteStats)
    const URL_MAX = 255;            // longueur maximale de shop_url acceptée par SSM Core
    const UPDATE_REPO = 'fred-selest/ssm-connector-ps';
    const BACKUP_DIR = 'ssm-backups';     // sauvegardes des modules, à la racine de la boutique
    const BACKUPS_KEPT = 3;               // combien de sauvegardes garder par module

    /**
     * Contrôle d'écriture du disque, remplaçable. `is_writable` est un builtin : sous root il
     * renvoie toujours vrai, et les tests ne peuvent donc pas simuler un répertoire non
     * inscriptible sans cette couture.
     */
    public static $disk_check = null;
    /** Coutures de test : dépôt d'une archive de sauvegarde. */
    public static $uploader = null;
    /** Connexion directe forcée par les tests (null : lire la constante SSM_CONNECTOR_ALLOW_LOGIN). */
    public static $login_allowed = null;
    private static $shutdown_registered = false;
    const UPDATE_TTL = 43200;
    const BACKUP_TMP = 'ssm-backup-tmp';
    const PHP_ERRORS_MAX = 100;
    const LOG_READ_MAX = 524288;
    const LOGIN_LOG_MAX = 20;
    const LOGIN_MAX_AHEAD = 120;    // secondes : un lien expirant plus loin que cela n'a pas été fait par SSM
    const ENC_PREFIX = 'enc1:';

    public function __construct()
    {
        $this->name = 'ssmconnector';
        $this->tab = 'administration';
        $this->version = '0.8.1';
        $this->author = 'Selest Informatique';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;
        parent::__construct();
        $this->displayName = $this->l('SSM Connector');
        $this->description = $this->l('Connecteur SSM (Selest Site Manager) : envoie l\'inventaire de la boutique à SSM Core et exécute ce que SSM demande.');
        // Erreurs fatales : relevées en fin de requête, une seule fois par processus.
        if (!self::$shutdown_registered) {
            self::$shutdown_registered = true;
            register_shutdown_function([$this, 'captureFatal']);
        }
    }

    /** Ce que ce module sait exécuter : SSM n'envoie rien d'autre (contrat 3 de SSM Core 2.13). */
    public function capabilities()
    {
        // `update_extension` n'est PAS annoncé : la mise à jour passe par $module->upgrade(), qui
        // n'existe pas dans la classe Module de PrestaShop (seulement dans le faux module des
        // tests) ; elle échouerait toujours. SSM n'enverra donc plus de mise à jour de module
        // tant qu'un téléchargement de la nouvelle version (Addons) n'est pas écrit.
        $caps = ['plugin_activate', 'plugin_deactivate', 'php_errors'];
        if (class_exists('ZipArchive') && function_exists('curl_init')) {
            $caps[] = 'backup_site';
        }
        if (self::loginAllowed()) {
            $caps[] = 'login';
        }
        return $caps;
    }

    /**
     * Connexion directe au back-office depuis SSM : fermée par défaut. Un super-administrateur l'ouvre
     * dans la page du module (case à cocher), jamais SSM à distance. La constante
     * SSM_CONNECTOR_ALLOW_LOGIN (config/defines_custom.inc.php) l'emporte : true l'ouvre, false la
     * verrouille fermée, quoi qu'indique la case.
     */
    public static function loginAllowed()
    {
        if (self::$login_allowed !== null) {
            return (bool) self::$login_allowed;
        }
        if (self::loginLockedByConstant()) {
            return (bool) SSM_CONNECTOR_ALLOW_LOGIN;
        }
        return (bool) Configuration::get('SSM_LOGIN_ALLOWED');
    }

    public static function loginLockedByConstant()
    {
        return defined('SSM_CONNECTOR_ALLOW_LOGIN');
    }

    public function install()
    {
        return parent::install()
            && $this->setupDefaults();
    }

    public function uninstall()
    {
        foreach ([
            'SSM_SSM_URL', 'SSM_HEARTBEAT_INTERVAL', 'SSM_AUTO_HEARTBEAT', 'SSM_LAST_HEARTBEAT_AT',
            'SSM_HEARTBEAT_OK', 'SSM_LAST_ERROR', 'SSM_HEARTBEAT_LOCK', 'SSM_CONNECTOR_TOKEN',
            'SSM_PENDING_EVENTS', 'SSM_SEND_EVENTS', 'SSM_UPDATE_CACHE', 'SSM_CRON_FAILS', 'SSM_SITE_ID',
            'SSM_UPDATE_RESULTS', 'SSM_COMMAND_RESULTS', 'SSM_PHP_ERRORS', 'SSM_LOG_OFFSET',
        ] as $key) {
            Configuration::deleteByName($key);
        }
        return parent::uninstall();
    }

    /**
     * Valeurs par défaut + hooks. Idempotent : utilisé à l'installation
     * et par les scripts de mise à jour (upgrade/).
     */
    public function setupDefaults()
    {
        $defaults = [
            'SSM_SSM_URL' => '',
            'SSM_HEARTBEAT_INTERVAL' => self::DEFAULT_INTERVAL,
            'SSM_AUTO_HEARTBEAT' => 1,
            'SSM_LAST_HEARTBEAT_AT' => '',
            'SSM_HEARTBEAT_OK' => 0,
            'SSM_LAST_ERROR' => '',
            'SSM_HEARTBEAT_LOCK' => 0,
            'SSM_CRON_FAILS' => '{}',
            // Les événements sont désactivés par défaut : SSM Core ne les lit pas (app/schemas.py,
            // HeartbeatRequest) et la file contient des identifiants de personnes. Les activer
            // reste possible si SSM Core décide un jour de les consommer.
            'SSM_SEND_EVENTS' => 0,
            'SSM_SITE_ID' => 0,
        ];
        foreach ($defaults as $key => $value) {
            if (Configuration::get($key) === false) {
                Configuration::updateValue($key, $value);
            }
        }

        // Réparation d'une file d'événements invalide
        if (!is_array(json_decode((string) Configuration::get('SSM_PENDING_EVENTS'), true))) {
            $this->setPendingEvents([]);
        }

        return $this->registerHooks();
    }

    /**
     * Crochets réellement déclenchés par PrestaShop (vérifiés dans le code source du cœur) :
     * actionAuthenticationBefore / actionAuthentication (connexion client), actionValidateOrder (commande),
     * actionProductUpdate, actionModuleInstallAfter / actionModuleUninstallAfter, displayHeader, displayBackOfficeTop.
     * Les anciens noms (actionCustomerLoginBefore/After, actionEmployeeLoginAfter, actionOrderCreated, displayBackOfficeHeader)
     * n'existent pas dans PrestaShop : ils sont détachés.
     */
    private function registerHooks()
    {
        foreach (['displayBackOfficeHeader', 'actionCustomerLoginBefore', 'actionCustomerLoginAfter', 'actionEmployeeLoginAfter', 'actionOrderCreated'] as $obsolete) {
            if ($this->isRegisteredInHook($obsolete)) {
                $this->unregisterHook($obsolete);
            }
        }

        return $this->registerHook('actionAuthenticationBefore')
            && $this->registerHook('actionAuthentication')
            && $this->registerHook('actionValidateOrder')
            && $this->registerHook('actionProductUpdate')
            && $this->registerHook('actionModuleInstallAfter')
            && $this->registerHook('actionModuleUninstallAfter')
            && $this->registerHook('displayBackOfficeTop')
            && $this->registerHook('displayHeader');
    }

    // === Hooks PrestaShop ===

    public function hookDisplayBackOfficeTop($params)
    {
        $this->rememberAdminDir();
        $this->maybeRunDueHeartbeat(false);

        $ok = (bool) Configuration::get('SSM_HEARTBEAT_OK');
        $last = (string) Configuration::get('SSM_LAST_HEARTBEAT_AT');
        $color = $ok ? '#22c55e' : '#ef4444';
        $text = $ok ? 'SSM: OK' : 'SSM: KO';
        if ($last) {
            $text .= ' (' . Tools::substr($last, 11, 8) . ')';
        }
        return '<span class="ssm-status-badge" style="background:' . $color . ';color:white;padding:0.25rem 0.5rem;border-radius:4px;font-size:0.75rem;font-weight:600;">' . $this->h($text) . '</span>';
    }

    /** Boutique : ne produit aucun affichage, sert uniquement de déclencheur à l'envoi automatique. */
    public function hookDisplayHeader($params)
    {
        $this->maybeRunDueHeartbeat(true);
        return '';
    }

    /** Connexion client réussie (le cœur fournit le client dans $params['customer']). */
    public function hookActionAuthentication($params)
    {
        $this->queueEvent('customer_login', ['id_customer' => isset($params['customer']->id) ? (int) $params['customer']->id : null]);
    }

    /** Tentative de connexion client : aucun paramètre fourni par le cœur, donc aucune donnée personnelle. */
    public function hookActionAuthenticationBefore($params)
    {
        $this->queueEvent('customer_login_attempt', [], true);
    }

    public function hookActionValidateOrder($params)
    {
        $this->queueEvent('order_created', ['id_order' => isset($params['order']->id) ? (int) $params['order']->id : null]);
    }

    public function hookActionProductUpdate($params)
    {
        $this->queueEvent('product_update', ['id_product' => isset($params['product']->id) ? (int) $params['product']->id : null]);
    }

    public function hookActionModuleInstallAfter($params)
    {
        $this->queueEvent('module_install', ['module' => isset($params['module']->name) ? $params['module']->name : null]);
    }

    public function hookActionModuleUninstallAfter($params)
    {
        $this->queueEvent('module_uninstall', ['module' => isset($params['module']->name) ? $params['module']->name : null]);
    }

    // === Configuration page (back-office) ===

    public function getContent()
    {
        $output = $this->processForms();
        $update = $this->getUpdateInfo();

        $url = (string) Configuration::get('SSM_SSM_URL');
        $interval = (int) Configuration::get('SSM_HEARTBEAT_INTERVAL');
        $auto = (bool) Configuration::get('SSM_AUTO_HEARTBEAT');
        $token = (string) Configuration::get('SSM_CONNECTOR_TOKEN');
        $last = (string) Configuration::get('SSM_LAST_HEARTBEAT_AT');
        $ok = (bool) Configuration::get('SSM_HEARTBEAT_OK');
        $error = (string) Configuration::get('SSM_LAST_ERROR');
        $nonce = '<input type="hidden" name="ssm_nonce" value="' . $this->h($this->nonce()) . '">';

        $output .= $this->renderStatus($url, $token, $ok, $last, $error, $auto, $update);

        // Étapes 1 à 3 : un seul formulaire. Le token n'est jamais réécrit dans la page (4 derniers caractères seulement).
        $output .= '<div class="panel"><div class="panel-heading"><i class="icon-cogs"></i> ' . $this->h($this->l('Configuration')) . '</div>';
        $output .= '<form method="post">' . $nonce;
        $output .= '<div class="form-group"><label><strong>1.</strong> ' . $this->h($this->l('Adresse de SSM Core')) . '</label>'
            . '<input type="text" name="SSM_SSM_URL" value="' . $this->h($url) . '" class="form-control" placeholder="https://ssm.example.com" autocomplete="off">'
            . '<p class="help-block">' . $this->h($this->l('Collez l\'adresse de SSM Core (copiée depuis votre navigateur, seule la partie https://nom-de-domaine compte). Le HTTPS est obligatoire.')) . '</p></div>';

        $placeholder = $token !== ''
            ? '•••• ' . substr($token, -4) . ' — ' . $this->l('laisser vide pour conserver')
            : $this->l('collez le token ici');
        $output .= '<div class="form-group"><label><strong>2.</strong> ' . $this->h($this->l('Token de cette boutique')) . '</label>'
            . '<input type="password" name="SSM_CONNECTOR_TOKEN" value="" class="form-control" placeholder="' . $this->h($placeholder) . '" autocomplete="new-password" spellcheck="false">'
            . '<p class="help-block">' . $this->h($this->l('Dans SSM Core : Sites, bouton 🔌 de la boutique, puis copiez le token (il n\'est affiché qu\'une fois). Un nouveau token remplace l\'ancien.')) . '</p></div>';

        $output .= '<div class="form-group"><label><strong>3.</strong> ' . $this->h($this->l('Fréquence d\'envoi')) . '</label>'
            . '<select name="SSM_HEARTBEAT_INTERVAL" class="form-control">';
        foreach ($this->intervalChoices($interval) as $seconds => $label) {
            $output .= '<option value="' . (int) $seconds . '"' . ($seconds === $interval ? ' selected' : '') . '>' . $this->h($label) . '</option>';
        }
        $output .= '</select></div>';

        $output .= '<div class="checkbox"><label><input type="checkbox" name="SSM_AUTO_HEARTBEAT" value="1"' . ($auto ? ' checked' : '') . '> '
            . $this->h($this->l('Envoi automatique (recommandé) : aucune tâche cron à configurer'))
            . '</label></div>';

        $events = (bool) Configuration::get('SSM_SEND_EVENTS');
        $output .= '<div class="checkbox"><label><input type="checkbox" name="SSM_SEND_EVENTS" value="1"' . ($events ? ' checked' : '') . '> '
            . $this->h($this->l('Envoyer les événements (connexions, commandes, modules)'))
            . '</label></div>';
        $output .= '<p class="help-block">' . $this->h($this->l('Désactivé par défaut : SSM Core ne les exploite pas encore, et ces événements contiennent des identifiants de personnes. À n\'activer que si vous savez qu\'ils sont utilisés.')) . '</p>';

        $output .= '<button type="submit" name="submitSSMConfig" class="btn btn-primary">Enregistrer et tester la connexion</button> ';
        $output .= '<button type="submit" name="submitSSMHeartbeatNow" class="btn btn-default">Tester maintenant</button> ';
        $output .= '<button type="submit" name="submitSSMCheckUpdate" class="btn btn-default">Vérifier les mises à jour</button>';
        $output .= '</form></div>';

        $output .= $this->renderLoginPanel($nonce);
        $output .= $this->renderSecurityPanel();
        $output .= $this->renderScripts();

        return $output;
    }

    private function processForms()
    {
        $actions = ['submitSSMConfig', 'submitSSMHeartbeatNow', 'submitSSMCheckUpdate', 'submitSSMLogin'];
        $submitted = null;
        foreach ($actions as $action) {
            if (Tools::isSubmit($action)) {
                $submitted = $action;
                break;
            }
        }
        if ($submitted === null) {
            return '';
        }

        if (!$this->isValidNonce((string) Tools::getValue('ssm_nonce'))) {
            return $this->displayError($this->l('Session expirée : rechargez la page puis recommencez.'));
        }

        switch ($submitted) {
            case 'submitSSMConfig':
                list($url, $error) = $this->normalizeUrl(Tools::getValue('SSM_SSM_URL'));
                if ($url === null) {
                    return $this->displayError($error);
                }
                // Token saisi (celui de SSM Core) : vide = on conserve l'actuel
                $typed = (string) Tools::getValue('SSM_CONNECTOR_TOKEN');
                $token = null;
                if (trim($typed) !== '') {
                    list($token, $error) = $this->normalizeToken($typed);
                    if ($token === null) {
                        return $this->displayError($error);
                    }
                }
                if ($token !== null) {
                    Configuration::updateValue('SSM_CONNECTOR_TOKEN', $token);
                }
                Configuration::updateValue('SSM_SSM_URL', $url);
                Configuration::updateValue('SSM_HEARTBEAT_INTERVAL', $this->clampInterval((int) Tools::getValue('SSM_HEARTBEAT_INTERVAL')));
                Configuration::updateValue('SSM_AUTO_HEARTBEAT', Tools::getValue('SSM_AUTO_HEARTBEAT') ? 1 : 0);
                Configuration::updateValue('SSM_SEND_EVENTS', Tools::getValue('SSM_SEND_EVENTS') ? 1 : 0);
                if (!Configuration::get('SSM_SEND_EVENTS')) {
                    // Décoché : la file ne sera ni alimentée ni envoyée. Elle contient des
                    // identifiants de personnes, on ne la garde pas en base pour rien.
                    $this->setPendingEvents([]);
                }
                return $this->displayConfirmation($this->l('Configuration enregistrée')) . $this->testConnection();

            case 'submitSSMHeartbeatNow':
                return $this->testConnection();

            case 'submitSSMLogin':
                return $this->saveLoginSettings();

            case 'submitSSMCheckUpdate':
                $this->getUpdateInfo(true);
                return '';
        }

        return '';
    }

    private function testConnection()
    {
        $result = $this->sendHeartbeat();
        return $result['ok']
            ? $this->displayConfirmation($this->l('Connexion réussie : SSM Core a bien reçu le heartbeat.'))
            : $this->displayError($result['hint']);
    }

    private function renderStatus($url, $token, $ok, $last, $error, $auto, array $update)
    {
        $configured = $url !== '' && $token !== '';
        if (!$configured) {
            $banner = '<div class="alert alert-info"><strong>Pas encore connecté.</strong> '
                . '1) Dans SSM Core, ouvrez <em>Sites</em>, cliquez sur 🔌 à côté de la boutique et copiez le <strong>token</strong> ; '
                . '2) collez ci-dessous l\'adresse de SSM Core et ce token ; '
                . '3) cliquez sur <strong>Enregistrer et tester la connexion</strong>.</div>';
        } elseif ($ok) {
            $site_id = (int) Configuration::get('SSM_SITE_ID');
            $banner = '<div class="alert alert-success"><strong>Connecté à SSM Core</strong>'
                . ($last ? ' — dernier échange le ' . $this->h($last) : '')
                . ($site_id > 0 ? ' — site <strong>n° ' . $site_id . '</strong>' : '')
                . '</div>';
        } else {
            $banner = '<div class="alert alert-danger"><strong>Non connecté.</strong> '
                . $this->h($error !== '' ? $error : $this->l('Aucun échange réussi pour le moment : cliquez sur « Enregistrer et tester la connexion ».')) . '</div>';
        }
        if ($token !== '' && strlen($token) < self::TOKEN_MIN) {
            $banner .= '<div class="alert alert-warning">Ce token est trop court pour SSM Core (' . (int) self::TOKEN_MIN . ' caractères minimum) : collez celui que SSM Core a généré.</div>';
        }

        if ($update['available']) {
            $version_check = [false, 'Mise à jour disponible'];
        } elseif ($update['latest'] === null) {
            $version_check = [null, 'Version v' . $update['current'] . ' (mises à jour non vérifiées)'];
        } else {
            $version_check = [true, 'Connecteur à jour (v' . $update['current'] . ')'];
        }
        $checks = [
            [$url !== '', 'Adresse de SSM Core renseignée'],
            [$token !== '', 'Token renseigné'],
            [$ok, 'Connexion à SSM Core réussie'],
            [$auto, 'Envoi automatique activé'],
            $version_check,
        ];
        $list = '<ul class="list-unstyled" style="margin:0 0 1rem;">';
        foreach ($checks as $check) {
            $color = $check[0] === null ? '#94a3b8' : ($check[0] ? '#22c55e' : '#ef4444');
            $glyph = $check[0] === null ? '–' : ($check[0] ? '✔' : '✖');
            $list .= '<li><span style="color:' . $color . ';font-weight:700;">' . $glyph . '</span> ' . $this->h($check[1]) . '</li>';
        }
        $list .= '</ul>';

        return '<div class="panel"><div class="panel-heading"><i class="icon-signal"></i> ' . $this->h($this->l('État de la connexion')) . '</div>'
            . $banner . $list . $this->renderUpdateNotice($update) . '</div>';
    }

    private function renderSecurityPanel()
    {
        $cron_url = $this->context->link->getModuleLink($this->name, 'cron');
        // Le token n'est jamais écrit dans la page : on y met un marqueur à remplacer par le token de l'étape 2.
        $command = 'curl -fsS -H "X-SSM-Token: VOTRE_TOKEN" "' . $cron_url . '"';

        $html = '<div class="panel"><div class="panel-heading"><i class="icon-lock"></i> ' . $this->h($this->l('Sécurité et options avancées')) . '</div>';
        $html .= '<p>' . $this->h($this->l('Si le token a été exposé : dans SSM Core, générez-en un nouveau (Sites, bouton 🔌) puis collez-le à l\'étape 2. L\'ancien cesse de fonctionner aussitôt. Le token n\'est jamais réaffiché ici.')) . '</p>';
        $html .= '<p><strong>' . $this->h($this->l('Tâche cron (facultatif)')) . '</strong> — '
            . $this->h($this->l('pour un envoi à heure fixe, même sans visite sur la boutique. À planifier toutes les 5 minutes, en remplaçant VOTRE_TOKEN par le token de l\'étape 2 :')) . '</p>'
            . '<code id="ssm-cron" data-value="' . $this->h($command) . '" style="background:#f1f5f9;padding:0.5rem;display:block;word-break:break-all;">' . $this->h($command) . '</code>'
            . '<p style="margin-top:0.5rem;"><button type="button" class="btn btn-default" onclick="ssmCopy(\'ssm-cron\')">Copier la commande</button></p>';
        return $html . '</div>';
    }

    /** Seul un super-administrateur peut ouvrir la connexion directe : elle peut ouvrir une session de super-administrateur. */
    private function isSuperAdmin()
    {
        $e = $this->context->employee;
        return isset($e->id_profile) && (int) $e->id_profile === (int) _PS_ADMIN_PROFILE_;
    }

    /** Employés actifs, pour choisir celui que SSM connectera. */
    public function activeEmployees()
    {
        return Db::getInstance()->executeS('SELECT id_employee, firstname, lastname, email FROM ' . _DB_PREFIX_ . 'employee'
            . ' WHERE active = 1 ORDER BY id_employee ASC') ?: [];
    }

    public function saveLoginSettings()
    {
        if (!$this->isSuperAdmin()) {
            return $this->displayError($this->l('Seul un super-administrateur peut régler la connexion directe.'));
        }
        if (self::loginLockedByConstant()) {
            return $this->displayError($this->l('Réglage imposé par SSM_CONNECTOR_ALLOW_LOGIN dans config/defines_custom.inc.php.'));
        }
        $employee = (int) Tools::getValue('SSM_LOGIN_EMPLOYEE');
        if ($employee > 0 && !in_array($employee, array_map('intval', array_column($this->activeEmployees(), 'id_employee')), true)) {
            return $this->displayError($this->l('Employé inconnu ou inactif.'));
        }
        $allowed = Tools::getValue('SSM_LOGIN_ALLOWED') ? 1 : 0;
        Configuration::updateValue('SSM_LOGIN_ALLOWED', $allowed);
        Configuration::updateValue('SSM_LOGIN_EMPLOYEE', $employee);
        if (!$allowed) {
            // Refermée : la clé et les liens déjà signés cessent de servir tout de suite.
            Configuration::deleteByName('SSM_LOGIN_KEY');
            Configuration::deleteByName('SSM_LOGIN_NONCES');
            $this->sendHeartbeat();   // SSM l'apprend tout de suite et ne propose plus le lien
            return $this->displayConfirmation($this->l('Connexion directe fermée.'));
        }
        return $this->syncLoginNow();
    }

    /**
     * Ouverture : deux envois tout de suite au lieu d'attendre deux envois planifiés (jusqu'à deux heures). Le
     * premier reçoit la clé de SSM, le second annonce son empreinte : SSM confirme, la connexion directe est prête.
     */
    public function syncLoginNow()
    {
        $later = $this->l('Connexion directe ouverte. SSM n\'a pas répondu : elle sera prête après deux envois (ou deux clics sur « Tester maintenant »).');
        if (!$this->sendHeartbeat()['ok']) {
            return $this->displayConfirmation($later);
        }
        if (!$this->loginKey()) {
            return $this->displayConfirmation($this->l('Connexion directe ouverte, mais SSM n\'a pas remis de clé : SSM 2.14.3 ou plus récent est nécessaire.'));
        }
        if (!$this->sendHeartbeat()['ok']) {
            return $this->displayConfirmation($later);
        }
        return $this->displayConfirmation($this->l('Connexion directe ouverte et prête : SSM peut ouvrir une session sur cette boutique.'));
    }

    /** Après une mise à jour du module : SSM reçoit la nouvelle version tout de suite (jamais bloquant). */
    public function announceNewVersion()
    {
        try {
            if (Configuration::get('SSM_SSM_URL') && Configuration::get('SSM_CONNECTOR_TOKEN')) {
                $this->sendHeartbeat(5);
            }
        } catch (\Throwable $e) {
            // une mise à jour du module ne doit jamais échouer pour un envoi manqué : l'envoi planifié suivra
        }
        return true;
    }

    private function renderLoginPanel($nonce)
    {
        $locked = self::loginLockedByConstant();
        $super = $this->isSuperAdmin();
        $allowed = self::loginAllowed();
        $html = '<div class="panel"><div class="panel-heading"><i class="icon-key"></i> ' . $this->h($this->l('Connexion directe depuis SSM')) . '</div>';
        $html .= '<p>' . $this->h($this->l('Un clic dans SSM ouvre ce back-office, sans mot de passe : lien signé, valable 60 secondes, une seule fois. Fermée par défaut ; SSM ne peut pas l\'ouvrir à distance.')) . '</p>';
        $html .= '<form method="post">' . $nonce;
        $disabled = ($locked || !$super) ? ' disabled' : '';
        $html .= '<div class="checkbox"><label><input type="checkbox" name="SSM_LOGIN_ALLOWED" value="1"' . ($allowed ? ' checked' : '') . $disabled . '> '
            . $this->h($this->l('Autoriser la connexion directe depuis SSM')) . '</label></div>';
        $chosen = (int) Configuration::get('SSM_LOGIN_EMPLOYEE');
        $html .= '<div class="form-group"><label>' . $this->h($this->l('Employé connecté')) . '</label><select name="SSM_LOGIN_EMPLOYEE" class="form-control"' . $disabled . '>'
            . '<option value="0">' . $this->h($this->l('Le premier super-administrateur actif')) . '</option>';
        foreach ($this->activeEmployees() as $e) {
            $id = (int) $e['id_employee'];
            $html .= '<option value="' . $id . '"' . ($id === $chosen ? ' selected' : '') . '>'
                . $this->h(trim($e['firstname'] . ' ' . $e['lastname']) . ' — ' . $e['email']) . '</option>';
        }
        $html .= '</select></div>';
        if ($locked) {
            $html .= '<p class="help-block">' . $this->h($allowed
                ? $this->l('Ouverte et verrouillée par SSM_CONNECTOR_ALLOW_LOGIN dans config/defines_custom.inc.php.')
                : $this->l('Fermée et verrouillée par SSM_CONNECTOR_ALLOW_LOGIN dans config/defines_custom.inc.php.')) . '</p>';
        } elseif (!$super) {
            $html .= '<p class="help-block">' . $this->h($this->l('Réglable par un super-administrateur seulement.')) . '</p>';
        } else {
            $html .= '<button type="submit" name="submitSSMLogin" class="btn btn-default">' . $this->h($this->l('Enregistrer')) . '</button>';
        }
        if ($allowed) {
            $html .= '<p class="help-block">' . $this->h($this->loginKey() ? $this->l('Clé reçue de SSM : prête.') : $this->l('Clé pas encore reçue (au prochain envoi).')) . '</p>';
        }
        $admin = $this->adminUrl();
        $html .= '<p><strong>' . $this->h($this->l('Back-office transmis à SSM')) . '</strong> — '
            . ($admin ? '<code>' . $this->h($admin) . '</code>' : $this->h($this->l('pas encore reconnu.'))) . '</p>';
        return $html . '</form></div>';
    }

    private function renderScripts()
    {
        return '<script>'
            . 'function ssmCopy(id){var e=document.getElementById(id);var v=e.dataset.value;if(navigator.clipboard){navigator.clipboard.writeText(v);}else{window.prompt("Copiez la valeur :",v);}}'
            . '</script>';
    }

    private function intervalChoices($current)
    {
        $choices = [
            300 => '5 minutes',
            900 => '15 minutes',
            1800 => '30 minutes (recommandé)',
            3600 => '1 heure',
            21600 => '6 heures',
            86400 => '24 heures',
        ];
        if (!isset($choices[$current])) {
            $choices[$current] = $current . ' secondes';
            ksort($choices);
        }
        return $choices;
    }

    private function clampInterval($seconds)
    {
        return max(self::MIN_INTERVAL, min(self::MAX_INTERVAL, (int) $seconds));
    }

    /**
     * Normalise l'adresse de SSM Core. Retourne [url, null] ou [null, message].
     * HTTPS obligatoire (http n'est toléré que pour la machine locale), pas
     * d'identifiants dans l'URL, ni de paramètres ou de fragment.
     */
    public function normalizeUrl($raw)
    {
        $url = trim((string) $raw);
        if ($url === '') {
            return [null, $this->l('Renseignez l\'adresse de SSM Core.')];
        }
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $url = 'https://' . $url;
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['host']) || empty($parts['scheme'])) {
            return [null, $this->l('Adresse invalide : exemple attendu https://ssm.example.com')];
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return [null, $this->l('Adresse invalide : seul le protocole https:// est accepté.')];
        }
        if ($scheme === 'http' && !in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)) {
            return [null, $this->l('Le HTTPS est obligatoire : le token ne doit jamais circuler en clair. Utilisez une adresse en https://.')];
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return [null, $this->l('Adresse invalide : ne mettez pas d\'identifiants dans l\'adresse.')];
        }
        if (isset($parts['query']) || isset($parts['fragment'])) {
            return [null, $this->l('Adresse invalide : indiquez uniquement l\'adresse du serveur, sans paramètres.')];
        }

        return [$scheme . '://' . $host . (isset($parts['port']) ? ':' . (int) $parts['port'] : '') . (isset($parts['path']) ? rtrim($parts['path'], '/') : ''), null];
    }

    /** Caractères ASCII visibles uniquement (le token est envoyé dans un en-tête HTTP), 32 à 256 comme SSM Core l'exige. */
    public function isValidToken($token)
    {
        return (bool) preg_match('/^[\x21-\x7E]{' . self::TOKEN_MIN . ',' . self::TOKEN_MAX . '}$/D', (string) $token);
    }

    /**
     * Nettoie un token collé (espaces, retours à la ligne, guillemets, préfixes « Bearer » / « X-SSM-Token: »)
     * puis le contrôle. Retourne [token, null] ou [null, message].
     */
    public function normalizeToken($raw)
    {
        $token = preg_replace('/^\s*(x-ssm-token\s*:|authorization\s*:\s*bearer|bearer)\s*/i', '', (string) $raw);
        $token = trim((string) preg_replace('/\s+/', '', $token), "\"'`<>");
        if ($token === '' || !$this->isValidToken($token)) {
            return [null, sprintf(
                $this->l('Token invalide : %d à %d caractères visibles, sans espace. Recopiez-le en entier depuis SSM Core (Sites, bouton 🔌).'),
                self::TOKEN_MIN,
                self::TOKEN_MAX
            )];
        }
        return [$token, null];
    }

    // === Protection des formulaires (CSRF) ===

    private function nonce()
    {
        $employee = isset($this->context->employee->id) ? (int) $this->context->employee->id : 0;
        return hash_hmac('sha256', 'ssmconnector|' . $employee, _COOKIE_KEY_);
    }

    private function isValidNonce($nonce)
    {
        return $nonce !== '' && hash_equals($this->nonce(), $nonce);
    }

    // === Heartbeat ===

    private function isHeartbeatDue()
    {
        if (trim((string) Configuration::get('SSM_SSM_URL')) === '' || trim((string) Configuration::get('SSM_CONNECTOR_TOKEN')) === '') {
            return false;   // pas encore connecté : rien à envoyer, et ce n'est pas une panne
        }
        $interval = $this->clampInterval((int) Configuration::get('SSM_HEARTBEAT_INTERVAL'));
        // Après un échec, nouvel essai plus rapide (au plus toutes les 5 minutes)
        $wait = Configuration::get('SSM_HEARTBEAT_OK') ? $interval : min($interval, self::MIN_INTERVAL);
        $last = strtotime((string) Configuration::get('SSM_LAST_HEARTBEAT_AT'));

        return !$last || (time() - $last) >= $wait;
    }

    /**
     * Envoi automatique, sans cron : déclenché par les pages vues. Un verrou évite
     * les envois simultanés. Côté boutique, l'envoi n'a lieu qu'après la réponse au
     * visiteur (PHP-FPM) pour ne jamais le ralentir ; sans PHP-FPM, seul le
     * back-office déclenche l'envoi.
     */
    public function maybeRunDueHeartbeat($front)
    {
        if (!Configuration::get('SSM_AUTO_HEARTBEAT') || !$this->isHeartbeatDue()) {
            return;
        }
        if ($front && !function_exists('fastcgi_finish_request')) {
            return;
        }
        $now = time();
        if ($now - (int) Configuration::get('SSM_HEARTBEAT_LOCK') < self::LOCK_TTL) {
            return;
        }
        Configuration::updateValue('SSM_HEARTBEAT_LOCK', $now);

        $this->runAfterResponse(function () {
            $this->sendHeartbeat(self::AUTO_TIMEOUT);
        });
    }

    protected function runAfterResponse($task)
    {
        register_shutdown_function(function () use ($task) {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            ignore_user_abort(true);
            try {
                $task();
            } catch (Throwable $e) {
                error_log('[SSM Connector] Automatic heartbeat failed: ' . $e->getMessage());
            }
        });
    }

    /**
     * Appelé par le contrôleur cron : n'envoie que si l'intervalle est écoulé
     * (ou si $force). Retourne l'état pour la réponse du contrôleur.
     */
    public function sendScheduledHeartbeat($force = false)
    {
        if (!$force && !$this->isHeartbeatDue()) {
            return ['status' => 'skipped'];
        }

        $result = $this->sendHeartbeat();
        return $result['ok'] ? ['status' => 'sent'] : ['status' => 'failed', 'error' => $result['hint']];
    }

    private function sendHeartbeat($timeout = 15)
    {
        $result = $this->postToSSM('/api/v1/heartbeat', $this->collectInventory(), $timeout);
        Configuration::updateValue('SSM_HEARTBEAT_OK', $result['ok'] ? 1 : 0);
        Configuration::updateValue('SSM_LAST_HEARTBEAT_AT', date('Y-m-d H:i:s'));
        Configuration::updateValue('SSM_LAST_ERROR', $result['ok'] ? '' : $result['hint']);
        // SSM Core répond avec l'identifiant du site reconnu par le token : on l'affiche, c'est
        // la façon de confirmer d'un coup d'œil que le token collé est bien celui de CETTE boutique.
        if ($result['ok'] && isset($result['site_id'])) {
            Configuration::updateValue('SSM_SITE_ID', (int) $result['site_id']);
        }
        // Clé de connexion directe : remise par SSM tant que l'empreinte annoncée diffère de la sienne.
        // Connexion fermée : la clé est effacée, un lien déjà signé ne peut plus servir.
        if (!self::loginAllowed()) {
            Configuration::deleteByName('SSM_LOGIN_KEY');
        } elseif ($result['ok'] && !empty($result['login_key']) && is_string($result['login_key'])) {
            Configuration::updateValue('SSM_LOGIN_KEY', self::protect($result['login_key']));
        }
        // SSM ne touche pas à la boutique : il envoie une instruction, ce module l'exécute et
        // renvoie ce qu'il a obtenu. C'est la seule source qui autorise SSM à écrire « appliquée ».
        if ($result['ok'] && !empty($result['commands'])) {
            $this->applyCommands($result['commands']);
        }
        return $result;
    }

    /**
     * Contrôle d'accès du point d'entrée cron : 'ok', 'forbidden' ou 'blocked'
     * (trop d'échecs depuis la même adresse IP ; compteur stocké sous forme de
     * condensat, jamais l'adresse en clair).
     */
    public function authenticateCron($provided, $ip)
    {
        $now = time();
        $failures = json_decode((string) Configuration::get('SSM_CRON_FAILS'), true);
        if (!is_array($failures)) {
            $failures = [];
        }
        foreach ($failures as $k => $f) {
            if (!isset($f['since']) || $now - (int) $f['since'] > self::CRON_WINDOW) {
                unset($failures[$k]);
            }
        }

        $key = substr(hash('sha256', (string) $ip . _COOKIE_KEY_), 0, 16);
        if (isset($failures[$key]) && (int) $failures[$key]['count'] >= self::CRON_MAX_FAILURES) {
            return 'blocked';
        }

        $expected = (string) Configuration::get('SSM_CONNECTOR_TOKEN');
        if ($expected !== '' && hash_equals($expected, (string) $provided)) {
            if (isset($failures[$key])) {
                unset($failures[$key]);
                Configuration::updateValue('SSM_CRON_FAILS', json_encode($failures ?: new stdClass()));
            }
            return 'ok';
        }

        $failures[$key] = [
            'count' => isset($failures[$key]) ? (int) $failures[$key]['count'] + 1 : 1,
            'since' => isset($failures[$key]) ? (int) $failures[$key]['since'] : $now,
        ];
        Configuration::updateValue('SSM_CRON_FAILS', json_encode(array_slice($failures, -100, null, true)));
        return 'forbidden';
    }

    /** Tronque à la longueur maximale acceptée par SSM Core : au-delà il refuse tout l'inventaire (422). */
    private function cut($value, $max)
    {
        $value = (string) $value;
        return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
    }

    private function cutOrNull($value, $max)
    {
        $value = $this->cut($value, $max);
        return $value === '' ? null : $value;
    }

    /**
     * Compteur métier borné aux limites de SSM Core (0 à MAX_COUNTER, app/schemas.py) : une
     * valeur hors bornes fait refuser tout l'inventaire en 422, pas seulement le compteur.
     */
    private function counter($value)
    {
        $value = (int) $value;
        if ($value < 0) {
            return 0;
        }
        return min($value, self::MAX_COUNTER);
    }

    /**
     * Inventaire envoyé à SSM Core. Les clés lues par SSM Core sont en tête (cms_version, php_version, db_version, web_server,
     * hostname, site_path, extensions, themes) ; le reste est ce que SSM Core lit désormais (2.7.0) ou conserve à titre
     * informatif. Les longueurs sont celles de SSM Core (app/schemas.py).
     */
    private function collectInventory()
    {
        $db = Db::getInstance();
        $extensions = [];
        $all_modules = $db->executeS('SELECT name, version, active FROM ' . _DB_PREFIX_ . 'module') ?: [];
        foreach ($all_modules as $m) {
            $slug = $this->cut($m['name'], 255);
            // SSM Core exige un slug non vide (min_length=1) : une ligne illisible ferait
            // refuser tout l'inventaire, on l'écarte donc plutôt que de tout casser.
            if ($slug === '') {
                continue;
            }
            $extensions[] = [
                'slug' => $slug,
                'name' => $slug,
                'version' => $this->cutOrNull($m['version'], 50),
                'is_active' => (bool) $m['active'],
            ];
        }
        // Plafond appliqué AVANT de compter, sinon module_count (après plafond) et
        // module_active_count (avant plafond) ne décrivent pas la même liste.
        $extensions = array_slice($extensions, 0, 1000);
        $active_count = count(array_filter($extensions, function ($m) {
            return $m['is_active'];
        }));

        $themes = array_slice($this->collectThemes(), 0, 200);
        $update = $this->getUpdateInfo();

        $stats = [
            'customers' => $this->counter($db->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'customer')),
            'products' => $this->counter($db->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'product')),
            'orders' => $this->counter($db->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'orders')),
            'employees' => $this->counter($db->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'employee')),
        ];

        $ssl = Configuration::get('PS_SSL_ENABLED');
        // Une installation fraîche peut ne pas encore avoir la clé : on rapporte alors « non
        // rapporté » (null) plutôt que « SSL désactivé », qui serait une affirmation que
        // personne n'a faite. SSM Core 2.7.0 fait précisément cette distinction.

        $login = [];
        if (self::loginAllowed()) {
            $key = $this->loginKey();
            $login = [
                'login_key_fingerprint' => $key ? substr(hash('sha256', $key), 0, 16) : null,
                'login_url' => $this->cutOrNull($this->context->link->getModuleLink($this->name, 'login', [], true), 500),
            ];
        }

        return $login + [
            // lus par SSM Core
            'cms_version' => $this->cut(_PS_VERSION_, 50),
            'php_version' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION,
            'db_version' => $this->cutOrNull($db->getVersion(), 50),
            'web_server' => isset($_SERVER['SERVER_SOFTWARE']) ? $this->cutOrNull($_SERVER['SERVER_SOFTWARE'], 50) : null,
            'hostname' => function_exists('gethostname') ? $this->cutOrNull(gethostname(), 255) : null,
            'site_path' => defined('_PS_ROOT_DIR_') ? $this->cutOrNull(_PS_ROOT_DIR_, 500) : null,
            'extensions' => $extensions,
            'themes' => $themes,
            // état de la boutique, lu par SSM Core depuis la 2.7.0
            'cms' => 'prestashop',
            'multistore' => (bool) Shop::isFeatureActive(),
            'shop_url' => $this->cutOrNull(Tools::getShopDomain(true), self::URL_MAX),
            'ssl_enabled' => $ssl === false ? null : (bool) $ssl,
            'debug_mode' => (bool) _PS_MODE_DEV_,
            'maintenance_mode' => !(bool) Configuration::get('PS_SHOP_ENABLE'),
            // version du connecteur, lue par SSM Core depuis la 2.7.0
            'connector_version' => $this->version,
            'latest_connector_version' => $this->cutOrNull($update['latest'], 20),
            'connector_update_available' => (bool) $update['available'],
            // comptes rendus des commandes exécutées au heartbeat précédent
            'results' => $this->takePendingResults(),
            // contrat 3 (SSM Core 2.13) : ignorés sans dommage par un SSM plus ancien
            'capabilities' => $this->capabilities(),
            'command_results' => $this->takeCommandResults(),
            'php_errors' => $this->takePhpErrors(),
            'login_enabled' => self::loginAllowed(),
            // adresse réelle du back-office (son dossier est renommé à l'installation) ; null si introuvable
            'admin_url' => $this->cutOrNull($this->adminUrl(), 500),
            // compteurs métier, lus par SSM Core depuis la 2.7.0
            'stats' => $stats,
            // informatifs (conservés, pas encore lus)
            'module_count' => count($extensions),
            'module_active_count' => $active_count,
            'theme_count' => count($themes),
            'timestamp' => date('c'),
        ];
    }

    private function collectThemes()
    {
        $themes = [];
        try {
            $shop = $this->context->shop;
            $active = !empty($shop->theme_name) ? $shop->theme_name : (isset($shop->theme->name) ? $shop->theme->name : null);

            if (method_exists('Theme', 'getThemes')) {
                foreach (Theme::getThemes() as $theme) {
                    $slug = $this->cut(!empty($theme->directory) ? $theme->directory : $theme->name, 255);
                    if ($slug === '') {
                        continue;   // slug exigé non vide par SSM Core
                    }
                    $version = method_exists($theme, 'getVersion') ? $theme->getVersion() : null;
                    $themes[] = [
                        'slug' => $slug,
                        'name' => $this->cut($theme->name, 255),
                        'version' => $this->cut($version ?: '1.0', 50),
                        'is_active' => $slug === $active,
                    ];
                }
            }
            if (!$themes && $active) {
                $themes[] = ['slug' => $this->cut($active, 255), 'name' => $this->cut($active, 255), 'version' => '1.0', 'is_active' => true];
            }
        } catch (Throwable $e) {
            error_log('[SSM Connector] Theme inventory failed: ' . $e->getMessage());
        }
        return $themes;
    }

    // === Mise à jour du connecteur ===

    /**
     * Compare la version installée à la dernière release GitHub publiée.
     * Résultat mis en cache (SSM_UPDATE_CACHE, UPDATE_TTL) ; $force ignore le cache.
     * Une erreur réseau ne casse jamais l'appelant : on garde la dernière info connue.
     */
    public function getUpdateInfo($force = false)
    {
        $cache = json_decode((string) Configuration::get('SSM_UPDATE_CACHE'), true);
        if (!is_array($cache)) {
            $cache = [];
        }

        $checked_at = isset($cache['checked_at']) ? (int) $cache['checked_at'] : 0;
        if ($force || (time() - $checked_at) >= self::UPDATE_TTL) {
            $release = null;
            try {
                $json = $this->fetchLatestRelease();
                $release = is_array($json) ? $this->parseRelease($json) : null;
            } catch (Throwable $e) {
                error_log('[SSM Connector] Update check failed: ' . $e->getMessage());
            }

            $cache = [
                'checked_at' => time(),
                'error' => $release === null,
                'latest' => $release ? $release['latest'] : (isset($cache['latest']) ? $cache['latest'] : null),
                'url' => $release ? $release['url'] : (isset($cache['url']) ? $cache['url'] : null),
                'download' => $release ? $release['download'] : (isset($cache['download']) ? $cache['download'] : null),
            ];
            Configuration::updateValue('SSM_UPDATE_CACHE', json_encode($cache));
        }

        $latest = isset($cache['latest']) ? $cache['latest'] : null;
        return [
            'current' => $this->version,
            'latest' => $latest,
            'available' => $latest !== null && version_compare($latest, $this->version, '>'),
            'url' => isset($cache['url']) ? $cache['url'] : null,
            'download' => isset($cache['download']) ? $cache['download'] : null,
            'checked_at' => isset($cache['checked_at']) ? (int) $cache['checked_at'] : 0,
            'error' => !empty($cache['error']),
        ];
    }

    /** Requête publique, sans authentification : aucune donnée de la boutique n'est transmise. */
    protected function fetchLatestRelease()
    {
        $ch = curl_init('https://api.github.com/repos/' . self::UPDATE_REPO . '/releases/latest');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER => [
                'Accept: application/vnd.github+json',
                'User-Agent: ssmconnector/' . $this->version,
            ],
        ]);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response === false || $http_code !== 200) {
            return null;
        }
        return json_decode($response, true);
    }

    /** Extrait version / liens d'une réponse GitHub ; null si inexploitable. */
    protected function parseRelease(array $release)
    {
        if (!empty($release['draft']) || !empty($release['prerelease'])) {
            return null;
        }
        if (!preg_match('/^v?(\d+\.\d+\.\d+)$/D', isset($release['tag_name']) ? (string) $release['tag_name'] : '', $m)) {
            return null;
        }

        $prefix = 'https://github.com/' . self::UPDATE_REPO . '/';
        $page = isset($release['html_url']) ? (string) $release['html_url'] : '';
        $download = null;
        if (!empty($release['assets']) && is_array($release['assets'])) {
            foreach ($release['assets'] as $asset) {
                if (isset($asset['name'], $asset['browser_download_url']) && $asset['name'] === 'ssmconnector.zip') {
                    $download = (string) $asset['browser_download_url'];
                }
            }
        }

        // Les liens affichés dans le back-office doivent pointer vers le dépôt du connecteur
        return [
            'latest' => $m[1],
            'url' => strpos($page, $prefix) === 0 ? $page : null,
            'download' => $download !== null && strpos($download, $prefix) === 0 ? $download : null,
        ];
    }

    private function renderUpdateNotice(array $update)
    {
        if ($update['available']) {
            $html = '<div class="alert alert-info"><strong>Mise à jour disponible :</strong> v' . $this->h($update['latest'])
                . ' (installée : v' . $this->h($update['current']) . ')';
            if ($update['download']) {
                $html .= ' — <a href="' . $this->h($update['download']) . '" rel="noopener">télécharger</a>';
            }
            if ($update['url']) {
                $html .= ' — <a href="' . $this->h($update['url']) . '" target="_blank" rel="noopener">notes de version</a>';
            }
            return $html . '<br>Remplacez le dossier <code>modules/ssmconnector/</code> par le contenu de l\'archive, puis lancez la mise à jour du module depuis le gestionnaire de modules.</div>';
        }

        if ($update['error'] && $update['latest'] === null) {
            return '<p class="help-block">Vérification des mises à jour impossible pour le moment.</p>';
        }

        return $update['checked_at']
            ? '<p class="help-block">Dernière vérification des mises à jour : ' . $this->h(date('Y-m-d H:i', $update['checked_at'])) . '.</p>'
            : '';
    }

    // === File d'événements ===

    private function getPendingEvents()
    {
        $events = json_decode((string) Configuration::get('SSM_PENDING_EVENTS'), true);
        return is_array($events) ? $events : [];
    }

    private function setPendingEvents(array $events)
    {
        Configuration::updateValue('SSM_PENDING_EVENTS', json_encode(array_values(array_slice($events, -self::MAX_PENDING_EVENTS))));
    }

    /**
     * Met un événement en file, si l'envoi d'événements est activé. Un événement identique au
     * précédent, à moins d'une minute, n'est pas répété (ou, avec $throttle, tout événement du
     * même type) : une attaque par force brute ou un import de produits n'écrit pas en base à
     * chaque requête.
     *
     * Désactivé par défaut : SSM Core ne consomme pas ces événements (app/schemas.py) alors que
     * la file contient des identifiants de personnes. Tant que SSM Core ne les exploite pas, les
     * écrire en base à chaque mise à jour produit n'a aucun intérêt et garde des identifiants
     * clients et commandes pour rien.
     */
    private function queueEvent($type, $payload, $throttle = false)
    {
        if (!Configuration::get('SSM_SEND_EVENTS')) {
            return;
        }
        $events = $this->getPendingEvents();
        $last = end($events);
        if (is_array($last) && isset($last['type'], $last['timestamp']) && $last['type'] === $type
            && (time() - (int) strtotime((string) $last['timestamp'])) < self::EVENT_THROTTLE
            && ($throttle || (isset($last['payload']) && $last['payload'] == $payload))) {
            return;
        }
        $events[] = ['type' => $type, 'payload' => $payload, 'timestamp' => date('c')];
        $this->setPendingEvents($events);
    }

    // === Transport ===

    /** Retourne ['ok' => bool, 'http_code' => int, 'hint' => message lisible]. */
    private function postToSSM($path, $body, $timeout = 15)
    {
        list($base, $error) = $this->normalizeUrl(Configuration::get('SSM_SSM_URL'));
        if ($base === null) {
            return ['ok' => false, 'http_code' => 0, 'hint' => $error];
        }

        $token = (string) Configuration::get('SSM_CONNECTOR_TOKEN');
        if ($token === '') {
            return ['ok' => false, 'http_code' => 0, 'hint' => $this->l('Token manquant : collez le token de cette boutique (dans SSM Core : Sites, bouton 🔌).')];
        }
        // Le drapeau décide à la fois de la mise en file ET de l'envoi : une file déjà
        // alimentée (avant désactivation, ou après un simple décochement) ne doit pas repartir
        // toute seule. Elle est vidée au décochement, dans processForms().
        $events = Configuration::get('SSM_SEND_EVENTS') ? $this->getPendingEvents() : [];
        if ($events) {
            $body['pending_events'] = $events;
        }

        $payload = json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE);
        if ($payload === false) {
            error_log('[SSM Connector] JSON encoding failed: ' . json_last_error_msg());
            return ['ok' => false, 'http_code' => 0, 'hint' => $this->l('Envoi impossible : données illisibles (encodage).')];
        }

        $ch = curl_init($base . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false, // ne jamais renvoyer le token vers une autre adresse
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-SSM-Token: ' . $token,
            ],
            CURLOPT_TIMEOUT => max(1, (int) $timeout),
            CURLOPT_CONNECTTIMEOUT => min(5, max(1, (int) $timeout)),
        ]);
        $response = curl_exec($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($response === false || $http_code < 200 || $http_code >= 300) {
            error_log("[SSM Connector] POST $path failed: HTTP $http_code (curl $errno)");
            return ['ok' => false, 'http_code' => $http_code, 'hint' => $this->describeFailure($http_code, $errno, is_string($response) ? $response : '')];
        }

        // Succès : on retire uniquement les événements envoyés (d'autres ont pu être ajoutés entre-temps)
        $this->setPendingEvents(array_slice($this->getPendingEvents(), count($events)));

        $json = json_decode((string) $response, true);
        return [
            'ok' => true,
            'http_code' => $http_code,
            'hint' => '',
            'site_id' => is_array($json) && isset($json['site_id']) ? (int) $json['site_id'] : null,
            'login_key' => is_array($json) && isset($json['login_key']) && is_string($json['login_key']) ? $json['login_key'] : null,
            // Les instructions de mise à jour éventuelles : un connecteur plus ancien ne les lit pas,
            // et une boutique portant une version antérieure reste parfaitement fonctionnelle.
            'commands' => (is_array($json) && !empty($json['commands']) && is_array($json['commands']))
                ? $json['commands'] : [],
        ];
    }

    // === Mises à jour demandées par SSM Core ===
    //
    // SSM n'a pas accès au système de fichiers de la boutique : il envoie une instruction, ce
    // module l'exécute et renvoie ce qu'il a obtenu. C'est la seule source qui autorise SSM à
    // écrire « appliquée » — un fait constaté ici, pas une intention là-bas.
    //
    // Ce chemin n'est atteint que si SSM l'a explicitement demandé : soit le prestataire a cliqué
    // sur « Demander », soit la boutique est en politique automatique (le défaut est « manuel »).

    /** Les comptes rendus en attente d'envoi. Ils partent UNE fois. */
    public function takePendingResults()
    {
        $r = Configuration::get('SSM_UPDATE_RESULTS');
        $r = is_string($r) ? json_decode($r, true) : $r;
        if (!is_array($r)) {
            return [];
        }
        Configuration::updateValue('SSM_UPDATE_RESULTS', '');   // partir, c'est les remettre
        return array_slice($r, 0, 200);
    }

    public function queueResult($update_id, $status, $error = null, $version = null)
    {
        $r = Configuration::get('SSM_UPDATE_RESULTS');
        $r = is_string($r) ? json_decode($r, true) : $r;
        if (!is_array($r)) {
            $r = [];
        }
        $entry = ['update_id' => (int) $update_id, 'status' => $status === 'success' ? 'success' : 'failed'];
        if ($version !== null && $version !== '') {
            $entry['version'] = $this->cutOrNull((string) $version, 50);
        }
        if ($error !== null && $error !== '') {
            $entry['error'] = $this->cutOrNull((string) $error, 300);
        }
        $r[] = $entry;
        // Borné : un module hors ligne des semaines ne doit pas accumuler des comptes rendus.
        Configuration::updateValue('SSM_UPDATE_RESULTS', json_encode(array_slice($r, -200)));
    }

    /** Exécute les commandes reçues. Jamais de `\Throwable` non rattrapé : un module cassé ne doit
     *  pas empêcher le heartbeat de partir. */
    public function applyCommands($commands)
    {
        if (!is_array($commands)) {
            return;
        }
        foreach ($commands as $cmd) {
            if (!is_array($cmd) || !isset($cmd['id'])) {
                continue;
            }
            $is_action = isset($cmd['ref']) && $cmd['ref'] === 'command';
            try {
                if ($is_action) {
                    $this->applyAction((int) $cmd['id'], isset($cmd['kind']) ? (string) $cmd['kind'] : '', $cmd);
                } else {
                    $this->applyCommand($cmd);
                }
            } catch (\Throwable $e) {
                if ($is_action) {
                    $this->queueCommandResult($cmd['id'], 'failed', $e->getMessage());
                } else {
                    $this->queueResult($cmd['id'], 'failed', $e->getMessage());
                }
            }
        }
    }

    // === Actions (contrat 3) : activer / désactiver un module, sauvegarder la boutique ===

    public function takeCommandResults()
    {
        $r = Configuration::get('SSM_COMMAND_RESULTS');
        $r = is_string($r) ? json_decode($r, true) : $r;
        if (!is_array($r)) {
            return [];
        }
        Configuration::updateValue('SSM_COMMAND_RESULTS', '');
        return array_slice($r, 0, 200);
    }

    public function queueCommandResult($command_id, $status, $error = null, $data = null)
    {
        $r = Configuration::get('SSM_COMMAND_RESULTS');
        $r = is_string($r) ? json_decode($r, true) : $r;
        if (!is_array($r)) {
            $r = [];
        }
        $entry = ['command_id' => (int) $command_id, 'status' => $status === 'success' ? 'success' : 'failed'];
        if ($error !== null && $error !== '') {
            $entry['error'] = $this->cut((string) $error, 500);
        }
        if (is_array($data)) {
            $entry['data'] = $data;
        }
        $r[] = $entry;
        Configuration::updateValue('SSM_COMMAND_RESULTS', json_encode(array_slice($r, -200)));
    }

    private function applyAction($id, $kind, $cmd)
    {
        $params = isset($cmd['params']) && is_array($cmd['params']) ? $cmd['params'] : [];
        if ($kind === 'backup_site') {
            $this->backupShop($id, $params);
            return;
        }
        if ($kind !== 'plugin_activate' && $kind !== 'plugin_deactivate') {
            $this->queueCommandResult($id, 'failed', 'Action inconnue : ' . $kind);
            return;
        }
        $slug = isset($cmd['slug']) ? (string) $cmd['slug'] : '';
        if (!preg_match('/^[a-z0-9][a-z0-9._-]*$/i', $slug)) {
            $this->queueCommandResult($id, 'failed', 'Nom de module refusé : ' . $slug);
            return;
        }
        if ($slug === $this->name) {
            $this->queueCommandResult($id, 'failed', 'Le connecteur ne se désactive pas lui-même.');
            return;
        }
        $module = $this->findModule($slug);
        if ($module === null) {
            $this->queueCommandResult($id, 'failed', 'Module « ' . $slug . ' » introuvable sur cette boutique.');
            return;
        }
        $ok = $kind === 'plugin_activate' ? $module->enable() : $module->disable();
        $this->queueCommandResult($id, $ok ? 'success' : 'failed', $ok ? null : 'PrestaShop a refusé l\'opération.');
    }

    /**
     * Exporte la base (database.sql) et les fichiers de la boutique (sans caches ni journaux, images
     * en option) dans une archive zip déposée sur l'URL pré-signée fournie par SSM. Le dossier de
     * travail est protégé et toujours vidé.
     */
    private function backupShop($id, $params)
    {
        $url = isset($params['upload_url']) ? (string) $params['upload_url'] : '';
        $max = isset($params['max_bytes']) ? (int) $params['max_bytes'] : 5368709120;
        $images = !isset($params['include_uploads']) || $params['include_uploads'];
        if (strpos($url, 'https://') !== 0 && strpos($url, 'http://') !== 0) {
            $this->queueCommandResult($id, 'failed', 'Adresse de dépôt invalide.');
            return;
        }
        if (!class_exists('ZipArchive')) {
            $this->queueCommandResult($id, 'failed', "L'extension PHP zip est requise pour sauvegarder.");
            return;
        }
        @set_time_limit(0);
        @ignore_user_abort(true);
        $root = rtrim(_PS_ROOT_DIR_, '/');
        $dir = $root . '/' . self::BACKUP_TMP;
        $this->removeTree($dir);
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
            $this->queueCommandResult($id, 'failed', 'Dossier de travail impossible à créer.');
            return;
        }
        @file_put_contents($dir . '/index.php', "<?php\nheader('HTTP/1.1 403 Forbidden');\nexit;\n");
        @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        try {
            $tables = $this->dumpDatabase($dir . '/database.sql');
            $zip_path = $dir . '/boutique.zip';
            $zip = new ZipArchive();
            if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Archive impossible à créer.');
            }
            $zip->addFile($dir . '/database.sql', 'database.sql');
            $excludes = [self::BACKUP_TMP, self::BACKUP_DIR, 'var/cache', 'var/logs', 'cache/smarty', 'cache/cachefs', '.git'];
            if (!$images) {
                $excludes[] = 'img';
            }
            $files = $this->zipTree($zip, $root, 'boutique', $excludes);
            $zip->close();
            $size = (int) filesize($zip_path);
            if ($size > $max) {
                throw new RuntimeException('Archive de ' . round($size / 1048576) . ' Mo : au-delà de la limite de dépôt.');
            }
            $sha = hash_file('sha256', $zip_path);
            $up = $this->upload($url, $zip_path, $size);
            if (!$up['ok']) {
                throw new RuntimeException('Dépôt refusé : ' . $up['error']);
            }
            $this->queueCommandResult($id, 'success', null, ['size_bytes' => $size, 'sha256' => $sha, 'files' => $files + 1, 'tables' => $tables]);
        } catch (\Throwable $e) {
            $this->queueCommandResult($id, 'failed', $e->getMessage());
        } finally {
            $this->removeTree($dir);
        }
    }

    private function zipTree($zip, $root, $prefix, $excludes, $rel = '')
    {
        $count = 0;
        $items = @scandir($root . ($rel !== '' ? '/' . $rel : ''));
        if ($items === false) {
            return 0;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path_rel = $rel !== '' ? $rel . '/' . $item : $item;
            if (in_array($path_rel, $excludes, true)) {
                continue;
            }
            $full = $root . '/' . $path_rel;
            if (is_link($full)) {
                continue;
            }
            if (is_dir($full)) {
                $count += $this->zipTree($zip, $root, $prefix, $excludes, $path_rel);
            } elseif (is_readable($full)) {
                $zip->addFile($full, $prefix . '/' . $path_rel);
                $count++;
            }
        }
        return $count;
    }

    private static function sqlValue($v)
    {
        if ($v === null) {
            return 'NULL';
        }
        return "'" . str_replace(["\\", "\0", "\n", "\r", "'", "\x1a"], ["\\\\", "\\0", "\\n", "\\r", "\\'", "\\Z"], (string) $v) . "'";
    }

    /** Export SQL des tables de la boutique (préfixe _DB_PREFIX_), par lots de 500 lignes. */
    private function dumpDatabase($path)
    {
        $db = Db::getInstance();
        $fh = fopen($path, 'wb');
        if (!$fh) {
            throw new RuntimeException('Export de la base impossible (écriture).');
        }
        fwrite($fh, '-- Export SSM Connector ' . $this->version . ' du ' . gmdate('Y-m-d H:i:s') . " UTC\nSET NAMES utf8mb4;\nSET foreign_key_checks = 0;\n\n");
        $like = str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], _DB_PREFIX_) . '%';
        $n = 0;
        foreach ((array) $db->executeS("SHOW TABLES LIKE '" . str_replace("'", "''", $like) . "'") as $row) {
            $table = str_replace('`', '', (string) reset($row));
            $create = $db->executeS('SHOW CREATE TABLE `' . $table . '`');
            if (!$create || !isset($create[0]['Create Table'])) {
                continue;
            }
            fwrite($fh, 'DROP TABLE IF EXISTS `' . $table . "`;\n" . $create[0]['Create Table'] . ";\n\n");
            for ($offset = 0; ; $offset += 500) {
                $rows = $db->executeS('SELECT * FROM `' . $table . '` LIMIT ' . $offset . ', 500');
                if (!$rows) {
                    break;
                }
                foreach ($rows as $r) {
                    fwrite($fh, 'INSERT INTO `' . $table . '` VALUES (' . implode(',', array_map([__CLASS__, 'sqlValue'], array_values($r))) . ");\n");
                }
                if (count($rows) < 500) {
                    break;
                }
            }
            fwrite($fh, "\n");
            $n++;
        }
        fwrite($fh, "SET foreign_key_checks = 1;\n");
        fclose($fh);
        return $n;
    }

    private function upload($url, $path, $size)
    {
        if (self::$uploader !== null) {
            return call_user_func(self::$uploader, $url, $path, $size);
        }
        $fp = fopen($path, 'rb');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_UPLOAD => true, CURLOPT_INFILE => $fp, CURLOPT_INFILESIZE => $size,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3600, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => ['Content-Type: application/zip'],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fp);
        if ($body === false) {
            return ['ok' => false, 'error' => 'réseau : ' . $err];
        }
        return $code >= 200 && $code < 300 ? ['ok' => true, 'error' => null]
            : ['ok' => false, 'error' => 'HTTP ' . $code . ' ' . $this->cut(strip_tags((string) $body), 160)];
    }

    // === Erreurs PHP ===

    private function relativePath($text)
    {
        $root = rtrim(_PS_ROOT_DIR_, '/') . '/';
        return str_replace([$root, (string) realpath($root) . '/'], '', (string) $text);
    }

    public function captureFatal()
    {
        $e = error_get_last();
        if (!is_array($e) || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            return;
        }
        if ($this->errorLogPath() !== null) {
            return;   // déjà écrite dans le journal de PHP, lu au heartbeat
        }
        try {
            $this->recordPhpError($e['type'] === E_PARSE ? 'parse' : 'fatal', $e['message'], $e['file'], $e['line']);
        } catch (\Throwable $x) {
            // la base elle-même peut être la cause
        }
    }

    public function recordPhpError($level, $message, $file, $line, $count = 1, $when = null)
    {
        $all = json_decode((string) Configuration::get('SSM_PHP_ERRORS'), true);
        if (!is_array($all)) {
            $all = [];
        }
        $message = (string) strtok((string) $message, "\n");
        if (preg_match('/^(.*) in (\S+?)(?: on line (\d+)|:(\d+))\s*$/', $message, $f)) {
            $message = $f[1];
            if ($file === null) {
                $file = $f[2];
                $line = (int) ($f[3] !== '' ? $f[3] : $f[4]);
            }
        }
        $message = $this->cut($this->relativePath($message), 1000);
        $file = $file !== null ? $this->cut($this->relativePath($file), 300) : null;
        $key = md5($level . '|' . $message . '|' . $file . '|' . $line);
        $at = gmdate('c', $when ?: time());
        if (isset($all[$key])) {
            $all[$key]['count'] += $count;
            $all[$key]['last_seen'] = $at;
        } elseif (count($all) < self::PHP_ERRORS_MAX) {
            $all[$key] = ['level' => $level, 'message' => $message !== '' ? $message : '(sans message)', 'file' => $file,
                          'line' => $line !== null ? (int) $line : null, 'count' => $count, 'last_seen' => $at];
        } else {
            return;
        }
        Configuration::updateValue('SSM_PHP_ERRORS', json_encode($all));
    }

    public function errorLogPath()
    {
        $p = ini_get('error_log');
        return ($p && is_string($p) && is_file($p) && is_readable($p)) ? $p : null;
    }

    public function readErrorLog()
    {
        $path = $this->errorLogPath();
        if ($path === null) {
            return;
        }
        clearstatcache(true, $path);
        $size = (int) filesize($path);
        $offset = Configuration::get('SSM_LOG_OFFSET');
        $offset = $offset === false || $offset === '' ? -1 : (int) $offset;
        if ($offset < 0 || $offset > $size) {
            $offset = max(0, $size - self::LOG_READ_MAX);
        }
        $fh = @fopen($path, 'rb');
        if (!$fh) {
            return;
        }
        fseek($fh, $offset);
        $chunk = (string) fread($fh, min(self::LOG_READ_MAX, max(0, $size - $offset)));
        fclose($fh);
        $end = strrpos($chunk, "\n");
        if ($end === false) {
            return;
        }
        $chunk = substr($chunk, 0, $end + 1);
        Configuration::updateValue('SSM_LOG_OFFSET', $offset + strlen($chunk));
        $levels = ['fatal error' => 'fatal', 'catchable fatal error' => 'fatal', 'recoverable fatal error' => 'fatal',
                   'parse error' => 'parse', 'warning' => 'warning', 'notice' => 'notice', 'deprecated' => 'deprecated'];
        foreach (explode("\n", $chunk) as $l) {
            if (!preg_match('/^\[([^\]]+)\] PHP ([A-Za-z ]+?):\s+(.*)$/', $l, $m)) {
                continue;
            }
            $level = isset($levels[strtolower($m[2])]) ? $levels[strtolower($m[2])] : null;
            if ($level !== null) {
                $ts = strtotime($m[1]);
                $this->recordPhpError($level, $m[3], null, null, 1, $ts ?: null);
            }
        }
    }

    public function takePhpErrors()
    {
        try {
            $this->readErrorLog();
        } catch (\Throwable $e) {
            // un journal illisible n'empêche pas le heartbeat
        }
        $all = json_decode((string) Configuration::get('SSM_PHP_ERRORS'), true);
        Configuration::updateValue('SSM_PHP_ERRORS', '');
        return is_array($all) ? array_slice(array_values($all), 0, self::PHP_ERRORS_MAX) : [];
    }

    private function applyCommand($cmd)
    {
        $id = (int) $cmd['id'];
        $kind = isset($cmd['kind']) ? (string) $cmd['kind'] : '';
        if ($kind !== 'update_extension') {
            $this->queueResult($id, 'failed', 'Commande inconnue : ' . $kind);
            return;
        }
        $slug = isset($cmd['slug']) ? (string) $cmd['slug'] : '';
        // Le nom vient d'un tiers. Sans contrôle, « ../../app/config/… » désignerait un chemin
        // hors du répertoire des modules.
        if (!preg_match('/^[a-z0-9][a-z0-9._-]*$/i', $slug)) {
            $this->queueResult($id, 'failed', 'Nom de module refusé : ' . $slug);
            return;
        }
        $cible = isset($cmd['to_version']) ? (string) $cmd['to_version'] : '';
        if ($cible === '') {
            $this->queueResult($id, 'failed', 'Version cible absente de la commande.');
            return;
        }

        $module = $this->findModule($slug);
        if ($module === null) {
            $this->queueResult($id, 'failed', 'Module « ' . $slug . ' » introuvable sur cette boutique.');
            return;
        }

        $avant = $this->moduleVersion($slug);
        if ($avant !== null && version_compare($avant, $cible, '>=')) {
            // Déjà à jour, ou en deçà : rien à faire, et surtout rien à dégrader.
            $this->queueResult($id, 'success', null, $avant);
            return;
        }

        $backup = $this->backupModule($slug);
        if ($backup === false) {
            // Pas de sauvegarde, pas de mise à jour : sans elle, un échec se traduit par une
            // boutique cassée, et SSM n'a aucun moyen de la remettre droit.
            $this->queueResult($id, 'failed', 'Sauvegarde impossible, mise à jour annulée. Vérifiez les droits d\'écriture de ' . self::BACKUP_DIR . '/.');
            return;
        }

        $erreur = $this->runModuleUpgrade($slug);
        if ($erreur !== null) {
            $remis = $this->restoreModule($slug, $backup);
            $suffixe = $remis
                ? ' — module remis à la version précédente.'
                : ' — ET LA RESTAURATION AUTOMATIQUE A ÉCHOUÉ, intervention manuelle requise.';
            $this->queueResult($id, 'failed', $erreur . $suffixe);
            return;
        }

        $apres = $this->moduleVersion($slug);
        if ($apres === null || version_compare($apres, $cible, '<')) {
            $remis = $this->restoreModule($slug, $backup);
            $suffixe = $remis
                ? ' — module remis à la version précédente.'
                : ' — ET LA RESTAURATION AUTOMATIQUE A ÉCHOUÉ, intervention manuelle requise.';
            $this->queueResult($id, 'failed', 'Mise à jour annoncée vers ' . $cible . ' mais version installée : ' . ($apres ?: 'inconnue') . '.' . $suffixe);
            return;
        }
        $this->queueResult($id, 'success', null, $apres);
    }

    private function modulePath($slug)
    {
        return _PS_MODULE_DIR_ . '/' . $slug;
    }

    private function findModule($slug)
    {
        if (!is_dir($this->modulePath($slug))) {
            return null;
        }
        if (class_exists('Module') && method_exists('Module', 'getInstanceByName')) {
            return Module::getInstanceByName($slug);
        }
        return null;
    }

    private function moduleVersion($slug)
    {
        $module = $this->findModule($slug);
        if ($module !== null && isset($module->version)) {
            return (string) $module->version;
        }
        $config = $this->modulePath($slug) . '/config.xml';
        if (is_file($config) && function_exists('simplexml_load_file')) {
            $xml = @simplexml_load_file($config);
            if ($xml !== false && isset($xml->version)) {
                return trim((string) $xml->version);
            }
        }
        return null;
    }

    /** Copie le module avant toute modification. Retourne le chemin, ou false si la copie est impossible. */
    private function backupModule($slug)
    {
        $source = $this->modulePath($slug);
        if (!is_dir($source)) {
            return false;
        }
        $base = _PS_ROOT_DIR_ . '/' . self::BACKUP_DIR;
        if (!$this->diskWritable(_PS_MODULE_DIR_)) {
            return false;
        }
        if (!is_dir($base) && !@mkdir($base, 0755, true) && !is_dir($base)) {
            return false;
        }
        $dest = $base . '/' . $slug . '-' . gmdate('Ymd-His');
        if (!$this->copyTree($source, $dest)) {
            return false;
        }
        $this->pruneBackups($slug);
        return $dest;
    }

    /** Ne garde que les BACKUPS_KEPT sauvegardes les plus récentes d'un module. */
    private function pruneBackups($slug)
    {
        $base = _PS_ROOT_DIR_ . '/' . self::BACKUP_DIR;
        $trouves = glob($base . '/' . $slug . '-*', GLOB_ONLYDIR);
        if (!$trouves || count($trouves) <= self::BACKUPS_KEPT) {
            return;
        }
        rsort($trouves);   // noms horodatés : l'ordre lexicographique est l'ordre chronologique
        foreach (array_slice($trouves, self::BACKUPS_KEPT) as $vieux) {
            $this->removeTree($vieux);
        }
    }

    private function restoreModule($slug, $backup)
    {
        if (!$backup || !is_dir($backup)) {
            return false;
        }
        $dest = $this->modulePath($slug);
        $this->removeTree($dest);
        return $this->copyTree($backup, $dest);
    }

    private function diskWritable($path)
    {
        if (self::$disk_check !== null) {
            return (bool) call_user_func(self::$disk_check, $path);
        }
        return is_writable($path);
    }

    private function copyTree($from, $to)
    {
        if (!is_dir($to) && !@mkdir($to, 0755, true) && !is_dir($to)) {
            return false;
        }
        $items = @scandir($from);
        if ($items === false) {
            return false;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $src = $from . '/' . $item;
            $dst = $to . '/' . $item;
            if (is_dir($src)) {
                if (!$this->copyTree($src, $dst)) {
                    return false;
                }
            } elseif (!@copy($src, $dst)) {
                return false;
            }
        }
        return true;
    }

    private function removeTree($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = @scandir($dir);
        if ($items !== false) {
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $path = $dir . '/' . $item;
                is_dir($path) ? $this->removeTree($path) : @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * Lance la mise à jour PrestaShop. Retourne null si réussie, sinon la raison de l'échec.
     *
     * PrestaShop refuse d'écrire depuis une tâche cron sans droits : on vérifie donc le disque
     * plutôt que de forcer, et on ne prétend avoir rien fait sinon.
     */
    private function runModuleUpgrade($slug)
    {
        if (!class_exists('Module')) {
            return 'La couche.modules de PrestaShop est indisponible.';
        }
        $module = $this->findModule($slug);
        if ($module === null) {
            return 'Module introuvable au moment de la mise à jour.';
        }
        try {
            if (method_exists($module, 'upgrade')) {
                $module->upgrade();   // installe la version du module présent dans modules/
            } else {
                return 'Ce module ne sait pas se mettre à jour lui-même.';
            }
        } catch (\Throwable $e) {
            return 'Erreur pendant la mise à jour : ' . $e->getMessage();
        }
        $module->clearCache();
        return null;
    }

    /** Explique un échec d'envoi en langage clair, avec l'action à mener. Ne cite jamais le token. */
    // === Adresse du back-office ===
    //
    // PrestaShop renomme le dossier d'administration à l'installation (admin123abc…) : SSM ne peut
    // pas le deviner. Le module le lit quand il tourne dans le back-office (_PS_ADMIN_DIR_), sinon
    // il cherche à la racine le dossier qui porte les fichiers propres au back-office.

    public function rememberAdminDir()
    {
        if (defined('_PS_ADMIN_DIR_')) {
            $dir = basename(rtrim((string) _PS_ADMIN_DIR_, '/\\'));
            if ($dir !== '' && $dir !== (string) Configuration::get('SSM_ADMIN_DIR')) {
                Configuration::updateValue('SSM_ADMIN_DIR', $dir);
            }
        }
    }

    public function isAdminDir($path)
    {
        return is_dir($path) && is_file($path . '/index.php')
            && (is_file($path . '/get-file-admin.php') || (is_file($path . '/init.php') && is_dir($path . '/filemanager')));
    }

    public function adminDir()
    {
        $this->rememberAdminDir();
        $root = rtrim((string) _PS_ROOT_DIR_, '/\\');
        $known = (string) Configuration::get('SSM_ADMIN_DIR');
        if ($known !== '' && strpbrk($known, '/\\') === false && $this->isAdminDir($root . '/' . $known)) {
            return $known;
        }
        $found = [];
        foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $path) {
            if ($this->isAdminDir($path)) {
                $found[] = basename($path);
            }
        }
        // Plusieurs candidats (copie de sauvegarde du dossier…) : on ne choisit pas au hasard.
        if (count($found) !== 1) {
            return null;
        }
        Configuration::updateValue('SSM_ADMIN_DIR', $found[0]);
        return $found[0];
    }

    public function adminUrl()
    {
        $dir = $this->adminDir();
        if ($dir === null) {
            return null;
        }
        $base = method_exists('Tools', 'getShopDomainSsl') ? Tools::getShopDomainSsl(true) : Tools::getShopDomain(true);
        $uri = defined('__PS_BASE_URI__') ? __PS_BASE_URI__ : '/';
        return rtrim((string) $base, '/') . '/' . trim($uri, '/') . (trim($uri, '/') === '' ? '' : '/') . rawurlencode($dir) . '/';
    }

    // === Connexion directe au back-office (seulement si SSM_CONNECTOR_ALLOW_LOGIN) ===
    //
    // 1. SSM remet une clé propre à la boutique dans la réponse au heartbeat ; elle est stockée chiffrée.
    // 2. Un lien SSM porte un jeton signé HMAC-SHA256 avec cette clé : site, demandeur, expiration à
    //    60 secondes, nonce. Il n'est accepté qu'une fois.
    // 3. Le module ouvre alors une session pour l'employé désigné par la boutique
    //    (SSM_CONNECTOR_LOGIN_EMPLOYEE), sinon le premier super-administrateur actif ; jamais un
    //    compte choisi par SSM.

    private static function cryptoKey()
    {
        return hash('sha256', 'ssmconnector-login|' . _COOKIE_KEY_, true);
    }

    public static function protect($plain)
    {
        if (!function_exists('sodium_crypto_secretbox')) {
            return $plain;
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return self::ENC_PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::cryptoKey()));
    }

    public static function reveal($stored)
    {
        $stored = (string) $stored;
        if (strpos($stored, self::ENC_PREFIX) !== 0) {
            return $stored === '' ? null : $stored;
        }
        if (!function_exists('sodium_crypto_secretbox_open')) {
            return null;
        }
        $raw = base64_decode(substr($stored, strlen(self::ENC_PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), self::cryptoKey());
        return $plain === false ? null : $plain;
    }

    public function loginKey()
    {
        return self::reveal((string) Configuration::get('SSM_LOGIN_KEY'));
    }

    private static function b64urlDecode($s)
    {
        return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    }

    /** Vérifie un jeton de connexion. Renvoie l'employé à connecter, ou la raison du refus (texte). */
    public function verifyLoginToken($token)
    {
        if (!self::loginAllowed()) {
            return 'connexion directe désactivée sur cette boutique';
        }
        $key = $this->loginKey();
        if (!$key) {
            return 'clé de connexion pas encore reçue de SSM';
        }
        $parts = explode('.', (string) $token);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return 'jeton illisible';
        }
        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $parts[0], $key, true)), '+/', '-_'), '=');
        if (!hash_equals($expected, $parts[1])) {
            return 'signature invalide';
        }
        $claims = json_decode((string) self::b64urlDecode($parts[0]), true);
        if (!is_array($claims) || !isset($claims['exp'], $claims['n'])) {
            return 'jeton incomplet';
        }
        $now = time();
        if ($now > (int) $claims['exp']) {
            return 'lien expiré (60 secondes)';
        }
        if ((int) $claims['exp'] - $now > self::LOGIN_MAX_AHEAD) {
            return 'expiration invalide';
        }
        $site_id = (int) Configuration::get('SSM_SITE_ID');
        if ($site_id && isset($claims['s']) && (int) $claims['s'] !== $site_id) {
            return 'lien destiné à un autre site';
        }
        // Nonces déjà servis : gardés jusqu'à leur expiration, puis oubliés.
        $used = json_decode((string) Configuration::get('SSM_LOGIN_NONCES'), true);
        $used = is_array($used) ? array_filter($used, function ($exp) use ($now) {
            return (int) $exp >= $now;
        }) : [];
        $nonce = hash('sha256', (string) $claims['n']);
        if (isset($used[$nonce])) {
            return 'lien déjà utilisé';
        }
        $used[$nonce] = (int) $claims['exp'];
        Configuration::updateValue('SSM_LOGIN_NONCES', json_encode(array_slice($used, -200, null, true)));

        $employee = $this->loginEmployee();
        if (!$employee) {
            return 'aucun employé à connecter (employé choisi introuvable ou inactif, ou aucun super-administrateur actif)';
        }
        $log = json_decode((string) Configuration::get('SSM_LOGIN_LOG'), true);
        $log = is_array($log) ? $log : [];
        array_unshift($log, ['at' => date('Y-m-d H:i:s'), 'by' => substr((string) ($claims['u'] ?? ''), 0, 64), 'as' => (string) $employee->email]);
        Configuration::updateValue('SSM_LOGIN_LOG', json_encode(array_slice($log, 0, self::LOGIN_LOG_MAX)));
        return $employee;
    }

    /** L'employé désigné (constante, sinon page du module), sinon le premier super-administrateur actif. */
    public function loginEmployee()
    {
        if (defined('SSM_CONNECTOR_LOGIN_EMPLOYEE') && SSM_CONNECTOR_LOGIN_EMPLOYEE) {
            $email = (string) SSM_CONNECTOR_LOGIN_EMPLOYEE;
            if (!Validate::isEmail($email)) {
                return null;
            }
            $employee = new Employee();
            return $employee->getByEmail($email) ? $employee : null;   // actifs seulement
        }
        $chosen = (int) Configuration::get('SSM_LOGIN_EMPLOYEE');   // choisi dans la page du module
        if ($chosen > 0) {
            $employee = new Employee($chosen);
            return Validate::isLoadedObject($employee) && $employee->active ? $employee : null;
        }
        $id = (int) Db::getInstance()->getValue('SELECT id_employee FROM ' . _DB_PREFIX_ . 'employee'
            . ' WHERE active = 1 AND id_profile = ' . (int) _PS_ADMIN_PROFILE_ . ' ORDER BY id_employee ASC');
        if (!$id) {
            return null;
        }
        $employee = new Employee($id);
        return Validate::isLoadedObject($employee) && $employee->active ? $employee : null;
    }

    /**
     * Ouvre la session du back-office, comme AdminLoginController::processLogin() de PrestaShop :
     * même cookie (psAdmin, mêmes durée et option SSL que config/config.inc.php), même session employé.
     */
    public function openBackOfficeSession($employee)
    {
        $lifetime = (int) Configuration::get('PS_COOKIE_LIFETIME_BO');
        if ($lifetime > 0) {
            $lifetime = time() + (max($lifetime, 1) * 3600);
        }
        $force_ssl = Configuration::get('PS_SSL_ENABLED') && Configuration::get('PS_SSL_ENABLED_EVERYWHERE');
        $cookie = new Cookie('psAdmin', '', $lifetime, null, false, $force_ssl);
        $employee->remote_addr = (int) ip2long(Tools::getRemoteAddr());
        $cookie->id_employee = (int) $employee->id;
        $cookie->email = $employee->email;
        $cookie->profile = $employee->id_profile;
        $cookie->passwd = $employee->passwd;
        $cookie->remote_addr = $employee->remote_addr;
        if (method_exists($cookie, 'registerSession') && class_exists('EmployeeSession')) {
            $cookie->registerSession(new EmployeeSession());
        }
        $cookie->last_activity = time();
        $cookie->write();
        if (class_exists('PrestaShopLogger')) {
            PrestaShopLogger::addLog('Connexion au back-office depuis SSM', 1, null, '', 0, true, (int) $employee->id);
        }
        return $cookie;
    }

    /** Tableau de bord du back-office, avec le jeton qu'AdminController attend pour cet employé. */
    public function dashboardUrl($employee)
    {
        $admin = $this->adminUrl();
        if ($admin === null) {
            return null;
        }
        $token = Tools::getAdminToken('AdminDashboard' . (int) Tab::getIdFromClassName('AdminDashboard') . (int) $employee->id);
        return $admin . 'index.php?controller=AdminDashboard&token=' . $token;
    }

    public function describeFailure($http_code, $errno, $body = '')
    {
        if ($errno === 6) {
            return $this->l('Adresse introuvable : vérifiez l\'adresse de SSM Core (faute de frappe ?).');
        }
        if (in_array($errno, [7, 28], true)) {
            return $this->l('SSM Core est injoignable (connexion refusée ou délai dépassé) : vérifiez l\'adresse et que ce serveur peut accéder à Internet.');
        }
        if (in_array($errno, [35, 51, 58, 60, 77], true)) {
            return $this->l('Certificat HTTPS invalide ou non reconnu par ce serveur : la connexion est refusée par sécurité.');
        }
        if ($http_code === 401) {
            return $this->l('Token refusé par SSM Core : collez le token généré par SSM Core pour cette boutique (Sites, bouton 🔌). Un nouveau token invalide l\'ancien.');
        }
        if ($http_code === 403) {
            return $this->l('Accès refusé (403) : un pare-feu ou un filtre devant SSM Core bloque peut-être cette boutique.');
        }
        if ($http_code === 404) {
            return $this->l('L\'API SSM est introuvable à cette adresse : vérifiez l\'adresse de SSM Core.');
        }
        if ($http_code === 422) {
            $detail = '';
            $json = json_decode((string) $body, true);
            if (is_array($json) && isset($json['detail'][0]) && is_array($json['detail'][0])) {
                $first = $json['detail'][0];
                $loc = isset($first['loc']) && is_array($first['loc']) ? implode('.', array_map('strval', $first['loc'])) : '';
                $detail = trim($loc . ' ' . (isset($first['msg']) ? (string) $first['msg'] : ''));
            }
            // Ce texte vient du serveur, pas de la boutique : il est rendu tel quel par
            // displayError(). On retire donc tout balisage plutôt que de l'échapper — un
            // échappement ici serait ré-échappé à l'affichage.
            $detail = trim(strip_tags($detail));
            $detail = preg_replace('/[\x00-\x1F\x7F]/u', '', $detail);
            return $this->l('Données refusées par SSM Core (422)') . ($detail !== '' ? ' : ' . $this->cut($detail, 200) : '') . '.';
        }
        if ($http_code === 429) {
            return $this->l('SSM Core limite temporairement les envois de cette boutique : réessayez dans quelques minutes.');
        }
        if ($http_code === 409) {
            // SSM Core refuse deux heartbeats simultanés pour la même boutique (IntegrityError).
            // C'est passager : l'inventaire n'est ni perdu ni corrompu, seul l'envoi est à refaire.
            return $this->l('Un envoi était déjà en cours côté SSM Core (409) : rien n\'est perdu, le prochain envoi repart normalement.');
        }
        if ($http_code === 413) {
            return $this->l('Inventaire trop volumineux pour SSM Core (413) : la boutique a un nombre inhabituel de modules ou de thèmes.');
        }
        if ($http_code >= 300 && $http_code < 400) {
            return $this->l('SSM Core redirige vers une autre adresse (non suivie par sécurité) : utilisez l\'adresse finale en https://.');
        }
        if ($http_code >= 500) {
            return sprintf($this->l('Erreur côté SSM Core (HTTP %d) : réessayez plus tard.'), $http_code);
        }
        return sprintf($this->l('Réponse inattendue de SSM Core (HTTP %d, erreur réseau %d).'), $http_code, $errno);
    }

    private function h($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
