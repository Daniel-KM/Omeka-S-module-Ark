<?php declare(strict_types=1);

namespace Ark;

use Ark\Name\PluginManager as NamePlugins;
use Ark\Qualifier\PluginManager as QualifierPlugins;
use Doctrine\DBAL\Connection;
use Laminas\Log\Logger;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Mvc\Controller\Plugin\Api;
use Omeka\Stdlib\Message;

class ArkManager
{
    /**
     * @string string
     */
    protected $naan;

    /**
     * @string string
     */
    protected $namePluginName;

    /**
     * @string string
     */
    protected $qualifierPluginName;

    /**
     * @string bool
     */
    protected $qualifierStatic;

    /**
     * @var Api
     */
    protected $api;

    /**
     * @var Connection
     */
    protected $connection;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var NamePlugins
     */
    protected $namePlugins;

    /**
     * @var QualifierPlugins
     */
    protected $qualifierPlugins;

    /**
     * @todo Remove all code related to missing naan (use 99999 for test).
     */
    public function __construct(
        ?string $naan,
        ?string $namePluginName,
        ?string $qualifierPluginName,
        bool $qualifierStatic,
        Api $api,
        Connection $connection,
        Logger $logger,
        NamePlugins $namePlugins,
        QualifierPlugins $qualifierPlugins
    ) {
        $this->naan = $naan;
        $this->namePluginName = $namePluginName;
        $this->qualifierPluginName = $qualifierPluginName;
        $this->qualifierStatic = $qualifierStatic;
        $this->api = $api;
        $this->connection = $connection;
        $this->logger = $logger;
        $this->namePlugins = $namePlugins;
        $this->qualifierPlugins = $qualifierPlugins;
    }

    /**
     * Find the resource from an ark. The qualifier can be dynamic or saved.
     *
     * @param string|array $ark
     */
    public function find($ark): ?AbstractResourceEntityRepresentation
    {
        if (empty($ark)) {
            return null;
        }

        $protocol = 'ark:';
        $base = $this->naan ? "$protocol/{$this->naan}/" : "$protocol/";

        if (is_string($ark)) {
            // Quick check of format.
            if (mb_strpos($ark, $base) !== 0) {
                return null;
            }

            // This is the ark of the naan.
            if ($ark === $base) {
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
            if ($ark['naan'] !== $this->naan
                    || empty($ark['name']) || $ark['name'] == '?' || $ark['name'] == '??'
                ) {
                return null;
            }
            $name = $ark['name'];
            $qualifier = empty($ark['qualifier']) ? '' : $ark['qualifier'];
        } else {
            return null;
        }

        $hasQualifier = strlen($qualifier);

        // The resource adapter does not implement the search operation for now.
        $qb = $this->connection->createQueryBuilder();
        $qb
            ->select([
                'value.value',
                'value.resource_id',
                'resource.resource_type',
                // Only needed to support group by on mysql.
                'value.id',
            ])
            ->from('value', 'value')
            ->innerJoin('value', 'resource', 'resource', 'resource.id = value.resource_id')
            // Property 10 = dcterms:identifier.
            ->where('value.property_id = 10')
            ->andWhere('value.type = "literal"')
            ->groupBy([
                'value.resource_id',
                'resource.resource_type',
            ])
            ->addOrderBy('value.resource_id', 'ASC')
            ->addOrderBy('value.id', 'ASC');
        if ($hasQualifier) {
            $qb
                // Manage the case where the qualifier is dynamic.
                ->andWhere($qb->expr()->orX(
                    'value.value = :value',
                    'value.value = :valueq'
                ))
                ->setParameter('value', $base . $name)
                ->setParameter('valueq', $base . $name . '/' . $qualifier)
                // The base is generally the same for item and media.
                ->setMaxResults(2);
        } else {
            $qb
                ->andWhere('value.value = :value')
                ->setParameter('value', $base . $name)
                // Only one identifier by resource.
                ->setMaxResults(1);
        }

        $resources = $this->connection->executeQuery($qb, $qb->getParameters())->fetchAll();

        if (empty($resources)) {
            return null;
        }

        if (count($resources) === 2) {
            // When there are two resources, the longest has the qualifier.
            $resource = mb_strlen($resources[0]['value']) > mb_strlen($resources[1]['value'])
                ? $resources[0]
                : $resources[1];
            $resourceType = $this->resourceType($resource['resource_type']);
            $resourceId = (int) $resource['resource_id'];
            $resource = $resourceType
                ? $this->api->searchOne($resourceType, ['id' => $resourceId])->getContent()
                : null;
        } else {
            $resource = $resources[0];
            $resourceId = empty($resource['resource_id']) ? 0 : (int) $resource['resource_id'];
            if (!$resourceId) {
                $resource = null;
            } elseif ($hasQualifier
                && $qualifierResource = $this->getResourceFromResourceIdAndQualifier($resourceId, $qualifier)
            ) {
                $resource = $qualifierResource;
            } elseif ($resourceType = $this->resourceType($resource['resource_type'])) {
                $resource = $this->api
                    ->searchOne($resourceType, ['id' => $resourceId])->getContent();
            } else {
                $resource = null;
            }
        }

        return $resource;
    }

    /**
     * Return the ark of a resource, if any.
     */
    public function getArk(?AbstractResourceEntityRepresentation $resource): ?Ark
    {
        if (!$resource) {
            return null;
        }

        $ark = null;
        $identifiers = $resource->value('dcterms:identifier', ['type' => 'literal', 'all' => true, 'default' => []]);
        $protocol = 'ark:';
        $base = $this->naan ? "$protocol/{$this->naan}/" : "$protocol/";
        foreach ($identifiers as $identifier) {
            if (strpos($identifier->value(), $base) === 0) {
                $ark = $identifier->value();
                break;
            }
        }

        // Clean the ark and add the qualifer if any.
        if ($ark) {
            $name = strtok(mb_substr($ark, mb_strlen($base)), '/');
            $qualifier = $resource->resourceName() === 'media'
                ? strtok('/')
                : null;
            return new Ark($this->naan, $name, $qualifier);
        }

        // Check dynamic ark for media.
        if ($resource->resourceName() !== 'media' || $this->qualifierStatic) {
            return null;
        }

        $media = $resource;
        $resource = $media->item();
        $identifiers = $resource->value('dcterms:identifier', ['type' => 'literal', 'all' => true, 'default' => []]);
        $protocol = 'ark:';
        $base = $this->naan ? "$protocol/{$this->naan}/" : "$protocol/";
        foreach ($identifiers as $identifier) {
            if (strpos($identifier->value(), $base) === 0) {
                $ark = $identifier->value();
                break;
            }
        }
        if (!$ark) {
            return null;
        }

        $name = strtok(mb_substr($ark, mb_strlen($base)), '/');
        $qualifier = $this->getQualifier($media);
        return new Ark($this->naan, $name, $qualifier);
    }

    /**
     * Return the ark of a resource via its id, if any.
     *
     * @param string $resourceType "items", "item_sets" or "media" or variants.
     */
    public function getArkFromResourceId($resourceId, ?string $resourceType = null): Ark
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
        $base = $this->naan ? "$protocol/{$this->naan}/" : "$protocol/";

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

        $ark = $this->connection->executeQuery($qb, $qb->getParameters())->fetchOne();

        if ($ark) {
            $ark = new Ark($this->naan, substr($ark, strlen($base)));
            if ($resourceClass === \Omeka\Entity\Media::class) {
                $qualifier = $this->getQualifierFromResourceId($resourceId);
                $ark->setQualifier($qualifier);
            }
        }

        return $ark;
    }

