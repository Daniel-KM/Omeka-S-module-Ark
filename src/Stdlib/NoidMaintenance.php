<?php declare(strict_types=1);

namespace Ark\Stdlib;

use Common\Stdlib\EasyMeta;
use Doctrine\DBAL\Connection;
use Noid\Lib\Globals;
use Noid\Storage\DatabaseInterface;
use Omeka\Settings\Settings;
use Omeka\Stdlib\Cli;

/**
 * Check, migrate and rebuild the noid database.
 */
class NoidMaintenance
{
    /**
     * Methods to read a BerkeleyDB database, by order of preference.
     */
    const MIGRATION_METHODS = [
        'db4',
        'db_dump',
        'dump_file',
        'json',
    ];

    /**
     * Legacy names of the random generators.
     *
     * @see \Noid\Lib\Generator::_genid()
     */
    const GENERATORS = [
        'php rand()' => 'mt_rand',
        'php mt_rand()' => 'mt_rand',
        'mt_rand' => 'mt_rand',
        'PerlRandom' => 'drand48',
        'Perl_Random' => 'drand48',
        'perl rand()' => 'drand48',
        'drand48' => 'drand48',
    ];

    /**
     * @var \Doctrine\DBAL\Connection
     */
    protected $connection;

    /**
     * @var \Common\Stdlib\EasyMeta
     */
    protected $easyMeta;

    /**
     * @var \Omeka\Settings\Settings
     */
    protected $settings;

    /**
     * @var \Omeka\Stdlib\Cli|null
     */
    protected $cli;

    /**
     * @var string
     */
    protected $databaseDir;

    /**
     * @var array
     */
    protected $connectionConfig;

    /**
     * @var string|null
     */
    protected $dbDumpPath;

    public function __construct(
        Connection $connection,
        EasyMeta $easyMeta,
        Settings $settings,
        ?Cli $cli,
        string $databaseDir,
        array $connectionConfig = []
    ) {
        $this->connection = $connection;
        $this->easyMeta = $easyMeta;
        $this->settings = $settings;
        $this->cli = $cli;
        $this->databaseDir = $databaseDir;
        $this->connectionConfig = $connectionConfig;
    }

    public function getDatabaseDir(): string
    {
        return $this->databaseDir;
    }

    public function getBdbFile(): string
    {
        return $this->databaseDir . '/NOID/noid.bdb';
    }

    /**
     * File created by "db_dump -p noid.bdb > noid.dump", maybe on another server.
     */
    public function getDumpFile(): string
    {
        return $this->databaseDir . '/NOID/noid.dump';
    }

    /**
     * File created by the export scripts of the noid library.
     */
    public function getJsonFile(): string
    {
        return $this->databaseDir . '/NOID/noid_data.json';
    }

    /**
     * Get the backend used to store the noid database.
     */
    public function getStore(): string
    {
        return NoidStore::resolve((string) $this->settings->get('ark_store'));
    }

    public function hasBdbDatabase(): bool
    {
        return file_exists($this->getBdbFile());
    }

