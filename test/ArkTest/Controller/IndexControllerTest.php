<?php

namespace ArkTest\Controller;

class IndexControllerTest extends ArkControllerTestCase
{
    protected $site;

    public function setUp()
    {
        parent::setUp();

        $arkManager = $this->getServiceLocator()->get('Ark\ArkManager');
        $namePlugin = $arkManager->getArkNamePlugin();
        $namePlugin->deleteDatabase();
        $namePlugin->createDatabase();

        $this->site = $this->api()->create('sites', [
            'o:title' => 'default',
            'o:slug' => 'default',
            'o:theme' => 'default',
            'o:is_public' => '1',
        ])->getContent();

        $items = $this->api()->search('items')->getContent();
        foreach ($items as $item) {
            $this->api()->delete('items', $item->id());
        }
    }

    public function tearDown()
    {
        $this->api()->delete('sites', $this->site->id());
    }

    public function testArkUrlShouldDisplayCorrectItem()
    {
        $item = $this->api()->create('items', [])->getContent();

        $ark = $item->value('dcterms:identifier')->value();
        $this->assertSame('ark:/99999/0n', $ark);

        $uri = "/s/default/$ark";
        $_SERVER['REQUEST_URI'] = $uri;
        $this->dispatch($uri);

        $this->assertResponseStatusCode(200);
        $this->assertControllerName('Omeka\Controller\Site\Item');
        $this->assertActionName('show');
        $this->assertQueryContentRegex('.property .value', '#ark:/99999/0n#');
    }

    public function testArkUrlShouldDisplayCorrectItemMetadata()
    {
        $item = $this->api()->create('items', [])->getContent();

        $ark = $item->value('dcterms:identifier')->value();
        $this->assertSame('ark:/99999/0n', $ark);

        $uri = "/s/default/$ark?";
        $_SERVER['REQUEST_URI'] = $uri;
        $this->dispatch($uri);

        $this->assertResponseStatusCode(200);
        $this->assertResponseHeaderContains('Content-Type', 'text/plain; charset=UTF-8');
    }
}
