<?php declare(strict_types=1);

namespace ArkTest\Controller;

use CommonTest\AbstractHttpControllerTestCase as BaseTestCase;

/**
 * Abstract controller test case for Ark module.
 *
 * Extends CommonTest\AbstractHttpControllerTestCase which provides
 * authentication handling that persists across application resets.
 */
abstract class AbstractControllerTestCase extends BaseTestCase
{
    // Module-specific test helpers can be added here.
}
