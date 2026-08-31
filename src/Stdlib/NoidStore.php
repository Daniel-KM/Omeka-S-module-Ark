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
