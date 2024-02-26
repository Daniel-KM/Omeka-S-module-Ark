<?php declare(strict_types=1);

namespace Ark\Name\Plugin;

use Laminas\Log\Logger;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Settings\Settings;

/**
 * Php Noid format for Ark name.
 *
 * @todo Use Noid via composer.
 */
class Internal implements PluginInterface
{
    /**
     * @var \Laminas\Log\Logger
     */
    protected $logger;

    /**
     * @var \Omeka\Settings\Settings
     */
    protected $settings;

    public function __construct(
        Logger $logger,
        Settings $settings
    ) {
        $this->logger = $logger;
        $this->settings = $settings;
    }

    public function isFullArk(): bool
    {
        return true;
    }

    public function create(AbstractResourceEntityRepresentation $resource): string
    {
        return $this->settings->get('ark_naan') . '/' . $resource->id();
    }

    /**
     * @todo Include the creation of the noid database in the interface or in another plugin.
     */
    public function isDatabaseCreated(): bool
    {
        return true;
    }

    /**
     * @todo Include the creation of the noid database in the interface or in another plugin.
     */
    public function createDatabase(): bool
    {
        return true;
    }

    /**
     * @todo Include the info of the noid database in the interface or in another plugin.
     *
     * @param string $level "meta" (default), "admin", "brief", "full", or "dump".
     * @return array|string
     */
    public function infoDatabase($level = 'meta'): ?array
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
