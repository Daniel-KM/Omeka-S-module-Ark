<?php
/**
 * Php Noid format for Ark name.
 */
namespace Ark\Name\Plugin;

// Use Noid via composer.

use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Settings\Settings;
use Omeka\Stdlib\Message;
use Laminas\Log\Logger;

class Noid implements PluginInterface
{
    /**
     * @var Omeka\Settings\Settings
     */
    protected $settings;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var string
     */
    protected $databaseDir;

    /**
     * @param Settings $settings
     * @param Logger $logger
     * @param string $databaseDir
     */
    public function __construct(Settings $settings, Logger $logger, $databaseDir)
    {
        $this->settings = $settings;
        $this->logger = $logger;
        $this->databaseDir = $databaseDir;
    }

    public function isFullArk()
    {
        return true;
    }

    public function create(AbstractResourceEntityRepresentation $resource)
    {
        $noid = $this->openDatabase(\Noid::DB_WRITE);
        if (empty($noid)) {
            $message = new Message(
                'Cannot open database: %s', // @translate
                \Noid::errmsg(null, 1) ?: 'No database'  // @translate
            );
            $this->logger->err($message);
            return null;
        }

        // Check if the url is already set (only the Omeka id: the other ids are
        // not automatic and can't be checked the same).
        $ark = \Noid::get_note($noid, 'locations/' . $resource->id());
        if ($ark) {
            \Noid::dbclose($noid);
            return $ark;
        }

        $resourceIds = [];
        $resourceIds[] = $resource->id();

        $contact = $this->getContact();

        $ark = \Noid::mint($noid, $contact);
        if (!strlen($ark)) {
            \Noid::dbclose($noid);
            $message = new Message(
                'Cannot create an Ark for %1$s #%2$d: %3$s', // @translate
                $resource->getControllerName(), $resource->id(), \Noid::errmsg($noid)
            );
            $this->logger->err($message);
            return null;
        }

        // Bind the ark and the record.
        $locations = implode('|', $resourceIds);
        $result = \Noid::bind($noid, $contact, 1, 'set', $ark, 'locations', $locations);
        if (empty($result)) {
            $message = new Message(
                'Ark set, but not bound [%1$s, %2$s #%3$d]: %4$s', // @translate
                $ark, $resource->getControllerName(), $resource->id(), \Noid::errmsg($noid)
            );
            $this->logger->warn($message);
        }

        // Save the reverse bind on Omeka id to find it instantly, as a "note".
        // If needed, other urls can be find in a second step via the ark.
        $result = \Noid::note($noid, $contact, 'locations/' . $resource->id(), $ark);
        if (empty($result)) {
            $message = new Message(
                'Ark set, but no reverse bind [%1$s, %2$s #%3$d]: %4$s',
                $ark, $resource->getControllerName(), $resource->id(), \Noid::errmsg($noid)
            );
            $this->logger->warn($message);
        }

        \Noid::dbclose($noid);

        return $ark;
    }

    /**
     * @todo Include the creation of the noid database in the interface or in another plugin.
     */
    public function isDatabaseCreated()
    {
        $noid = $this->openDatabase();
        if (empty($noid)) {
            return false;
        }
        \Noid::dbclose($noid);
        return true;
    }

    /**
     * @todo Include the creation of the noid database in the interface or in another plugin.
     */
    public function createDatabase()
    {
        $contact = $this->getContact();

        $database = $this->getDatabaseDir();

        $template = $this->settings->get('ark_name_noid_template');
        $naan = $this->settings->get('ark_naan');
        $naa = $this->settings->get('ark_naa');
        $subnaa = $this->settings->get('ark_subnaa');

        $term = ($naan && $naa && $subnaa) ? 'long' : 'medium';

        $erc = \Noid::dbcreate($database, $contact, $template, $term, $naan, $naa, $subnaa);

        if (empty($erc)) {
            return \Noid::errmsg(null, 1);
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

        $levelNoid = in_array($level, ['meta', 'admin'])? 'brief' : $level;
        ob_start();
        $result = \Noid::dbinfo($noid, $levelNoid);
        $info = ob_get_contents();
        ob_end_clean();
        \Noid::dbclose($noid);

        if (!$result) {
            $message = new Message(
                'Cannot get database info: %s', // @translate
                \Noid::errmsg($noid, 1)
            );
            $this->logger->err($message);
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
                preg_match('~^  :/subnaa:\s+(.+)$~m', $info, $matches);
                $result['subnaa'] = $matches[1];
                preg_match('~^Template:\s+([\w\d.]+)$~m', $info, $matches);
                $result['template'] = $matches[1];
                $info = $result;
            }
        }

        return $info;
    }

    protected function openDatabase($mode = \Noid::DB_RDONLY)
    {
        $database = $this->getDatabaseDir();
        if (empty($database) || !is_dir($database)) {
            return false;
        }

        $database = $database . '/NOID/noid.bdb';

        return \Noid::dbopen($database, $mode);
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
}
