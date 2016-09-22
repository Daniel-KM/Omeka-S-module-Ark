<?php

namespace ArkTest\Controller\Admin;

use ArkTest\Controller\ArkControllerTestCase;

class ConfigControllerTest extends ArkControllerTestCase
{
    protected $namePlugin;

    public function setUp()
    {
        parent::setUp();

        $arkManager = $this->getServiceLocator()->get('Ark\ArkManager');
        $this->namePlugin = $arkManager->getArkNamePlugin();
        $this->namePlugin->deleteDatabase();
    }

    public function testConfigFormShouldReturnOkStatus()
    {
        $this->dispatch($this->moduleConfigureUrl('Ark'));

        $this->assertResponseStatusCode(200);
    }

    public function testConfigFormSubmitShouldSaveCreateDbAndRedirect()
    {
        $data = [
            'ark_naan' => '12345',
            'ark_naa' => 'example.com',
            'ark_subnaa' => 'subnaa',
            'ark_noid_template' => '.seeeeek',
            'ark_note' => 'Modified Note',
            'ark_policy_statement' => 'Modified Policy statement',
            'ark_policy_main' => 'Modified Main policy statement',
        ];
        $this->dispatch($this->moduleConfigureUrl('Ark'), 'POST', $data);

        foreach ($data as $name => $value) {
            $this->assertSame($value, $this->settings()->get($name));
        }

        $this->assertTrue($this->namePlugin->isDatabaseCreated());

        $this->assertResponseStatusCode(302);
    }
}
