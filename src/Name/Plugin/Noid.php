<?php declare(strict_types=1);
/**
 * Php Noid format for Ark name.
 */
namespace Ark\Name\Plugin;

// Use Noid via composer (daniel-km/noid ^1.4).

use Laminas\Log\Logger;
use Noid\Lib\Db;
use Noid\Lib\Log;
use Noid\Noid as NoidLib;
use Noid\Storage\DatabaseInterface;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Settings\Settings;

class Noid implements PluginInterface
{
    /**
     * @var string
     */
    protected $databaseDir;

    /**
     * @var \Laminas\Log\Logger
     */
    protected $logger;

    /**
     * @var \Omeka\Settings\Settings
     */
    protected $settings;

    /**
     * Database type to use (lmdb, sqlite, xml, pdo, bdb).
     *
     * Since Noid4Php 1.3, lmdb is the default and recommended backend.
     * Requires php-dba with lmdb handler (available by default on Debian 10+).
     *
     * @var string
     */
    protected $dbType = 'lmdb';

    public function __construct(
        Logger $logger,
        Settings $settings,
        $databaseDir
    ) {
        $this->logger = $logger;
        $this->settings = $settings;
        $this->databaseDir = $databaseDir;
    }

    public function isFullArk(): bool
    {
        return true;
    }

    public function create(AbstractResourceEntityRepresentation $resource): ?string
    {
        $noid = $this->openDatabase(DatabaseInterface::DB_WRITE);
        if (empty($noid)) {
            $this->logger->err(
                'Cannot open database: {message}', // @translate
                ['message' => Log::errmsg(null, 1) ?: 'No database']  // @translate
            );
            return null;
        }

        // Check if the url is already set (only the Omeka id: the other ids are
        // not automatic and can't be checked the same).
        $ark = Log::get_note($noid, 'locations/' . $resource->id());
        if ($ark) {
            Db::dbclose($noid);
            return $ark;
        }

        $resourceIds = [];
        $resourceIds[] = $resource->id();

        $contact = $this->getContact();

        $ark = NoidLib::mint($noid, $contact);
        if (!strlen((string) $ark)) {
            Db::dbclose($noid);
            $this->logger->err(
                'Cannot create an Ark for {resource} #{resource_id}: {message}', // @translate
                ['resource' => $resource->getControllerName(), 'resource_id' => $resource->id(), 'message' => Log::errmsg($noid)]
            );
            return null;
        }

        // Bind the ark and the record.
        $locations = implode('|', $resourceIds);
        $result = NoidLib::bind($noid, $contact, 1, 'set', $ark, 'locations', $locations);
        if (empty($result)) {
            $this->logger->warn(
                'Ark set, but not bound [{ark}, {resource} #{resource_id}]: {message}', // @translate
                ['ark' => $ark, 'resource' => $resource->getControllerName(), 'resource_id' => $resource->id(), 'message' => Log::errmsg($noid)]
            );
        }

        // Save the reverse bind on Omeka id to find it instantly, as a "note".
        // If needed, other urls can be find in a second step via the ark.
        $result = Log::note($noid, $contact, 'locations/' . $resource->id(), $ark);
        if (empty($result)) {
            $this->logger->warn(
                'Ark set, but no reverse bind [{ark}, {resource} #{resource_id}]: {message}', // @translate
                ['ark' => $ark, 'resource' => $resource->getControllerName(), 'resource_id' => $resource->id(), 'message' => Log::errmsg($noid)]
            );
        }

        Db::dbclose($noid);

        return $ark;
    }

    /**
     * Create arks for multiple resources in batch.
     *
     * This method is more efficient than calling create() multiple times as it:
     * - Opens the database once
     * - Uses mintMultiple() for batch minting
     * - Uses bindMultiple() for batch bindings
     *
     * @param AbstractResourceEntityRepresentation[] $resources
     * @return array Associative array of resource id => ark (or null on failure)
     */
    public function createMany(array $resources): array
    {
        if (empty($resources)) {
            return [];
        }

        $noid = $this->openDatabase(DatabaseInterface::DB_WRITE);
        if (empty($noid)) {
            $this->logger->err(
                'Cannot open database: {message}', // @translate
                ['message' => Log::errmsg(null, 1) ?: 'No database']  // @translate
            );
            return [];
        }

        $result = [];
        $toMint = [];

        // Check which resources already have arks.
        foreach ($resources as $resource) {
            $resourceId = $resource->id();
            $existingArk = Log::get_note($noid, 'locations/' . $resourceId);
            if ($existingArk) {
                $result[$resourceId] = $existingArk;
            } else {
                $toMint[$resourceId] = $resource;
            }
        }

        if (empty($toMint)) {
            Db::dbclose($noid);
            return $result;
        }

        $contact = $this->getContact();
        $count = count($toMint);

        // Mint multiple arks at once.
        $arks = NoidLib::mintMultiple($noid, $contact, $count);
        if (empty($arks)) {
            $this->logger->err(
                'Cannot create Arks for {count} resources: {message}', // @translate
                ['count' => $count, 'message' => Log::errmsg($noid)]
            );
            Db::dbclose($noid);
            return $result;
        }

        if (count($arks) < $count) {
            $this->logger->warn(
                'Minter exhausted: requested {count} arks, received {received}.', // @translate
                ['count' => $count, 'received' => count($arks)]
            );
        }

        // Prepare bindings for all arks.
        $bindings = [];
        $arkIndex = 0;
        $resourceArks = [];

        foreach ($toMint as $resourceId => $resource) {
            if (!isset($arks[$arkIndex])) {
                $this->logger->err(
                    'No ark available for {resource} #{resource_id}.', // @translate
                    ['resource' => $resource->getControllerName(), 'resource_id' => $resourceId]
                );
                $result[$resourceId] = null;
                continue;
            }

            $ark = $arks[$arkIndex++];
            $resourceArks[$resourceId] = $ark;

            // Prepare binding for locations.
            $bindings[] = [
                'how' => 'set',
                'id' => $ark,
                'elem' => 'locations',
                'value' => (string) $resourceId,
            ];
        }

        // Bind all arks at once.
        if (!empty($bindings)) {
            $bindResults = NoidLib::bindMultiple($noid, $contact, 1, $bindings);
            foreach ($bindResults as $i => $bindResult) {
                if ($bindResult === null) {
                    $resourceId = array_keys($resourceArks)[$i] ?? null;
                    if ($resourceId) {
                        $this->logger->warn(
                            'Ark set, but not bound [{ark}, resource #{resource_id}]: {message}', // @translate
                            ['ark' => $resourceArks[$resourceId], 'resource_id' => $resourceId, 'message' => Log::errmsg($noid)]
                        );
                    }
                }
            }
        }

        // Save reverse binds (notes) for instant lookup.
        foreach ($resourceArks as $resourceId => $ark) {
            $noteResult = Log::note($noid, $contact, 'locations/' . $resourceId, $ark);
            if (empty($noteResult)) {
                $this->logger->warn(
                    'Ark set, but no reverse bind [{ark}, resource #{resource_id}]: {message}', // @translate
                    ['ark' => $ark, 'resource_id' => $resourceId, 'message' => Log::errmsg($noid)]
                );
            }
            $result[$resourceId] = $ark;
        }

        Db::dbclose($noid);

        return $result;
    }

