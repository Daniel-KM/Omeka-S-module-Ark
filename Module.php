<?php

namespace Ark;

use Zend\EventManager\SharedEventManagerInterface;
use Zend\ModuleManager\ModuleManager;
use Zend\Mvc\Controller\AbstractController;
use Zend\ServiceManager\ServiceLocatorInterface;
use Zend\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\ResourceReference;
use Omeka\Entity\Value;
use Omeka\Module\AbstractModule;
use Ark\Form\ConfigForm;

/**
 * Ark.
 *
 * Creates and manages unique, universel and persistent ark identifiers.
 *
 * @copyright Daniel Berthereau, 2015-2016
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */

/**
 * The Ark plugin.
 */
class Module extends AbstractModule
{
    /**
     * @var array This plugin's options
     */
    protected $settings = [
        // 12345 means example and 99999 means test.
        'ark_naan' => '99999',
        'ark_naa' => 'example.org',
        'ark_subnaa' => 'sub',
        'ark_noid_template' => '.zek',
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
    ];

    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function init(ModuleManager $moduleManager)
    {
        require_once __DIR__ . '/vendor/autoload.php';

        $event = $moduleManager->getEvent();
        $container = $event->getParam('ServiceManager');
        $serviceListener = $container->get('ServiceListener');

        $serviceListener->addServiceManager(
            'Ark\NamePluginManager',
            'ark_name_plugins',
            'Ark\ModuleManager\Feature\NamePluginProviderInterface',
            'getArkNamePluginConfig'
        );
        $serviceListener->addServiceManager(
            'Ark\QualifierPluginManager',
            'ark_qualifier_plugins',
            'Ark\ModuleManager\Feature\QualifierPluginProviderInterface',
            'getArkQualifierPluginConfig'
        );
    }

    /**
     * Installs the plugin.
     */
    public function install(ServiceLocatorInterface $serviceLocator)
    {
        $this->installSettings($serviceLocator);
    }

    protected function installSettings($serviceLocator)
    {
        $settings = $serviceLocator->get('Omeka\Settings');
        foreach ($this->settings as $key => $value) {
            $settings->set($key, $value);
        }
    }

    /**
     * Uninstalls the plugin.
     */
    public function uninstall(ServiceLocatorInterface $serviceLocator)
    {
        $this->uninstallSettings($serviceLocator);
    }

    protected function uninstallSettings($serviceLocator)
    {
        $settings = $serviceLocator->get('Omeka\Settings');
        foreach ($this->settings as $key => $value) {
            $settings->delete($key);
        }
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
     * @param array Options set in the config form
     */
    public function handleConfigForm(AbstractController $controller)
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $arkManager = $services->get('Ark\ArkManager');

        $post = $controller->getRequest()->getPost();

        foreach (array_keys($this->settings) as $name) {
            $value = $post->get($name);
            if (isset($value)) {
                $settings->set($name, $value);
            }
        }

        $namePlugin = $arkManager->getArkNamePlugin();
        if (!$namePlugin->isDatabaseCreated()) {
            $namePlugin->createDatabase();
        }
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
     */
    public function addArk($event)
    {
        $services = $this->getServiceLocator();
        $entityManager = $services->get('Omeka\EntityManager');
        $arkManager = $services->get('Ark\ArkManager');
        $api = $services->get('Omeka\ApiManager');

        $request = $event->getParam('request');
        $response = $event->getParam('response');
        $requestResource = $request->getResource();

        $resource = $response->getContent();
        if ($resource instanceof ResourceReference) {
            $resource = $api->read($resource->resourceName(), $resource->id())->getContent();
        }

        // Check if an ark exists (no automatic change or update), else create.
        $ark = $arkManager->getArk($resource);
        if (empty($ark)) {
            $ark = $arkManager->createName($resource);
            if ($ark) {
                $entity = $this->getEntityFromRepresentation($resource);
                $values = $entity->getValues();

                $value = new Value;
                $value->setType('literal');
                $value->setResource($entity);
                $value->setProperty($this->getIdentifierPropertyEntity());
                $value->setValue($ark);

                $values->add($value);
                $entityManager->flush();

                $apiAdapters = $this->getServiceLocator()->get('Omeka\ApiAdapterManager');
                $adapter = $apiAdapters->get($resource->resourceName());
                $resource = $adapter->getRepresentation($entity);
                $response->setContent($resource);
            }
        }
    }

    protected function getIdentifierPropertyEntity()
    {
        $entityManager = $this->getServiceLocator()->get('Omeka\EntityManager');

        $query = $entityManager->createQuery("
            SELECT p FROM Omeka\Entity\Property p JOIN p.vocabulary v
            WHERE v.prefix = 'dcterms' AND p.localName = 'identifier'
        ");
        $property = $query->getSingleResult();

        return $property;
    }

    protected function getEntityFromRepresentation($resource)
    {
        $services = $this->getServiceLocator();
        $apiAdapterManager = $services->get('Omeka\ApiAdapterManager');
        $entityManager = $services->get('Omeka\EntityManager');

        $adapter = $apiAdapterManager->get($resource->resourceName());
        $entityClass = $adapter->getEntityClass();
        $entity = $entityManager->find($entityClass, $resource->id());

        return $entity;
    }
}