    /**
     * Create the ark for a resource.
     */
    public function createName(AbstractResourceEntityRepresentation $resource): ?string
    {
        if ($resource->resourceName() === 'media') {
            return $this->createNameQualifier($resource);
        }

        $ark = $this->getArkNamePlugin()->create($resource);

        // Check the result.
        if (empty($ark)) {
            $message = new Message(
                'No Ark created: check your processor "%1$s" [%2$s #%3$d].', // @translate
                $this->namePluginName, $resource->getControllerName(), $resource->id()
            );
            $this->logger->err($message);
            return null;
        }

        // Check ark (useful only for external process).
        if (!$this->checkFullArk($ark)) {
            $message = new Message(
                'Ark "%1$s" is not correct: check your naan "%2$s", your template, and your processor [%3$s].', // @translate
                $ark, $this->naan, $this->namePluginName
            );
            $this->logger->err($message);
            return null;
        }

        // Add the protocol.
        $ark = 'ark:/' . $ark;

        // Check if the ark is single.
        if ($this->arkExists($ark)) {
            if ($this->getArkNamePlugin()->isFullArk()) {
                $message = new Message(
                    'The proposed ark "%1$s" by the processor "%2$s" is not unique [%3$s #%4$d].', // @translate
                    $ark, $this->namePluginName, $resource->getControllerName(), $resource->id()
                );
                $this->logger->err($message);
                return null;
            }

            $message = new Message(
                'Unable to create a unique ark. Check parameters of the processor "%1$s" [%2$s #%3$d].', // @translate
                $this->namePluginName, $resource->getControllerName(), $resource->id()
            );
            $this->logger->err($message);
            return null;
        }

        return $ark;
    }

    protected function createNameQualifier(MediaRepresentation $media): ?string
    {
        // Check if the item has an ark first: avoid to set an ark separately
        // for a media.
        $ark = $this->getArk($media->item());
        if (!$ark) {
            $message = new Message(
                'No Ark qualfiier created for media #%1$d: the item #%2$d does not have an ark. Update it first.', // @translate
                $media->id(), $media->item()->id()
            );
            $this->logger->err($message);
            return null;
        }

        if (!$this->qualifierStatic) {
            $message = new Message(
                'Unable to create a qualifier for media #%1$d: option is "dynamic qualifier".', // @translate
                $media->id()
            );
            $this->logger->err($message);
            return null;
        }

        $qualifier = $this->getQualifier($media);
        if (!$qualifier) {
            $message = new Message(
                'Unable to create a qualifier for media #%1$d. Check the processor "%2$s".', // @translate
                $media->id(), $this->qualifierPluginName
            );
            $this->logger->err($message);
            return null;
        }

        $ark .= '/' . $qualifier;

        // Check if the ark is single.
        if ($this->arkExists($ark)) {
            $message = new Message(
                'Unable to create a unique ark. Check the processor "%1$s" [%2$s #%3$d].', // @translate
                $this->qualifierPluginName, $media->getControllerName(), $media->id()
            );
            $this->logger->err($message);
            return null;
        }

        return $ark;
    }

