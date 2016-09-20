<?php

namespace Ark\Service\NamePlugin;

use Interop\Container\ContainerInterface;
use Zend\ServiceManager\Factory\FactoryInterface;
use Ark\Name\Plugin\Noid;

class NoidFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $name, array $options = null)
    {
        $settings = $services->get('Omeka\Settings');
        $arkManager = $services->get('Ark\ArkManager');

        return new Noid($settings, $arkManager);
    }
}
