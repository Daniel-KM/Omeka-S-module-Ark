<?php

namespace Ark\Service;

use Ark\ArkManager;
use Interop\Container\ContainerInterface;
use Zend\ServiceManager\Factory\FactoryInterface;

class ArkManagerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $name, array $options = null)
    {
        $api = $services->get('ControllerPluginManager')->get('api');
        $connection = $services->get('Omeka\Connection');
        $settings = $services->get('Omeka\Settings');
        $namePlugins = $services->get('Ark\NamePluginManager');
        $qualifierPlugins = $services->get('Ark\QualifierPluginManager');
        return new ArkManager(
            $api,
            $connection,
            $settings,
            $namePlugins,
            $qualifierPlugins
        );
    }
}
