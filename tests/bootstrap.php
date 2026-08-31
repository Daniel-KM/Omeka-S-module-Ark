<?php declare(strict_types=1);

/**
 * Bootstrap file for module tests.
 *
 * Use Common module Bootstrap helper for test setup.
 * The Bootstrap automatically registers:
 * - CommonTest\ namespace (test utilities like AbstractHttpControllerTestCase)
 * - Module namespaces from composer.json (autoload and autoload-dev)
 */

require dirname(__DIR__, 3) . '/modules/Common/tests/Bootstrap.php';

\CommonTest\Bootstrap::bootstrap(
    // ?DigitalObject: registered globally via STI on resource, its table must
    // exist for resource value queries (linked resources).
    ['Common', 'Ark', '?DigitalObject'],
    'ArkTest',
    __DIR__ . '/ArkTest'
);
