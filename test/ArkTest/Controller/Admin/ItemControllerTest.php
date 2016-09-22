<?php

namespace ArkTest\Controller\Admin;

use Zend\Form\Element\Csrf;
use ArkTest\Controller\ArkControllerTestCase;

class ItemControllerTest extends ArkControllerTestCase
{
    protected $namePlugin;

    public function setUp()
    {
        parent::setUp();

        $arkManager = $this->getServiceLocator()->get('Ark\ArkManager');
        $this->namePlugin = $arkManager->getArkNamePlugin();
        $this->namePlugin->deleteDatabase();
        $this->namePlugin->createDatabase();
    }

    public function testItemAddShouldCreateArkIdentifier()
    {
        $this->dispatch($this->urlFromRoute('admin/default', [
            'controller' => 'item',
            'action' => 'add',
        ]), 'POST', [
            'csrf' => (new Csrf('csrf'))->getValue(),
        ]);
        $this->assertResponseStatusCode(302);

        $itemId = $this->getIdFromLocationHeader();
        $item = $this->api()->read('items', $itemId)->getContent();
        $this->assertSame('ark:/99999/0n', $item->value('dcterms:identifier')->value());
    }

    protected function getIdFromLocationHeader()
    {
        $headers = $this->getResponse()->getHeaders();
        $location = $headers->get('Location')->getUri();

        return substr(strrchr($location, '/'), 1);
    }
}
