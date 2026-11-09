<?php declare(strict_types=1);

namespace Ark\Stdlib;

/**
 * Requirements of the storage backends available for the noid database.
 */
class NoidStore
{
    /**
     * Php extension and dba handler required by each storage backend.
     */
    const REQUIREMENTS = [
        'xml' => [
            'extension' => 'simplexml',
            'message' => 'The XML database format requires the php-xml extension.',
        ],
        'pdo' => [
            'extension' => 'pdo',
            'message' => 'The PDO database format requires the php-pdo extension.',
        ],
        'sqlite' => [
            'extension' => 'sqlite3',
            'message' => 'The SQLite database format requires the php-sqlite3 extension.',
        ],
        'lmdb' => [
            'extension' => 'dba',
            'handler' => 'lmdb',
            'message' => 'The LMDB database format requires the php-dba extension with lmdb handler.',
        ],
        'bdb' => [
            'extension' => 'dba',
            'handler' => 'db4',
            'message' => 'The BerkeleyDB database format requires the php-dba extension with db4 handler (deprecated and unavailable on recent systems).',
        ],
    ];

    /**
     * Files of the file backends, the first one being the database itself.
     */
    const FILES = [
        'bdb' => ['noid.bdb'],
        'lmdb' => ['noid.lmdb', 'noid.lmdb-lock'],
        'sqlite' => ['noid.sqlite'],
        'xml' => ['noid.xml'],
    ];

    /**
     * Get the backend of a store, the default one being lmdb if available.
     */
    public static function resolve(string $store): string
    {
        if ($store !== '') {
            return $store;
        }
        return static::isAvailable('lmdb') ? 'lmdb' : 'xml';
    }

    /**
     * Get the settings of the noid library to open a store.
     *
     * The backend "pdo" uses the Omeka database. The driver is hard coded,
     * since Omeka supports only MySQL/MariaDB.
     */
    public static function settings(string $store, string $databaseDir, array $connection = []): array
    {
        $storageConfig = [
            'data_dir' => $databaseDir,
            'db_name' => 'NOID',
        ];

        if ($store === 'pdo' && $connection) {
            $storageConfig = array_merge($storageConfig, [
                'driver' => 'mysql',
                'host' => $connection['host'] ?? 'localhost',
                'user' => $connection['user'] ?? '',
                'password' => $connection['password'] ?? '',
                'db_name' => $connection['dbname'] ?? '',
                'table_name' => 'noid',
            ]);
        }

        return [
            'db_type' => $store,
            'storage' => [
                $store => $storageConfig,
            ],
        ];
    }

    /**
     * Get the paths of the files of a store, or nothing for a sql database.
     */
    public static function files(string $store, string $databaseDir): array
    {
        return array_map(
            fn ($file) => $databaseDir . '/NOID/' . $file,
            static::FILES[$store] ?? []
        );
    }

    /**
     * Check if a storage backend is supported by the current php install.
     */
    public static function isAvailable(string $dbType): bool
    {
        return static::unavailability($dbType) === null;
    }

    /**
     * Get the reason why a storage backend cannot be used, if any.
     *
     * The returned message lists the available dba handlers when the missing
     * requirement is a handler.
     */
    public static function unavailability(string $dbType): ?string
    {
        if (!isset(static::REQUIREMENTS[$dbType])) {
            return sprintf(
                'Unknown Noid database type "%1$s". Supported types: %2$s.',
                $dbType,
                implode(', ', array_keys(static::REQUIREMENTS))
            );
        }

        $requirement = static::REQUIREMENTS[$dbType];

        if (!extension_loaded($requirement['extension'])) {
            return $requirement['message'];
        }

        if (!isset($requirement['handler'])) {
            return null;
        }

        $handlers = dba_handlers();
        if (in_array($requirement['handler'], $handlers)) {
            return null;
        }

        return sprintf(
            '%1$s Available dba handlers: %2$s.',
            $requirement['message'],
            implode(', ', $handlers) ?: 'none'
        );
    }
}
