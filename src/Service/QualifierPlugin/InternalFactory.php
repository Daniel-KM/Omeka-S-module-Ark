<?php

namespace Ark\Service\QualifierPlugin;

use Ark\Qualifier\Plugin\Internal;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class InternalFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $name, array $options = null)
    {
        return new Internal(
            $services->get('Omeka\ApiManager')
        );
    }
}
