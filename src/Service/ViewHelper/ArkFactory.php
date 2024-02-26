<?php declare(strict_types=1);

namespace Ark\Service\ViewHelper;

use Ark\View\Helper\Ark;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ArkFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $name, array $options = null)
    {
        return new Ark(
            $services->get('Ark\ArkManager')
        );
    }
}
