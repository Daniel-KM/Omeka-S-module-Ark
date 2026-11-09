<?php declare(strict_types=1);

namespace Ark\Service;

use Ark\Stdlib\NoidMaintenance;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

class NoidMaintenanceFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        $config = $services->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        return new NoidMaintenance(
            $services->get('Omeka\Connection'),
            $services->get('Common\EasyMeta'),
            $services->get('Omeka\Settings'),
            $services->get('Omeka\Cli'),
            $basePath . '/arkandnoid',
            $config['connection'] ?? [],
            $services->get('Omeka\Logger')
        );
    }
}
