<?php declare(strict_types=1);

namespace ArkTest\Controller\Admin;

use ArkTest\Controller\ArkControllerTestCase;
use Laminas\Form\Element\Csrf;

class ItemControllerTest extends ArkControllerTestCase
{
    protected $namePlugin;

    public function setUp(): void
    {
        parent::setUp();

        $arkManager = $this->getServiceLocator()->get('Ark\ArkManager');
        $this->namePlugin = $arkManager->getArkNamePlugin();
        $this->namePlugin->deleteDatabase();
        $this->namePlugin->createDatabase();
    }

    public function testItemAddShouldCreateArkIdentifier(): void
    {
        $this->dispatch($this->urlFromRoute('admin/default', [
            'controller' => 'item',
            'action' => 'add',
        ]), 'POST', [
            'values_json' => '{}',
            'csrf' => (new Csrf('csrf'))->getValue(),
        ]);
        $this->assertResponseStatusCode(302);

        $itemId = $this->getIdFromLocationHeader();
        $item = $this->api()->read('items', $itemId)->getContent();
        $this->assertSame('ark:/99999/bapZs2', $item->value('dcterms:identifier')->value());
    }

    protected function getIdFromLocationHeader()
    {
        $headers = $this->getResponse()->getHeaders();
        $location = $headers->get('Location')->getUri();

        return substr(strrchr($location, '/'), 1);
    }
}
