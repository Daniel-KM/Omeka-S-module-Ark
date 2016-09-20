<?php

namespace Ark\Qualifier\Plugin;

use Omeka\Api\Manager as ApiManager;

/**
 * Change the format for Ark qualifier.
 *
 * @package Ark
 */
class Internal implements PluginInterface
{
    public function __construct(ApiManager $api)
    {
        $this->api = $api;
    }

    public function create($resource)
    {
        return $resource->id();
    }

    public function getResourceFromQualifier($resource, $qualifier)
    {
        if ($resource->resourceName() != 'items') {
            return;
        }

        $qualifier = (integer) $qualifier;
        if (empty($qualifier)) {
            return;
        }

        $media = $this->api->read('media', $qualifier)->getContent();
        if (empty($media) || $media->item()->id() != $resource->id()) {
            return;
        }

        return $media;
    }
}
