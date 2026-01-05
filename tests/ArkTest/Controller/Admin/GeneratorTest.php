<?php declare(strict_types=1);

namespace ArkTest\Controller\Admin;

use ArkTest\ArkTestTrait;
use CommonTest\AbstractHttpControllerTestCase;

/**
 * Tests for ARK generator settings.
 *
 * @covers \Ark\Name\Plugin\Noid
 */
class GeneratorTest extends AbstractHttpControllerTestCase
{
    use ArkTestTrait;

    protected $namePlugin;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
        $this->configureArkSettings();
        $this->setupMockNoid();

        $arkManager = $this->getServiceLocator()->get('Ark\ArkManager');
        $this->namePlugin = $arkManager->getArkNamePlugin();
    }

    public function tearDown(): void
    {
        $this->cleanupResources();
        parent::tearDown();
    }

    /**
     * Test that MT generator produces valid arks.
     */
    public function testMtRandGeneratorProducesValidArks(): void
    {
        $settings = $this->settings();
        $settings->set('ark_name_noid_generator', 'mt_rand');

        $this->namePlugin->deleteDatabase();
        $this->namePlugin->createDatabase();

        $item = $this->createItem([]);

        $ark = $item->value('dcterms:identifier')->value();
        $this->assertMatchesRegularExpression('/^ark:\/99999\/[a-zA-Z0-9]+$/', $ark);
    }

    /**
     * Test that LCG (drand48) generator produces valid arks.
     */
    public function testDrand48GeneratorProducesValidArks(): void
    {
        $settings = $this->settings();
        $settings->set('ark_name_noid_generator', 'drand48');

        $this->namePlugin->deleteDatabase();
        $this->namePlugin->createDatabase();

        $item = $this->createItem([]);

        $ark = $item->value('dcterms:identifier')->value();
        $this->assertMatchesRegularExpression('/^ark:\/99999\/[a-zA-Z0-9]+$/', $ark);
    }

    /**
     * Test that different generators produce different ark sequences.
     */
    public function testDifferentGeneratorsProduceDifferentArks(): void
    {
        $settings = $this->settings();

        // Generate arks with mt_rand.
        $settings->set('ark_name_noid_generator', 'mt_rand');
        $this->namePlugin->deleteDatabase();
        $this->namePlugin->createDatabase();

        $mtArks = [];
        for ($i = 0; $i < 3; $i++) {
            $item = $this->createItem([]);
            $mtArks[] = $item->value('dcterms:identifier')->value();
        }
        $this->cleanupResources();

        // Generate arks with drand48.
        $settings->set('ark_name_noid_generator', 'drand48');
        $this->namePlugin->deleteDatabase();
        $this->namePlugin->createDatabase();

        $lcgArks = [];
        for ($i = 0; $i < 3; $i++) {
            $item = $this->createItem([]);
            $lcgArks[] = $item->value('dcterms:identifier')->value();
        }

        // The sequences should be different.
        $this->assertNotEquals(
            $mtArks,
            $lcgArks,
            'MT and LCG generators should produce different ark sequences'
        );
    }

    /**
     * Test that generator setting defaults to mt_rand.
     */
    public function testGeneratorDefaultsToMtRand(): void
    {
        $settings = $this->settings();

        // Remove the setting to test default behavior.
        $settings->delete('ark_name_noid_generator');

        $this->namePlugin->deleteDatabase();
        $this->namePlugin->createDatabase();

        $item = $this->createItem([]);

        $ark = $item->value('dcterms:identifier')->value();
        $this->assertMatchesRegularExpression('/^ark:\/99999\/[a-zA-Z0-9]+$/', $ark);
    }
}
