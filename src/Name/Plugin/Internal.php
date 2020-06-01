<?php
/**
 * Php Noid format for Ark name.
 */
namespace Ark\Name\Plugin;

// Use Noid via composer.

use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Settings\Settings;
use Zend\Log\Logger;

class Internal implements PluginInterface
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
     * @param Settings $settings
     * @param Logger $logger
     */
    public function __construct(Settings $settings, Logger $logger)
    {
        $this->settings = $settings;
        $this->logger = $logger;
    }

    public function isFullArk()
    {
        return true;
    }

    public function create(AbstractResourceEntityRepresentation $resource)
    {
        return $this->settings->get('ark_naan') . '/' . $resource->id();
    }

    /**
     * @todo Include the creation of the noid database in the interface or in another plugin.
     */
    public function isDatabaseCreated()
    {
        return true;
    }

    /**
     * @todo Include the creation of the noid database in the interface or in another plugin.
     */
    public function createDatabase()
    {
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
        $arkNaan = $this->settings->get('ark_naan');
        $arkNaa = $this->settings->get('ark_naa');
        $arkSubnaa = $this->settings->get('ark_subnaa');
        $arkTemplate = $this->settings->get('ark_name_noid_template');

        if ($level === 'meta') {
            return [
                'naan' => $arkNaan,
                'naa' => $arkNaa,
                'subnaa' => $arkSubnaa,
                'template' => $arkTemplate,
            ];
        }
    }
}
