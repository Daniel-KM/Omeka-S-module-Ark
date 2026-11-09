<?php declare(strict_types=1);

namespace Ark\Stdlib;

use Common\Stdlib\EasyMeta;
use Doctrine\DBAL\Connection;
use Laminas\Log\LoggerInterface;
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
        'php',
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

    /**
     * @var \Laminas\Log\LoggerInterface|null
     */
    protected $logger;

    public function __construct(
        Connection $connection,
        EasyMeta $easyMeta,
        Settings $settings,
        ?Cli $cli,
        string $databaseDir,
        array $connectionConfig = [],
        ?LoggerInterface $logger = null
    ) {
        $this->connection = $connection;
        $this->easyMeta = $easyMeta;
        $this->settings = $settings;
        $this->cli = $cli;
        $this->databaseDir = $databaseDir;
        $this->connectionConfig = $connectionConfig;
        $this->logger = $logger;
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
     * When the store is set to another backend, the migration is needed while
     * its database does not exist, so an existing database is never replaced.
     */
    public function isMigrationNeeded(): bool
    {
        if (!$this->hasBdbDatabase()) {
            return false;
        }
        $store = (string) $this->settings->get('ark_store');
        return in_array($store, ['', 'bdb'], true)
            ? !$this->hasMigratedDatabase()
            : $this->counters($store) === null;
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
            'php' => is_readable($this->getBdbFile()),
            'db_dump' => (bool) $this->dbDumpPath(),
            'dump_file' => is_readable($this->getDumpFile()),
            'json' => is_readable($this->getJsonFile()),
        ];
    }

    /**
     * Get the backends where to migrate the database, by order of preference.
     *
     * The store selected in the config is used, else lmdb, else xml.
     */
    public function migrationStores(): array
    {
        $store = (string) $this->settings->get('ark_store');
        $stores = in_array($store, ['', 'bdb'], true) ? ['lmdb', 'xml'] : [$store];
        return array_values(array_filter($stores, [NoidStore::class, 'isAvailable']));
    }

    /**
     * Migrate the BerkeleyDB database to lmdb, or xml if not available.
     *
     * @param string|null $method By default, the available methods are tried
     *   by order of preference until one reads the database.
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
        if ($method !== null && empty($methods[$method])) {
            throw new \RuntimeException(sprintf('The method "%s" is not available to read the BerkeleyDB database.', $method));
        }

        $data = [];
        $errors = [];
        foreach ($method === null ? array_keys($methods) : [$method] as $method) {
            $this->log('notice', 'Migration of the noid database: reading the BerkeleyDB database with the method {method}.', ['method' => $method]); // @translate
            $start = microtime(true);
            try {
                $data = $this->readBdb($method);
            } catch (\Throwable $e) {
                $this->log('warn', 'Migration of the noid database: the method {method} cannot read the BerkeleyDB database: {message}', ['method' => $method, 'message' => $e->getMessage()]); // @translate
                $errors[] = $method . ': ' . $e->getMessage();
                continue;
            }
            if ($data) {
                $this->log('notice', 'Migration of the noid database: {count} keys read in {duration} seconds.', ['count' => count($data), 'duration' => round(microtime(true) - $start, 1)]); // @translate
                break;
            }
            $errors[] = $method . ': no data found.';
        }
        if (!$data) {
            throw new \RuntimeException($errors
                ? 'The BerkeleyDB database cannot be read. ' . implode(' ', $errors)
                : 'No method is available to read the BerkeleyDB database.');
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
                $this->syncGenerator();
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
     * remaining arks were not issued by this minter (another template, manual
     * input), so they are held to be never issued.
     *
     * The setting of the generator may differ from the one of the lost
     * database, for example when it was created by an old version, and another
     * generator issues the identifiers in another order, so the removed arks
     * would be issued again. So the replay is done with each generator, and
     * the one that finds the arks with the less identifiers is kept and saved.
     *
     * The replay is done in memory, then written with the import of the store,
     * that is linear. The previous database is kept as a backup, and restored
     * when the rebuild fails.
     *
     * The identifiers issued after the last ark still stored in Omeka, for
     * example the ones of the last resources removed, cannot be known, so a
     * margin of identifiers is issued after it, so they will never be issued.
     *
     * @param callable|null $shouldStop Return true to stop the process.
     * @param int $margin Number of identifiers to issue after the last ark.
     * @return array With keys "store", "generator", "arks", "replayed",
     *   "held", "minted", "reserved" and "backups".
     * @throws \RuntimeException
     */
    public function rebuild(?callable $shouldStop = null, int $margin = 0): array
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
        $generator = self::GENERATORS[$generator] ?? 'mt_rand';
        $generators = array_unique(array_merge([$generator], array_values(self::GENERATORS)));

        $backups = $this->backupDatabase($store);
        try {
            $best = null;
            foreach ($generators as $generator) {
                $this->log('notice', 'Rebuild of the noid database: replay of the minting with the generator {generator} to find {count} arks.', ['generator' => $generator, 'count' => count($arks)]); // @translate
                $start = microtime(true);
                $settings = $this->storageSettings($store);
                $settings['generator'] = $generator;
                if (!Db::dbcreate($settings, $contact, $template, $term, $naan, $naa, $subnaa)) {
                    throw new \RuntimeException('The database cannot be created: ' . Log::errmsg(null, 1));
                }
                $noid = Db::dbopen($settings, DatabaseInterface::DB_WRITE);
                if (!$noid) {
                    throw new \RuntimeException('The database cannot be opened: ' . Log::errmsg(null, 1));
                }

                // Mint in memory: the database keeps its handle, only the
                // engine that reads and writes the keys is replaced.
                $engine = Db::$engine;
                $memory = new NoidMemoryStorage($engine->get_range(''));
                Db::$engine = $memory;
                try {
                    $result = $this->replay($noid, $contact, $arks, $shouldStop, $margin);
                } finally {
                    Db::$engine = $engine;
                    Db::dbclose($noid, true);
                }

                $this->log('notice', 'Rebuild of the noid database: with the generator {generator}, {replayed} arks found with {minted} identifiers in {duration} seconds.', ['generator' => $generator, 'replayed' => $result['replayed'], 'minted' => $result['minted'], 'duration' => round(microtime(true) - $start, 1)]); // @translate

                // Keep the generator that finds most arks with less mintings.
                if (!$best
                    || $result['replayed'] > $best['result']['replayed']
                    || ($result['replayed'] === $best['result']['replayed'] && $result['minted'] < $best['result']['minted'])
                ) {
                    $best = ['generator' => $generator, 'memory' => $memory, 'result' => $result];
                }
                unset($memory);
            }

            $this->importData($best['memory']->get_range(''), $store);
        } catch (\Throwable $e) {
            $this->restoreDatabase($store, $backups);
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }

        $this->settings->set('ark_store', $store);
        $this->settings->set('ark_name_noid_generator', $best['generator']);
        $this->updateLock();

        return [
            'store' => $store,
            'generator' => $best['generator'],
            'arks' => count($arks),
            'backups' => array_values($backups),
        ] + $best['result'];
    }

    /**
     * Mint until all the arks are found, then hold the remaining ones.
     */
    protected function replay(string $noid, string $contact, array $arks, ?callable $shouldStop, int $margin = 0): array
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

        // The first identifiers may belong to removed resources, so the replay
        // stops only after a long series of identifiers that are not arks of
        // Omeka.
        $total = count($pending);
        $maxMisses = max(100000, 2 * $total);
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
            if ($minted % 10000 === 0) {
                $this->log('info', 'Rebuild of the noid database: {minted} identifiers issued, {replayed}/{total} arks found.', ['minted' => $minted, 'replayed' => $replayed, 'total' => $total]); // @translate
            }
            if (isset($pending[$id])) {
                $this->bindArk($noid, $contact, $id, $pending[$id], true);
                unset($pending[$id]);
                ++$replayed;
                $misses = 0;
            } elseif (++$misses >= $maxMisses) {
                break;
            }
        }

        // Issue the margin, that may include the arks of removed resources.
        $reserved = 0;
        while ($reserved < $margin && NoidLib::mint($noid, $contact)) {
            ++$reserved;
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
            'reserved' => $reserved,
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

        $total = count($data);
        $this->log('notice', 'Noid database: writing {count} keys with the backend {store}.', ['count' => $total, 'store' => $store]); // @translate
        $start = microtime(true);

        /** @var \Noid\Storage\DatabaseInterface $storage */
        $storage = new $storageClass();
        try {
            $storage->open($this->storageSettings($store), DatabaseInterface::DB_CREATE);
            if (!$storage->isOpen()) {
                throw new \RuntimeException('The database cannot be created.');
            }
            if ($store === 'xml' || $store === 'pdo') {
                // A write in xml looks for the key in the whole document, and a
                // write in pdo is a transaction, so the import of the storage,
                // that writes all keys at once, is used.
                $imported = $storage->import(new NoidMemoryStorage($data));
            } else {
                // The database is created empty, so the keys are written one by
                // one, in order to check each write and to log the progress.
                $imported = true;
                $done = 0;
                $step = max(1, (int) ceil($total / 10));
                foreach ($data as $key => $value) {
                    if (!$storage->set((string) $key, (string) $value)) {
                        $imported = false;
                        break;
                    }
                    if (++$done % $step === 0 && $done < $total) {
                        $this->log('info', 'Noid database: {count}/{total} keys written ({percent}%) in {duration} seconds.', ['count' => $done, 'total' => $total, 'percent' => (int) round(100 * $done / $total), 'duration' => round(microtime(true) - $start, 1)]); // @translate
                    }
                }
            }
            // Some backends write the database on close, so it must be closed
            // before the files can be removed.
            $storage->close();
            if (!$imported) {
                throw new \RuntimeException('Some data cannot be written.');
            }
            $this->log('notice', 'Noid database: {count} keys written in {duration} seconds.', ['count' => $total, 'duration' => round(microtime(true) - $start, 1)]); // @translate
        } catch (\Throwable $e) {
            if ($storage->isOpen()) {
                $storage->close();
            }
            $this->removeFiles($store);
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Set the generator of the settings to the one of the database.
     *
     * The setting was added in version 3.5.16 with the default "mt_rand", but
     * the older databases use the default of the library, "drand48". The
     * minting uses the generator of the database, so the setting is only
     * informative, but it should not be misleading.
     *
     * @return string|null The generator, or null when there is no database or
     *   when the generator is not random.
     */
    public function syncGenerator(): ?string
    {
        $store = $this->getStore();
        if ($this->counters($store) === null) {
            return null;
        }
        $storageClass = Globals::DB_TYPES[$store];
        /** @var \Noid\Storage\DatabaseInterface $storage */
        $storage = new $storageClass();
        $storage->open($this->storageSettings($store), DatabaseInterface::DB_RDONLY);
        $type = $storage->get(Globals::_RR . '/generator_type');
        $generator = $storage->get(Globals::_RR . '/generator_random');
        $storage->close();
        if ($type !== 'random') {
            return null;
        }
        // A random database without generator was created with the default of
        // the library.
        $generator = self::GENERATORS[$generator] ?? 'drand48';
        $this->settings->set('ark_name_noid_generator', $generator);
        return $generator;
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
     * Check if the minter cannot issue an ark already stored in Omeka.
     *
     * When the database knows as many identifiers as the arks of Omeka, it is
     * consistent. Else, each ark is checked, because some arks may have been
     * imported from another system with the same naan: they are a risk only
     * if the minter can issue them.
     */
    public function isConsistent(): bool
    {
        $arks = $this->countArks();
        return !$arks
            || $this->checkCounters($this->counters(), $arks)
            || !$this->unknownArks();
    }

    /**
     * Get the arks of Omeka unknown to the database that the minter can issue.
     *
     * An ark is known when it was issued or held. An unknown ark that does not
     * match the template cannot be issued, so it is not a duplicate to come.
     * When there is no database, all the arks are unknown.
     *
     * @return string[] The arks without "ark:/".
     */
    public function unknownArks(): array
    {
        $arks = array_map('strval', array_keys($this->fetchArks()));
        $store = $this->getStore();
        if (!$arks || $this->counters($store) === null) {
            return $arks;
        }

        $settings = $this->storageSettings($store);
        $storageClass = Globals::DB_TYPES[$store];
        /** @var \Noid\Storage\DatabaseInterface $storage */
        $storage = new $storageClass();
        $storage->open($settings, DatabaseInterface::DB_RDONLY);
        // The whole database is read once, since a lookup is slow on xml.
        $keys = $storage->get_range('');
        $storage->close();

        // An ark without the naan and the prefix of the minter cannot be issued.
        $firstPart = (string) ($keys[Globals::_RR . '/firstpart'] ?? '');
        $unknown = array_filter($arks, fn ($id) => strpos($id, $firstPart) === 0
            && !isset($keys[$id . "\t" . Globals::_RR . '/c'])
            && !isset($keys[$id . "\t" . Globals::_RR . '/h']));
        if (!$unknown) {
            return [];
        }

        $noid = Db::dbopen($settings, DatabaseInterface::DB_RDONLY);
        if (!$noid) {
            return array_values($unknown);
        }
        try {
            return array_values(array_filter($unknown, function ($id) use ($noid) {
                $check = NoidLib::validate($noid, '-', [$id]);
                return $check && strpos((string) reset($check), 'id: ') === 0;
            }));
        } finally {
            Db::dbclose($noid, true);
        }
    }

    /**
     * Hold the arks of Omeka unknown to the database, so they are never issued.
     *
     * The arks are bound to their resources too. It is lighter than a rebuild
     * for a working database, for example when arks were imported from another
     * system with the same naan and template. The previous database is kept as
     * a backup.
     *
     * @return array With keys "reserved" and "backups".
     * @throws \RuntimeException
     */
    public function reserveUnknownArks(): array
    {
        $unknown = array_flip($this->unknownArks());
        $store = $this->getStore();
        if (!$unknown || $this->counters($store) === null) {
            throw new \RuntimeException('There is no database or no unknown ark to reserve.');
        }

        $arks = array_intersect_key($this->fetchArks(), $unknown);
        $this->log('notice', 'Noid database: reservation of {count} arks unknown to the database.', ['count' => count($arks)]); // @translate
        $contact = (string) $this->settings->get('administrator_email', '[Unknown user]');
        $settings = $this->storageSettings($store);

        // Update in memory, then write the database with a backup.
        $noid = Db::dbopen($settings, DatabaseInterface::DB_RDONLY);
        if (!$noid) {
            throw new \RuntimeException('The database cannot be opened: ' . Log::errmsg(null, 1));
        }
        $engine = Db::$engine;
        $memory = new NoidMemoryStorage($engine->get_range(''));
        Db::$engine = $memory;
        try {
            foreach ($arks as $id => $resourceIds) {
                NoidLib::hold_set($noid, (string) $id);
                $this->bindArk($noid, $contact, (string) $id, $resourceIds, false);
            }
        } finally {
            Db::$engine = $engine;
            Db::dbclose($noid, true);
        }

        $backups = $this->backupDatabase($store);
        try {
            $this->importData($memory->get_range(''), $store);
        } catch (\Throwable $e) {
            $this->restoreDatabase($store, $backups);
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }

        $this->updateLock();

        return [
            'reserved' => count($arks),
            'backups' => array_values($backups),
        ];
    }

    /**
     * Get the identifiers issued whose ark is not stored in Omeka.
     *
     * They are the arks of the removed resources, and the margin skipped after
     * the last ark by a rebuild, never assigned. In a working database, the
     * ark of a removed resource remains bound to it, so its former resource is
     * known, but not after a rebuild.
     *
     * @return array Arrays with keys "ark" (without "ark:/"), "position" (rank
     *   of issue), "date", "resources" (former resource ids, if known) and
     *   "type" ("removed" or "margin"), by position.
     */
    public function identifiersWithoutResource(): array
    {
        $store = $this->getStore();
        if ($this->counters($store) === null) {
            return [];
        }

        $storageClass = Globals::DB_TYPES[$store];
        /** @var \Noid\Storage\DatabaseInterface $storage */
        $storage = new $storageClass();
        $storage->open($this->storageSettings($store), DatabaseInterface::DB_RDONLY);
        $keys = $storage->get_range('');
        $storage->close();

        $arks = $this->fetchArks();

        // The circulation record is "status|date|contact|position".
        $circulation = "\t" . Globals::_RR . '/c';
        $length = strlen($circulation);
        $lastPresent = 0;
        $rows = [];
        foreach ($keys as $key => $value) {
            $key = (string) $key;
            if (substr($key, -$length) !== $circulation) {
                continue;
            }
            $id = substr($key, 0, -$length);
            $parts = explode('|', (string) $value);
            $position = (int) end($parts);
            if (isset($arks[$id])) {
                $lastPresent = max($lastPresent, $position);
                continue;
            }
            $date = $parts[1] ?? '';
            $rows[] = [
                'ark' => $id,
                'position' => $position,
                'date' => strlen($date) === 14
                    ? substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2) . ' ' . substr($date, 8, 2) . ':' . substr($date, 10, 2) . ':' . substr($date, 12, 2)
                    : $date,
                'resources' => (string) ($keys[$id . "\tlocations"] ?? ''),
            ];
        }

        usort($rows, fn ($a, $b) => $a['position'] <=> $b['position']);
        foreach ($rows as &$row) {
            // The margin is never bound and is after the last ark of Omeka.
            $row['type'] = $row['resources'] !== '' || $row['position'] < $lastPresent ? 'removed' : 'margin';
        }
        unset($row);

        return $rows;
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
            'consistent' => !$arks || $this->checkCounters($counters, $arks) || !($unknown = $this->unknownArks()),
            'unknown_arks' => count($unknown ?? []),
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

            case 'php':
                return (new BerkeleyDbReader())->read($this->getBdbFile());

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

    protected function log(string $priority, string $message, array $context = []): void
    {
        if ($this->logger) {
            $this->logger->{$priority}($message, $context);
        }
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
