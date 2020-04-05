<?php

namespace Ark\Qualifier\Plugin;

use Doctrine\ORM\EntityManager;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Zend\Log\Logger;

/**
 * Change the format for Ark qualifier.
 */
class Position implements PluginInterface
{
    /**
     * @var string
     */
    protected $format;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var ApiManager
     */
    protected $api;

    /**
     * @var EntityManager
     */
    protected $entityManager;

    /**
     * @param string $format
     * @param Logger $logger
     * @param ApiManager $api
     * @param EntityManager $entityManager
     */
    public function __construct($format, Logger $logger, ApiManager $api, EntityManager $entityManager)
    {
        $this->format = mb_strlen($format) ? $format : 'p%d';
        $this->logger = $logger;
        $this->api = $api;
        $this->entityManager = $entityManager;
    }

    public function create(AbstractResourceEntityRepresentation $resource)
    {
        if ($resource->resourceName() !== 'media') {
            return null;
        }

        /** @var \Omeka\Entity\Media $resource */
        $resource = $this->api->read('media', ['id' => $resource->id()], [], ['responseContent' => 'resource'])->getContent();

        // Fix the position before adding it: some media have no position, some
        // other don't start from 1.

        // Don't use $item->media() to avoid a different position for
        // public/private.
        // Media are automatically ordered (cf. item entity).
        $position = 0;
        $positionResource = 0;
        foreach ($resource->getItem()->getMedia() as $media) {
            ++$position;
            if ($media->getPosition() !== $position) {
                $media->setPosition($position);
                $this->entityManager->persist($media);
            }
            if ($media->getId() === $resource->getId()) {
                $positionResource = $position;
            }
        }
        $this->entityManager->flush();

        return sprintf($this->format, $positionResource);
    }

    public function createFromResourceId($resourceId)
    {
        try {
            $resource = $this->api->read('media', ['id' => $resourceId])->getContent();
            return $this->create($resource);
        } catch (\Omeka\Api\Exception\NotFoundException $e) {
            return null;
        }
    }

    public function getResourceFromQualifier(AbstractResourceEntityRepresentation $resource, $qualifier)
    {
        if ($resource->resourceName() !== 'items') {
            return null;
        }
        return $this->getResourceFromResourceIdAndQualifier($resource->id(), $qualifier);
    }

    public function getResourceFromResourceIdAndQualifier($resourceId, $qualifier)
    {
        $resourceId = (int) $resourceId;
        if (empty($resourceId)) {
            return null;
        }

        // Whatever the format, use the numeric character only: sprintf cannot
        // be reversed.
        $position = preg_replace('~\D~', '', $qualifier);
        $sql = 'SELECT id FROM media WHERE item_id = :item_id AND position = :position;';
        $stmt = $this->entityManager->getConnection()->prepare($sql);
        $stmt->bindValue('item_id', $resourceId);
        $stmt->bindValue('position', $position);
        $stmt->execute();
        $id = $stmt->fetchColumn();

        if (!$id) {
            return null;
        }

        // The id may be private.
        try {
            return $this->api()->read('media', $id)->getContent();
        } catch (\Omeka\Api\Exception\NotFoundException $e) {
            return null;
        }
    }
}
