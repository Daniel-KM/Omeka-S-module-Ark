<?php

namespace Ark\Service;

use Ark\ArkManager;
use Interop\Container\ContainerInterface;
use Zend\ServiceManager\Factory\FactoryInterface;

class ArkManagerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $name, array $options = null)
    {
        $settings = $services->get('Omeka\Settings');
        return new ArkManager(
            $settings->get('ark_naan'),
            $settings->get('ark_name'),
            $settings->get('ark_qualifier'),
            (bool) $settings->get('ark_qualifier_static'),
            $services->get('ControllerPluginManager')->get('api'),
            $services->get('Omeka\Connection'),
            $services->get('Omeka\Logger'),
            $services->get('Ark\NamePluginManager'),
            $services->get('Ark\QualifierPluginManager')
        );
    }
}
