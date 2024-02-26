<?php declare(strict_types=1);

namespace ArkTest\Controller\Admin;

use ArkTest\Controller\ArkControllerTestCase;

class ConfigControllerTest extends ArkControllerTestCase
{
    protected $namePlugin;

    public function setUp(): void
    {
        parent::setUp();

        $arkManager = $this->getServiceLocator()->get('Ark\ArkManager');
        $this->namePlugin = $arkManager->getArkNamePlugin();
        $this->namePlugin->deleteDatabase();
    }

    public function testConfigFormShouldReturnOkStatus(): void
    {
        $this->dispatch($this->moduleConfigureUrl('Ark'));

        $this->assertResponseStatusCode(200);
    }

    public function testConfigFormSubmitShouldSaveCreateDbAndRedirect(): void
    {
        $data = [
            'ark_naan' => '12345',
            'ark_naa' => 'example.com',
            'ark_subnaa' => 'subnaa',
            'ark_name' => 'noid',
            'ark_name_noid_template' => '.seeeeek',
            'ark_qualifier' => 'internal',
            'ark_qualifier_position_format' => '',
            'ark_qualifier_static' => 0,
            'ark_property' => 'dcterms:identifier',
            'ark_policy_statement' => 'Modified Policy statement',
            'ark_policy_main' => 'Modified Main policy statement',
            'ark_note' => 'Modified Note',
        ];

        /**
         * The controller adds one more csrf, so it should be added in post.
         *
         * @var \Laminas\Form\Form $csrfForm
         * @var \Ark\Form\ConfigForm $configForm
         * @see \Omeka\Controller\Admin\ModuleController::configureAction()
         */
        $id = 'Ark';
        $services = $this->getServiceLocator();
        $getForm = $services->get('ControllerPluginManager')->get('getForm');
        $formName = "module_{$id}_configure";
        $csrfForm = $getForm(\Laminas\Form\Form::class, ['name' => $formName]);
        $configForm = $getForm(\Ark\Form\ConfigForm::class);
        $dataCsrf = [
            'csrf' => $configForm->get('csrf')->getValue(),
            "{$formName}_csrf" => $csrfForm->get("{$formName}_csrf")->getValue(),
        ];
        $this->dispatch($this->moduleConfigureUrl($id), 'POST', $data + $dataCsrf);

        foreach ($data as $name => $value) {
            $this->assertSame($value, $this->settings()->get($name));
        }

        $this->assertTrue($this->namePlugin->isDatabaseCreated());

        $this->assertResponseStatusCode(302);
    }
}
