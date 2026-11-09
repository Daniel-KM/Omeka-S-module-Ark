<?php declare(strict_types=1);

namespace Ark;

use Common\Stdlib\PsrMessage;

// Load the module dependencies when installed as a zip. With composer,
// libraries are stored in omeka vendor/ and the module has none.
if (file_exists(dirname(__DIR__, 2) . '/vendor/autoload.php')) {
    require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
}

/**
 * @var Module $this
 * @var \Laminas\ServiceManager\ServiceLocatorInterface $services
 * @var string $newVersion
 * @var string $oldVersion
 *
 * @var \Omeka\Api\Manager $api
 * @var \Omeka\View\Helper\Url $url
 * @var \Omeka\Settings\Settings $settings
 * @var \Doctrine\DBAL\Connection $connection
 * @var \Doctrine\ORM\EntityManager $entityManager
 * @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger
 */
$plugins = $services->get('ControllerPluginManager');
$url = $services->get('ViewHelperManager')->get('url');
$api = $plugins->get('api');
$settings = $services->get('Omeka\Settings');
$translate = $plugins->get('translate');
$translator = $services->get('MvcTranslator');
$connection = $services->get('Omeka\Connection');
$messenger = $plugins->get('messenger');
$entityManager = $services->get('Omeka\EntityManager');

$config = $services->get('Config');
$basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
$databaseDir = $basePath . '/arkandnoid';
$noidDir = $databaseDir . '/NOID';

$noidMaintenance = new \Ark\Stdlib\NoidMaintenance(
    $connection,
    $services->get('Common\EasyMeta'),
    $settings,
    $services->get('Omeka\Cli'),
    $databaseDir,
    $config['connection'] ?? [],
    $services->get('Omeka\Logger'),
    $services->get('Common\DirectoryManager')
);

if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.96')) {
    $message = new \Omeka\Stdlib\Message(
        $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
        'Common', '3.4.96'
    );
    $messenger->addError($message);
    throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $translate('Missing requirement. Unable to upgrade.')); // @translate
}

$this->checkPhpVersion();

if (version_compare($oldVersion, '3.5.7', '<')) {
    $settings->delete('ark_use_admin');
    $settings->delete('ark_use_public');

    $settings->set('ark_name_noid_template', $settings->get('ark_noid_template'));
    $settings->delete('ark_noid_template');

    $settings->set('ark_name', 'noid');
    $settings->set('ark_qualifier', 'internal');
    $settings->set('ark_qualifier_position_format', '');
    $settings->set('ark_qualifier_static', false);
}

if (version_compare($oldVersion, '3.5.14', '<')) {
    $settings->set('ark_property', 'dcterms:identifier');
    $message = new PsrMessage(
        'It is now possible to define a specific property to store arks. Warning: if you change it, old arks won’t be moved (use module {link}Bulk Edit{link_end} for that).', // @translate
        ['link' => '<a href="https://gitlab.com/Daniel-KM/Omeka-S-module-BulkEdit" target="_blank" rel="noopener">', 'link_end' => '</a>']
    );
    $message->setEscapeHtml(false);
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.5.16', '<')) {
    // Migrate from BerkeleyDB to LMDB or XML if possible. BerkeleyDB is
    // deprecated and unavailable on recent Linux distributions. When it is not
    // possible, the upgrade is not blocked: the minting is locked below and the
    // migration is available in the config form.
    if ($noidMaintenance->isMigrationNeeded()) {
        if (array_filter($noidMaintenance->migrationMethods())) {
            try {
                $store = $noidMaintenance->migrate();
                $message = new PsrMessage(
                    'The Noid database has been migrated from BerkeleyDB to {format}.', // @translate
                    ['format' => strtoupper($store)]
                );
                $messenger->addSuccess($message);
            } catch (\RuntimeException $e) {
                $message = new PsrMessage(
                    'Migration from BerkeleyDB failed: {error}', // @translate
                    ['error' => $e->getMessage()]
                );
                $messenger->addError($message);
            }
        }
    } elseif ($noidMaintenance->hasBdbDatabase()) {
        $message = new PsrMessage(
            'A Noid database other than BerkeleyDB already exists. Skipping migration. You may remove noid.bdb manually if migration was successful.' // @translate
        );
        $messenger->addWarning($message);
    }

    // Initialize new setting if not set.
    if ($settings->get('ark_store') === null) {
        $settings->set('ark_store', '');
    }
}

