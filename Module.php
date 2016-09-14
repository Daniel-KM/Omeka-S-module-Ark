<?php
namespace Ark;

use Omeka\Module\AbstractModule;
use Zend\ServiceManager\ServiceLocatorInterface;
use Zend\View\Renderer\PhpRenderer;
use Zend\Mvc\Controller\AbstractController;
use Zend\EventManager\SharedEventManagerInterface;
use Ark\Form\ConfigForm;
use Ark\Ark\Name\Noid;

require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'src/Ark/Name/Noid.php';

/**
 * Ark
 *
 * Creates and manages unique, universel and persistent ark identifiers.
 *
 * @copyright Daniel Berthereau, 2015-2016
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */

/**
 * The Ark plugin.
 * @package Omeka\Plugins\Ark
 */
class Module extends AbstractModule
{
    /**
     * @var array This plugin's filters.
     */
    protected $_filters = array(
        'ark_format_names',
        'ark_format_qualifiers',
        'filterDisplayCollectionDublinCoreIdentifier' => array('Display', 'Collection', 'Dublin Core', 'Identifier'),
        'filterDisplayItemDublinCoreIdentifier' => array('Display', 'Item', 'Dublin Core', 'Identifier'),
    );

    /**
     * @var array This plugin's options.
     */
    protected $_options = array(
        'ark_protocol' => 'ark:',
        // 12345 means example and 99999 means test.
        'ark_naan' => '99999',
        'ark_naa' => 'example.org',
        'ark_subnaa' => 'sub',
        'ark_format_name' => 'noid',
        'ark_noid_database' => '',
        'ark_noid_template' => '.zek',
        'ark_format_qualifier' => 'order',
        'ark_file_variants' => 'original fullsize thumbnail square_thumbnail',
        'ark_note' => '',
        'ark_policy_statement' => 'erc-support:
who: Our Institution
what: Permanent: Stable Content:
when: 20160101
where: http://example.com/ark:/99999/',
        // From the policy statement of the California Digital Library.
        'ark_policy_main' => 'Our institution assigns identifiers within the ARK domain under the NAAN 99999 and according to the following principles:

* No ARK shall be re-assigned; that is, once an ARK-to-object association has been made public, that association shall be considered unique into the indefinite future.
* To help them age and travel well, the Name part of our institution-assigned ARKs shall contain no widely recognizable semantic information (to the extent possible).
* Our institution-assigned ARKs shall be generated with a terminal check character that guarantees them against single character errors and transposition errors.',
        'ark_use_public' => true,
        'ark_use_admin' => false,
        'ark_display_public' => '<a href="WEB_ROOT/%1$s">%1$s</a>',
        'ark_display_admin' => '<a href="WEB_ROOT/admin/%1$s">%1$s</a>',
        'ark_routes_ini' => false,
    );

    /**
     * Installs the plugin.
     */
    public function install(ServiceLocatorInterface $serviceLocator)
    {
        $length = 32;
        $this->_installOptions($serviceLocator);
        $processor = $this->_getArkProcessor();
        if (!$processor->isDatabaseCreated())
            $result = $processor->createDatabase();
    }

    protected function _installOptions($serviceLocator) {
        foreach ($this->_options as $key => $value) {
            $serviceLocator->get('Omeka\Settings')->set($key, $value);
        }
    }


    protected function _uninstallOptions($serviceLocator) {
        foreach ($this->_options as $key => $value) {
            $serviceLocator->get('Omeka\Settings')->delete($key);
        }
    }


    /**
     * Uninstalls the plugin.
     */
    public function uninstall(ServiceLocatorInterface $serviceLocator)
    {
        $this->_uninstallOptions($serviceLocator);
    }

    /**
     * Shows plugin configuration page.
     */
    public function getConfigForm(PhpRenderer $renderer)
    {
        $forms = $this->getServiceLocator()->get('FormElementManager');
        $form = $forms->get(ConfigForm::class);
        return $renderer->render('plugins/ark-config-form',
                                 [ 'form'  => $form ]);

    }

