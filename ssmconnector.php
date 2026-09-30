<?php
/**
 * SSM Connector — module natif PrestaShop 8/9
 *
 * @author Selest Informatique
 * @version 0.2.0
 */

if (!defined(\'_PS_VERSION_\')) {
    exit;
}

class Ssmconnector extends Module
{
    public function __construct()
    {
        $this->name = \'ssmconnector\';
        $this->tab = \'administration\';
        $this->version = \'0.2.0\';
        $this->author = \'Selest Informatique\';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = [\'min\' => \'8.0.0\', \'max\' => _PS_VERSION_];
        $this->bootstrap = true;
        parent::__construct();
        $this->displayName = $this->l(\'SSM Connector\');
        $this->description = $this->l(\'Connecteur SSM (Selest Site Manager) — inventaire, MAJ, logs, sécurité.\');
    }

    public function install()
    {
        return parent::install()
            && Configuration::updateValue(\'SSM_SSM_URL\', \'\')
            && Configuration::updateValue(\'SSM_HEARTBEAT_INTERVAL\', 1800)
            && Configuration::updateValue(\'SSM_LAST_HEARTBEAT_AT\', \'\')
            && Configuration::updateValue(\'SSM_HEARTBEAT_OK\', 0)
            && $this->generateToken()
            && $this->registerHooks();
    }

    public function uninstall()
    {
        return parent::uninstall()
            && Configuration::deleteByName(\'SSM_SSM_URL\')
            && Configuration::deleteByName(\'SSM_HEARTBEAT_INTERVAL\')
            && Configuration::deleteByName(\'SSM_LAST_HEARTBEAT_AT\')
            && Configuration::deleteByName(\'SSM_HEARTBEAT_OK\')
            && Configuration::deleteByName(\'SSM_CONNECTOR_TOKEN\')
            && Configuration::deleteByName(\'SSM_PENDING_EVENTS\');
    }

    private function generateToken()
    {
        if (!Configuration::get(\'SSM_CONNECTOR_TOKEN\')) {
            Configuration::updateValue(\'SSM_CONNECTOR_TOKEN\', bin2hex(random_bytes(32)));
        }
        return true;
    }

    private function registerHooks()
    {
        return $this->registerHook(\'actionCustomerLoginBefore\')
            && $this->registerHook(\'actionCustomerLoginAfter\')
            && $this->registerHook(\'actionEmployeeLoginBefore\')
            && $this->registerHook(\'actionEmployeeLoginAfter\')
            && $this->registerHook(\'actionOrderCreated\')
            && $this->registerHook(\'actionProductUpdate\')
            && $this->registerHook(\'actionModuleInstallAfter\')
            && $this->registerHook(\'actionModuleUninstallAfter\')
            && $this->registerHook(\'displayBackOfficeHeader\');
    }

    // === Hooks PrestaShop ===

    public function hookDisplayBackOfficeHeader($params)
    {
        $ok = (bool)Configuration::get(\'SSM_HEARTBEAT_OK\');
        $last = Configuration::get(\'SSM_LAST_HEARTBEAT_AT\');
        $color = $ok ? \'#22c55e\' : \'#ef4444\';
        $text = $ok ? \'SSM: OK\' : \'SSM: KO\';
        if ($last) $text .= \' (\' . Tools::substr($last, 11, 8) . \')\';
        return \'<span class="ssm-status-badge" style="background:\' . $color . \';color:white;padding:0.25rem 0.5rem;border-radius:4px;font-size:0.75rem;font-weight:600;">\' . $text . \'</span>\';
    }

    public function hookActionCustomerLoginAfter($params)
    {
        $this->queueEvent(\'customer_login\', [\'id_customer\' => isset($params[\'customer\']->id) ? $params[\'customer\']->id : null]);
    }

    public function hookActionCustomerLoginBefore($params)
    {
        $this->queueEvent(\'customer_login_attempt\', [
            \'email_hash\' => isset($params[\'email\']) ? substr(hash(\'sha256\', $params[\'email\']), 0, 8) : null,
        ]);
    }

    public function hookActionEmployeeLoginAfter($params)
    {
        $this->queueEvent(\'employee_login\', [
            \'id_employee\' => isset($params[\'employee\']->id) ? $params[\'employee\']->id : null,
            \'profile\' => isset($params[\'employee\']->id_profile) ? $params[\'employee\']->id_profile : null,
        ]);
    }

    public function hookActionOrderCreated($params)
    {
        $this->queueEvent(\'order_created\', [\'id_order\' => isset($params[\'order\']->id) ? $params[\'order\']->id : null]);
    }

    public function hookActionProductUpdate($params)
    {
        $this->queueEvent(\'product_update\', [\'id_product\' => isset($params[\'product\']->id) ? $params[\'product\']->id : null]);
    }

    public function hookActionModuleInstallAfter($params)
    {
        $this->queueEvent(\'module_install\', [\'module\' => isset($params[\'module\']->name) ? $params[\'module\']->name : null]);
    }

    public function hookActionModuleUninstallAfter($params)
    {
        $this->queueEvent(\'module_uninstall\', [\'module\' => isset($params[\'module\']->name) ? $params[\'module\']->name : null]);
    }

    // === Configuration page (back-office) ===

    public function getContent()
    {
        $output = \'\';
        if (Tools::isSubmit(\'submitSSMConfig\')) {
            Configuration::updateValue(\'SSM_SSM_URL\', Tools::getValue(\'SSM_SSM_URL\'));
            Configuration::updateValue(\'SSM_HEARTBEAT_INTERVAL\', (int)Tools::getValue(\'SSM_HEARTBEAT_INTERVAL\'));
            $output .= $this->displayConfirmation($this->l(\'Configuration enregistrée\'));
        }

        if (Tools::isSubmit(\'submitSSMHeartbeatNow\')) {
            $this->sendHeartbeat();
            $output .= $this->displayConfirmation($this->l(\'Heartbeat envoyé\'));
        }

        $url = Configuration::get(\'SSM_SSM_URL\');
        $interval = (int)Configuration::get(\'SSM_HEARTBEAT_INTERVAL\');
        $token = Configuration::get(\'SSM_CONNECTOR_TOKEN\');
        $last = Configuration::get(\'SSM_LAST_HEARTBEAT_AT\');
        $ok = (bool)Configuration::get(\'SSM_HEARTBEAT_OK\');

        $output .= \'<div class="panel"><div class="panel-heading"><i class="icon-cogs"></i> \' . $this->l(\'SSM Connector\') . \'</div>\';
        $output .= \'<form method="post">\';
        $output .= \'<div class="form-group"><label>URL SSM Core</label><input type="url" name="SSM_SSM_URL" value="\' . htmlspecialchars($url) . \'" class="input" placeholder="https://ssm.selest.info" required></div>\';
        $output .= \'<div class="form-group"><label>Intervalle heartbeat (s)</label><input type="number" name="SSM_HEARTBEAT_INTERVAL" value="\' . $interval . \'" min="300" max="86400"></div>\';
        $output .= \'<div class="form-group"><label>Token</label><code style="background:#f1f5f9;padding:0.5rem;display:block;">\' . htmlspecialchars($token ?: \'non généré\') . \'</code></div>\';
        $output .= \'<button type="submit" name="submitSSMConfig" class="btn btn-default">Enregistrer</button> \';
        $output .= \'<button type="submit" name="submitSSMHeartbeatNow" class="btn btn-primary">Heartbeat maintenant</button>\';
        $output .= \'</form>\';

        $output .= \'<div class="alert alert-\' . ($ok ? \'success\' : \'warning\') . \'"><strong>Statut :</strong> \' . ($ok ? \'Heartbeat OK\' : \'Pas de ping\');
        if ($last) $output .= \' — dernier : \' . htmlspecialchars($last);
        $output .= \'</div></div>\';

        return $output;
    }

    public function sendScheduledHeartbeat()
    {
        $this->sendHeartbeat();
    }

    private function sendHeartbeat()
    {
        $inventory = $this->collectInventory();
        $ok = $this->postToSSM(\'/api/v1/heartbeat\', $inventory);
        Configuration::updateValue(\'SSM_HEARTBEAT_OK\', $ok ? 1 : 0);
        Configuration::updateValue(\'SSM_LAST_HEARTBEAT_AT\', date(\'Y-m-d H:i:s\'));
    }

    private function collectInventory()
    {
        $db = Db::getInstance();
        $modules = [];
        $all_modules = $db->executeS(\'SELECT name, version, active FROM \' . _DB_PREFIX_ . \'module\');
        foreach ($all_modules as $m) {
            $modules[] = [
                \'type\' => \'module\',
                \'slug\' => $m[\'name\'],
                \'name\' => $m[\'name\'],
                \'version\' => $m[\'version\'],
                \'is_active\' => (bool)$m[\'active\'],
            ];
        }

        $themes = [];
        foreach (Theme::getThemes() as $theme) {
            $themes[] = [
                \'type\' => \'theme\',
                \'slug\' => $theme->name,
                \'name\' => $theme->getName(),
                \'version\' => $theme->getVersion() ?: \'1.0\',
                \'is_active\' => $theme->isActive(),
            ];
        }

        $stats = [
            \'customers\' => (int)$db->getValue(\'SELECT COUNT(*) FROM \' . _DB_PREFIX_ . \'customer\'),
            \'products\' => (int)$db->getValue(\'SELECT COUNT(*) FROM \' . _DB_PREFIX_ . \'product\'),
            \'orders\' => (int)$db->getValue(\'SELECT COUNT(*) FROM \' . _DB_PREFIX_ . \'orders\'),
            \'employees\' => (int)$db->getValue(\'SELECT COUNT(*) FROM \' . _DB_PREFIX_ . \'employee\'),
        ];

        return [
            \'cms\' => \'prestashop\',
            \'cms_version\' => _PS_VERSION_,
            \'php_version\' => phpversion(),
            \'mysql_version\' => $db->getVersion(),
            \'multistore\' => Shop::isFeatureActive(),
            \'shop_url\' => Tools::getShopDomain(true),
            \'ssl_enabled\' => Configuration::get(\'PS_SSL_ENABLED\'),
            \'debug_mode\' => (bool)_PS_MODE_DEV_,
            \'maintenance_mode\' => !(bool)Configuration::get(\'PS_SHOP_ENABLE\'),
            \'module_count\' => count($modules),
            \'module_active_count\' => count(array_filter($modules, fn($m) => $m[\'is_active\'])),
            \'theme_count\' => count($themes),
            \'modules\' => $modules,
            \'themes\' => $themes,
            \'stats\' => $stats,
            \'connector_version\' => $this->version,
            \'timestamp\' => date(\'c\'),
        ];
    }

    private function queueEvent($type, $payload)
    {
        $events = json_decode(Configuration::get(\'SSM_PENDING_EVENTS\') ?: \'[\', true) ?: [];
        $events[] = [\'type\' => $type, \'payload\' => $payload, \'timestamp\' => date(\'c\')];
        if (count($events) > 100) $events = array_slice($events, -100);
        Configuration::updateValue(\'SSM_PENDING_EVENTS\', json_encode($events));
    }

    private function postToSSM($path, $body)
    {
        $url = rtrim(Configuration::get(\'SSM_SSM_URL\'), \'/\') . $path;
        if (empty($url)) {
            error_log(\'[SSM Connector] URL not configured\');
            return false;
        }

        $token = Configuration::get(\'SSM_CONNECTOR_TOKEN\');
        $events = json_decode(Configuration::get(\'SSM_PENDING_EVENTS\') ?: \'[\', true) ?: [];
        $body[\'pending_events\'] = $events;
        Configuration::updateValue(\'SSM_PENDING_EVENTS\', \'[\');

        $payload = json_encode($body);
        $signature = hash_hmac(\'sha256\', $payload, $token);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                \'Content-Type: application/json\',
                \'X-SSM-Token: \' . $token,
                \'X-SSM-Signature: \' . $signature,
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
        return true;
    }
}