if (version_compare($oldVersion, '3.5.18', '<')) {
    // The setting of the generator was added in version 3.5.16 with "mt_rand",
    // but the older databases use "drand48", so the setting is aligned on the
    // database.
    $noidMaintenance->syncGenerator();
}

// The noid database is stored in the directory "files", that is served by the
// web server, so its directory is protected.
if (is_dir($databaseDir) && !$services->get('Common\DirectoryManager')->protectDirectory($databaseDir)) {
    $message = new PsrMessage(
        'The directory of the noid database, {path}, is served by the web server, but the file .htaccess cannot be written to protect it. Deny the access to it in the config of the web server.', // @translate
        ['path' => $databaseDir]
    );
    $messenger->addError($message);
}

// Fix the store to the backend of the existing database, since the web server
// and the command line, that runs the jobs, may have different extensions, and
// use two databases with the same template, so they would issue the same arks.
$noidMaintenance->syncStore();

// Repair a store pointing to a backend that the server cannot use, for example
// bdb when the extension dba was removed by a system upgrade: any page creating
// a resource would fail. The existing database is preferred to the default one,
// in order to keep the minted arks.
$currentStore = (string) $settings->get('ark_store');
if (strlen($currentStore) && !\Ark\Stdlib\NoidStore::isAvailable($currentStore)) {
    $newStore = '';
    foreach (['lmdb', 'xml', 'sqlite'] as $format) {
        if (file_exists($noidDir . '/noid.' . $format) && \Ark\Stdlib\NoidStore::isAvailable($format)) {
            $newStore = $format;
            break;
        }
    }
    $settings->set('ark_store', $newStore);
    $message = new PsrMessage(
        'The storage backend "{old}" of the noid database is not available on this server. It has been replaced by "{new}". Check the config of the module.', // @translate
        ['old' => $currentStore, 'new' => $newStore ?: 'default']
    );
    $messenger->addWarning($message);
}

if ($settings->get('ark_name') === 'noid' && $noidMaintenance->updateLock()) {
    // Lock the minting when the noid database is not ready, for example when
    // it should be migrated from BerkeleyDB, or when it was emptied by a failed
    // migration of version 3.5.16: the module version stored in the database is
    // not reverted with the files, so a rolled back version is not migrated
    // again. Else the next arks would be duplicates of existing ones.
    $link = [
        'link' => sprintf('<a href="%s">', htmlspecialchars($url('admin/default', ['controller' => 'module', 'action' => 'configure'], ['query' => ['id' => 'Ark'], 'fragment' => 'ark-noid-maintenance']))),
        'link_end' => '</a>',
    ];
    if ($noidMaintenance->isMigrationNeeded()) {
        $message = new PsrMessage(
            'The noid database should be migrated from BerkeleyDB: no ark will be created until then. See the {link}config of the module{link_end}.', // @translate
            $link
        );
    } else {
        $message = new PsrMessage(
            'The noid database does not know {count} arks with the naan "{naan}" stored in Omeka that the minter can issue, so the next arks may be duplicates: no ark will be created until they are reserved, or the database is restored or rebuilt. See the {link}config of the module{link_end}.', // @translate
            $link + ['count' => count($noidMaintenance->unknownArks()), 'naan' => (string) $settings->get('ark_naan')]
        );
    }
    $message->setEscapeHtml(false);
    $messenger->addError($message);
}
