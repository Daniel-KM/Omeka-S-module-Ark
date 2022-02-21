<?php declare(strict_types=1);

namespace Ark\Qualifier\Plugin;

use Omeka\Api\Representation\AbstractResourceEntityRepresentation;

interface PluginInterface
{
    /**
     * Create the qualifier for a resource, generally a media.
     */
    public function create(AbstractResourceEntityRepresentation $resource): ?string;

    /**
     * Create the qualifier from a resource id, generally a media id.
     *
     * May avoid a query when the id is known, but not the resource.
     */
    public function createFromResourceId($resourceId): ?string;

    /**
     * Get the resource from a resource (generally item) and a qualifier
     * (generally media).
     */
    public function getResourceFromQualifier(AbstractResourceEntityRepresentation $resource, string $qualifier): ?AbstractResourceEntityRepresentation;

    /**
     * Get the resource from a resource id (generally item) and a qualifier
     * (generally media).
     */
    public function getResourceFromResourceIdAndQualifier($resourceId, string $qualifier): ?AbstractResourceEntityRepresentation;
}
