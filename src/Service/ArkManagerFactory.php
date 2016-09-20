<?php

namespace Ark\Service;

use Interop\Container\ContainerInterface;
use Zend\ServiceManager\Factory\FactoryInterface;
use Ark\ArkManager;

class ArkManagerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $name, array $options = null)
    {
        $api = $services->get('Omeka\ApiManager');
        $settings = $services->get('Omeka\Settings');
        $qualifierPlugins = $services->get('Ark\QualifierPluginManager');
        $namePlugins = $services->get('Ark\NamePluginManager');

        return new ArkManager($api, $settings, $qualifierPlugins, $namePlugins);
    }
}
