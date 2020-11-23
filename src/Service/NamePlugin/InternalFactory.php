<?php declare(strict_types=1);

namespace Ark\Service\NamePlugin;

use Ark\Name\Plugin\Internal;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class InternalFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $name, array $options = null)
    {
        return new Internal(
            $services->get('Omeka\Settings'),
            $services->get('Omeka\Logger')
        );
    }
}
