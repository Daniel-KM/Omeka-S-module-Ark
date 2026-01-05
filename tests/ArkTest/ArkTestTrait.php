<?php declare(strict_types=1);

namespace ArkTest;

use ArkTest\Name\Plugin\MockNoid;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Api\Manager as ApiManager;

/**
 * Shared test helpers for Ark module tests.
 *
 * Based on Mapper test patterns for comprehensive testing.
 */
trait ArkTestTrait
{
    /**
     * @var ServiceLocatorInterface
     */
    protected $services;

    /**
     * @var array IDs of items created during tests (for cleanup).
     */
    protected array $createdItemIds = [];

    /**
     * @var array IDs of sites created during tests (for cleanup).
     */
    protected array $createdSiteIds = [];

    /**
     * @var bool Whether admin is logged in.
     */
    protected bool $isLoggedIn = false;

    /**
     * Get the service locator.
     *
     * Gets the service manager from the current application.
     * Uses $this->application if available, otherwise gets it fresh.
     */
    protected function getServiceLocator(): ServiceLocatorInterface
    {
        // Always get fresh service locator from current application to avoid
        // stale references after reset().
        if (isset($this->application) && $this->application !== null) {
            return $this->application->getServiceManager();
        }
        return $this->getApplication()->getServiceManager();
    }

    /**
     * Reset the cached service locator.
     */
    protected function resetServiceLocator(): void
    {
        $this->services = null;
    }

    /**
     * Get the API manager.
     */
    protected function api(): ApiManager
    {
        // Ensure we're logged in before API calls.
        if ($this->isLoggedIn) {
            $this->ensureLoggedIn();
        }
        return $this->getServiceLocator()->get('Omeka\ApiManager');
    }

    /**
     * Get the Entity Manager.
     *
     * Note: Made public to match Omeka\Test\TestCase signature.
     */
    public function getEntityManager(): \Doctrine\ORM\EntityManager
    {
        return $this->getServiceLocator()->get('Omeka\EntityManager');
    }

    /**
     * Get the settings service.
     */
    protected function settings(): \Omeka\Settings\Settings
    {
        return $this->getServiceLocator()->get('Omeka\Settings');
    }

    /**
     * Login as admin user.
     *
     * Sets the identity directly in the authentication storage to persist
     * across dispatches in controller tests.
     */
    protected function loginAdmin(): void
    {
        $this->isLoggedIn = true;
        $this->ensureLoggedIn();
    }

    /**
     * Ensure admin is logged in on the current application instance.
     *
     * This is called before API calls and dispatches to handle cases where
     * the application was reset.
     */
    protected function ensureLoggedIn(): void
    {
        $services = $this->getServiceLocator();
        $auth = $services->get('Omeka\AuthenticationService');

        // Check if already authenticated.
        if ($auth->hasIdentity()) {
            return;
        }

        // Authenticate using the password adapter.
        $adapter = $auth->getAdapter();
        $adapter->setIdentity('admin@example.com');
        $adapter->setCredential('root');
        $auth->authenticate();
        // Don't throw on failure - let the test handle the auth error naturally.
    }

    /**
     * Logout current user.
     */
    protected function logout(): void
    {
        $this->isLoggedIn = false;
        $auth = $this->getServiceLocator()->get('Omeka\AuthenticationService');
        $auth->clearIdentity();
    }

    /**
     * Configure Ark settings for testing.
     */
    protected function configureArkSettings(): void
    {
        $settings = $this->settings();
        $settings->set('ark_naan', '99999');
        $settings->set('ark_naa', 'example.org');
        $settings->set('ark_subnaa', 'sub');
        $settings->set('ark_name', 'noid');
        $settings->set('ark_name_noid_template', 'b.rllllk');
        $settings->set('ark_qualifier', 'internal');
        $settings->set('ark_qualifier_position_format', '');
        $settings->set('ark_qualifier_static', 0);
        $settings->set('ark_property', 'dcterms:identifier');
        $settings->set('ark_policy_statement', 'Policy statement');
        $settings->set('ark_policy_main', 'Main policy statement');
        $settings->set('ark_note', 'Note');
    }

