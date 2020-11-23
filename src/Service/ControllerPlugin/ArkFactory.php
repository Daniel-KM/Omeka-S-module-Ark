<?php declare(strict_types=1);

namespace Ark\Service\ControllerPlugin;

use Ark\Mvc\Controller\Plugin\Ark;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ArkFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $name, array $options = null)
    {
        return new Ark($services->get('Ark\ArkManager'));
    }
}