    /**
     * Check if a file database other than BerkeleyDB exists.
     */
    public function hasMigratedDatabase(): bool
    {
        foreach (['lmdb', 'xml', 'sqlite'] as $store) {
            if (file_exists(NoidStore::files($store, $this->databaseDir)[0])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if a BerkeleyDB database should be migrated.
     *
     * A store set to another backend, in particular a database without file,
     * means that it was configured manually, so a migration would override a
     * working setting.
     */
    public function isMigrationNeeded(): bool
    {
        return $this->hasBdbDatabase()
            && !$this->hasMigratedDatabase()
            && in_array((string) $this->settings->get('ark_store'), ['', 'bdb'], true);
    }

    /**
     * Get the availability of the methods to read the BerkeleyDB database.
     *
     * @return bool[] By method, in order of preference.
     */
    public function migrationMethods(): array
    {
        return [
            'db4' => NoidStore::isAvailable('bdb'),
            'db_dump' => (bool) $this->dbDumpPath(),
            'dump_file' => is_readable($this->getDumpFile()),
            'json' => is_readable($this->getJsonFile()),
        ];
    }

    /**
     * Get the backends where to migrate the database, by order of preference.
     */
    public function migrationStores(): array
    {
        return array_values(array_filter(['lmdb', 'xml'], [NoidStore::class, 'isAvailable']));
    }

    /**
     * Migrate the BerkeleyDB database to lmdb, or xml if not available.
     *
     * @param string|null $method The first available method by default.
     * @return string The backend of the migrated database, that is stored in
     *   the settings.
     * @throws \RuntimeException
     */
    public function migrate(?string $method = null): string
    {
        if (!$this->isMigrationNeeded()) {
            throw new \RuntimeException('There is no BerkeleyDB database to migrate.');
        }

        $methods = array_filter($this->migrationMethods());
        if ($method === null) {
            $method = key($methods);
            if ($method === null) {
                throw new \RuntimeException('No method is available to read the BerkeleyDB database.');
            }
        } elseif (empty($methods[$method])) {
            throw new \RuntimeException(sprintf('The method "%s" is not available to read the BerkeleyDB database.', $method));
        }

        $data = $this->readBdb($method);
        if (!$data) {
            throw new \RuntimeException('No data found in the BerkeleyDB database.');
        }

        $generatorKey = Globals::_RR . '/generator_random';
        if (isset($data[$generatorKey])) {
            $data[$generatorKey] = self::GENERATORS[$data[$generatorKey]] ?? 'drand48';
        }

        $errors = [];
        foreach ($this->migrationStores() as $store) {
            try {
                $this->importData($data, $store);
                $this->settings->set('ark_store', $store);
                return $store;
            } catch (\Throwable $e) {
                $errors[] = $store . ': ' . $e->getMessage();
            }
        }

        throw new \RuntimeException(implode(' ', $errors) ?: 'No backend is available to store the noid database.');
    }

    /**
     * Write data in a new database, removing it when the import fails.
     *
     * The data are wrapped as a source database in order to use the import of
     * the storage, that is linear.
     *
     * @throws \RuntimeException
     */
    public function importData(array $data, string $store): void
    {
        $storageClass = Globals::DB_TYPES[$store] ?? null;
        if (!$storageClass || !class_exists($storageClass) || !NoidStore::isAvailable($store)) {
            throw new \RuntimeException(sprintf('The backend "%s" is not available.', $store));
        }

        /** @var \Noid\Storage\DatabaseInterface $storage */
        $storage = new $storageClass();
        try {
            $storage->open($this->storageSettings($store), DatabaseInterface::DB_CREATE);
            if (!$storage->isOpen()) {
                throw new \RuntimeException('The database cannot be created.');
            }
            $imported = $storage->import(new NoidMemoryStorage($data));
            // Some backends write the database on close, so it must be closed
            // before the files can be removed.
            $storage->close();
            if (!$imported) {
                throw new \RuntimeException('No data imported.');
            }
        } catch (\Throwable $e) {
            if ($storage->isOpen()) {
                $storage->close();
            }
            $this->removeFiles($store);
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Get the counters of the database, or null when it does not exist.
     *
     * @return array|null With keys "minted" (number of identifiers issued since
     *   the creation), "held" (identifiers that cannot be issued anymore, that
     *   includes the issued ones for a long term minter), and "known" (number
     *   of identifiers that will never be issued again).
     */
    public function counters(?string $store = null): ?array
    {
        $store = $store ?? $this->getStore();
        $files = NoidStore::files($store, $this->databaseDir);
        if ($files && !file_exists($files[0])) {
            return null;
        }

        $storageClass = Globals::DB_TYPES[$store] ?? null;
        if (!$storageClass || !class_exists($storageClass) || !NoidStore::isAvailable($store)) {
            return null;
        }

        try {
            /** @var \Noid\Storage\DatabaseInterface $storage */
            $storage = new $storageClass();
            $storage->open($this->storageSettings($store), DatabaseInterface::DB_RDONLY);
            if (!$storage->isOpen()) {
                return null;
            }
            $template = $storage->get(Globals::_RR . '/template');
            $minted = $storage->get(Globals::_RR . '/oacounter');
            $held = $storage->get(Globals::_RR . '/held');
            $longterm = $storage->get(Globals::_RR . '/longterm');
            $storage->close();
        } catch (\Throwable $e) {
            return null;
        }

        if ($template === false && $minted === false) {
            return null;
        }

        // A long term minter holds each issued identifier, so only the other
        // holds are added.
        $minted = (int) $minted;
        $held = (int) $held;
        return [
            'minted' => $minted,
            'held' => $held,
            'known' => $minted + max(0, $longterm ? $held - $minted : $held),
        ];
    }

    /**
     * Count the arks that the minter can issue, stored in Omeka.
     *
     * Arks imported from another institution have another naan, arks stored in
     * another property are not managed by the module, and the qualifiers of the
     * media are not minted.
     */
    public function countArks(): int
    {
        $propertyId = $this->propertyId();
        if (!$propertyId) {
            return 0;
        }
        $base = $this->arkBase();
        return (int) $this->connection->executeQuery(
            'SELECT COUNT(DISTINCT `value`) FROM `value` WHERE `property_id` = :property_id AND `type` = :type AND `value` LIKE :base AND `value` NOT LIKE :qualified',
            [
                'property_id' => $propertyId,
                'type' => 'literal',
                'base' => $base . '%',
                'qualified' => $base . '%/%',
            ]
        )->fetchOne();
    }

    /**
     * Check if the database contains all the arks stored in Omeka.
     *
     * Each issued or held identifier is counted, so a database that knows less
     * identifiers than the arks of Omeka was reset or replaced, and the next
     * arks would be duplicates of existing ones.
     */
    public function isConsistent(): bool
    {
        $arks = $this->countArks();
        return !$arks || $this->checkCounters($this->counters(), $arks);
    }

    /**
     * Get the full status of the noid database.
     */
    public function status(): array
    {
        $store = $this->getStore();
        $counters = $this->counters($store);
        $arks = $this->countArks();
        return [
            'store' => $store,
            'store_available' => NoidStore::isAvailable($store),
            'counters' => $counters,
            'arks' => $arks,
            'consistent' => !$arks || $this->checkCounters($counters, $arks),
            'bdb' => $this->hasBdbDatabase(),
            'migration_needed' => $this->isMigrationNeeded(),
            'migration_methods' => $this->migrationMethods(),
            'migration_stores' => $this->migrationStores(),
        ];
    }

    /**
     * Read all the keys and values of the BerkeleyDB database.
     *
     * @throws \RuntimeException
     */
    protected function readBdb(string $method): array
    {
        switch ($method) {
            case 'db4':
                $storage = new \Noid\Storage\BerkeleyDB();
                $storage->open($this->storageSettings('bdb'), DatabaseInterface::DB_RDONLY);
                if (!$storage->isOpen()) {
                    throw new \RuntimeException('The BerkeleyDB database cannot be opened.');
                }
                $data = $storage->get_range('');
                $storage->close();
                return $data ?: [];

            case 'db_dump':
                $command = sprintf('%s -p %s', escapeshellarg($this->dbDumpPath()), escapeshellarg($this->getBdbFile()));
                $output = $this->cli->execute($command);
                if ($output === false) {
                    throw new \RuntimeException('The BerkeleyDB database cannot be dumped with db_dump.');
                }
                return $this->parseDump($output);

            case 'dump_file':
                $output = file_get_contents($this->getDumpFile());
                if ($output === false) {
                    throw new \RuntimeException(sprintf('The file %s cannot be read.', $this->getDumpFile()));
                }
                return $this->parseDump($output);

            case 'json':
                $content = file_get_contents($this->getJsonFile());
                $data = $content === false ? null : json_decode($content, true);
                if (!is_array($data)) {
                    throw new \RuntimeException(sprintf('The file %1$s is not a valid json file: %2$s', $this->getJsonFile(), json_last_error_msg()));
                }
                return $data;

            default:
                throw new \RuntimeException(sprintf('Unknown method "%s".', $method));
        }
    }

    /**
     * Parse the output of "db_dump -p".
     *
     * The format is a header ended by "HEADER=END", then each key and each
     * value on a line starting with a space, then "DATA=END". Special
     * characters are escaped as \xx (hex) and a backslash as a double
     * backslash.
     */
    protected function parseDump(string $output): array
    {
        $data = [];
        $inData = false;
        $key = null;
        foreach (explode("\n", $output) as $line) {
            if (!$inData) {
                $inData = trim($line) === 'HEADER=END';
                continue;
            }
            if (trim($line) === 'DATA=END') {
                break;
            }
            if ($line === '' || $line[0] !== ' ') {
                continue;
            }
            // Decode a double backslash and the hex format in a single pass,
            // else a literal backslash followed by two hex digits would be
            // decoded twice.
            $value = preg_replace_callback(
                '/\\\\(\\\\|[0-9a-fA-F]{2})/',
                fn ($matches) => $matches[1] === '\\' ? '\\' : chr(hexdec($matches[1])),
                substr($line, 1)
            );
            if ($key === null) {
                $key = $value;
            } else {
                $data[$key] = $value;
                $key = null;
            }
        }
        return $data;
    }

    protected function checkCounters(?array $counters, int $arks): bool
    {
        return $counters !== null
            && $counters['known'] >= $arks;
    }

    /**
     * Get the path of the command db_dump, without logging when it is missing.
     */
    protected function dbDumpPath(): ?string
    {
        if ($this->dbDumpPath === null) {
            $this->dbDumpPath = '';
            $paths = getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';
            foreach ($this->cli ? explode(PATH_SEPARATOR, $paths) : [] as $dir) {
                $path = $dir === '' ? false : $this->cli->validateCommand($dir, 'db_dump');
                if ($path) {
                    $this->dbDumpPath = $path;
                    break;
                }
            }
        }
        return $this->dbDumpPath ?: null;
    }

    protected function storageSettings(string $store): array
    {
        return NoidStore::settings($store, $this->databaseDir, $this->connectionConfig);
    }

    protected function removeFiles(string $store): void
    {
        foreach (NoidStore::files($store, $this->databaseDir) as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
    }

    protected function arkBase(): string
    {
        $naan = (string) $this->settings->get('ark_naan');
        return strlen($naan) ? 'ark:/' . $naan . '/' : 'ark:/';
    }

    protected function propertyId(): ?int
    {
        $term = $this->settings->get('ark_property') ?: 'dcterms:identifier';
        return $this->easyMeta->propertyId($term) ?: null;
    }
}
