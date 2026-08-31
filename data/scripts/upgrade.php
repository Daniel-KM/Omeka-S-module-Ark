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

if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.91')) {
    $message = new \Omeka\Stdlib\Message(
        $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
        'Common', '3.4.91'
    );
    $messenger->addError($message);
    throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $translate('Missing requirement. Unable to upgrade.')); // @translate
}

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
    // Migrate from BerkeleyDB to LMDB or XML if needed. BerkeleyDB is
    // deprecated and unavailable on recent Linux distributions.

    $bdbFile = $noidDir . '/noid.bdb';

    // Check if a bdb database exists that needs migration.
    $hasBdbDatabase = file_exists($bdbFile);

    if ($hasBdbDatabase) {
        // Check if migration was already done to avoid overwriting existing
        // data. A store set to another backend, in particular a database
        // without file, means that it was configured manually, so the migration
        // would override a working setting.
        $xmlFile = $noidDir . '/noid.xml';
        $lmdbFile = $noidDir . '/noid.lmdb';
        $sqliteFile = $noidDir . '/noid.sqlite';
        $currentStore = (string) $settings->get('ark_store');
        if (file_exists($xmlFile) || file_exists($lmdbFile) || file_exists($sqliteFile)
            || !in_array($currentStore, ['', 'bdb'], true)
        ) {
            $message = new PsrMessage(
                'A Noid database other than BerkeleyDB already exists. Skipping migration. You may remove noid.bdb manually if migration was successful.' // @translate
            );
            $messenger->addWarning($message);
        } else {
            $hasDba = extension_loaded('dba');
            $handlers = $hasDba ? dba_handlers() : [];
            $hasBdbHandler = in_array('db4', $handlers);
            $hasLmdbHandler = in_array('lmdb', $handlers);

            /** @var \Omeka\Stdlib\Cli $cli */
            $cli = $services->get('Omeka\Cli');

            // The target database is created before the data are imported, so a
            // partial file remains when the import fails. It must be removed,
            // else the next upgrade would take it for a successful migration
            // and would skip it, loosing all arks.
            $removeTargetFile = function (string $format) use ($noidDir): void {
                $extensions = [
                    'lmdb' => 'lmdb',
                    'xml' => 'xml',
                    'sqlite' => 'sqlite',
                ];
                if (isset($extensions[$format])) {
                    $file = $noidDir . '/noid.' . $extensions[$format];
                    if (file_exists($file)) {
                        @unlink($file);
                    }
                }
            };

            if (!$hasBdbHandler) {
                // db4 handler not available: cannot read the bdb database
                // directly. Try alternative migration methods.

                $jsonFile = $noidDir . '/noid_data.json';

                // Check if db_dump command is available using Omeka CLI service.
                $dbDumpPath = $cli->getCommandPath('db_dump');
                $hasDbDump = (bool) $dbDumpPath;

                // Determine target format: lmdb if available, else xml.
                $targetFormat = $hasLmdbHandler ? 'lmdb' : 'xml';

                /**
                 * Helper to parse db_dump -p output file to return key-value pairs.
                 *
                 * The db_dump -p format is:
                 *   VERSION=3
                 *   format=print
                 *   type=btree
                 *   ...
                 *   HEADER=END
                 *    key1
                 *    value1
                 *    key2
                 *    value2
                 *   DATA=END
                 *
                 * Each key and value line starts with a single space. Special
                 * characters are escaped as \xx (hex) and a literal backslash
                 * is escaped as a double backslash.
                 */
                $parseDumpOutput = function (string $dumpOutput): array {
                    $data = [];
                    $lines = explode("\n", $dumpOutput);
                    $inData = false;
                    $isKey = true;
                    $currentKey = null;
                    foreach ($lines as $line) {
                        if (!$inData) {
                            if (trim($line) === 'HEADER=END') {
                                $inData = true;
                            }
                            continue;
                        }
                        if (trim($line) === 'DATA=END') {
                            break;
                        }
                        if ($line === '') {
                            continue;
                        }
                        if ($line[0] === ' ') {
                            $value = substr($line, 1);
                            // Decode a double backslash and the \xx hex format
                            // in a single pass, else a literal backslash
                            // followed by two hex digits would be decoded
                            // twice.
                            $value = preg_replace_callback(
                                '/\\\\(\\\\|[0-9a-fA-F]{2})/',
                                function ($matches) {
                                    return $matches[1] === '\\' ? '\\' : chr(hexdec($matches[1]));
                                },
                                $value
                            );
                            if ($isKey) {
                                $currentKey = $value;
                                $isKey = false;
                            } else {
                                if ($currentKey !== null) {
                                    $data[$currentKey] = $value;
                                }
                                $currentKey = null;
                                $isKey = true;
                            }
                        }
                    }
                    return $data;
                };

                /**
                 * Wrap an array of keys/values as a read-only source database.
                 *
                 * This allows to use the import of the storage classes, that
                 * writes the keys directly, instead of a loop on set(), that
                 * checks the existence of each key: on xml, that lookup is a
                 * xpath on the whole document, so the import of a big database
                 * takes hours instead of seconds.
                 */
                $arraySource = function (array $data): \Noid\Storage\DatabaseInterface {
                    return new class($data) implements \Noid\Storage\DatabaseInterface {
                        private $data;

                        public function __construct(array $data)
                        {
                            $this->data = $data;
                        }

                        public function open($settings, $mode)
                        {
                            return $this;
                        }

                        public function close()
                        {
                        }

                        public function import($src_db)
                        {
                            return false;
                        }

                        public function get($key)
                        {
                            return $this->data[$key] ?? false;
                        }

                        public function set($key, $value)
                        {
                            $this->data[$key] = $value;
                            return true;
                        }

                        public function delete($key)
                        {
                            unset($this->data[$key]);
                            return true;
                        }

                        public function exists($key)
                        {
                            return isset($this->data[$key]);
                        }

                        public function get_range($pattern, $limit = 0)
                        {
                            $result = [];
                            foreach ($this->data as $key => $value) {
                                if ((string) $pattern !== '' && strpos((string) $key, (string) $pattern) !== 0) {
                                    continue;
                                }
                                $result[$key] = $value;
                                if ($limit > 0 && count($result) >= $limit) {
                                    break;
                                }
                            }
                            return $result;
                        }

                        public function isOpen()
                        {
                            return true;
                        }
                    };
                };

                // Helper function to import data to target format using Noid
                // storage classes.
                $importToTarget = function (array $data, string $noidDir, string $format) use ($databaseDir, $arraySource, $removeTargetFile): void {
                    $storageClass = \Noid\Lib\Globals::DB_TYPES[$format] ?? null;
                    if (!$storageClass || !class_exists($storageClass)) {
                        throw new \Exception("Unknown or unavailable storage format: $format");
                    }
                    /** @var \Noid\Storage\DatabaseInterface $storage */
                    $storage = new $storageClass();
                    $storageSettings = [
                        'db_type' => $format,
                        'storage' => [
                            $format => [
                                'data_dir' => $databaseDir,
                                'db_name' => 'NOID',
                            ],
                        ],
                    ];
                    try {
                        $storage->open($storageSettings, \Noid\Storage\DatabaseInterface::DB_CREATE);
                    } catch (\Throwable $e) {
                        throw new \Exception("Cannot create $format database in $noidDir: " . $e->getMessage());
                    }
                    if (!$storage->isOpen()) {
                        throw new \Exception("Cannot create $format database in $noidDir: database not open");
                    }
                    $imported = $storage->import($arraySource($data));
                    // The database is written on close, so it must be closed
                    // before the partial file can be removed.
                    $storage->close();
                    if (!$imported) {
                        $removeTargetFile($format);
                        throw new \Exception("No data imported into the $format database in $noidDir");
                    }
                };

                if ($hasDbDump) {
                    // db_dump is available: create dump and import directly.
                    $dumpCmd = sprintf('%s -p %s', escapeshellarg($dbDumpPath), escapeshellarg($bdbFile));
                    $dumpOutput = $cli->execute($dumpCmd);

                    if ($dumpOutput === false) {
                        $message = new PsrMessage(
                            'Failed to dump BerkeleyDB database using db_dump.' // @translate
                        );
                        throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message->setTranslator($translator));
                    }

                    // Parse dump output.
                    $data = $parseDumpOutput($dumpOutput);
                    if (empty($data)) {
                        $message = new PsrMessage(
                            'No data found in BerkeleyDB dump. You may remove existing files in noid directory.' // @translate
                        );
                        throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message->setTranslator($translator));
                    }

                    // Normalize generator_random key to new names.
                    $generatorKey = ':/generator_random';
                    if (isset($data[$generatorKey])) {
                        $oldGenerator = $data[$generatorKey];
                        // Map legacy names to new standardized names.
                        // See Noid\Lib\Generator::_genid() switch statement.
                        $generatorMap = [
                            'php rand()' => 'mt_rand',
                            'php mt_rand()' => 'mt_rand',
                            'mt_rand' => 'mt_rand',
                            'PerlRandom' => 'drand48',
                            'Perl_Random' => 'drand48',
                            'perl rand()' => 'drand48',
                            'drand48' => 'drand48',
                        ];
                        $data[$generatorKey] = $generatorMap[$oldGenerator] ?? 'drand48';
                    }

                    // Import to target format using Noid storage classes.
                    try {
                        $importToTarget($data, $noidDir, $targetFormat);
                    } catch (\Throwable $e) {
                        $removeTargetFile($targetFormat);
                        $message = new PsrMessage(
                            'Failed to import Noid database: {error}', // @translate
                            ['error' => $e->getMessage()]
                        );
                        throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message->setTranslator($translator));
                    }

                    // Update setting to use new format.
                    $settings->set('ark_store', $targetFormat);

                    $message = new PsrMessage(
                        'The Noid database has been migrated from BerkeleyDB to {format} using db_dump.', // @translate
                        ['format' => strtoupper($targetFormat)]
                    );
                    $messenger->addSuccess($message);

                } elseif (file_exists($jsonFile)) {
                    // JSON export file exists: import it directly.
                    $jsonContent = file_get_contents($jsonFile);
                    if ($jsonContent === false) {
                        $message = new PsrMessage(
                            'Cannot read JSON file: {file}', // @translate
                            ['file' => $jsonFile]
                        );
                        throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message->setTranslator($translator));
                    }

                    $data = json_decode($jsonContent, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $message = new PsrMessage(
                            'Invalid JSON in file {file}: {error}', // @translate
                            ['file' => $jsonFile, 'error' => json_last_error_msg()]
                        );
                        throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message->setTranslator($translator));
                    }

                    if (empty($data)) {
                        $message = new PsrMessage(
                            'No data found in JSON file.' // @translate
                        );
                        throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message->setTranslator($translator));
                    }

                    // Import to target format using Noid storage classes.
                    try {
                        $importToTarget($data, $noidDir, $targetFormat);
                    } catch (\Throwable $e) {
                        $removeTargetFile($targetFormat);
                        $message = new PsrMessage(
                            'Failed to import Noid database from JSON: {error}', // @translate
                            ['error' => $e->getMessage()]
                        );
                        throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message->setTranslator($translator));
                    }

                    // Update setting to use new format.
                    $settings->set('ark_store', $targetFormat);

                    $message = new PsrMessage(
                        'The Noid database has been migrated from BerkeleyDB to {format} using noid_data.json.', // @translate
                        ['format' => strtoupper($targetFormat)]
                    );
                    $messenger->addSuccess($message);

                } else {
                    // No automatic migration possible: show instructions.
                    $message = new PsrMessage(<<<MSG
                            A BerkeleyDB Noid database was found but cannot be migrated automatically.
                            Option 1: Install db-util and retry:
                                - With Debian/Ubuntu: `apt install db-util`
                                - With RedHat/Fedora: `dnf install libdb-utils`
                            Option 2: Export to JSON manually and retry:
                                - Use Perl: `perl vendor/daniel-km/noid/scripts/export_bdb_to_json.pl {bdb_file} {json_file}`
                                - Use Python: `python3 vendor/daniel-km/noid/scripts/export_bdb_to_json.py {bdb_file} {json_file}`
                            See {link}Noid4Php documentation{link_end} for details.
                            MSG, // @translate
                        [
                            'bdb_file' => $bdbFile,
                            'json_file' => $jsonFile,
                            'link' => '<a href="https://gitlab.com/Daniel-KM/Noid4Php#method-2-using-import_from_dumpphp-db4-handler-not-available" target="_blank" rel="noopener">',
                            'link_end' => '</a>',
                        ]
                    );
                    $message->setEscapeHtml(false);
                    $messenger->addError($message);
                    throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $translate('Missing requirement. Unable to upgrade.')); // @translate
                }
            } else {
                // db4 handler is available: automatic migration possible.
                // Try lmdb first if available, fallback to xml.
                $targetFormats = $hasLmdbHandler ? ['lmdb', 'xml'] : ['xml'];

                $bdbSettings = [
                    'db_type' => 'bdb',
                    'storage' => [
                        'bdb' => [
                            'data_dir' => $databaseDir,
                            'db_name' => 'NOID',
                        ],
                    ],
                ];

                // Open bdb to get database info.
                $noid = \Noid\Lib\Db::dbopen($bdbSettings, \Noid\Storage\DatabaseInterface::DB_RDONLY);
                if (!$noid) {
                    $message = new PsrMessage(
                        'Cannot open BerkeleyDB database for migration.' // @translate
                    );
                    throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message->setTranslator($translator));
                }

                $template = \Noid\Lib\Db::getCached('template');
                $naan = \Noid\Lib\Db::getCached('naan');
                $naa = \Noid\Lib\Db::getCached('naa');
                $subnaa = \Noid\Lib\Db::getCached('subnaa');
                $generator = \Noid\Lib\Db::getCached('generator') ?: 'drand48';
                \Noid\Lib\Db::dbclose($noid);

                $contact = $settings->get('administrator_email', 'admin@example.org');
                $term = ($naan && $naa && $subnaa) ? 'long' : 'medium';

                $migrated = false;
                $lastError = '';

                foreach ($targetFormats as $targetFormat) {
                    try {
                        // Prepare target settings.
                        $targetSettings = [
                            'db_type' => $targetFormat,
                            'generator' => $generator,
                            'storage' => [
                                $targetFormat => [
                                    'data_dir' => $databaseDir,
                                    'db_name' => 'NOID',
                                ],
                                'bdb' => [
                                    'data_dir' => $databaseDir,
                                    'db_name' => 'NOID',
                                ],
                            ],
                        ];

                        // Create target database.
                        $erc = \Noid\Lib\Db::dbcreate($targetSettings, $contact, $template, $term, $naan, $naa, $subnaa);
                        if (!$erc) {
                            throw new \Exception('Cannot create database: ' . \Noid\Lib\Log::errmsg(null, 1));
                        }

                        // Import data from bdb to target. The import erases the
                        // destination first, so a false result means that the
                        // target database is empty and that all arks are lost.
                        $imported = \Noid\Lib\Db::dbimport($targetSettings, 'bdb');
                        if (!$imported) {
                            throw new \Exception('No data imported from the BerkeleyDB database.');
                        }

                        // Update setting to use new format.
                        $settings->set('ark_store', $targetFormat);

                        $message = new PsrMessage(
                            'The Noid database has been migrated from BerkeleyDB to {format}.', // @translate
                            ['format' => strtoupper($targetFormat)]
                        );
                        $messenger->addSuccess($message);

                        $migrated = true;
                        break;

                    } catch (\Throwable $e) {
                        // Remove the partial database before the next format.
                        $removeTargetFile($targetFormat);
                        $lastError .= $e->getMessage();
                        // Continue to try next format.
                    }
                }

                if (!$migrated) {
                    $message = new PsrMessage(
                        'Migration from BerkeleyDB failed: {error}', // @translate
                        ['error' => $lastError]
                    );
                    throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message->setTranslator($translator));
                }
            }
        }
    }

    // Initialize new setting if not set.
    if ($settings->get('ark_store') === null) {
        $settings->set('ark_store', '');
    }

}

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

