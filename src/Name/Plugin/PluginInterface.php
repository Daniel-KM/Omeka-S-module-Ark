<?php

namespace Ark\Name\Plugin;

use Omeka\Api\Representation\AbstractResourceEntityRepresentation;

interface PluginInterface
{
    /**
     * @return bool
     */
    public function isFullArk();

    /**
     * @param AbstractResourceEntityRepresentation $resource
     * @return string|null
     */
    public function create(AbstractResourceEntityRepresentation $resource);
}
