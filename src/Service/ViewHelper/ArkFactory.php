<?php

namespace Ark\Service\ViewHelper;

use Ark\View\Helper\Ark;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ArkFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $name, array $options = null)
    {
        $arkManager = $services->get('Ark\ArkManager');

        return new Ark($arkManager);
    }
}
