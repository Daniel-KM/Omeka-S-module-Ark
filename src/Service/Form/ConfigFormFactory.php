<?php

namespace Ark\Service\Form;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Ark\Form\ConfigForm;

class ConfigFormFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $configForm = new ConfigForm(null, $options);
        return $configForm
            ->setArkManager($services->get('Ark\ArkManager'));
    }
}
