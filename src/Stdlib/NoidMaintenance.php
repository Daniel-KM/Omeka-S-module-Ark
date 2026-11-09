<?php declare(strict_types=1);

namespace Ark\Stdlib;

use Common\Stdlib\EasyMeta;
use Doctrine\DBAL\Connection;
use Noid\Lib\Db;
use Noid\Lib\Globals;
use Noid\Lib\Log;
use Noid\Noid as NoidLib;
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
     * Rebuild the database from the arks stored in Omeka.
     *
     * A new database is created with the same settings, then the minting is
     * replayed: the generator is deterministic, so the same identifiers are
     * issued in the same order, the counters are restored, and the identifiers
     * of the removed resources are issued too, so they will never be issued
     * again. Each ark found is bound to its resources. The replay stops when
     * all the arks are found, or when none is found during a long time: the
     * remaining arks were not issued by this minter (another template, another
     * generator, manual input), so they are held to be never issued.
     *
     * The replay is done in memory, then written with the import of the store,
     * that is linear. The previous database is kept as a backup, and restored
     * when the rebuild fails.
     *
     * @param callable|null $shouldStop Return true to stop the process.
     * @return array With keys "store", "arks", "replayed", "held", "minted" and
     *   "backups".
     * @throws \RuntimeException
     */
    public function rebuild(?callable $shouldStop = null): array
    {
        $store = (string) $this->settings->get('ark_store');
        $store = $store === 'bdb' || !NoidStore::isAvailable(NoidStore::resolve($store))
            ? NoidStore::resolve('')
            : NoidStore::resolve($store);
        if (!NoidStore::isAvailable($store)) {
            throw new \RuntimeException(sprintf('The backend "%s" is not available.', $store));
        }

        $template = (string) $this->settings->get('ark_name_noid_template');
        if ($template === '') {
            throw new \RuntimeException('The template of the identifiers is not set.');
        }

        $naan = (string) $this->settings->get('ark_naan');
        $naa = (string) $this->settings->get('ark_naa');
        $subnaa = (string) $this->settings->get('ark_subnaa');
        $term = $naan && $naa && $subnaa ? 'long' : 'medium';
        $contact = (string) $this->settings->get('administrator_email', '[Unknown user]');
        $generator = (string) $this->settings->get('ark_name_noid_generator', 'mt_rand');

        $arks = $this->fetchArks();
        $settings = $this->storageSettings($store);
        $settings['generator'] = self::GENERATORS[$generator] ?? 'mt_rand';

        $backups = $this->backupDatabase($store);
        try {
            if (!Db::dbcreate($settings, $contact, $template, $term, $naan, $naa, $subnaa)) {
                throw new \RuntimeException('The database cannot be created: ' . Log::errmsg(null, 1));
            }
            $noid = Db::dbopen($settings, DatabaseInterface::DB_WRITE);
            if (!$noid) {
                throw new \RuntimeException('The database cannot be opened: ' . Log::errmsg(null, 1));
            }

            // Mint in memory: the database keeps its handle, only the engine
            // that reads and writes the keys is replaced.
            $engine = Db::$engine;
            $memory = new NoidMemoryStorage($engine->get_range(''));
            Db::$engine = $memory;
            try {
                $result = $this->replay($noid, $contact, $arks, $shouldStop);
            } finally {
                Db::$engine = $engine;
                Db::dbclose($noid, true);
            }

            $this->importData($memory->get_range(''), $store);
        } catch (\Throwable $e) {
            $this->restoreDatabase($store, $backups);
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }

        $this->settings->set('ark_store', $store);
        $this->updateLock();

        return [
            'store' => $store,
            'arks' => count($arks),
            'backups' => array_values($backups),
        ] + $result;
    }

    /**
     * Mint until all the arks are found, then hold the remaining ones.
     */
    protected function replay(string $noid, string $contact, array $arks, ?callable $shouldStop): array
    {
        // An ark that does not match the template cannot be issued, so it is
        // held at once instead of being searched.
        $pending = [];
        $foreign = [];
        foreach ($arks as $id => $resourceIds) {
            $check = NoidLib::validate($noid, '-', [(string) $id]);
            if ($check && strpos((string) reset($check), 'id: ') === 0) {
                $pending[$id] = $resourceIds;
            } else {
                $foreign[$id] = $resourceIds;
            }
        }

        $total = count($pending);
        // When no ark is found at the beginning, the generator differs, so the
        // replay is useless.
        $probe = min(1000, $total);
        $maxMisses = max(1000, $total);
        $minted = 0;
        $replayed = 0;
        $misses = 0;

        while ($pending) {
            if ($shouldStop && $minted % 1000 === 0 && $shouldStop()) {
                throw new \RuntimeException('The rebuild was stopped.');
            }
            $id = NoidLib::mint($noid, $contact);
            if ($id === null || $id === '') {
                break;
            }
            ++$minted;
            if (isset($pending[$id])) {
                $this->bindArk($noid, $contact, $id, $pending[$id], true);
                unset($pending[$id]);
                ++$replayed;
                $misses = 0;
            } elseif ((!$replayed && $minted >= $probe) || ++$misses >= $maxMisses) {
                break;
            }
        }

        // The arks that are not issued by the minter are held, so they cannot
        // be issued later, whatever their template.
        $pending += $foreign;
        foreach ($pending as $id => $resourceIds) {
            NoidLib::hold_set($noid, (string) $id);
            $this->bindArk($noid, $contact, (string) $id, $resourceIds, false);
        }

        return [
            'replayed' => $replayed,
            'held' => count($pending),
            'minted' => $minted,
        ];
    }

    /**
     * Bind an ark to its resources, with the reverse binds used by the module.
     *
     * @see \Ark\Name\Plugin\Noid::create()
     */
    protected function bindArk(string $noid, string $contact, string $id, array $resourceIds, bool $validate): void
    {
        NoidLib::bind($noid, $contact, $validate ? 1 : 0, 'set', $id, 'locations', implode('|', $resourceIds));
        foreach ($resourceIds as $resourceId) {
            Log::note($noid, $contact, 'locations/' . $resourceId, $id);
        }
    }

    /**
     * Get the arks that the minter can issue, without "ark:/", with their
     * resource ids.
     *
     * @see self::countArks()
     */
    protected function fetchArks(): array
    {
        $propertyId = $this->propertyId();
        if (!$propertyId) {
            return [];
        }
        $base = $this->arkBase();
        $rows = $this->connection->executeQuery(
            'SELECT `value`, `resource_id` FROM `value` WHERE `property_id` = :property_id AND `type` = :type AND `value` LIKE :base AND `value` NOT LIKE :qualified ORDER BY `resource_id`',
            [
                'property_id' => $propertyId,
                'type' => 'literal',
                'base' => $base . '%',
                'qualified' => $base . '%/%',
            ]
        )->fetchAllNumeric();
        $arks = [];
        foreach ($rows as [$value, $resourceId]) {
            $arks[substr($value, 5)][] = (int) $resourceId;
        }
        return array_map('array_unique', $arks);
    }

    /**
     * Rename the files or the table of a database to keep it as a backup.
     *
     * @return array The backups by original name.
     */
    protected function backupDatabase(string $store): array
    {
        $backups = [];
        $suffix = date('Ymd-His');

        if ($store === 'pdo') {
            if ($this->connection->executeQuery('SHOW TABLES LIKE :table', ['table' => 'noid'])->fetchOne()) {
                $backup = 'noid_' . strtr($suffix, ['-' => '_']);
                $this->connection->executeStatement('RENAME TABLE `noid` TO `' . $backup . '`');
                $backups['noid'] = $backup;
            }
            return $backups;
        }

        foreach (NoidStore::files($store, $this->databaseDir) as $file) {
            if (file_exists($file)) {
                $backup = $file . '.' . $suffix . '.bak';
                if (!rename($file, $backup)) {
                    $this->restoreDatabase($store, $backups);
                    throw new \RuntimeException(sprintf('The file %s cannot be renamed to keep a backup.', $file));
                }
                $backups[$file] = $backup;
            }
        }
        return $backups;
    }

    protected function restoreDatabase(string $store, array $backups): void
    {
        if ($store === 'pdo') {
            if (isset($backups['noid'])) {
                $this->connection->executeStatement('DROP TABLE IF EXISTS `noid`');
                $this->connection->executeStatement('RENAME TABLE `' . $backups['noid'] . '` TO `noid`');
            }
            return;
        }

        $this->removeFiles($store);
        foreach ($backups as $file => $backup) {
            rename($backup, $file);
        }
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
     * Lock the minting when the database is not ready, so no duplicate is
     * issued, or unlock it.
     *
     * @return bool True when the minting is locked.
     */
    public function updateLock(): bool
    {
        $locked = $this->isMigrationNeeded() || !$this->isConsistent();
        $this->settings->set('ark_name_noid_locked', $locked);
        return $locked;
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
