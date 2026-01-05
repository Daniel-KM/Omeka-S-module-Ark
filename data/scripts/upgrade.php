<?php declare(strict_types=1);

namespace Ark;

use Common\Stdlib\PsrMessage;

// Load Noid library autoloader for upgrade operations.
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

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
$connection = $services->get('Omeka\Connection');
$messenger = $plugins->get('messenger');
$entityManager = $services->get('Omeka\EntityManager');

if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.76')) {
    $message = new \Omeka\Stdlib\Message(
        $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
        'Common', '3.4.76'
    );
    throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
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
    // Migrate from BerkeleyDB to LMDB or XML if needed.
    // BerkeleyDB is deprecated and unavailable on recent Linux distributions.

    $config = $services->get('Config');
    $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
    $databaseDir = $basePath . '/arkandnoid';
    $bdbFile = $databaseDir . '/NOID/noid.bdb';

    // Check if a bdb database exists that needs migration.
    $hasBdbDatabase = file_exists($bdbFile);

    if ($hasBdbDatabase) {
        // Check if migration was already done to avoid overwriting existing data.
        $noidDir = $databaseDir . '/NOID';
        $xmlFile = $noidDir . '/noid.xml';
        $lmdbFile = $noidDir . '/noid.lmdb';
        $sqliteFile = $noidDir . '/noid.sqlite';
        if (file_exists($xmlFile) || file_exists($lmdbFile) || file_exists($sqliteFile)) {
            $message = new PsrMessage(
                'A Noid database (xml, lmdb or sqlite) already exists. Skipping BerkeleyDB migration. You may remove noid.bdb manually if migration was successful.' // @translate
            );
            $messenger->addWarning($message);
        } else {
            $hasDba = extension_loaded('dba');
            $handlers = $hasDba ? dba_handlers() : [];
            $hasBdbHandler = in_array('db4', $handlers);
            $hasLmdbHandler = in_array('lmdb', $handlers);

            /** @var \Omeka\Stdlib\Cli $cli */
            $cli = $services->get('Omeka\Cli');

            if (!$hasBdbHandler) {
                // db4 handler not available: cannot read the bdb database directly.
                // Try alternative migration methods.

                $noidDir = $databaseDir . '/NOID';
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
                 * Each key and value line starts with a single space.
                 * Special characters are escaped as \xx (hex).
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
                        if (strlen($line) > 0 && $line[0] === ' ') {
                            $value = substr($line, 1);
                            // Decode escaped characters (\xx hex format).
                            $value = preg_replace_callback(
                                '/\\\\([0-9a-fA-F]{2})/',
                                function ($matches) {
                                    return chr(hexdec($matches[1]));
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

                // Helper function to import data to target format using Noid storage classes.
                $importToTarget = function (array $data, string $noidDir, string $format) use ($databaseDir): void {
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
                    } catch (\Exception $e) {
                        throw new \Exception("Cannot create $format database in $noidDir: " . $e->getMessage());
                    }
                    if (!$storage->isOpen()) {
                        throw new \Exception("Cannot create $format database in $noidDir: database not open");
                    }
                    foreach ($data as $key => $value) {
                        $storage->set($key, (string) $value);
                    }
                    $storage->close();
                };

                if ($hasDbDump) {
                    // db_dump is available: create dump and import directly.
                    $dumpCmd = sprintf('%s -p %s', escapeshellarg($dbDumpPath), escapeshellarg($bdbFile));
                    $dumpOutput = $cli->execute($dumpCmd);

                    if ($dumpOutput === false) {
                        $message = new PsrMessage(
                            'Failed to dump BerkeleyDB database using db_dump.' // @translate
                        );
                        throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
                    }

                    // Parse dump output.
                    $data = $parseDumpOutput($dumpOutput);
                    if (empty($data)) {
                        $message = new PsrMessage(
                            'No data found in BerkeleyDB dump. You may remove existing files in noid directory.' // @translate
                        );
                        throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
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
                    } catch (\Exception $e) {
                        $message = new PsrMessage(
                            'Failed to import Noid database: {error}', // @translate
                            ['error' => $e->getMessage()]
                        );
                        throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
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
                        throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
                    }

                    $data = json_decode($jsonContent, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $message = new PsrMessage(
                            'Invalid JSON in file {file}: {error}', // @translate
                            ['file' => $jsonFile, 'error' => json_last_error_msg()]
                        );
                        throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
                    }

                    if (empty($data)) {
                        $message = new PsrMessage(
                            'No data found in JSON file.' // @translate
                        );
                        throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
                    }

                    // Import to target format using Noid storage classes.
                    try {
                        $importToTarget($data, $noidDir, $targetFormat);
                    } catch (\Exception $e) {
                        $message = new PsrMessage(
                            'Failed to import Noid database from JSON: {error}', // @translate
                            ['error' => $e->getMessage()]
                        );
                        throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
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
                    throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
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
                    throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
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

                        // Import data from bdb to target.
                        \Noid\Lib\Db::dbimport($targetSettings, 'bdb');

                        // Update setting to use new format.
                        $settings->set('ark_store', $targetFormat);

                        $message = new PsrMessage(
                            'The Noid database has been migrated from BerkeleyDB to {format}.', // @translate
                            ['format' => strtoupper($targetFormat)]
                        );
                        $messenger->addSuccess($message);

                        $migrated = true;
                        break;

                    } catch (\Exception $e) {
                        $lastError .= $e->getMessage();
                        // Continue to try next format.
                    }
                }

                if (!$migrated) {
                    $message = new PsrMessage(
                        'Migration from BerkeleyDB failed: {error}', // @translate
                        ['error' => $lastError]
                    );
                    throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
                }
            }
        }
    }

    // Initialize new setting if not set.
    if ($settings->get('ark_store') === null) {
        $settings->set('ark_store', '');
    }
}
