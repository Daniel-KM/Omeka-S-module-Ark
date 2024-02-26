<?php declare(strict_types=1);

namespace Ark;

if (!class_exists(\Common\TraitModule::class)) {
    require_once dirname(__DIR__) . '/Common/TraitModule.php';
}

use Common\TraitModule;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\ModuleManager\ModuleManager;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\Mvc\MvcEvent;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Entity\Resource;
use Omeka\Entity\Value;
use Omeka\Module\AbstractModule;

/**
 * Ark.
 *
 * Creates and manages unique, universel and persistent ark identifiers.
 *
 * @copyright Daniel Berthereau, 2015-2024
 * @copyright biblibre, 2016-2017
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */
class Module extends AbstractModule
{
    use TraitModule;

    const NAMESPACE = __NAMESPACE__;

    public function init(ModuleManager $moduleManager): void
    {
        require_once __DIR__ . '/vendor/autoload.php';

        $event = $moduleManager->getEvent();
        $container = $event->getParam('ServiceManager');

        /** @var \Laminas\ModuleManager\Listener\ServiceListener $serviceListener */
        $serviceListener = $container->get('ServiceListener');
        $serviceListener->addServiceManager(
            'Ark\NamePluginManager',
            'ark_name_plugins',
            \Ark\ModuleManager\Feature\NamePluginProviderInterface::class,
            'getArkNamePluginConfig'
        );
        $serviceListener->addServiceManager(
            'Ark\QualifierPluginManager',
            'ark_qualifier_plugins',
            \Ark\ModuleManager\Feature\QualifierPluginProviderInterface::class,
            'getArkQualifierPluginConfig'
        );
    }

    protected function preInstall(): void
    {
        $services = $this->getServiceLocator();
        $translate = $services->get('ControllerPluginManager')->get('translate');

        if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.54')) {
            $message = new \Omeka\Stdlib\Message(
                $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
                'Common', '3.4.54'
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
        }
    }

    public function onBootstrap(MvcEvent $event): void
    {
        parent::onBootstrap($event);
        $this->addAclRules();
    }

    public function getConfigForm(PhpRenderer $view)
    {
        $html = '<p class="explanation">'
            . $view->translate('Ark allows to create and manage unique, universel and persistent ark identifiers.') //  @translate
            . '</p><p>'
            . sprintf($view->translate('See %sthe official help%s for more informations.'), // @translate
                '<a href="http://n2t.net/e/ark_ids.html">', '</a>')
            . '</p>';

        $services = $this->getServiceLocator();
        $arkManager = $services->get('Ark\ArkManager');
        $name = $arkManager->getArkNamePlugin();
        if ($name->isDatabaseCreated()) {
            $info = $name->infoDatabase('meta');

            $settings = $services->get('Omeka\Settings');
            $arkNaan = $settings->get('ark_naan');
            $arkNaa = $settings->get('ark_naa');
            $arkSubnaa = $settings->get('ark_subnaa');
            $arkTemplate = $settings->get('ark_name_noid_template');

            if ($arkNaan === $info['naan']
                && $arkNaa === $info['naa']
                && $arkSubnaa === $info['subnaa']
                && $arkTemplate === $info['template']
            ) {
                $html .= '<p>'
                    . $view->translate('NOID database is already created, which means some settings are not modifiable.')
                    . '</p><p>'
                    . sprintf($view->translate('To be able to modify them, you have to manually remove the database (located in %s).'), // @translate
                        OMEKA_PATH . '/files/arkandnoid')
                    . '</p>';
            } else {
                $html .= '<p>'
                    . $view->translate('NOID database is already created, but the settings are not the same than in the Omeka database.')
                    . '</p><p>'
                    // TODO Add a button to reset the database.
                    . sprintf($view->translate('To be able to modify them, you have to manually remove the database (located in %s).'), // @translate
                        OMEKA_PATH . '/files/arkandnoid')
                    . '</p><p>'
                    . sprintf(
                        $view->translate('Naan: %1$s; Naa: %2$s; Subnaa: %3$s; Template: %4$s.'), // @translate
                        '<strong>' . $info['naan'] . '</strong>',
                        '<strong>' . $info['naa'] . '</strong>',
                        '<strong>' . $info['subnaa'] . '</strong>',
                        '<strong>' . $info['template'] . '</strong>'
                    )
                    . '</p>';
            }
        }

        return $html
            . $this->getConfigFormAuto($view);
    }