    /**
     * Setup mock Noid plugin for testing.
     */
    protected function setupMockNoid(): void
    {
        $services = $this->getServiceLocator();

        $namePlugins = $services->get('Ark\NamePluginManager');
        $namePlugins->setAllowOverride(true);
        $noid = new MockNoid(
            $services->get('Omeka\Logger'),
            $services->get('Omeka\Settings'),
            ''
        );
        $namePlugins->setService('noid', $noid);
        $namePlugins->setAllowOverride(false);
    }

    /**
     * Create a test item with the given data.
     *
     * @param array $data Item data with property terms as keys.
     * @return \Omeka\Api\Representation\ItemRepresentation
     */
    protected function createItem(array $data = []): \Omeka\Api\Representation\ItemRepresentation
    {
        $easyMeta = $this->getServiceLocator()->get('Common\EasyMeta');

        // Build the proper API format.
        $itemData = [];
        foreach ($data as $term => $values) {
            // Skip special keys.
            if (in_array($term, ['o:is_public', 'o:item_set', 'o:resource_template', 'o:resource_class'])) {
                $itemData[$term] = $values;
                continue;
            }

            // Handle property values.
            $propertyId = $easyMeta->propertyId($term);
            if (!$propertyId) {
                continue;
            }

            if (!is_array($values)) {
                $values = [$values];
            }

            $itemData[$term] = [];
            foreach ($values as $value) {
                if (is_array($value)) {
                    // Already formatted.
                    $value['property_id'] = $propertyId;
                    $itemData[$term][] = $value;
                } else {
                    $itemData[$term][] = [
                        'type' => 'literal',
                        'property_id' => $propertyId,
                        '@value' => (string) $value,
                    ];
                }
            }
        }

        $response = $this->api()->create('items', $itemData);
        $item = $response->getContent();
        $this->createdItemIds[] = $item->id();

        return $item;
    }

    /**
     * Create a test site.
     *
     * @param string $slug Site slug.
     * @param string $title Site title.
     * @return \Omeka\Api\Representation\SiteRepresentation
     */
    protected function createSite(string $slug = 'default', string $title = 'Default Site'): \Omeka\Api\Representation\SiteRepresentation
    {
        $response = $this->api()->create('sites', [
            'o:title' => $title,
            'o:slug' => $slug,
            'o:theme' => 'default',
            'o:is_public' => '1',
        ]);
        $site = $response->getContent();
        $this->createdSiteIds[] = $site->id();

        return $site;
    }

    /**
     * Get the path to the fixtures directory.
     */
    protected function getFixturesPath(): string
    {
        return dirname(__DIR__) . '/fixtures';
    }

    /**
     * Load a fixture file by name.
     *
     * @param string $name Fixture filename (with extension).
     * @return string File contents.
     */
    protected function getFixture(string $name): string
    {
        $path = $this->getFixturesPath() . '/' . $name;
        if (!file_exists($path)) {
            throw new \RuntimeException("Fixture not found: $path");
        }
        return file_get_contents($path);
    }

    /**
     * Cleanup resources created during tests.
     */
    protected function cleanupResources(): void
    {
        // Delete created items.
        foreach ($this->createdItemIds as $id) {
            try {
                $this->api()->delete('items', $id);
            } catch (\Exception $e) {
                // Ignore errors during cleanup.
            }
        }
        $this->createdItemIds = [];

        // Delete created sites.
        foreach ($this->createdSiteIds as $id) {
            try {
                $this->api()->delete('sites', $id);
            } catch (\Exception $e) {
                // Ignore errors during cleanup.
            }
        }
        $this->createdSiteIds = [];
    }

    /**
     * Assert that a string contains another string (helper for older PHPUnit).
     */
    protected function assertStringContains(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertStringContainsString($needle, $haystack, $message);
    }

    /**
     * Build URL from route name and parameters.
     *
     * @param string $route Route name.
     * @param array $params Route parameters.
     * @return string The URL.
     */
    protected function urlFromRoute(string $route, array $params = []): string
    {
        $services = $this->getServiceLocator();
        $router = $services->get('Router');
        return $router->assemble($params, ['name' => $route]);
    }

    /**
     * Get the URL for a module's configure page.
     *
     * @param string $moduleId Module ID.
     * @return string The configure URL.
     */
    protected function moduleConfigureUrl(string $moduleId): string
    {
        return $this->urlFromRoute('admin/default', [
            'controller' => 'module',
            'action' => 'configure',
            'id' => $moduleId,
        ]);
    }
}
