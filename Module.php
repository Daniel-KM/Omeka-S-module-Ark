<?php

namespace Ark;

use Ark\Form\ConfigForm;
use Omeka\Entity\Value;
use Omeka\Module\AbstractModule;
use Zend\EventManager\Event;
use Zend\EventManager\SharedEventManagerInterface;
use Zend\ModuleManager\ModuleManager;
use Zend\Mvc\Controller\AbstractController;
use Zend\ServiceManager\ServiceLocatorInterface;
use Zend\View\Renderer\PhpRenderer;

/**
 * Ark.
 *
 * Creates and manages unique, universel and persistent ark identifiers.
 *
 * @copyright Daniel Berthereau, 2015-2018
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */

/**
 * The Ark plugin.
 */
class Module extends AbstractModule
{
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

        /** @var \Zend\ModuleManager\Listener\ServiceListener $serviceListener */
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

    public function install(ServiceLocatorInterface $serviceLocator)
    {
        $this->manageSettings($serviceLocator->get('Omeka\Settings'), 'install');
    }

    public function uninstall(ServiceLocatorInterface $serviceLocator)
    {
        $this->manageSettings($serviceLocator->get('Omeka\Settings'), 'uninstall');
    }

    protected function manageSettings($settings, $process, $key = 'config')
    {
        $config = require __DIR__ . '/config/module.config.php';
        $defaultSettings = $config[strtolower(__NAMESPACE__)][$key];
        foreach ($defaultSettings as $name => $value) {
            switch ($process) {
                case 'install':
                    $settings->set($name, $value);
                    break;
                case 'uninstall':
                    $settings->delete($name);
                    break;
            }
        }
    }

    public function getConfigForm(PhpRenderer $renderer)
    {
        $services = $this->getServiceLocator();
        $config = $services->get('Config');
        $settings = $services->get('Omeka\Settings');
        $formElementManager = $services->get('FormElementManager');

        $data = [];
        $defaultSettings = $config[strtolower(__NAMESPACE__)]['config'];
        foreach ($defaultSettings as $name => $value) {
            $data[$name] = $settings->get($name);
        }

        $form = $formElementManager->get(ConfigForm::class);
        $form->init();
        $form->setData($data);

        return $renderer->render('ark/module/config', [
            'form' => $form,
        ]);
    }
    public function handleConfigForm(AbstractController $controller)
    {
        $services = $this->getServiceLocator();
        $config = $services->get('Config');
        $settings = $services->get('Omeka\Settings');
        $arkManager = $services->get('Ark\ArkManager');

        $params = $controller->getRequest()->getPost();

        $form = $this->getServiceLocator()->get('FormElementManager')
            ->get(ConfigForm::class);
        $form->init();
        $form->setData($params);
        if (!$form->isValid()) {
            $controller->messenger()->addErrors($form->getMessages());
            return false;
        }

        $defaultSettings = $config[strtolower(__NAMESPACE__)]['config'];
        foreach ($params as $name => $value) {
            if (isset($defaultSettings[$name])) {
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
     * @param Event $event
     */
    public function addArk(Event $event)
    {
        $services = $this->getServiceLocator();
        $entityManager = $services->get('Omeka\EntityManager');
        $arkManager = $services->get('Ark\ArkManager');
        $api = $services->get('Omeka\ApiManager');

        $request = $event->getParam('request');
        $response = $event->getParam('response');
        $requestResource = $request->getResource();

        $resource = $response->getContent();
        $representation = $api->read($resource->getResourceName(), $resource->getId())->getContent();

        // Check if an ark exists (no automatic change or update), else create.
        $ark = $arkManager->getArk($representation);
        if (empty($ark)) {
            $ark = $arkManager->createName($representation);
            if ($ark) {
                $values = $resource->getValues();

                $value = new Value;
                $value->setType('literal');
                $value->setResource($resource);
                $value->setProperty($this->getIdentifierPropertyEntity());
                $value->setValue($ark);

                $values->add($value);
                $entityManager->flush();
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
}