    public function handleConfigForm(AbstractController $controller)
    {
        // The parent cannot be used, since some fields are disabled.

        $services = $this->getServiceLocator();
        $config = $services->get('Config');
        $settings = $services->get('Omeka\Settings');
        $form = $services->get('FormElementManager')->get(\Ark\Form\ConfigForm::class);
        $arkManager = $services->get('Ark\ArkManager');

        $params = $controller->getRequest()->getPost();

        $form->init();
        $form->setData($params);
        if (!$form->isValid()) {
            $controller->messenger()->addErrors($form->getMessages());
            return false;
        }

        $params = $form->getData();
        $defaultSettings = $config['ark']['config'];
        $params = array_intersect_key($params, $defaultSettings);

        // Avoid to reset existing settings from the disabled fields.
        $namePlugin = $arkManager->getArkNamePlugin();
        if ($namePlugin->isDatabaseCreated()) {
            unset(
                $params['ark_naan'],
                $params['ark_naa'],
                $params['ark_subnaa'],
                $params['ark_name_noid_template']
            );
        }
        foreach ($params as $name => $value) {
            $settings->set($name, $value);
        }

        if (!$namePlugin->isDatabaseCreated()) {
            $namePlugin->createDatabase();
        }

        return true;
    }

    /**
     * Add ACL rules for this module.
     */
    protected function addAclRules(): void
    {
        // Allow all access to the controller, because there will be a forward.
        $services = $this->getServiceLocator();
        $acl = $services->get('Omeka\Acl');
        $roles = $acl->getRoles();
        $acl
            ->allow(
                null,
                [Controller\ArkController::class]
            )
            ->allow(
                $roles,
                ['Ark\Controller\Admin\Ark']
            );
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.create.post',
            [$this, 'handleSaveResource']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.update.post',
            [$this, 'handleSaveResource']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\MediaAdapter::class,
            'api.create.post',
            [$this, 'handleSaveResource']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\MediaAdapter::class,
            'api.update.post',
            [$this, 'handleSaveResource']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemSetAdapter::class,
            'api.create.post',
            [$this, 'handleSaveResource']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemSetAdapter::class,
            'api.update.post',
            [$this, 'handleSaveResource']
        );
    }

    /**
     * @param Event $event
     */
    public function handleSaveResource(Event $event): void
    {
        /** @var \Omeka\Entity\Resource $resource */
        $resource = $event->getParam('response')->getContent();

        $this->addArk($resource);

        if ($resource->getResourceName() === 'items') {
            foreach ($resource->getMedia() as $media) {
                $this->addArk($media);
            }
        }
    }

    /**
     * Add an ark to a record, if needed.
     *
     * @param Resource $resource
     */
    protected function addArk(Resource $resource): void
    {
        $services = $this->getServiceLocator();
        $entityManager = $services->get('Omeka\EntityManager');
        $arkManager = $services->get('Ark\ArkManager');
        $api = $services->get('Omeka\ApiManager');

        // Check if the media ark should be set.
        $isMedia = $resource->getResourceName() === 'media';
        if ($isMedia) {
            $settings = $services->get('Omeka\Settings');
            if (!$settings->get('ark_qualifier_static')) {
                return;
            }
        }

        $representation = $api->read($resource->getResourceName(), $resource->getId())->getContent();

        // Check if an ark exists (no automatic change or update), else create.
        $ark = $arkManager->getArk($representation);
        if ($ark) {
            // For media, the ark is static, as checked above and in manager.
            return;
        }

        $ark = $arkManager->createName($representation);
        if (empty($ark)) {
            return;
        }

        // 10 is dcterms:identifier id in default hard coded install.
        $property = $api->read('properties', ['id' => 10], [], ['responseContent' => 'resource'])->getContent();

        $values = $resource->getValues();

        $value = new Value;
        $value->setType('literal');
        $value->setResource($resource);
        $value->setProperty($property);
        $value->setValue($ark);

        $values->add($value);
        $entityManager->flush();
    }
}
