<?php

namespace Ark\Service\NamePlugin;

use Ark\Name\Plugin\Noid;
use Interop\Container\ContainerInterface;
use Zend\ServiceManager\Factory\FactoryInterface;

class NoidFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $name, array $options = null)
    {
        $settings = $services->get('Omeka\Settings');
        $basePath = $services->get('Config')['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        $databaseDir = $basePath . '/arkandnoid';
        return new Noid($settings, $databaseDir);
    }
}
