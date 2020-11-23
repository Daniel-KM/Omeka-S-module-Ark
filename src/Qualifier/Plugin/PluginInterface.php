<?php declare(strict_types=1);

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
     * Create the qualifier from a resource id, generally a media id.
     *
     * May avoid a query when the id is known, but not the resource.
     *
     * @param int $resourceId
     * @return string
     */
    public function createFromResourceId($resourceId);

    /**
     * Get the resource from a resource (generally item) and a qualifier
     * (generally media).
     *
     * @param AbstractResourceEntityRepresentation $resource
     * @param string $qualifier
     * @return AbstractResourceEntityRepresentation|null
     */
    public function getResourceFromQualifier(AbstractResourceEntityRepresentation $resource, $qualifier);

    /**
     * Get the resource from a resource id (generally item) and a qualifier
     * (generally media).
     *
     * @param int $resourceId
     * @param string $qualifier
     * @return AbstractResourceEntityRepresentation|null
     */
    public function getResourceFromResourceIdAndQualifier($resourceId, $qualifier);
}
