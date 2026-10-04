<?php
/**
 * SSM Connector — module natif PrestaShop 8/9
 *
 * @author  Selest Informatique
 * @license MIT
 * @version 0.4.2
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
    const UPDATE_REPO = 'fred-selest/ssm-connector-ps';
    const UPDATE_TTL = 43200;

    public function __construct()
    {
        $this->name = 'ssmconnector';
        $this->tab = 'administration';
        $this->version = '0.4.2';
        $this->author = 'Selest Informatique';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;
        parent::__construct();
        $this->displayName = $this->l('SSM Connector');
        $this->description = $this->l('Connecteur SSM (Selest Site Manager) : envoie l\'inventaire de la boutique à SSM Core, sans rien modifier.');
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
            'SSM_PENDING_EVENTS', 'SSM_UPDATE_CACHE', 'SSM_CRON_FAILS',
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

        $output .= '<button type="submit" name="submitSSMConfig" class="btn btn-primary">Enregistrer et tester la connexion</button> ';
        $output .= '<button type="submit" name="submitSSMHeartbeatNow" class="btn btn-default">Tester maintenant</button> ';
        $output .= '<button type="submit" name="submitSSMCheckUpdate" class="btn btn-default">Vérifier les mises à jour</button>';
        $output .= '</form></div>';

        $output .= $this->renderSecurityPanel();
        $output .= $this->renderScripts();

        return $output;
    }

    private function processForms()
    {
        $actions = ['submitSSMConfig', 'submitSSMHeartbeatNow', 'submitSSMCheckUpdate'];
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
                return $this->displayConfirmation($this->l('Configuration enregistrée')) . $this->testConnection();

            case 'submitSSMHeartbeatNow':
                return $this->testConnection();

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
            $banner = '<div class="alert alert-success"><strong>Connecté à SSM Core</strong>' . ($last ? ' — dernier échange le ' . $this->h($last) : '') . '</div>';
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
     * Inventaire envoyé à SSM Core. Les clés lues par SSM Core sont en tête (cms_version, php_version, db_version, web_server,
     * hostname, site_path, extensions, themes) ; le reste est informatif. Les longueurs sont celles de SSM Core (app/schemas.py).
     */
    private function collectInventory()
    {
        $db = Db::getInstance();
        $extensions = [];
        $all_modules = $db->executeS('SELECT name, version, active FROM ' . _DB_PREFIX_ . 'module') ?: [];
        foreach ($all_modules as $m) {
            $extensions[] = [
                'slug' => $this->cut($m['name'], 255),
                'name' => $this->cut($m['name'], 255),
                'version' => $this->cutOrNull($m['version'], 50),
                'is_active' => (bool) $m['active'],
            ];
        }
        $active_count = count(array_filter($extensions, function ($m) {
            return $m['is_active'];
        }));
        $extensions = array_slice($extensions, 0, 1000);

        $themes = array_slice($this->collectThemes(), 0, 200);
        $update = $this->getUpdateInfo();

        $stats = [
            'customers' => (int) $db->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'customer'),
            'products' => (int) $db->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'product'),
            'orders' => (int) $db->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'orders'),
            'employees' => (int) $db->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'employee'),
        ];

        return [
            // lus par SSM Core
            'cms_version' => $this->cut(_PS_VERSION_, 50),
            'php_version' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION,
            'db_version' => $this->cutOrNull($db->getVersion(), 50),
            'web_server' => isset($_SERVER['SERVER_SOFTWARE']) ? $this->cutOrNull($_SERVER['SERVER_SOFTWARE'], 50) : null,
            'hostname' => function_exists('gethostname') ? $this->cutOrNull(gethostname(), 255) : null,
            'site_path' => defined('_PS_ROOT_DIR_') ? $this->cutOrNull(_PS_ROOT_DIR_, 500) : null,
            'extensions' => $extensions,
            'themes' => $themes,
            // informatifs (ignorés par SSM Core pour l'instant)
            'cms' => 'prestashop',
            'multistore' => (bool) Shop::isFeatureActive(),
            'shop_url' => Tools::getShopDomain(true),
            'ssl_enabled' => (bool) Configuration::get('PS_SSL_ENABLED'),
            'debug_mode' => (bool) _PS_MODE_DEV_,
            'maintenance_mode' => !(bool) Configuration::get('PS_SHOP_ENABLE'),
            'module_count' => count($extensions),
            'module_active_count' => $active_count,
            'theme_count' => count($themes),
            'stats' => $stats,
            'connector_version' => $this->version,
            'latest_connector_version' => $update['latest'],
            'connector_update_available' => $update['available'],
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
                    $slug = !empty($theme->directory) ? $theme->directory : $theme->name;
                    $version = method_exists($theme, 'getVersion') ? $theme->getVersion() : null;
                    $themes[] = [
                        'slug' => $this->cut($slug, 255),
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
     * Met un événement en file. Un événement identique au précédent, à moins d'une minute, n'est pas répété
     * (ou, avec $throttle, tout événement du même type) : une attaque par force brute ou un import de produits
     * n'écrit pas en base à chaque requête.
     */
    private function queueEvent($type, $payload, $throttle = false)
    {
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
        $events = $this->getPendingEvents();
        $body['pending_events'] = $events;

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
        return ['ok' => true, 'http_code' => $http_code, 'hint' => ''];
    }

    /** Explique un échec d'envoi en langage clair, avec l'action à mener. Ne cite jamais le token. */
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
            return $this->l('Données refusées par SSM Core (422)') . ($detail !== '' ? ' : ' . $this->cut($detail, 200) : '') . '.';
        }
        if ($http_code === 429) {
            return $this->l('SSM Core limite temporairement les envois de cette boutique : réessayez dans quelques minutes.');
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