    /**
     * Saves plugin configuration page.
     *
     * @param array Options set in the config form.
     */
    public function handleConfigForm(AbstractController $controller)
    {
        $post = $args['post'];

        // Fill the disabled fields to avoid notices.
        $post['ark_protocol'] = isset($post['ark_protocol']) ? $post['ark_protocol'] : get_option($post['ark_protocol']);
        $post['ark_naan'] = isset($post['ark_naan']) ? $post['ark_naan'] : get_option('ark_naan');
        $post['ark_naa'] = isset($post['ark_naa']) ? $post['ark_naa'] : get_option('ark_naa');
        $post['ark_subnaa'] = isset($post['ark_subnaa']) ? $post['ark_subnaa'] : get_option('ark_subnaa');
        $post['ark_noid_database'] = isset($post['ark_noid_database']) ? $post['ark_noid_database'] : get_option('ark_noid_database');
        $post['ark_noid_template'] = isset($post['ark_noid_template']) ? $post['ark_noid_template'] : get_option('ark_noid_template');

        $post['ark_web_root'] = empty($post['ark_web_root']) ? $this->_options['ark_web_root'] : $post['ark_web_root'];

        // Special check for prefix/suffix.
        $format = $post['ark_format_name'];

        // Check the parameters for the format.
        $format = $post['ark_format_name'];
        $parameters = array(
            'protocol' => $post['ark_protocol'],
            'naan' => $post['ark_naan'],
            'naa' => $post['ark_naa'],
            'subnaa' => $post['ark_subnaa'],
            // Parameters for Noid.
            'database' => $post['ark_noid_database'],
            'template' => $post['ark_noid_template'],
            // Parameters for Omeka Id.
            'prefix' => $post['ark_id_prefix'] . $post['ark_id_prefix_collection'] . $post['ark_id_prefix_item'],
            'suffix' => $post['ark_id_suffix'] . $post['ark_id_suffix_collection'] . $post['ark_id_suffix_item'],
            'length' => $post['ark_id_length'],
            'pad' => $post['ark_id_pad'],
            'salt' => $post['ark_id_salt'],
            'alphabet' => $post['ark_id_alphabet'],
            'control_key' => $post['ark_id_control_key'],
            // Parameters for Command.
            'command' => $post['ark_command'],
            // This value is used only to check if a zero may be prepended for
            // collections with the Omeka Id format.
            'identifix' => $post['ark_id_prefix_collection'] === $post['ark_id_prefix_item']
                && $post['ark_id_suffix_collection'] === $post['ark_id_suffix_item'],
        );

        try {
            $processor = $this->_getArkProcessor($format, null, $parameters);
        } catch (Ark_ArkException $e) {
            throw new Omeka_Validate_Exception($e->getMessage());
        }

        // Check if the database is created for the format Noid.
        if ($post['ark_format_name'] == 'noid') {
            if ($post['ark_create_database']) {
                if ($processor->isDatabaseCreated()) {
                    throw new Omeka_Validate_Exception(__('The database exists already: remove it manually or change the path to create a new one.'));
                }

                $result = $processor->createDatabase();
                if ($result !== true) {
                    throw new Omeka_Validate_Exception(__('The database cannot be created: %s', $result));
                }
            }
            // Check if the database exists.
            elseif (!$processor->isDatabaseCreated()) {
                throw new Omeka_Validate_Exception(__('With format "Noid", the database should be created: check the box "Create the database".'));
            }
            // Nothing to do else: the database should be created.
        }

        // Save the previous salt if needed.
        $salt = get_option('ark_id_salt');
        $previousSalts = get_option('ark_id_previous_salts');

        // Clean the file variants.
        $post['ark_file_variants'] = preg_replace('/\s+/', ' ', trim($post['ark_file_variants']));

        foreach ($this->_options as $optionKey => $optionValue) {
            if (isset($post[$optionKey])) {
                set_option($optionKey, $post[$optionKey]);
            }
        }

        // Save the previous salt if needed.
        $newSalt = get_option('ark_id_salt');
        if ($newSalt !== $salt && strlen($newSalt) > 0) {
            set_option('ark_id_previous_salts', $previousSalts . PHP_EOL . $newSalt);
        }
    }

