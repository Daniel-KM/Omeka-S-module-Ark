<?php declare(strict_types=1);

namespace ArkTest\Controller;

use ArkTest\Name\Plugin\MockNoid;
use OmekaTestHelper\Controller\OmekaControllerTestCase;

abstract class ArkControllerTestCase extends OmekaControllerTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->loginAsAdmin();

        /** @var \Omeka\Settings\SiteSettings $siteSettings */
        $userSettings = $this->getServiceLocator()->get('Omeka\Settings\User');
        $userSettings->setTargetId(1);

        $settings = $this->settings();
        $settings->set('ark_naan', '99999');
        $settings->set('ark_naa', 'example.org');
        $settings->set('ark_subnaa', 'sub');
        $settings->set('ark_name', 'noid');
        $settings->set('ark_name_noid_template', 'b.rllllk');
        $settings->set('ark_qualifier', 'internal');
        $settings->set('ark_qualifier_position_format', '');
        $settings->set('ark_qualifier_static', 0);
        $settings->set('ark_property', 'dcterms:identifier');
        $settings->set('ark_policy_statement', 'Policy statement');
        $settings->set('ark_policy_main', 'Main policy statement');
        $settings->set('ark_note', 'Note');

        $services = $this->getServiceLocator();

        $namePlugins = $services->get('Ark\NamePluginManager');
        $namePlugins->setAllowOverride(true);
        $noid = new MockNoid(
            $services->get('Omeka\Logger'),
            $services->get('Omeka\Settings'),
            ''
        );
        $namePlugins->setService('noid', $noid);
        $namePlugins->setAllowOverride(false);
    }
}
