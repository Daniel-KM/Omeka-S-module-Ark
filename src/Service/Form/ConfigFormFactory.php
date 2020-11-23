<?php declare(strict_types=1);

namespace Ark\Service\Form;

use Ark\Form\ConfigForm;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ConfigFormFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $configForm = new ConfigForm(null, $options);
        return $configForm
            ->setArkManager($services->get('Ark\ArkManager'));
    }
}
