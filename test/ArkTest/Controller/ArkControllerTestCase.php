<?php

namespace ArkTest\Controller;

use OmekaTestHelper\Controller\OmekaControllerTestCase;
use ArkTest\Name\Plugin\Noid;

abstract class ArkControllerTestCase extends OmekaControllerTestCase
{
    public function setUp()
    {
        parent::setUp();

        $this->loginAsAdmin();

        $this->settings()->set('ark_naan', '99999');
        $this->settings()->set('ark_naa', 'example.org');
        $this->settings()->set('ark_subnaa', 'sub');
        $this->settings()->set('ark_noid_template', '.zek');
        $this->settings()->set('ark_note', 'Note');
        $this->settings()->set('ark_policy_statement', 'Policy statement');
        $this->settings()->set('ark_policy_main', 'Main policy statement');

        $services = $this->getServiceLocator();

        $namePlugins = $services->get('Ark\NamePluginManager');
        $namePlugins->setAllowOverride(true);
        $noid = new Noid($this->settings(), $services->get('Ark\ArkManager'));
        $namePlugins->setService('noid', $noid);
        $namePlugins->setAllowOverride(false);
    }
}
