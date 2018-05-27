<?php

namespace Ark\Qualifier\Plugin;

use Omeka\Api\Representation\AbstractResourceEntityRepresentation;

interface PluginInterface
{
    /**
     * Create the qualifier for a resource, generally a media.
     *
     * @param AbstractResourceEntityRepresentation $resource
     * @return string
     */
    public function create(AbstractResourceEntityRepresentation $resource);

    /**
     * Get the resource from a resource (generally item) and a qualifier
     * (generally media).
     *
     * @param AbstractResourceEntityRepresentation $resource
     * @param string $qualifier
     * @return AbstractResourceEntityRepresentation|null
     */
    public function getResourceFromQualifier(AbstractResourceEntityRepresentation $resource, $qualifier);
}
