<?php

namespace Ark\Service\Form;

use Interop\Container\ContainerInterface;
use Zend\ServiceManager\Factory\FactoryInterface;
use Ark\Form\ConfigForm;

class ConfigFormFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $translator = $services->get('MvcTranslator');
        $settings = $services->get('Omeka\Settings');

        $configForm = new ConfigForm(null, $options);
        $configForm->setTranslator($translator);
        $configForm->setSettings($settings);

        return $configForm;
    }
}
