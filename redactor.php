<?php
/**
* 2007-2023 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author    PrestaShop SA <contact@prestashop.com>
*  @copyright 2007-2023 PrestaShop SA
*  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

if (!defined('_PS_VERSION_')) {
    exit;
}

// ABAJO SQL CREAR TABLA LAFRIPS_REDACTOR_DESCRIPCION

require_once(dirname(__FILE__).'/classes/Redactame.php');
require_once(dirname(__FILE__).'/classes/OpenAIRedactor.php');

class Redactor extends Module
{
    protected $config_form = false;

    public function __construct()
    {
        $this->name = 'redactor';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'Sergio';
        $this->need_instance = 0;

        /**
         * Set $this->bootstrap to true if your module is compliant with bootstrap (PrestaShop 1.6)
         */
        $this->bootstrap = true;

        parent::__construct();

        $this->admin_tab[] = array('classname' => 'AdminRedactorDescripciones', 'parent' => 'AdminCatalog', 'displayname' => 'Redactor Descripciones');

        $this->displayName = $this->l('Redactor');
        $this->description = $this->l('Generar descripciones mediante la API de redacta.me');

        $this->confirmUninstall = $this->l('¿Me vas a desinstalar?');

        $this->ps_versions_compliancy = array('min' => '1.6', 'max' => '1.7');

        // ini_set('error_log', '/var/www/vhost/lafrikileria.com/home/html/test/modules/redactor/log/error.log');

        // // Turn on error reporting
        // ini_set('display_errors', 1);
        // // error_reporting(E_ALL); cambiamos para que no saque E_NOTICE
        // error_reporting(E_ERROR | E_WARNING | E_PARSE | E_DEPRECATED | E_STRICT);
    }

    /**
     * Don't forget to create update methods if needed:
     * http://doc.prestashop.com/display/PS16/Enabling+the+Auto-Update
     */
    public function install()
    {
        Configuration::updateValue('REDACTOR_LIVE_MODE', false);

        //añadimos link en pestaña de productos llamando a installTab
        foreach ($this->admin_tab as $tab) {
            $this->installTab($tab['classname'], $tab['parent'], $this->name, $tab['displayname']);
        }

        return parent::install() &&
            $this->registerHook('header') &&
            $this->registerHook('displayBackOfficeHeader');
    }

    public function uninstall()
    {
        Configuration::deleteByName('REDACTOR_LIVE_MODE');

        //desinstalar el link de la pestaña productos llamando a unistallTab
        foreach ($this->admin_tab as $tab) {
            $this->unInstallTab($tab['classname']);
        }            

        return parent::uninstall();
    }

    /*
     * Crear el link en pestañas
     */    
    protected function installTab($classname = false, $parent = false, $module = false, $displayname = false) {
        if (!$classname)
            return true;

        $tab = new Tab();
        $tab->class_name = $classname;
        if ($parent)
            if (!is_int($parent))
                $tab->id_parent = (int) Tab::getIdFromClassName($parent);
            else
                $tab->id_parent = (int) $parent;
        if (!$module)
            $module = $this->name;
        $tab->module = $module;
        $tab->active = true;
        if (!$displayname)
            $displayname = $this->displayName;
        $tab->name[(int) (Configuration::get('PS_LANG_DEFAULT'))] = $displayname;

        if (!$tab->add())
            return false;

        return true;
    }

    /*
     * Quitar el link en pestañas
     */
    protected function unInstallTab($classname = false) {
        if (!$classname)
            return true;

        $idTab = Tab::getIdFromClassName($classname);
        if ($idTab) {
            $tab = new Tab($idTab);
            return $tab->delete();
            ;
        }
        return true;
    }

    /**
     * Load the configuration form
     */
    public function getContent()
    {
        /**
         * If values have been submitted in the form, process.
         */
        if (((bool)Tools::isSubmit('submitRedactorModule')) == true) {
            $this->postProcess();
        }

        $this->context->smarty->assign('module_dir', $this->_path);

        $output = $this->context->smarty->fetch($this->local_path.'views/templates/admin/configure.tpl');

        return $output.$this->renderForm();
    }

    /**
     * Create the form that will be displayed in the configuration of your module.
     */
    protected function renderForm()
    {
        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitRedactorModule';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            .'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFormValues(), /* Add values for your inputs */
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );

        return $helper->generateForm(array($this->getConfigForm()));
    }

    /**
     * Create the structure of your form.
     */
    protected function getConfigForm()
    {
        return array(
            'form' => array(
                'legend' => array(
                'title' => $this->l('Settings'),
                'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'col' => 5,
                        'type' => 'select',                        
                        'desc' => 'Modelo de GPT a utilizar en las peticiones',
                        'name' => 'REDACTOR_OPENAI_MODEL',
                        'label' => 'Selecciona Modelo',
                        'options' => array(
                            'query' => array(
                                array('id_option' => 'gpt-4o', 'name' => 'GPT-4o'),  
                                array('id_option' => 'gpt-4o-mini', 'name' => 'GPT-4o-mini')  
                            ),
                            'id' => 'id_option',  // Campo que representa el valor de la opción
                            'name' => 'name'  // Campo que representa el texto visible
                        )
                    ),
                    array(
                        'col' => 5,
                        'row' => 25,
                        'type' => 'textarea',
                        'prefix' => '<i class="icon icon-edit"></i>',
                        'desc' => 'Contexto para GPT de OpenAI',
                        'name' => 'REDACTOR_OPENAI_SYSTEM_ROLE_CONTEXT',
                        'label' => 'Contexto para rol de sistema',
                        // 'autoload_rte' => true,  // Habilita el editor enriquecido (TinyMCE), vamos a guardar un texto con etiqeutas html y van a desaparecer al insertar en la tabla de configuración si no hacemos esto, además de permitir html en el getValue() de abajo y en updateValue() - LO QUITO, se comporta como un editor, aplicando html. Lo que haré será meter solo el nombre de cada etiqueta asi: strrong, h1, h2 ...
                    ),
                    array(
                        'col' => 5,
                        'type' => 'text',
                        'prefix' => '<i class="icon icon-align-center"></i>',
                        'desc' => 'Número máximo de tokens que serán utilizados para la respuesta. No incluyen los del prompt y la suma total de ambos no debe superar el límite del modelo en uso (mantengámoslo en unos 4000 máximo)',
                        'name' => 'REDACTOR_OPENAI_MAX_TOKENS',
                        'label' => 'Max Tokens',
                    ),
                    array(
                        'col' => 5,
                        'type' => 'text',
                        'prefix' => '<i class="icon icon-question"></i>',
                        'desc' => 'Controla la aleatoriedad y creatividad en las respuestas del modelo. Valores más bajos dan respuestas más determinísticas y conservadoras, valores más altos, más creativas, variadas e impredecibles.',
                        'name' => 'REDACTOR_OPENAI_TEMPERATURE',
                        'label' => 'Temperature',
                    ),
                    // array(
                    //     'type' => 'switch',
                    //     'label' => $this->l('Live mode'),
                    //     'name' => 'REDACTOR_LIVE_MODE',
                    //     'is_bool' => true,
                    //     'desc' => $this->l('Use this module in live mode'),
                    //     'values' => array(
                    //         array(
                    //             'id' => 'active_on',
                    //             'value' => true,
                    //             'label' => $this->l('Enabled')
                    //         ),
                    //         array(
                    //             'id' => 'active_off',
                    //             'value' => false,
                    //             'label' => $this->l('Disabled')
                    //         )
                    //     ),
                    // ),
                    // array(
                    //     'col' => 3,
                    //     'type' => 'text',
                    //     'prefix' => '<i class="icon icon-envelope"></i>',
                    //     'desc' => $this->l('Enter a valid email address'),
                    //     'name' => 'REDACTOR_ACCOUNT_EMAIL',
                    //     'label' => $this->l('Email'),
                    // ),
                    // array(
                    //     'type' => 'password',
                    //     'name' => 'REDACTOR_ACCOUNT_PASSWORD',
                    //     'label' => $this->l('Password'),
                    // ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );
    }

    /**
     * Set values for the inputs.
     */
    protected function getConfigFormValues()
    {
        return array(
            'REDACTOR_OPENAI_SYSTEM_ROLE_CONTEXT' => Configuration::get('REDACTOR_OPENAI_SYSTEM_ROLE_CONTEXT', null),
            'REDACTOR_OPENAI_MAX_TOKENS' => Configuration::get('REDACTOR_OPENAI_MAX_TOKENS', 1000),
            'REDACTOR_OPENAI_TEMPERATURE' => Configuration::get('REDACTOR_OPENAI_TEMPERATURE', 0.7),
            'REDACTOR_OPENAI_MODEL' => Configuration::get('REDACTOR_OPENAI_MODEL', 'gpt-4o'),
            // 'REDACTOR_LIVE_MODE' => Configuration::get('REDACTOR_LIVE_MODE', true),
            // 'REDACTOR_ACCOUNT_EMAIL' => Configuration::get('REDACTOR_ACCOUNT_EMAIL', 'contact@prestashop.com'),
            // 'REDACTOR_ACCOUNT_PASSWORD' => Configuration::get('REDACTOR_ACCOUNT_PASSWORD', null),
        );
    }

    /**
     * Save form data.
     */
    protected function postProcess()
    {
        $form_values = $this->getConfigFormValues();

        foreach (array_keys($form_values) as $key) {
            Configuration::updateValue($key, Tools::getValue($key));
            // Configuration::updateValue($key, Tools::getValue($key, true), true); //true en getValue() = permitir html, true en updateValue() = sin filtrado. OJO que lo estamos haceindo para todos los inputs  NO FUNCIONA BIEN, guarda las etiquetas pero repite algunas ¿? Las guardo solo con su nombre : strong, h1, br, etc
        }
    }

    /**
    * Add the CSS & JavaScript files you want to be loaded in the BO.
    */
    public function hookDisplayBackOfficeHeader()
    {
        if (Tools::getValue('configure') == $this->name) {
            $this->context->controller->addJS($this->_path.'views/js/back.js');
            $this->context->controller->addCSS($this->_path.'views/css/back.css');
        }
    }

    /**
     * Add the CSS & JavaScript files you want to be added on the FO.
     */
    public function hookHeader()
    {
        $this->context->controller->addJS($this->_path.'/views/js/front.js');
        $this->context->controller->addCSS($this->_path.'/views/css/front.css');
    }

    /*

    CREATE TABLE `lafrips_redactor_descripcion` (
        `id_redactor_descripcion` int(10) NOT NULL AUTO_INCREMENT,
        `id_product` int(10) NOT NULL, 
        `api` varchar(16) NOT NULL, # redacta-me o openai
        `procesando` tinyint(1) NOT NULL,
        `inicio_proceso` datetime NOT NULL,
        `api_json` text, 
        `en_cola` tinyint(1) NOT NULL,
        `date_metido_cola` datetime NOT NULL,
        `id_employee_metido_cola` int(10) NOT NULL, 
        `date_eliminado_cola` datetime NOT NULL,
        `id_employee_eliminado_cola` int(10) NOT NULL, 
        `redactado` tinyint(1) NOT NULL,  
        `redactado_api` varchar(16) NOT NULL, # redacta-me o openai, cuando se marca redactado se guarda aquí la api, porque 'api' puede cambiar de nuevo
        `revisado` tinyint(1) NOT NULL,  
        `date_redactado` datetime NOT NULL,
        `id_employee_redactado` int(10) NOT NULL, 
        `date_revisado` datetime NOT NULL,
        `id_employee_revisado` int(10) NOT NULL,
        `error` tinyint(1) NOT NULL,
        `date_error` datetime NOT NULL,
        `error_message` text NOT NULL,
        `descripcion` text NOT NULL, 
        `date_add` datetime NOT NULL,
        `date_upd` datetime NOT NULL,
        PRIMARY KEY (`id_redactor_descripcion`),
        UNIQUE (`id_product`)
        ) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8;

        ALTER TABLE lafrips_redactor_descripcion ADD INDEX id_producto (id_product);

    */
}

