<?php

namespace Ark\Service\ViewHelper;

use Interop\Container\ContainerInterface;
use Zend\ServiceManager\Factory\FactoryInterface;
use Ark\View\Helper\Ark;

class ArkFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $name, array $options = null)
    {
        $arkManager = $services->get('Ark\ArkManager');

        return new Ark($arkManager);
    }
}