    /**
     * Return the qualifier part of an ark via the resource id.
     */
    protected function getQualifierFromResourceId(int $resourceId): ?string
    {
        return $this->getQualifierPlugin()->createFromResourceId($resourceId);
    }

    /**
     * Get resource from resource qualifier.
     */
    protected function getResourceFromQualifier(AbstractResourceEntityRepresentation $resource, string $qualifier): ?AbstractResourceEntityRepresentation
    {
        return $this->getQualifierPlugin()->getResourceFromQualifier($resource, $qualifier);
    }

    /**
     * Get resource from qualifier.
     */
    protected function getResourceFromResourceIdAndQualifier(int $resourceId, string $qualifier): ?AbstractResourceEntityRepresentation
    {
        return $this->getQualifierPlugin()->getResourceFromResourceIdAndQualifier($resourceId, $qualifier);
    }

    /**
     * Check if a full ark is a true ark.
     */
    protected function checkFullArk(string $ark): bool
    {
        $ark = trim($ark);
        $result = explode('/', $ark);

        if ($this->naan) {
            if (count($result) != 2) {
                return false;
            }
            if ($result[0] != $this->naan) {
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

        return $clean === $ark;
    }

    /**
     * Check if an ark exists in the database.
     *
     * @param string $ark The full well formed ark, with "ark:/"
     */
    protected function arkExists(string $ark): bool
    {
        return (bool) $this->findStatic($ark);
    }

    /**
     * Find the resource from a static ark.
     */
    protected function findStatic(string $ark): ?AbstractResourceEntityRepresentation
    {
        if (empty($ark)) {
            return null;
        }

        $protocol = 'ark:';
        $base = $this->naan ? "$protocol/{$this->naan}/" : "$protocol/";

        // Quick check of format.
        if (mb_strpos($ark, $base) !== 0) {
            return null;
        }

        // The resource adapter does not implement the search operation for now.
        $qb = $this->connection->createQueryBuilder();
        $qb
            ->select([
                'value.resource_id',
                'resource.resource_type',
                // Only needed to support group by on mysql.
                'value.id',
            ])
            ->from('value', 'value')
            ->innerJoin('value', 'resource', 'resource', 'resource.id = value.resource_id')
            // Property 10 = dcterms:identifier.
            ->where('value.property_id = 10')
            ->andWhere('value.type = "literal"')
            ->andWhere('value.value = :value')
            ->setParameter('value', $ark)
            ->groupBy([
                'value.resource_id',
                'resource.resource_type',
                'value.id',
            ])
            ->addOrderBy('value.resource_id', 'ASC')
            ->addOrderBy('value.id', 'ASC')
            // Only one identifier by resource.
            ->setMaxResults(1);
        $resource = $this->connection->executeQuery($qb, $qb->getParameters())->fetchAssociative();

        if (empty($resource)) {
            return null;
        }

        $resourceType = $this->resourceType($resource['resource_type']);
        return $resourceType
            ? $this->api->searchOne($resourceType, ['id' => $resource['resource_id']])->getContent()
            : null;
    }

    /**
     * Return the qualifier part of an ark.
     */
    protected function getQualifier(AbstractResourceEntityRepresentation $resource): ?string
    {
        $result = $this->getQualifierPlugin()->create($resource);
        return is_null($result) ? null : (string) $result;
    }

    /**
     * Get the resource class from the resource type.
     *
     * @return string|null|bool Null if any resources. False if not managed.
     */
    protected function resourceClass(?string $resourceType)
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
        return $resourceTypes[$resourceType] ?? false;
    }

    /**
     * Get the resource type from the resource class.
     *
     * @return string|null|bool Null if any resources. False if not managed.
     */
    protected function resourceType(?string $resourceClass)
    {
        $resourceClasses = [
            null => null,
            \Omeka\Entity\Item::class => 'items',
            \Omeka\Entity\ItemSet::class => 'item_sets',
            \Omeka\Entity\Media::class => 'media',
        ];
        return $resourceClasses[$resourceClass] ?? false;
    }

    public function getArkNamePlugin(): \Ark\Name\Plugin\PluginInterface
    {
        return $this->namePlugins->get($this->namePluginName);
    }

    public function getQualifierPlugin(): \Ark\Qualifier\Plugin\PluginInterface
    {
        return $this->qualifierPlugins->get($this->qualifierPluginName);
    }
}