if (version_compare($oldVersion, '3.5.17', '<') && $settings->get('ark_name') === 'noid') {
    // Version 3.5.16 did not check the result of the import, that erases the
    // destination before reading the source, so a failed migration could leave
    // a new database with the counter reset. This check is done for any old
    // version, because such a database may have been created by a version
    // 3.5.16 that was rolled back: the module version stored in the database is
    // not reverted with the files, so the migration above is skipped.
    $store = (string) $settings->get('ark_store');
    if ($store === '') {
        $store = extension_loaded('dba') && in_array('lmdb', dba_handlers()) ? 'lmdb' : 'xml';
    }

    $storageClass = \Noid\Lib\Globals::DB_TYPES[$store] ?? null;
    if ($storageClass && class_exists($storageClass) && \Ark\Stdlib\NoidStore::isAvailable($store)) {
        // The counter is the number of identifiers minted since the creation.
        $counter = null;
        try {
            /** @var \Noid\Storage\DatabaseInterface $storage */
            $storage = new $storageClass();
            $storage->open([
                'db_type' => $store,
                'storage' => [
                    $store => [
                        'data_dir' => $databaseDir,
                        'db_name' => 'NOID',
                    ],
                ],
            ], \Noid\Storage\DatabaseInterface::DB_RDONLY);
            if ($storage->isOpen()) {
                $counter = $storage->get(':/oacounter');
                $storage->close();
            }
        } catch (\Throwable $e) {
            // There is no database to check yet.
        }

        // Only the arks that the minter can issue may become duplicates: arks
        // imported from another institution have another naan, and arks stored
        // in another property are not managed by the module.
        $naan = (string) $settings->get('ark_naan');
        $propertyTerm = $settings->get('ark_property') ?: 'dcterms:identifier';
        $propertyId = (int) $services->get('Common\EasyMeta')->propertyId($propertyTerm);
        $totalArks = $propertyId
            ? (int) $connection->executeQuery(
                'SELECT COUNT(*) FROM `value` WHERE `property_id` = :property_id AND `type` = "literal" AND `value` LIKE :base',
                ['property_id' => $propertyId, 'base' => strlen($naan) ? 'ark:/' . $naan . '/%' : 'ark:/%']
            )->fetchOne()
            : 0;

        if ($counter !== null && $counter !== false && (int) $counter === 0 && $totalArks) {
            $message = new PsrMessage(
                'The noid database contains no minted identifier, but {count} arks with the naan "{naan}" are stored in Omeka. A previous migration emptied it, so the next arks would be duplicates of existing ones. Restore the noid database from a backup, or remove the arks of the resources, then upgrade again.', // @translate
                ['count' => $totalArks, 'naan' => $naan]
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message->setTranslator($translator));
        }
    }
}
