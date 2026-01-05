<?php declare(strict_types=1);

namespace Ark\Job;

use Omeka\Entity\Property;
use Omeka\Entity\Value;
use Omeka\Job\AbstractJob;

class CreateArks extends AbstractJob
{
    public function perform(): void
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $arkManager = $services->get('Ark\ArkManager');

        // Enable persistent connection for batch processing.
        $plugin = $arkManager->getArkNamePlugin();
        $hasPersistence = method_exists($plugin, 'enablePersistence');
        if ($hasPersistence) {
            $plugin->enablePersistence();
        }

        try {
            $this->processResources('item_sets');
            $this->processResources('items');
            if ($settings->get('ark_qualifier_static')) {
                $this->processResources('media');
            }
        } finally {
            // Ensure connection is closed even if an error occurs.
            if ($hasPersistence) {
                $plugin->disablePersistence();
            }
        }
    }

    protected function processResources(string $resourceName): void
    {
        /**
         * @var \Laminas\Log\Logger $logger
         * @var \Omeka\Api\Adapter\Manager $apiAdapters
         * @var \Doctrine\ORM\EntityManager $entityManager
         * @var \Ark\ArkManager $arkManager
         */
        $services = $this->getServiceLocator();
        $logger = $services->get('Omeka\Logger');
        $apiAdapters = $services->get('Omeka\ApiAdapterManager');
        $arkManager = $services->get('Ark\ArkManager');
        $entityManager = $services->get('Omeka\EntityManager');

        $adapter = $apiAdapters->get($resourceName);
        $entityClass = $adapter->getEntityClass();

        $identifierProperty = $this->getDctermsIdentifierProperty();

        $dql = <<<DQL
SELECT r
FROM $entityClass r
WHERE NOT EXISTS (
    SELECT v.value
    FROM Omeka\Entity\Value v
    WHERE v.resource = r.id
        AND v.property = :property
        AND v.value LIKE 'ark:%'
    )
ORDER BY r.id
DQL;
        $query = $entityManager->createQuery($dql);
        $query->setParameter('property', $identifierProperty->getId());
        $query->setMaxResults(100);
        while (!empty($resources = $query->getResult())) {
            if ($this->shouldStop()) {
                return;
            }

            // Get it one time by loop to avoid issues with doctrine.
            $identifierProperty = $this->getDctermsIdentifierProperty();

            // Build representations for batch processing.
            $representations = [];
            $resourceMap = [];
            foreach ($resources as $resource) {
                $representation = $adapter->getRepresentation($resource);
                $representations[] = $representation;
                $resourceMap[$resource->getId()] = $resource;
            }

            // Create arks in batch for better performance.
            $arks = $arkManager->createNames($representations);

            foreach ($arks as $resourceId => $ark) {
                if (!$ark) {
                    $logger->err(
                        '{resource} #{resource_id}: Failed to create ARK identifier.', // @translate
                        ['resource' => $adapter->getResourceName(), 'resource_id' => $resourceId]
                    );
                    continue;
                }

                $resource = $resourceMap[$resourceId];
                $value = new Value();
                $value->setResource($resource);
                $value->setProperty($identifierProperty);
                $value->setType('literal');
                $value->setIsPublic(true);
                $value->setValue($ark);

                $entityManager->persist($value);
                $logger->info(
                    '{resource} #{resource_id}: Created ARK identifier {identifier}.', // @translate
                    ['resource' => $adapter->getResourceName(), 'resource_id' => $resourceId, 'identifier' => $ark]
                );
            }

            $entityManager->flush();
            $entityManager->clear();

            // Refresh the job to avoid issue after clear.
            $this->job = $entityManager->find(\Omeka\Entity\Job::class, $this->job->getId());
        }
    }

    protected function getDctermsIdentifierProperty(): ?Property
    {
        $services = $this->getServiceLocator();
        $entityManager = $services->get('Omeka\EntityManager');

        $vocabularyRepository = $entityManager->getRepository('Omeka\Entity\Vocabulary');
        $propertyRepository = $entityManager->getRepository('Omeka\Entity\Property');

        $dctermsVocabulary = $vocabularyRepository->findOneBy(['prefix' => 'dcterms']);
        return $propertyRepository->findOneBy([
            'localName' => 'identifier',
            'vocabulary' => $dctermsVocabulary->getId(),
        ]);
    }
}
