<?php

namespace Ark\Service;

use Interop\Container\ContainerInterface;
use Zend\ServiceManager\Factory\FactoryInterface;
use Ark\Ark\ArkHelper;

class ArkHelperFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new ArkHelper($services->get('Omeka\ApiManager'));
    }
}
