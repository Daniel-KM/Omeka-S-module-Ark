<?php

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
     *
     * @param ApiManager $api
     */
    public function __construct(ApiManager $api)
    {
        $this->api = $api;
    }

    public function create(AbstractResourceEntityRepresentation $resource)
    {
        return $resource->id();
    }

    public function createFromResourceId($resourceId)
    {
        $resourceId = (int) $resourceId;
        return $resourceId ? (string) $resourceId : '';
    }

    public function getResourceFromQualifier(AbstractResourceEntityRepresentation $resource, $qualifier)
    {
        if ($resource->resourceName() != 'items') {
            return;
        }

        $qualifier = (int) $qualifier;
        if (empty($qualifier)) {
            return;
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

    public function getResourceFromResourceIdAndQualifier($resourceId, $qualifier)
    {
        $resourceId = (int) $resourceId;
        if (empty($resourceId)) {
            return;
        }

        $qualifier = (int) $qualifier;
        if (empty($qualifier)) {
            return;
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
