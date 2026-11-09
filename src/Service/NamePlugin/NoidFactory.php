<?php declare(strict_types=1);

namespace Ark\Service\NamePlugin;

use Ark\Name\Plugin\Noid;
use Ark\Stdlib\NoidStore;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

class NoidFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $name, ?array $options = null)
    {
        $settings = $services->get('Omeka\Settings');
        $config = $services->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        $databaseDir = $basePath . '/arkandnoid';

        $dbType = NoidStore::resolve((string) $settings->get('ark_store', ''), $databaseDir);

        $this->checkExtension($dbType);

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
            $connectionConfig,
            $services->get('Common\DirectoryManager')
        );
    }

    /**
     * Check if the required PHP extension is available for the database type.
     *
     * @throws \RuntimeException if the required extension is missing
     */
    protected function checkExtension(string $dbType): void
    {
        $message = NoidStore::unavailability($dbType);
        if ($message !== null) {
            throw new \RuntimeException($message);
        }
    }
}
