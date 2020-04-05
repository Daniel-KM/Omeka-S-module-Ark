<?php

namespace Ark\Service;

use Ark\ArkManager;
use Interop\Container\ContainerInterface;
use Zend\ServiceManager\Factory\FactoryInterface;

class ArkManagerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $name, array $options = null)
    {
        return new ArkManager(
            $services->get('ControllerPluginManager')->get('api'),
            $services->get('Omeka\Connection'),
            $services->get('Omeka\Logger'),
            $services->get('Omeka\Settings'),
            $services->get('Ark\NamePluginManager'),
            $services->get('Ark\QualifierPluginManager')
        );
    }
}
