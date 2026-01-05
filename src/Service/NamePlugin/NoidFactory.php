<?php declare(strict_types=1);

namespace Ark\Service\NamePlugin;

use Ark\Name\Plugin\Noid;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class NoidFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $name, array $options = null)
    {
        $settings = $services->get('Omeka\Settings');
        $dbType = $settings->get('ark_store', '');

        // Determine default storage: lmdb if available, else xml.
        if ($dbType === '') {
            $dbType = $this->hasLmdbSupport() ? 'lmdb' : 'xml';
        }

        $this->checkExtension($dbType);

        $config = $services->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        $databaseDir = $basePath . '/arkandnoid';

        // Get connection config for PDO storage (uses Omeka database).
        $connectionConfig = [];
        if ($dbType === 'pdo' && isset($config['connection'])) {
            $connectionConfig = $config['connection'];
        }

        return new Noid(
            $services->get('Omeka\Logger'),
            $settings,
            $databaseDir,
            $dbType,
            $connectionConfig
        );
    }

    /**
     * Check if php-dba with lmdb handler is available.
     */
    protected function hasLmdbSupport(): bool
    {
        if (!extension_loaded('dba')) {
            return false;
        }
        $handlers = dba_handlers();
        return in_array('lmdb', $handlers);
    }

    /**
     * Check if the required PHP extension is available for the database type.
     *
     * @throws \RuntimeException if the required extension is missing
     */
    protected function checkExtension(string $dbType): void
    {
        $requirements = [
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

        if (!isset($requirements[$dbType])) {
            throw new \RuntimeException(sprintf(
                'Unknown Noid database type "%1$s". Supported types: %2$s.',
                $dbType,
                implode(', ', array_keys($requirements))
            ));
        }

        $req = $requirements[$dbType];

        if (!extension_loaded($req['extension'])) {
            throw new \RuntimeException($req['message']);
        }

        // For dba-based backends, check if the specific handler is available.
        if (isset($req['handler'])) {
            $handlers = dba_handlers();
            if (!in_array($req['handler'], $handlers)) {
                throw new \RuntimeException(sprintf(
                    '%1$s Available dba handlers: %2$s.',
                    $req['message'],
                    implode(', ', $handlers) ?: 'none'
                ));
            }
        }
    }
}
