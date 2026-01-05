<?php declare(strict_types=1);

namespace ArkTest\Controller;

class IndexControllerTest extends ArkControllerTestCase
{
    protected $site;

    public function setUp(): void
    {
        parent::setUp();

        $arkManager = $this->getServiceLocator()->get('Ark\ArkManager');
        $namePlugin = $arkManager->getArkNamePlugin();
        $namePlugin->deleteDatabase();
        $namePlugin->createDatabase();

        $this->site = $this->createSite('default', 'default');

        /** @var \Omeka\Settings\SiteSettings $siteSettings */
        $siteSettings = $this->getServiceLocator()->get('Omeka\Settings\Site');
        $siteSettings->setTargetId($this->site->id());

        $items = $this->api()->search('items')->getContent();
        foreach ($items as $item) {
            $this->api()->delete('items', $item->id());
        }
    }

    public function tearDown(): void
    {
        $this->cleanupResources();
    }

    public function testArkUrlShouldDisplayCorrectItem(): void
    {
        $item = $this->api()->create('items', [])->getContent();

        $ark = $item->value('dcterms:identifier')->value();
        $this->assertSame('ark:/99999/bapZs2', $ark);

        $uri = "/s/default/$ark";
        $_SERVER['REQUEST_URI'] = $uri;
        $this->dispatch($uri);

        $this->assertResponseStatusCode(200);
        $this->assertControllerName('Omeka\Controller\Site\Item');
        $this->assertActionName('show');
        $this->assertQueryContentRegex('.property .value', '#ark:/99999/bapZs2#');
    }

    public function testArkUrlShouldDisplayCorrectItemMetadata(): void
    {
        $item = $this->api()->create('items', [])->getContent();

        $ark = $item->value('dcterms:identifier')->value();
        $this->assertSame('ark:/99999/bapZs2', $ark);

        $uri = "/s/default/$ark?";
        $_SERVER['REQUEST_URI'] = $uri;
        $this->dispatch($uri);

        $this->assertResponseStatusCode(200);
        $this->assertResponseHeaderContains('Content-Type', 'text/plain; charset=UTF-8');
    }
}
