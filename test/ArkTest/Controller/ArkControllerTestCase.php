<?php declare(strict_types=1);

namespace ArkTest\Controller;

use ArkTest\ArkTestTrait;
use CommonTest\AbstractHttpControllerTestCase;

/**
 * Base controller test case for Ark module.
 *
 * Extends CommonTest\AbstractHttpControllerTestCase which provides
 * authentication handling that persists across application resets.
 *
 * Includes ArkTestTrait for common test helpers:
 * - configureArkSettings() - Set up ARK module settings
 * - setupMockNoid() - Set up mock Noid plugin
 * - createItem() - Create test items
 * - createSite() - Create test sites
 * - cleanupResources() - Clean up created resources
 */
abstract class ArkControllerTestCase extends AbstractHttpControllerTestCase
{
    use ArkTestTrait;

    public function setUp(): void
    {
        parent::setUp();

        $this->loginAdmin();

        // Configure Ark settings for testing.
        $this->configureArkSettings();
        $this->setupMockNoid();
    }

    public function tearDown(): void
    {
        $this->cleanupResources();
        parent::tearDown();
    }
}
