<?php declare(strict_types=1);

namespace Ark\Service\Controller\Admin;

use Ark\Controller\Admin\ArkController;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

class ArkControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new ArkController(
            $services->get('Ark\NoidMaintenance')
        );
    }
}
