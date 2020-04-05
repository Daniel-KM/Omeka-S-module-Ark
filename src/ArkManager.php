<?php

namespace Ark;

use Ark\Name\PluginManager as NamePlugins;
use Ark\Qualifier\PluginManager as QualifierPlugins;
use Doctrine\DBAL\Connection;
use Omeka\Mvc\Controller\Plugin\Api;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Settings\Settings;

class ArkManager
{
    /**
     * @var Api
     */
    protected $api;

    /**
     * @var Connection
     */
    protected $connection;

    /**
     * @var Settings
     */
    protected $settings;

    /**
     * @var NamePlugins
     */
    protected $namePlugins;

    /**
     * @var QualifierPlugins
     */
    protected $qualifierPlugins;

    /**
     * @param Api $api
     * @param Connection $connection
     * @param Settings $settings
     * @param NamePlugins $namePlugins
     * @param QualifierPlugins $qualifierPlugins
     */
    public function __construct(
        Api $api,
        Connection $connection,
        Settings $settings,
        NamePlugins $namePlugins,
        QualifierPlugins $qualifierPlugins
    ) {
        $this->api = $api;
        $this->connection = $connection;
        $this->settings = $settings;
        $this->namePlugins = $namePlugins;
        $this->qualifierPlugins = $qualifierPlugins;
    }

    /**
     * Find the resource from an ark.
     *
     * @param string|array $ark
     * @return AbstractResourceEntityRepresentation|null
     */
    public function find($ark)
    {
        if (empty($ark)) {
            return null;
        }

        $protocol = 'ark:';
        $naan = $this->settings->get('ark_naan');
        $base = $naan ? "$protocol/$naan/" : "$protocol/";

        if (is_string($ark)) {
            // Quick check of format.
            if (mb_strpos($ark, $base) !== 0) {
                return null;
            }

            // This is the ark of the naan.
            if ($ark == $base) {
                return null;
            }

            $fullName = mb_substr($ark, mb_strlen($base));
            if ($fullName == '?' || $fullName == '??') {
                return null;
            }

            // Get the identifier and the qualifier parts.
            $pos = mb_strpos($fullName, '/');
            if ($pos === false) {
                $name = $fullName;
                $qualifier = '';
            } else {
                $name = mb_substr($fullName, 0, $pos);
                $qualifier = mb_substr($fullName, $pos + 1);
            }
        } elseif (is_array($ark)) {
            if ($ark['naan'] !== $naan
                    || empty($ark['name']) || $ark['name'] == '?' || $ark['name'] == '??'
                ) {
                return null;
            }
            $name = $ark['name'];
            $qualifier = empty($ark['qualifier']) ? null : $ark['qualifier'];
        } else {
            return null;
        }

        // The resource adapter does not implement the search operation for now.
        $qb = $this->connection->createQueryBuilder();
        $qb
            ->select('value.resource_id, resource.resource_type')
            ->from('value', 'value')
            ->innerJoin('value', 'resource', 'resource', 'resource.id = value.resource_id')
            // Property 10 = dcterms:identifier.
            ->where('value.property_id = 10')
            ->andWhere('value.type = "literal"')
            ->andWhere('value.value = :value')
            ->setParameter('value', $base . $name)
            ->groupBy(['value.resource_id'])
            ->addOrderBy('value.resource_id', 'ASC')
            ->addOrderBy('value.id', 'ASC')
            // Only one identifier by resource.
            ->setMaxResults(1);
        $stmt = $this->connection->executeQuery($qb, $qb->getParameters());
        $resource = $stmt->fetch();

        if (empty($resource)) {
            return null;
        }

        if ($qualifier
            && $qualifierResource = $this->getResourceFromResourceIdAndQualifier($resource['resource_id'], $qualifier)
        ) {
            $resource = $qualifierResource;
        } else {
            $resourceType = $this->resourceType($resource['resource_type']);
            if ($resourceType) {
                $resource = $this->api
                    ->searchOne($resourceType, ['id' => $resource['resource_id']])->getContent();
            } else {
                $resource = null;
            }
        }

        return $resource;
    }

    /**
     * Return the ark of a resource, if any.
     *
     * @param AbstractResourceEntityRepresentation $resource
     * @return Ark
     */
    public function getArk(AbstractResourceEntityRepresentation $resource)
    {
        if ($resource->resourceName() == 'media') {
            $media = $resource;
            $resource = $media->item();
        } else {
            $media = null;
        }

        $identifiers = $resource->value('dcterms:identifier', ['all' => true, 'type' => 'literal']);
        $protocol = 'ark:';
        $naan = $this->settings->get('ark_naan');
        $base = $naan ? "$protocol/$naan/" : "$protocol/";
        $ark = null;
        if (!empty($identifiers)) {
            foreach ($identifiers as $identifier) {
                if (strpos($identifier->value(), $base) === 0) {
                    $ark = $identifier->value();
                    break;
                }
            }
        }

        if ($ark) {
            $ark = new Ark($naan, mb_substr($ark, mb_strlen($base)));

            if ($media) {
                $qualifier = $this->getQualifier($media);
                $ark->setQualifier($qualifier);
            }
        }

        return $ark;
    }

