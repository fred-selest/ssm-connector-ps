<?php
/**
 * SSM Connector — module natif PrestaShop
 *
 * @author Selest Informatique
 * @version 0.1.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class Ssmconnector extends Module
{
    public function __construct()
    {
        $this->name = 'ssmconnector';
        $this->tab = 'administration';
        $this->version = '0.1.0';
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
            && Configuration::updateValue('SSM_SSM_URL', '')
            && Configuration::updateValue('SSM_HEARTBEAT_INTERVAL', 1800)
            && $this->generateToken();
    }

    public function uninstall()
    {
        return parent::uninstall()
            && Configuration::deleteByName('SSM_SSM_URL')
            && Configuration::deleteByName('SSM_HEARTBEAT_INTERVAL')
            && Configuration::deleteByName('SSM_CONNECTOR_TOKEN');
    }

    private function generateToken()
    {
        if (!Configuration::get('SSM_CONNECTOR_TOKEN')) {
            Configuration::updateValue('SSM_CONNECTOR_TOKEN', bin2hex(random_bytes(32)));
        }
        return true;
    }

    public function getContent()
    {
        $output = '';
        if (Tools::isSubmit('submit'.$this->name)) {
            $ssm_url = Tools::getValue('SSM_SSM_URL');
            $interval = (int) Tools::getValue('SSM_HEARTBEAT_INTERVAL');
            if ($interval < 300) $interval = 300;
            Configuration::updateValue('SSM_SSM_URL', $ssm_url);
            Configuration::updateValue('SSM_HEARTBEAT_INTERVAL', $interval);
            $output .= $this->displayConfirmation($this->l('Settings saved'));
        }

        $fields_form = [
            'form' => [
                'legend' => ['title' => $this->l('SSM Connector Settings')],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->l('SSM Core URL'),
                        'name' => 'SSM_SSM_URL',
                        'size' => 60,
                        'required' => true,
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Heartbeat interval (seconds)'),
                        'name' => 'SSM_HEARTBEAT_INTERVAL',
                        'size' => 10,
                        'required' => true,
                    ],
                ],
                'submit' => ['title' => $this->l('Save')],
            ],
        ];
        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->title = $this->displayName;
        $helper->show_toolbar = false;
        $helper->toolbar_scroll = true;
        $helper->submit_action = 'submit' . $this->name;
        $helper->fields_value['SSM_SSM_URL'] = Configuration::get('SSM_SSM_URL');
        $helper->fields_value['SSM_HEARTBEAT_INTERVAL'] = Configuration::get('SSM_HEARTBEAT_INTERVAL');

        $token_display = '<div class="alert alert-info">'
            . '<p><strong>Token :</strong></p>'
            . '<code style="background:#f5f5f5; padding:8px; display:block; word-break:break-all;">'
            . Configuration::get('SSM_CONNECTOR_TOKEN') . '</code></div>';

        return $token_display . $helper->generateForm([$fields_form]);
    }

    public function collectInventory()
    {
        $modules = Module::getModulesOnDisk(true);
        $installed_modules = [];
        foreach ($modules as $module) {
            $installed_modules[] = [
                'slug' => $module->name,
                'name' => $module->displayName,
                'version' => $module->version,
                'is_active' => (bool) $module->active,
                'update_available' => false,
                'latest_version' => null,
            ];
        }
        $theme = $this->context->shop->theme->name ?? 'classic';
        return [
            'cms_type' => 'prestashop',
            'cms_version' => _PS_VERSION_,
            'php_version' => phpversion(),
            'db_version' => Db::getInstance()->getVersion(),
            'web_server' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
            'shop_url' => Configuration::get('PS_SHOP_DOMAIN_SSL'),
            'multiboutique' => Shop::isFeatureActive(),
            'theme' => [
                'slug' => $theme, 'name' => $theme,
                'version' => '1.0.0', 'is_active' => true,
            ],
            'extensions' => $installed_modules,
            'themes' => [$theme],
        ];
    }

    public function sendHeartbeat()
    {
        $ssm_url = Configuration::get('SSM_SSM_URL');
        if (empty($ssm_url)) return false;
        $token = Configuration::get('SSM_CONNECTOR_TOKEN');
        $inventory = $this->collectInventory();
        $ch = curl_init(rtrim($ssm_url, '/') . '/api/v1/heartbeat');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($inventory));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response === false || $http_code >= 400) {
            error_log("[SSM Connector] Heartbeat failed: HTTP $http_code");
            return false;
        }
        return true;
    }
}
