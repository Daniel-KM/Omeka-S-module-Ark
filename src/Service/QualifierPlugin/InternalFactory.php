<?php

namespace Ark\Service\QualifierPlugin;

use Interop\Container\ContainerInterface;
use Zend\ServiceManager\Factory\FactoryInterface;
use Ark\Qualifier\Plugin\Internal;

class InternalFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $name, array $options = null)
    {
        $api = $services->get('Omeka\ApiManager');
        return new Internal($api);
    }
}