    /**
     * Return the ark of a resource via its id, if any.
     *
     * @param int $resourceId
     * @param string $resourceType "items", "item_sets" or "media" or variants.
     * @return Ark|null
     */
    public function getArkFromResourceId($resourceId, $resourceType = null)
    {
        $resourceId = (int) $resourceId;
        if (empty($resourceId)) {
            return null;
        }

        $resourceClass = $this->resourceClass($resourceType);
        if ($resourceClass === false) {
            return null;
        }

        $protocol = 'ark:';
        $naan = $this->settings->get('ark_naan');
        $base = $naan ? "$protocol/$naan/" : "$protocol/";

        $qb = $this->connection->createQueryBuilder();
        $qb
            ->select('value.value')
            ->from('value', 'value')
            // Property 10 = dcterms:identifier.
            ->where('value.property_id = 10')
            ->andWhere('value.type = "literal"');

        if ($resourceClass) {
            $qb
                ->innerJoin('value', 'resource', 'resource', 'resource.id = value.resource_id')
                ->andWhere('resource.resource_type = :resource_type');
            if ($resourceClass === \Omeka\Entity\Media::class) {
                $qb
                    ->innerJoin('resource', 'media', 'media', 'media.item_id = resource.id')
                    ->setParameter('resource_type', \Omeka\Entity\Item::class)
                    ->andWhere('media.id = :media_id')
                    ->setParameter('media_id', $resourceId);
            } else {
                $qb
                    ->andWhere('value.resource_id = :resource_id')
                    ->setParameter('resource_id', $resourceId)
                    ->setParameter('resource_type', $resourceClass);
            }
        } else {
            $qb
                ->andWhere('value.resource_id = :resource_id')
                ->setParameter('resource_id', $resourceId);
        }

        $qb
            ->andWhere('value.value LIKE :value')
            ->setParameter('value', $base . '%')
            ->groupBy(['value.resource_id'])
            ->addOrderBy('value.resource_id', 'ASC')
            ->addOrderBy('value.id', 'ASC')
            // Only one identifier by resource.
            ->setMaxResults(1);

        $stmt = $this->connection->executeQuery($qb, $qb->getParameters());
        $ark = $stmt->fetchColumn();

        if ($ark) {
            $ark = new Ark($naan, substr($ark, strlen($base)));
            if ($resourceClass === \Omeka\Entity\Media::class) {
                $qualifier = $this->getQualifierFromResourceId($resourceId);
                $ark->setQualifier($qualifier);
            }
        }

        return $ark;
    }

    /**
     * @return \Ark\Name\Plugin\Noid
     */
    public function getArkNamePlugin()
    {
        return $this->namePlugins->get('noid');
    }

    /**
     * Create the ark for a resource.
     *
     * @param AbstractResourceEntityRepresentation $resource
     * @return string|null
     */
    public function createName(AbstractResourceEntityRepresentation $resource)
    {
        if (empty($resource)) {
            return;
        }

        $naan = $this->settings->get('ark_naan');

        $namePlugin = $this->namePlugins->get('noid');
        $ark = $namePlugin->create($resource);

        // Check the result.
        if (empty($ark)) {
            $message = sprintf('No Ark created: check your format "%s" [%s #%d].',
                get_class($namePlugin), get_class($resource), $resource->id());
            error_log('[Ark&Noid] ' . $message);

            return;
        }

        // Complete partial ark.
        $mainPart = $ark;
        // Check ark (useful only for external process).
        if (!$this->checkFullArk($ark)) {
            $message = sprintf('Ark "%s" is not correct: check your format "%s" and your processor [%s].', $ark, get_class($namePlugin), get_class($resource));
            error_log('[Ark&Noid] ' . $message);

            return;
        }

        // Add the protocol.
        $ark = 'ark:/' . $ark;

        // Check if the ark is single.
        if ($this->arkExists($ark)) {
            if ($namePlugin->isFullArk()) {
                $message = sprintf('The proposed ark "%s" is not unique [%s #%d].',
                    $ark, get_class($resource), $resource->id());
                error_log('[Ark&Noid] ' . $message);

                return null;
            }

            $message = 'Unable to create a unique ark.'
                . ' ' . sprintf('Check parameters of the format "%s" [%s #%d].',
                get_class($namePlugin), get_class($resource), $resource->id());
            error_log('[Ark&Noid] ' . $message);

            return null;
        }

        return $ark;
    }

