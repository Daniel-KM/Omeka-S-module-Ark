<?php declare(strict_types=1);

namespace Ark\Qualifier\Plugin;

use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;

/**
 * Change the format for Ark qualifier.
 */
class Internal implements PluginInterface
{
    /**
     * @var ApiManager
     */
    protected $api;

    /**
     * @param ApiManager $api
     */
    public function __construct(ApiManager $api)
    {
        $this->api = $api;
    }

    public function create(AbstractResourceEntityRepresentation $resource): ?string
    {
        return (string) $resource->id();
    }

    public function createFromResourceId($resourceId): ?string
    {
        $resourceId = (int) $resourceId;
        return $resourceId ? (string) $resourceId : null;
    }

    public function getResourceFromQualifier(AbstractResourceEntityRepresentation $resource, string $qualifier): ?AbstractResourceEntityRepresentation
    {
        if ($resource->resourceName() !== 'items') {
            return null;
        }

        $qualifier = (int) $qualifier;
        if (empty($qualifier)) {
            return null;
        }

        $media = $this->api
            ->search('media', [
                'id' => $qualifier,
                'item_id' => $resource->id(),
                'limit' => 1,
            ])
            ->getContent();
        return $media ? reset($media) : null;
    }

    public function getResourceFromResourceIdAndQualifier($resourceId, string $qualifier): ?AbstractResourceEntityRepresentation
    {
        $resourceId = (int) $resourceId;
        if (empty($resourceId)) {
            return null;
        }

        $qualifier = (int) $qualifier;
        if (empty($qualifier)) {
            return null;
        }

        $media = $this->api
            ->search('media', [
                'id' => $qualifier,
                'item_id' => $resourceId,
                'limit' => 1,
            ])
            ->getContent();
        return $media ? reset($media) : null;
    }
}
