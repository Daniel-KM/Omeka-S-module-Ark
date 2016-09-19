<?php
namespace Ark;

use Zend\ServiceManager\ServiceLocatorInterface;
use Zend\View\Renderer\PhpRenderer;
use Zend\Mvc\Controller\AbstractController;
use Zend\EventManager\SharedEventManagerInterface;
use Omeka\Api\Representation\ResourceReference;
use Omeka\Module\AbstractModule;
use Ark\Form\ConfigForm;
use Ark\Ark\Name\Noid;

require __DIR__ . '/vendor/autoload.php';

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
        $this->_installOptions($serviceLocator);
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

        return $renderer->render('ark/config-form', [
            'form' => $form,
        ]);
    }

    /**
     * Saves plugin configuration page.
     *
     * @param array Options set in the config form.
     */
    public function handleConfigForm(AbstractController $controller)
    {
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $post = $controller->getRequest()->getPost();

        foreach (array_keys($this->_options) as $name) {
            $value = $post->get($name);
            if (isset($value)) {
                $settings->set($name, $value);
            }
        }

        $processor = $this->_getArkProcessor();
        if (!$processor->isDatabaseCreated()) {
            $processor->createDatabase();
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
        if ($resource instanceof ResourceReference) {
            $resource = $resource->getRepresentation();
        }
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
        $settings = $this->getServiceLocator()->get('Omeka\Settings');

        $parameters = [
                       'protocol' => 'ark:',
                       'naan' => $settings->get('ark_naan'),
                       'naa' => 'example.org',
                       'subnaa' => 'sub',
                       // Parameters for Noid.
                       'database' => OMEKA_PATH . '/files' . DIRECTORY_SEPARATOR . 'arkandnoid',
                       'template' => '.zek',
        ];

        $arkProcessor = new \Ark\Ark\Name\Noid($parameters);
        $arkProcessor->setServiceLocator($this->getServiceLocator());
        return $arkProcessor;
    }

    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }
}