    /**
     * Defines routes.
     */
    public function hookDefineRoutes($args)
    {
        $router = $args['router'];

        if (get_option('ark_routes_ini')) {
            $router->addConfig(new Zend_Config_Ini(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'routes.ini', 'routes'));
            return;
        }

        $protocol = get_option('ark_protocol');
        if (empty($protocol)) {
            return;
        }

        // Routes is different with "ark", because there is a naan.
        if ($protocol == 'ark:') {
            $naan = get_option('ark_naan');
            if (empty($naan)) {
                return;
            }

            $router->addRoute('ark_policy', new Zend_Controller_Router_Route(
                "$protocol/$naan/",
                array(
                    'module' => 'ark',
                    'controller' => 'index',
                    'action' => 'policy',
                    'naan' => $naan,
            )));

            // Two non standard routes for ark.
            $router->addRoute('ark_policy_short', new Zend_Controller_Router_Route(
                'ark/policy',
                array(
                    'module' => 'ark',
                    'controller' => 'index',
                    'action' => 'policy',
                    'naan' => $naan,
            )));

            $router->addRoute('ark_policy_ark', new Zend_Controller_Router_Route(
                "$protocol/policy",
                array(
                    'module' => 'ark',
                    'controller' => 'index',
                    'action' => 'policy',
                    'naan' => $naan,
            )));

            $protocolBase = "ark:/$naan";
        }

        // Routes for non-arks unique identifiers.
        else {
            $router->addRoute('ark_policy', new Zend_Controller_Router_Route(
                $protocol . '/policy',
                array(
                    'module' => 'ark',
                    'controller' => 'index',
                    'action' => 'policy',
                    'naan' => $naan,
            )));

            $protocolBase = $protocol;
        }

        $router->addRoute('ark_id', new Zend_Controller_Router_Route(
            "$protocolBase/:name/:qualifier",
            array(
                'module' => 'ark',
                'controller' => 'index',
                'action' => 'index',
                'naan' => $naan,
                'qualifier' => '',
            ),
            array(
                'name' => '\w+',
        )));

        // A regex is needed, because a variant is separated by a ".", not a
        // "/".
        $router->addRoute('ark_file_variant', new Zend_Controller_Router_Route_Regex(
            $protocolBase . '/(\w+)/(.*)\.(' . str_replace(' ', '|', get_option('ark_file_variants')) . ')',
            array(
                'module' => 'ark',
                'controller' => 'index',
                'action' => 'index',
                'naan' => $naan,
            ),
            array(
                1 => 'name',
                2 => 'qualifier',
                3 => 'variant',
            ),
            "$protocolBase/%s/%s.%s"
        ));
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\ItemAdapter',
            'api.create.post',
            [$this, 'addArk']
        );
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\ItemAdapter',
            'api.update.post',
            [$this, 'addArk']
        );
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\ItemSetAdapter',
            'api.create.post',
            [$this, 'addArk']
        );
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\ItemSetAdapter',
            'api.update.post',
            [$this, 'addArk']
        );
    }

    /**
     * Add an ark to a record, if needed.
     *
     * @param Record $record
     * @return void
     */
    public function addArk($event)
    {
        $serviceLocator = $this->getServiceLocator();
        $viewHelpers = $serviceLocator->get('ViewHelperManager');
        $arkHelper = $viewHelpers->get('ark');
        $api = $serviceLocator->get('Omeka\ApiManager');

        $request = $event->getParam('request');
        $response = $event->getParam('response');
        $requestResource = $request->getResource();

        $resource = $response->getContent();
        //$resource = $api->read($requestResource, $request->getId())->getContent();

        // Check if an ark exists (no automatic change or update), else create.
        $ark = $arkHelper($resource);
        if (empty($ark)) {
            $ark = $this->_getArkProcessor();

            $ark = $ark->create($resource);
            if ($ark) {
                $properties = $api->search('properties', [
                    'term' => 'dcterms:identifier'
                ])->getContent();
                $propertyId = $properties[0]->id();

                $data = [
                    'dcterms:identifier' => [
                        [
                            'type' => 'literal',
                            'property_id' => $propertyId,
                            '@value' => $ark,
                        ],
                    ],
                ];
                $api->update($requestResource, $resource->id(), $data, null, true);
            }
        }
    }

    /**
     * Return the selected processor or throw an error.
     *
     * @param string $format
     * @param string|null $recordType
     * @return Ark_Name class.
     */
    protected function _getArkProcessor()
    {
        $class = 'Ark\Ark\Name\Noid';

        $parameters = [
                       'protocol' => 'ark:',
                       'naan' => '99999',
                       'naa' => 'example.org',
                       'subnaa' => 'sub',
                       // Parameters for Noid.
                       'database' => OMEKA_PATH . '/files'.DIRECTORY_SEPARATOR . 'arkandnoid',
                       'template' => '.zek',
        ];

        $arkProcessor = new $class($parameters);
        $arkProcessor->setServiceLocator($this->getServiceLocator());
        return $arkProcessor;
    }

    public function getConfig() {
        return include __DIR__ . '/config/module.config.php';
    }

}
