<?php declare(strict_types=1);

namespace ArkTest\Controller\Admin;

use ArkTest\ArkTestTrait;
use CommonTest\AbstractHttpControllerTestCase;
use Laminas\View\Renderer\PhpRenderer;

/**
 * Tests for the Ark module configuration.
 *
 * Note: Module configure pages via /admin/module/configure?id=Ark require
 * the module to be loaded in Laminas ModuleManager at boot time. In test
 * environment, modules are installed via Omeka's ModuleManager but not
 * registered with Laminas. Therefore, we test getConfigForm() and
 * handleConfigForm() directly instead of dispatching to the route.
 *
 * @covers \Ark\Module
 */
class ConfigControllerTest extends AbstractHttpControllerTestCase
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
    }

    public function tearDown(): void
    {
        $this->cleanupResources();
        parent::tearDown();
    }

    /**
     * Test that ARK settings are applied correctly.
     */
    public function testArkSettingsAreApplied(): void
    {
        $settings = $this->settings();

        // Verify our configured settings are applied.
        $this->assertSame('99999', $settings->get('ark_naan'));
        $this->assertSame('example.org', $settings->get('ark_naa'));
        $this->assertSame('noid', $settings->get('ark_name'));
    }

    /**
     * Test that getConfigForm() returns valid HTML.
     *
     * This tests the Module::getConfigForm() method directly, which is what
     * the configure page would render.
     */
    public function testGetConfigFormReturnsValidHtml(): void
    {
        $services = $this->getServiceLocator();

        // Get the Module instance.
        $moduleManager = $services->get('Omeka\ModuleManager');
        $module = $moduleManager->getModule('Ark');
        $this->assertNotNull($module, 'Ark module should be installed');

        // Get the actual module class instance from Laminas ModuleManager.
        // Note: We need to instantiate it ourselves since it's not in Laminas.
        $moduleClass = new \Ark\Module();
        $moduleClass->setServiceLocator($services);

        // Get a PhpRenderer for rendering the form.
        $renderer = $services->get('ViewPhpRenderer');

        // Get the config form HTML.
        $html = $moduleClass->getConfigForm($renderer);

        // Verify it returns valid HTML.
        $this->assertIsString($html);
        $this->assertNotEmpty($html);

        // Check for expected form elements.
        $this->assertStringContainsString('ark_naan', $html);
        $this->assertStringContainsString('ark_naa', $html);
        $this->assertStringContainsString('ark_name', $html);
    }

    /**
     * Test that configuration form contains expected explanatory text.
     */
    public function testConfigFormContainsExplanatoryText(): void
    {
        $services = $this->getServiceLocator();

        $moduleClass = new \Ark\Module();
        $moduleClass->setServiceLocator($services);

        $renderer = $services->get('ViewPhpRenderer');
        $html = $moduleClass->getConfigForm($renderer);

        // Check for explanatory text about ARK.
        $this->assertStringContainsString('ark', strtolower($html));
        $this->assertStringContainsString('explanation', $html);
    }

    /**
     * Test that settings can be modified.
     */
    public function testSettingsCanBeModified(): void
    {
        $settings = $this->settings();

        // Change a setting.
        $originalNaa = $settings->get('ark_naa');
        $settings->set('ark_naa', 'modified.example.org');

        // Verify the change.
        $this->assertSame('modified.example.org', $settings->get('ark_naa'));

        // Restore original.
        $settings->set('ark_naa', $originalNaa);
    }

    /**
     * Test that the Ark module is configurable.
     */
    public function testModuleIsConfigurable(): void
    {
        $services = $this->getServiceLocator();
        $moduleManager = $services->get('Omeka\ModuleManager');

        $module = $moduleManager->getModule('Ark');
        $this->assertNotNull($module);

        // Check that module reports as configurable.
        $this->assertTrue($module->isConfigurable(), 'Ark module should be configurable');
    }

    /**
     * Test that config form shows warning when NOID database exists.
     *
     * When the NOID database is created, some settings (NAAN, NAA, subnaa,
     * template) become unmodifiable because they are stored in the database.
     */
    public function testConfigFormShowsWarningWhenDatabaseExists(): void
    {
        $services = $this->getServiceLocator();

        // Create the NOID database.
        $this->namePlugin->createDatabase();

        $moduleClass = new \Ark\Module();
        $moduleClass->setServiceLocator($services);

        $renderer = $services->get('ViewPhpRenderer');
        $html = $moduleClass->getConfigForm($renderer);

        // Check for warning message about unmodifiable settings.
        $this->assertStringContainsString('NOID database is already created', $html);
        $this->assertStringContainsString('not modifiable', $html);
        $this->assertStringContainsString('arkandnoid', $html);
    }

    /**
     * Test that config form shows mismatch warning when settings differ from database.
     *
     * When the Omeka settings differ from what's stored in the NOID database,
     * the form should display a warning with the actual database values.
     */
    public function testConfigFormShowsMismatchWarningWhenSettingsDiffer(): void
    {
        $services = $this->getServiceLocator();
        $settings = $this->settings();

        // Create the NOID database with current settings.
        $this->namePlugin->createDatabase();

        // Change the Omeka setting to differ from the database.
        $settings->set('ark_naa', 'different.example.org');

        $moduleClass = new \Ark\Module();
        $moduleClass->setServiceLocator($services);

        $renderer = $services->get('ViewPhpRenderer');
        $html = $moduleClass->getConfigForm($renderer);

        // Check for mismatch warning message.
        $this->assertStringContainsString('NOID database is already created', $html);
        $this->assertStringContainsString('settings are not the same', $html);

        // Check that stored database values are displayed.
        $this->assertStringContainsString('example.org', $html); // Original NAA from database.
    }

    /**
     * Test that config form does not show warning when database does not exist.
     */
    public function testConfigFormNoWarningWithoutDatabase(): void
    {
        $services = $this->getServiceLocator();

        // Ensure database is deleted (done in setUp, but be explicit).
        $this->namePlugin->deleteDatabase();

        $moduleClass = new \Ark\Module();
        $moduleClass->setServiceLocator($services);

        $renderer = $services->get('ViewPhpRenderer');
        $html = $moduleClass->getConfigForm($renderer);

        // Should NOT contain the database warning.
        $this->assertStringNotContainsString('NOID database is already created', $html);
    }

    /**
     * The prefix is advised only before the creation of the database.
     */
    public function testConfigFormAdvisesPrefixBeforeCreation(): void
    {
        $advice = 'A prefix before the dot is recommended';
        $this->assertStringContainsString($advice, $this->renderConfigForm());

        $this->namePlugin->createDatabase();
        $this->assertStringNotContainsString($advice, $this->renderConfigForm());
    }

    public function prefixProvider(): array
    {
        return [
            'with prefix' => ['b.rllllk', false, true],
            'without prefix' => ['.zek', false, false],
            'without prefix, confirmed' => ['.zek', true, true],
        ];
    }

    /**
     * The database is not created without prefix until it is confirmed.
     *
     * @dataProvider prefixProvider
     */
    public function testCreationRequiresConfirmationWithoutPrefix(string $template, bool $confirmed, bool $created): void
    {
        $services = $this->getServiceLocator();
        $this->settings()->set('ark_name_noid_template', $template);
        $this->settings()->set('ark_store', '');

        $post = [];
        foreach (array_keys($services->get('Config')['ark']['config']) as $key) {
            $value = $this->settings()->get($key);
            $post[$key] = is_bool($value) ? (int) $value : $value;
        }
        $form = $services->get('FormElementManager')->get(\Ark\Form\ConfigForm::class);
        $form->init();
        $post['csrf'] = $form->get('csrf')->getValue();
        $post['ark_noid_no_prefix'] = (int) $confirmed;

        $request = new \Laminas\Http\PhpEnvironment\Request();
        $request->setMethod('POST');
        $request->setPost(new \Laminas\Stdlib\Parameters($post));
        $controller = new class($request) extends \Laminas\Mvc\Controller\AbstractActionController {
            public function __construct($request)
            {
                $this->request = $request;
            }
        };
        $controller->setPluginManager($services->get('ControllerPluginManager'));

        $messenger = $services->get('ControllerPluginManager')->get('messenger');
        $messenger->clear();

        $module = new \Ark\Module();
        $module->setServiceLocator($services);
        $this->assertTrue($module->handleConfigForm($controller));
        $this->assertSame($created, $this->namePlugin->isDatabaseCreated());
        if ($created) {
            $this->assertNotSame('', (string) $this->settings()->get('ark_store'), 'The store is fixed once the database is created.');
        }

        $warnings = implode(' ', array_map('strval', $messenger->get()[\Omeka\Mvc\Controller\Plugin\Messenger::WARNING] ?? []));
        $this->assertSame(!$created, strpos($warnings, 'has no prefix') !== false, $warnings);
    }

    protected function renderConfigForm(): string
    {
        $services = $this->getServiceLocator();
        $module = new \Ark\Module();
        $module->setServiceLocator($services);
        return $module->getConfigForm($services->get('ViewPhpRenderer'));
    }
}