    /**
     * Return the qualifier part of an ark.
     *
     * @param AbstractResourceEntityRepresentation $resource
     * @return string
     */
    protected function getQualifier(AbstractResourceEntityRepresentation $resource)
    {
        /** @var \Ark\Qualifier\Plugin\Internal $qualifierPlugin */
        $qualifierPlugin = $this->qualifierPlugins->get('internal');
        return $qualifierPlugin->create($resource);
    }

    /**
     * Return the qualifier part of an ark via the resource id.
     *
     * @param int $resourceId
     * @return string
     */
    protected function getQualifierFromResourceId($resourceId)
    {
        /** @var \Ark\Qualifier\Plugin\Internal $qualifierPlugin */
        $qualifierPlugin = $this->qualifierPlugins->get('internal');
        return $qualifierPlugin->createFromResourceId($resourceId);
    }

    /**
     * Get resource from resource qualifier.
     *
     * @param AbstractResourceEntityRepresentation $resource
     * @param string $qualifier
     * @return AbstractResourceEntityRepresentation
     */
    protected function getResourceFromQualifier(AbstractResourceEntityRepresentation $resource, $qualifier)
    {
        /** @var \Ark\Qualifier\Plugin\Internal $qualifierPlugin */
        $qualifierPlugin = $this->qualifierPlugins->get('internal');
        return $qualifierPlugin->getResourceFromQualifier($resource, $qualifier);
    }

    /**
     * Get resource from qualifier.
     *
     * @param AbstractResourceEntityRepresentation $resource
     * @param string $qualifier
     * @return AbstractResourceEntityRepresentation
     */
    protected function getResourceFromResourceIdAndQualifier($resourceId, $qualifier)
    {
        /** @var \Ark\Qualifier\Plugin\Internal $qualifierPlugin */
        $qualifierPlugin = $this->qualifierPlugins->get('internal');
        return $qualifierPlugin->getResourceFromResourceIdAndQualifier($resourceId, $qualifier);
    }

    /**
     * Check if a full ark is a true ark.
     *
     * @param string $ark
     * @return bool
     */
    protected function checkFullArk($ark)
    {
        $ark = trim($ark);
        $result = explode('/', $ark);

        $naan = $this->settings->get('ark_naan');

        if ($naan) {
            if (count($result) != 2) {
                return false;
            }
            if ($result[0] != $naan) {
                return false;
            }

            $clean = preg_replace('/[^a-zA-Z0-9]/', '', $result[1]);

            return $clean == $result[1];
        }

        // Else no naan.
        if (strpos($ark, '/') !== false) {
            return false;
        }

        $clean = preg_replace('/[^a-zA-Z0-9]/', '', $ark);

        return $clean == $ark;
    }

    /**
     * Check if an ark exists in the database.
     *
     * @param string $ark The full well formed ark, with "ark:/"
     * @return bool
     */
    protected function arkExists($ark)
    {
        return (bool) $this->find($ark);
    }

    /**
     * Get the resource class from the resource type.
     *
     * @param string|null  $resourceType
     * @return string|null|bool Null if any resources. False if not managed.
     */
    protected function resourceClass($resourceType)
    {
        $resourceTypes = [
            null => null,
            'item' => \Omeka\Entity\Item::class,
            'items' => \Omeka\Entity\Item::class,
            'item-set' => \Omeka\Entity\ItemSet::class,
            'item_sets' => \Omeka\Entity\ItemSet::class,
            'media' => \Omeka\Entity\Media::class,
            'resource' => null,
            'resources' => null,
            // Avoid a check.
            \Omeka\Entity\Item::class => \Omeka\Entity\Item::class,
            \Omeka\Entity\ItemSet::class => \Omeka\Entity\ItemSet::class,
            \Omeka\Entity\Media::class => \Omeka\Entity\Media::class,
            \Omeka\Entity\Resource::class => null,
        ];
        if (!isset($resourceTypes[$resourceType])) {
            return false;
        }
        return $resourceTypes[$resourceType];
    }

    /**
     * Get the resource type from the resource class.
     *
     * @param string $resourceClass
     * @return string|null|bool Null if any resources. False if not managed.
     */
    protected function resourceType($resourceClass)
    {
        $resourceClasses = [
            null => null,
            \Omeka\Entity\Item::class => 'items',
            \Omeka\Entity\ItemSet::class => 'item_sets',
            \Omeka\Entity\Media::class => 'media',
        ];
        if (!isset($resourceClasses[$resourceClass])) {
            return false;
        }
        return $resourceClasses[$resourceClass];
    }
}
