<?php
/**
 * Php Noid format for Ark name.
 *
 * @package Ark
 */
namespace Ark\Ark\Name;
use Ark\Ark\Name\AbstractName;
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'AbstractName.php';
require_once dirname(__FILE__) . '/../../Noid4Php/lib/Noid.php';
class Noid extends AbstractName
{
    protected $_isFullArk = true;

    protected $_database = '';
    protected $_noid = '';

    public function __construct($parameters = array())
    {
        parent::__construct($parameters);
    }

    protected function _create()
    {
        // Record and parameters are not used to create the noid (an index), but
        // to store it for long term purposes.
        $record = &$this->_record;

        $noid = $this->_openDatabase(\Noid::DB_WRITE);
        if (empty($noid)) {
            $message = __('Cannot open database: %s', \Noid::errmsg(null, 1) ?: __('No database'));
            _log('[Ark&Noid] ' . $message, Zend_Log::ERR);
            return;
        }

        $recordUrl = $record->adminUrl();

        // Check if the url is already set (only the Omeka id: the other ids are
        // not automatic and can't be checked the same).
        $ark = \Noid::get_note($noid, 'locations/' . $recordUrl);
        if ($ark) {
            \Noid::dbclose($noid);
            return $ark;
        }

        $recordUrls[] = $recordUrl;

        $contact = $this->_getContact();

        $ark = \Noid::mint($noid, $contact);
        if (strlen($ark) == '') {
            $message = __('Cannot create an Ark for %s #%d: %s',
                get_class($record), $record->id(), Noid::errmsg($noid));
            _log('[Ark&Noid] ' . $message, Zend_Log::ERR);
            \Noid::dbclose($noid);
            return;
        }

        // Bind the ark and the record.
        $locations = implode('|', $recordUrls);
        $result = \Noid::bind($noid, $contact, 1, 'set', $ark, 'locations', $locations);
        if (empty($result)) {
            $message = __('Ark set, but not bound [%s, %s #%d]: %s',
                $ark, get_class($record), $record->id(), \Noid::errmsg($noid));
            _log('[Ark&Noid] ' . $message, Zend_Log::ERR);
        }

        // Save the reverse bind on Omeka id to find it instantly, as a "note".
        // If needed, other urls can be find in a second step via the ark.
        $result = \Noid::note($noid, $contact, 'locations/' . $recordUrl, $ark);
        if (empty($result)) {
            $message = __('Ark set, but no reverse bind [%s, %s #%d]: %s',
                $ark, get_class($record), $record->id(), \Noid::errmsg($noid));
            _log('[Ark&Noid] ' . $message, Zend_Log::ERR);
        }

        \Noid::dbclose($noid);

        return $ark;
    }

    /**
     * Check parameters.
     *
     * @return boolean
     */
    protected function _checkParameters()
    {
        if ($this->isDatabaseCreated()) {
            return true;
        }

        // Only the template is checked, because non-ark may be created.
        $template = $this->_getParameter('template');
        if (empty($template)) {
            return false;
        }

        $prefix = null;
        $mask = null;
        $gen_type = null;
        $message = null;

        $total = \Noid::parse_template($template, $prefix, $mask, $gen_type, $message);
        if (empty($total)) {
            $this->_errorMessage = __('Template unparsable: %s', $message);
            return false;
        }

        return true;
    }

    public function isDatabaseCreated()
    {
        $noid = $this->_openDatabase();
        if (empty($noid)) {
            return false;
        }
        \Noid::dbclose($noid);
        return true;
    }

    public function createDatabase()
    {
        $contact = $this->_getContact();

        $database = $this->_getParameter('database');

        $template = $this->_getParameter('template');
        $naan = $this->_getParameter('naan');
        $naa = $this->_getParameter('naa');
        $subnaa = $this->_getParameter('subnaa');

        $term = ($naan && $naa && $subnaa) ? 'long' : 'medium';

        $erc = \Noid::dbcreate($database, $contact, $template, $term, $naan, $naa, $subnaa);

        if (empty($erc)) {
            return \Noid::errmsg(null, 1);
        }
        // dbcreate() closes the database automatically.

        return true;
    }

    protected function _openDatabase($mode = \Noid::DB_RDONLY)
    {
        $database = $this->_getParameter('database');
        if (empty($database) || !is_dir($database)) {
            return false;
        }

        $this->_database = $database.
            DIRECTORY_SEPARATOR . 'NOID' . DIRECTORY_SEPARATOR . 'noid.bdb';
        $this->_noid = \Noid::dbopen($this->_database, $mode);
        return $this->_noid;
    }
}
