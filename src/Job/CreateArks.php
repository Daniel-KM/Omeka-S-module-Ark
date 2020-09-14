<?php

namespace Ark\Job;

use Omeka\Entity\Value;
use Omeka\Job\AbstractJob;

class CreateArks extends AbstractJob
{
    public function perform()
    {
        $services = $this->getServiceLocator();
        $apiAdapters = $services->get('Omeka\ApiAdapterManager');
        $settings = $services->get('Omeka\Settings');

        $this->processResources('item_sets');
        $this->processResources('items');
        if ($settings->get('ark_qualifier_static')) {
            $this->processResources('media');
        }
    }

    protected function processResources(string $resourceName)
    {
        $services = $this->getServiceLocator();
        $em = $services->get('Omeka\EntityManager');
        $logger = $services->get('Omeka\Logger');
        $arkManager = $services->get('Ark\ArkManager');
        $apiAdapters = $services->get('Omeka\ApiAdapterManager');

        $adapter = $apiAdapters->get($resourceName);
        $entityClass = $adapter->getEntityClass();

        $identifierProperty = $this->getDctermsIdentifierProperty();

        $query = $em->createQuery("SELECT r FROM $entityClass r WHERE NOT EXISTS (SELECT v.value FROM Omeka\Entity\Value v WHERE v.resource = r.id AND v.property = :property AND v.value LIKE 'ark:%') ORDER BY r.id");
        $query->setParameter('property', $identifierProperty->getId());
        $query->setMaxResults(100);
        while (!empty($resources = $query->getResult())) {
            if ($this->shouldStop()) {
                return;
            }

            $identifierProperty = $this->getDctermsIdentifierProperty();

            foreach ($resources as $resource) {
                $representation = $adapter->getRepresentation($resource);
                $ark = $arkManager->createName($representation);
                if (!$ark) {
                    $logger->err(sprintf('Failed to create ARK identifier for resource %d (%s)', $resource->getId(), $adapter->getResourceName()));
                    continue;
                }

                $value = new Value();
                $value->setResource($resource);
                $value->setProperty($identifierProperty);
                $value->setType('literal');
                $value->setIsPublic(true);
                $value->setValue($ark);

                $em->persist($value);
                $logger->info(sprintf('Created ARK identifier (%s) for resource %d (%s)', $ark, $resource->getId(), $adapter->getResourceName()));
            }

            $em->flush();
            $em->clear();
            $this->job = $em->merge($this->job);
        }
    }

    protected function getDctermsIdentifierProperty()
    {
        $services = $this->getServiceLocator();
        $em = $services->get('Omeka\EntityManager');

        $vocabularyRepository = $em->getRepository('Omeka\Entity\Vocabulary');
        $propertyRepository = $em->getRepository('Omeka\Entity\Property');

        $dctermsVocabulary = $vocabularyRepository->findOneBy(['prefix' => 'dcterms']);
        $identifierProperty = $propertyRepository->findOneBy([
            'localName' => 'identifier',
            'vocabulary' => $dctermsVocabulary->getId(),
        ]);

        return $identifierProperty;
    }
}
