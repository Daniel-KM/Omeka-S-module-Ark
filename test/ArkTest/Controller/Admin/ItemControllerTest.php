<?php declare(strict_types=1);

namespace ArkTest\Controller\Admin;

use ArkTest\ArkTestTrait;
use CommonTest\AbstractHttpControllerTestCase;

/**
 * Tests for ARK generation during item creation.
 *
 * @covers \Ark\Module
 */
class ItemControllerTest extends AbstractHttpControllerTestCase
{
    use ArkTestTrait;

    protected $namePlugin;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();

        // Configure Ark settings.
        $this->configureArkSettings();
        $this->setupMockNoid();

        $arkManager = $this->getServiceLocator()->get('Ark\ArkManager');
        $this->namePlugin = $arkManager->getArkNamePlugin();
        $this->namePlugin->deleteDatabase();
        $this->namePlugin->createDatabase();
    }

    public function tearDown(): void
    {
        $this->cleanupResources();
        parent::tearDown();
    }

    /**
     * Test that creating an item via API generates an ARK identifier.
     */
    public function testItemCreationGeneratesArk(): void
    {
        // Create item via API (bypasses controller dispatch issues).
        $item = $this->createItem([]);

        // Check that ARK was generated.
        $ark = $item->value('dcterms:identifier');
        $this->assertNotNull($ark, 'Item should have dcterms:identifier with ARK');
        $this->assertStringStartsWith('ark:/99999/', $ark->value());
    }

    /**
     * Test that ARK is generated with expected format.
     */
    public function testArkFormatIsCorrect(): void
    {
        $item = $this->createItem([]);

        $ark = $item->value('dcterms:identifier')->value();

        // ARK format: ark:/NAAN/name
        $this->assertMatchesRegularExpression('/^ark:\/99999\/[a-zA-Z0-9]+$/', $ark);
    }

    /**
     * Test that multiple items get unique ARKs.
     *
     * Creates 15 items and verifies all generated ARKs are unique.
     */
    public function testMultipleItemsGetUniqueArks(): void
    {
        $itemCount = 15;
        $arks = [];

        for ($i = 0; $i < $itemCount; $i++) {
            $item = $this->createItem([]);
            $ark = $item->value('dcterms:identifier')->value();
            $this->assertNotNull($ark, "Item $i should have an ARK");
            $arks[] = $ark;
        }

        // Check all ARKs are unique.
        $uniqueArks = array_unique($arks);
        $this->assertCount(
            $itemCount,
            $uniqueArks,
            sprintf(
                'All %d items should have unique ARKs. Found %d duplicates.',
                $itemCount,
                $itemCount - count($uniqueArks)
            )
        );

        // Additional check: verify ARK format for all.
        foreach ($arks as $index => $ark) {
            $this->assertMatchesRegularExpression(
                '/^ark:\/99999\/[a-zA-Z0-9]+$/',
                $ark,
                "ARK $index has invalid format: $ark"
            );
        }
    }
}
