<?php

namespace Ark\Service\NamePlugin;

use Ark\Name\Plugin\Noid;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class NoidFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $name, array $options = null)
    {
        $basePath = $services->get('Config')['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        $databaseDir = $basePath . '/arkandnoid';
        return new Noid(
            $services->get('Omeka\Settings'),
            $services->get('Omeka\Logger'),
            $databaseDir
        );
    }
}
