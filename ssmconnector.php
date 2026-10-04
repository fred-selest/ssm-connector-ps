<?php
/**
 * SSM Connector — module natif PrestaShop 8/9
 *
 * @author  Selest Informatique
 * @license MIT
 * @version 0.3.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class Ssmconnector extends Module
{
    const MAX_PENDING_EVENTS = 100;
    const MIN_INTERVAL = 300;
    const MAX_INTERVAL = 86400;
    const UPDATE_REPO = 'fred-selest/ssm-connector-ps';
    const UPDATE_TTL = 43200;

    public function __construct()
    {
        $this->name = 'ssmconnector';
        $this->tab = 'administration';
        $this->version = '0.3.0';
        $this->author = 'Selest Informatique';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;
        parent::__construct();
        $this->displayName = $this->l('SSM Connector');
        $this->description = $this->l('Connecteur SSM (Selest Site Manager) — inventaire, MAJ, logs, sécurité.');
    }

    public function install()
    {
        return parent::install()
            && $this->setupDefaults();
    }

    public function uninstall()
    {
        return parent::uninstall()
            && Configuration::deleteByName('SSM_SSM_URL')
            && Configuration::deleteByName('SSM_HEARTBEAT_INTERVAL')
            && Configuration::deleteByName('SSM_LAST_HEARTBEAT_AT')
            && Configuration::deleteByName('SSM_HEARTBEAT_OK')
            && Configuration::deleteByName('SSM_CONNECTOR_TOKEN')
            && Configuration::deleteByName('SSM_PENDING_EVENTS')
            && Configuration::deleteByName('SSM_UPDATE_CACHE');
    }

    /**
     * Valeurs par défaut + hooks. Idempotent : utilisé à l'installation
     * et par le script de mise à jour (upgrade/upgrade-0.3.0.php).
     */
    public function setupDefaults()
    {
        $defaults = [
            'SSM_SSM_URL' => '',
            'SSM_HEARTBEAT_INTERVAL' => 1800,
            'SSM_LAST_HEARTBEAT_AT' => '',
            'SSM_HEARTBEAT_OK' => 0,
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

        return $this->generateToken()
            && $this->registerHooks();
    }

    private function generateToken()
    {
        if (!Configuration::get('SSM_CONNECTOR_TOKEN')) {
            Configuration::updateValue('SSM_CONNECTOR_TOKEN', bin2hex(random_bytes(32)));
        }
        return true;
    }

    private function registerHooks()
    {
        if ($this->isRegisteredInHook('displayBackOfficeHeader')) {
            $this->unregisterHook('displayBackOfficeHeader');
        }

        return $this->registerHook('actionCustomerLoginBefore')
            && $this->registerHook('actionCustomerLoginAfter')
            && $this->registerHook('actionEmployeeLoginAfter')
            && $this->registerHook('actionOrderCreated')
            && $this->registerHook('actionProductUpdate')
            && $this->registerHook('actionModuleInstallAfter')
            && $this->registerHook('actionModuleUninstallAfter')
            && $this->registerHook('displayBackOfficeTop');
    }

    // === Hooks PrestaShop ===

    public function hookDisplayBackOfficeTop($params)
    {
        $ok = (bool) Configuration::get('SSM_HEARTBEAT_OK');
        $last = (string) Configuration::get('SSM_LAST_HEARTBEAT_AT');
        $color = $ok ? '#22c55e' : '#ef4444';
        $text = $ok ? 'SSM: OK' : 'SSM: KO';
        if ($last) {
            $text .= ' (' . Tools::substr($last, 11, 8) . ')';
        }
        return '<span class="ssm-status-badge" style="background:' . $color . ';color:white;padding:0.25rem 0.5rem;border-radius:4px;font-size:0.75rem;font-weight:600;">' . $this->h($text) . '</span>';
    }

    public function hookActionCustomerLoginAfter($params)
    {
        $this->queueEvent('customer_login', ['id_customer' => isset($params['customer']->id) ? $params['customer']->id : null]);
    }

    public function hookActionCustomerLoginBefore($params)
    {
        $this->queueEvent('customer_login_attempt', [
            'email_hash' => isset($params['email']) ? substr(hash('sha256', $params['email']), 0, 8) : null,
        ]);
    }

    public function hookActionEmployeeLoginAfter($params)
    {
        $this->queueEvent('employee_login', [
            'id_employee' => isset($params['employee']->id) ? $params['employee']->id : null,
            'profile' => isset($params['employee']->id_profile) ? $params['employee']->id_profile : null,
        ]);
    }

    public function hookActionOrderCreated($params)
    {
        $this->queueEvent('order_created', ['id_order' => isset($params['order']->id) ? $params['order']->id : null]);
    }

    public function hookActionProductUpdate($params)
    {
        $this->queueEvent('product_update', ['id_product' => isset($params['product']->id) ? $params['product']->id : null]);
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
        $output = '';
        if (Tools::isSubmit('submitSSMConfig')) {
            $url = trim((string) Tools::getValue('SSM_SSM_URL'));
            $interval = (int) Tools::getValue('SSM_HEARTBEAT_INTERVAL');
            $interval = max(self::MIN_INTERVAL, min(self::MAX_INTERVAL, $interval));

            if (!preg_match('#^https?://#i', $url) || !Validate::isAbsoluteUrl($url)) {
                $output .= $this->displayError($this->l('URL SSM Core invalide (http:// ou https:// attendu)'));
            } else {
                Configuration::updateValue('SSM_SSM_URL', $url);
                Configuration::updateValue('SSM_HEARTBEAT_INTERVAL', $interval);
                $output .= $this->displayConfirmation($this->l('Configuration enregistrée'));
            }
        }

        if (Tools::isSubmit('submitSSMHeartbeatNow')) {
            if ($this->sendHeartbeat()) {
                $output .= $this->displayConfirmation($this->l('Heartbeat envoyé'));
            } else {
                $output .= $this->displayError($this->l('Échec de l\'envoi du heartbeat (voir les logs PHP)'));
            }
        }

        $update = $this->getUpdateInfo(Tools::isSubmit('submitSSMCheckUpdate'));

        $url = (string) Configuration::get('SSM_SSM_URL');
        $interval = (int) Configuration::get('SSM_HEARTBEAT_INTERVAL');
        $token = (string) Configuration::get('SSM_CONNECTOR_TOKEN');
        $last = (string) Configuration::get('SSM_LAST_HEARTBEAT_AT');
        $ok = (bool) Configuration::get('SSM_HEARTBEAT_OK');
        $cron_url = $this->context->link->getModuleLink($this->name, 'cron');

        $output .= '<div class="panel"><div class="panel-heading"><i class="icon-cogs"></i> ' . $this->h($this->l('SSM Connector')) . '</div>';
        $output .= $this->renderUpdateNotice($update);
        $output .= '<form method="post">';
        $output .= '<div class="form-group"><label>URL SSM Core</label><input type="url" name="SSM_SSM_URL" value="' . $this->h($url) . '" class="input" placeholder="https://ssm.example.com" required></div>';
        $output .= '<div class="form-group"><label>Intervalle heartbeat (s)</label><input type="number" name="SSM_HEARTBEAT_INTERVAL" value="' . $interval . '" min="' . self::MIN_INTERVAL . '" max="' . self::MAX_INTERVAL . '"></div>';
        $output .= '<div class="form-group"><label>Token</label><code style="background:#f1f5f9;padding:0.5rem;display:block;word-break:break-all;">' . $this->h($token ?: 'non généré') . '</code></div>';
        $output .= '<div class="form-group"><label>URL cron (appel périodique)</label><code style="background:#f1f5f9;padding:0.5rem;display:block;word-break:break-all;">curl -fsS -H "X-SSM-Token: &lt;token&gt;" ' . $this->h($cron_url) . '</code></div>';
        $output .= '<button type="submit" name="submitSSMConfig" class="btn btn-default">Enregistrer</button> ';
        $output .= '<button type="submit" name="submitSSMHeartbeatNow" class="btn btn-primary">Heartbeat maintenant</button> ';
        $output .= '<button type="submit" name="submitSSMCheckUpdate" class="btn btn-default" formnovalidate>Vérifier les mises à jour</button>';
        $output .= '</form>';

        $output .= '<div class="alert alert-' . ($ok ? 'success' : 'warning') . '"><strong>Statut :</strong> ' . ($ok ? 'Heartbeat OK' : 'Pas de ping');
        if ($last) {
            $output .= ' — dernier : ' . $this->h($last);
        }
        $output .= '</div></div>';

        return $output;
    }

    // === Heartbeat ===

    /**
     * Appelé par le contrôleur cron : n'envoie que si l'intervalle est écoulé
     * (ou si $force). Retourne l'état pour la réponse du contrôleur.
     */
    public function sendScheduledHeartbeat($force = false)
    {
        $interval = max(self::MIN_INTERVAL, (int) Configuration::get('SSM_HEARTBEAT_INTERVAL'));
        $last = strtotime((string) Configuration::get('SSM_LAST_HEARTBEAT_AT'));

        if (!$force && $last && (time() - $last) < $interval) {
            return ['status' => 'skipped', 'next_in' => $interval - (time() - $last)];
        }

        return ['status' => $this->sendHeartbeat() ? 'sent' : 'failed'];
    }

    private function sendHeartbeat()
    {
        $ok = $this->postToSSM('/api/v1/heartbeat', $this->collectInventory());
        Configuration::updateValue('SSM_HEARTBEAT_OK', $ok ? 1 : 0);
        Configuration::updateValue('SSM_LAST_HEARTBEAT_AT', date('Y-m-d H:i:s'));
        return $ok;
    }

    private function collectInventory()
    {
        $db = Db::getInstance();
        $modules = [];
        $all_modules = $db->executeS('SELECT name, version, active FROM ' . _DB_PREFIX_ . 'module') ?: [];
        foreach ($all_modules as $m) {
            $modules[] = [
                'type' => 'module',
                'slug' => $m['name'],
                'name' => $m['name'],
                'version' => $m['version'],
                'is_active' => (bool) $m['active'],
            ];
        }

        $themes = $this->collectThemes();
        $update = $this->getUpdateInfo();

        $stats = [
            'customers' => (int) $db->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'customer'),
            'products' => (int) $db->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'product'),
            'orders' => (int) $db->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'orders'),
            'employees' => (int) $db->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'employee'),
        ];

        return [
            'cms' => 'prestashop',
            'cms_version' => _PS_VERSION_,
            'php_version' => phpversion(),
            'mysql_version' => $db->getVersion(),
            'multistore' => (bool) Shop::isFeatureActive(),
            'shop_url' => Tools::getShopDomain(true),
            'ssl_enabled' => (bool) Configuration::get('PS_SSL_ENABLED'),
            'debug_mode' => (bool) _PS_MODE_DEV_,
            'maintenance_mode' => !(bool) Configuration::get('PS_SHOP_ENABLE'),
            'module_count' => count($modules),
            'module_active_count' => count(array_filter($modules, function ($m) {
                return $m['is_active'];
            })),
            'theme_count' => count($themes),
            'modules' => $modules,
            'themes' => $themes,
            'stats' => $stats,
            'connector_version' => $this->version,
            'latest_connector_version' => $update['latest'],
            'connector_update_available' => $update['available'],
            'timestamp' => date('c'),
        ];
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
        if (!preg_match('/^v?(\d+\.\d+\.\d+)$/', isset($release['tag_name']) ? (string) $release['tag_name'] : '', $m)) {
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
            return '<div class="alert alert-warning">Vérification des mises à jour impossible pour le moment (v' . $this->h($update['current']) . ' installée).</div>';
        }

        return '<p class="help-block">Connecteur à jour (v' . $this->h($update['current']) . ')'
            . ($update['checked_at'] ? ' — vérifié le ' . $this->h(date('Y-m-d H:i', $update['checked_at'])) : '') . '.</p>';
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
                        'type' => 'theme',
                        'slug' => $slug,
                        'name' => $theme->name,
                        'version' => $version ?: '1.0',
                        'is_active' => $slug === $active,
                    ];
                }
            }
            if (!$themes && $active) {
                $themes[] = ['type' => 'theme', 'slug' => $active, 'name' => $active, 'version' => '1.0', 'is_active' => true];
            }
        } catch (Throwable $e) {
            error_log('[SSM Connector] Theme inventory failed: ' . $e->getMessage());
        }
        return $themes;
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

    private function queueEvent($type, $payload)
    {
        $events = $this->getPendingEvents();
        $events[] = ['type' => $type, 'payload' => $payload, 'timestamp' => date('c')];
        $this->setPendingEvents($events);
    }

    // === Transport ===

    private function postToSSM($path, $body)
    {
        $base = rtrim((string) Configuration::get('SSM_SSM_URL'), '/');
        if ($base === '') {
            error_log('[SSM Connector] URL not configured');
            return false;
        }

        $token = (string) Configuration::get('SSM_CONNECTOR_TOKEN');
        $events = $this->getPendingEvents();
        $body['pending_events'] = $events;

        $payload = json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE);
        if ($payload === false) {
            error_log('[SSM Connector] JSON encoding failed: ' . json_last_error_msg());
            return false;
        }
        $signature = hash_hmac('sha256', $payload, $token);

        $ch = curl_init($base . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-SSM-Token: ' . $token,
                'X-SSM-Signature: ' . $signature,
            ],
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response === false || $http_code >= 400) {
            error_log("[SSM Connector] POST $path failed: HTTP $http_code");
            return false;
        }

        // Succès : on retire uniquement les événements envoyés (d'autres ont pu être ajoutés entre-temps)
        $this->setPendingEvents(array_slice($this->getPendingEvents(), count($events)));
        return true;
    }

    private function h($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