    /**
     * @todo Include the creation of the noid database in the interface or in another plugin.
     */
    public function isDatabaseCreated(): bool
    {
        $noid = $this->openDatabase();
        if (empty($noid)) {
            return false;
        }
        Db::dbclose($noid);
        return true;
    }

    /**
     * @todo Include the creation of the noid database in the interface or in another plugin.
     */
    public function createDatabase()
    {
        $contact = $this->getContact();

        $settings = $this->buildSettings();

        $template = $this->settings->get('ark_name_noid_template');
        $naan = $this->settings->get('ark_naan');
        $naa = $this->settings->get('ark_naa');
        $subnaa = $this->settings->get('ark_subnaa');

        $term = ($naan && $naa && $subnaa) ? 'long' : 'medium';

        $erc = Db::dbcreate($settings, $contact, $template, $term, $naan, $naa, $subnaa);

        if (empty($erc)) {
            return Log::errmsg(null, 1);
        }
        // dbcreate() closes the database automatically.

        return true;
    }

    /**
     * @todo Include the info of the noid database in the interface or in another plugin.
     *
     * @param string $level "meta" (default), "admin", "brief", "full", or "dump".
     * @return array|string
     */
    public function infoDatabase($level = 'meta')
    {
        $noid = $this->openDatabase();
        if (empty($noid)) {
            return '';
        }

        $levelNoid = in_array($level, ['meta', 'admin']) ? 'brief' : $level;
        ob_start();
        $result = Db::dbinfo($noid, $levelNoid);
        $info = ob_get_contents();
        ob_end_clean();
        Db::dbclose($noid);

        if (!$result) {
            $this->logger->err(
                'Cannot get database info: {message}', // @translate
                ['message' => Log::errmsg($noid, 1)]
            );
            return '';
        }

        if (in_array($level, ['meta', 'admin'])) {
            $info = mb_substr($info, strpos($info, 'Admin Values' . PHP_EOL) + 13);

            if ($level === 'meta') {
                $result = [];
                $matches = [];
                preg_match('~^NAAN:\s*(\d{5})$~m', $info, $matches);
                $result['naan'] = $matches[1];
                preg_match('~^  :/naa:\s+(.+)$~m', $info, $matches);
                $result['naa'] = $matches[1];
                preg_match('~^  :/subnaa:[^\S\r\n]+(.*)$~m', $info, $matches);
                $result['subnaa'] = $matches[1];
                preg_match('~^Template:\s+([\w\d.]+)$~m', $info, $matches);
                $result['template'] = $matches[1];
                $info = $result;
            }
        }

        return $info;
    }

    /**
     * Build settings array for the Noid library.
     *
     * @return array
     */
    protected function buildSettings(): array
    {
        $dataDir = $this->getDatabaseDir();
        return [
            'db_type' => $this->dbType,
            'storage' => [
                $this->dbType => [
                    'data_dir' => $dataDir,
                    'db_name' => 'NOID',
                ],
            ],
        ];
    }

    protected function openDatabase($mode = DatabaseInterface::DB_RDONLY)
    {
        $database = $this->getDatabaseDir();
        if (empty($database)) {
            return false;
        }

        // Create directory if it doesn't exist.
        if (!is_dir($database)) {
            return false;
        }

        $settings = $this->buildSettings();

        return Db::dbopen($settings, $mode);
    }

    protected function getContact()
    {
        return $this->settings->get(
            'administrator_email',
            '[Unknown user]' // @translate
        );
    }

    protected function getDatabaseDir()
    {
        return $this->databaseDir;
    }

    /**
     * Enable persistent database connection mode.
     *
     * When enabled, dbclose() calls will not actually close the connection,
     * allowing multiple operations to reuse the same connection. This is
     * useful for batch operations across multiple calls.
     *
     * Call disablePersistence() when done to close the connection.
     */
    public function enablePersistence(): void
    {
        Db::dbpersist(true);
    }

    /**
     * Disable persistent mode and close the database connection.
     */
    public function disablePersistence(): void
    {
        Db::dbunpersist();
    }
}
