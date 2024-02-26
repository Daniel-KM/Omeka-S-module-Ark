<?php declare(strict_types=1);

namespace Ark\Service\QualifierPlugin;

use Ark\Qualifier\Plugin\Position;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class PositionFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $name, array $options = null)
    {
        return new Position(
            $services->get('Omeka\ApiManager'),
            $services->get('Omeka\EntityManager'),
            $services->get('Omeka\Logger'),
            (string) $services->get('Omeka\Settings')->get('ark_qualifier_position_format')
        );
    }
}
