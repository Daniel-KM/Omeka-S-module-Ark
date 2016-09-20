<?php
/**
 * Php Noid format for Ark name.
 *
 * @package Ark
 */
namespace Ark\Name\Plugin;

class Noid implements PluginInterface
{
    protected $databaseDir;

    public function __construct($settings)
    {
        $this->settings = $settings;

        $this->databaseDir = OMEKA_PATH . '/files/arkandnoid';
    }

    public function isFullArk()
    {
        return true;
    }

    public function create($resource)
    {
        $noid = $this->openDatabase(\Noid::DB_WRITE);
        if (empty($noid)) {
            $message = sprintf('Cannot open database: %s',
                \Noid::errmsg(null, 1) ?: 'No database');
            error_log('[Ark&Noid] ' . $message);
            return;
        }

        $recordUrl = $resource->adminUrl();

        // Check if the url is already set (only the Omeka id: the other ids are
        // not automatic and can't be checked the same).
        $ark = \Noid::get_note($noid, 'locations/' . $recordUrl);
        if ($ark) {
            \Noid::dbclose($noid);
            return $ark;
        }

        $recordUrls[] = $recordUrl;

        $contact = $this->getContact();

        $ark = \Noid::mint($noid, $contact);
        if (strlen($ark) == '') {
            $message = sprintf('Cannot create an Ark for %s #%d: %s',
                get_class($resource), $resource->id(), \Noid::errmsg($noid));
            error_log('[Ark&Noid] ' . $message);
            \Noid::dbclose($noid);
            return;
        }

        // Bind the ark and the record.
        $locations = implode('|', $recordUrls);
        $result = \Noid::bind($noid, $contact, 1, 'set', $ark, 'locations', $locations);
        if (empty($result)) {
            $message = sprintf('Ark set, but not bound [%s, %s #%d]: %s',
                $ark, get_class($resource), $resource->id(), \Noid::errmsg($noid));
            error_log('[Ark&Noid] ' . $message);
        }

        // Save the reverse bind on Omeka id to find it instantly, as a "note".
        // If needed, other urls can be find in a second step via the ark.
        $result = \Noid::note($noid, $contact, 'locations/' . $recordUrl, $ark);
        if (empty($result)) {
            $message = sprintf('Ark set, but no reverse bind [%s, %s #%d]: %s',
                $ark, get_class($resource), $resource->id(), \Noid::errmsg($noid));
            error_log('[Ark&Noid] ' . $message);
        }

        \Noid::dbclose($noid);

        return $ark;
    }

    public function isDatabaseCreated()
    {
        $noid = $this->openDatabase();
        if (empty($noid)) {
            return false;
        }
        \Noid::dbclose($noid);
        return true;
    }

    public function createDatabase()
    {
        $contact = $this->getContact();

        $database = $this->databaseDir;

        $template = $this->settings->get('ark_noid_template');
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

    protected function openDatabase($mode = \Noid::DB_RDONLY)
    {
        $database = $this->databaseDir;
        if (empty($database) || !is_dir($database)) {
            return false;
        }

        $database = $database . '/NOID/noid.bdb';
        return \Noid::dbopen($database, $mode);
    }

    protected function getContact()
    {
        return $this->settings->get('administrator_email', 'Unknown user');
    }
}
