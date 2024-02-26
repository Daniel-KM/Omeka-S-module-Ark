<?php declare(strict_types=1);

namespace Ark\Service;

use Ark\ArkManager;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ArkManagerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $name, array $options = null)
    {
        /**
         * @var \Omeka\Settings\Settings $settings
         * @var \Common\Stdlib\EasyMeta $easyMeta
         *
         * 10 is dcterms:identifier id in default hard coded install.
         */
        $easyMeta = $services->get('EasyMeta');
        $settings = $services->get('Omeka\Settings');
        $propertyTerm = $settings->get('ark_property') ?: 'dcterms:identifier';
        $propertyId = $easyMeta->propertyId($propertyTerm) ?: 10;
        return new ArkManager(
            $settings->get('ark_naan'),
            $settings->get('ark_name'),
            $settings->get('ark_qualifier'),
            (bool) $settings->get('ark_qualifier_static'),
            $propertyId,
            $propertyTerm,
            $services->get('Omeka\ApiManager'),
            $services->get('Omeka\Connection'),
            $services->get('EasyMeta'),
            $services->get('Omeka\Logger'),
            $services->get('Ark\NamePluginManager'),
            $services->get('Ark\QualifierPluginManager')
        );
    }
}
