<?php

namespace Ark\View\Helper;

use Ark\Ark as ArkArk;
use Ark\ArkManager;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Traversable;
use Zend\View\Helper\AbstractHelper;

/**
 * Helper to get or create ark.
 */
class Ark extends AbstractHelper
{
    /**
     * @var ArkManager
     */
    protected $arkManager;

    /**
     * @var AbstractResourceEntityRepresentation
     */
    protected $resource;

    public function __construct(ArkManager $arkManager)
    {
        $this->arkManager = $arkManager;
    }

    /**
     * Return this helper for ark.
     *
     * @param AbstractResourceEntityRepresentation $resource
     * @return self
     */
    public function __invoke(AbstractResourceEntityRepresentation $resource = null)
    {
        $this->resource = $resource;
        return $this;
    }

    /**
     * Get the ark for a resource.
     *
     * @param AbstractResourceEntityRepresentation $resource
     * @return Ark|null
     */
    public function identifier(AbstractResourceEntityRepresentation $resource = null)
    {
        if (empty($resource)) {
            if (empty($this->resource)) {
                return;
            }
            $resource = $this->resource;
        }
        return $this->arkManager
            ->getArk($resource);
    }

    /**
     * Get the ark for a resource id.
     *
     * @param int $resourceId
     * @param string $resourceType "items", "item_sets" or "media" or variants.
     * @return Ark|null
     */
    public function identifierFromResourceId($resourceId, $resourceType = null)
    {
        return $this->arkManager
            ->getArkFromResourceId($resourceId, $resourceType);
    }

    /**
     * Return the ark url to a resource.
     *
     * @param AbstractResourceEntityRepresentation $resource
     * @param array|Traversable $options Url options. Params are reused.
     * @return string|null
     */
    public function url(AbstractResourceEntityRepresentation $resource = null, array $options = [])
    {
        $ark = $this->identifier($resource);
        if (empty($ark)) {
            return;
        }
        return $this->urlFromArk($ark, $options);
    }

    /**
     * Quick return the ark url to a resource id.
     *
     * @param int $resourceId
     * @param string $resourceType "items", "item_sets" or "media" or variants.
     * @param array|Traversable $options Url options. Params are reused.
     * @return string|null
     */
    public function urlFromResourceId($resourceId, $resourceType = null, array $options = [])
    {
        $ark = $this->identifierFromResourceId($resourceId, $resourceType);
        if (empty($ark)) {
            return;
        }
        return $this->urlFromArk($ark, $options);
    }

    /**
     * Get the absolute ark url of a resource.
     *
     * @deprecated since 0.1.3: uses url() instead.
     * @param AbstractResourceEntityRepresentation $resource
     * @return string|null
     */
    public function getAbsoluteUrl(AbstractResourceEntityRepresentation $resource = null)
    {
        return $this->url($resource, ['force_canonical' => true]);
    }

    /**
     * Check if the ark database is ready.
     *
     * @return bool
     */
    public function isNoidDatabaseCreated()
    {
        return $this->arkManager->getArkNamePlugin()->isDatabaseCreated();
    }

    /**
     * Get the url for an ark.
     *
     * @param ArkArk $ark
     * @param array|Traversable $options Url options. Params are reused.
     * @return string
     */
    protected function urlFromArk(ArkArk $ark, array $options = [])
    {
        $view = $this->getView();
        $isAdmin = $view->params()->fromRoute('__ADMIN__');
        $route = $isAdmin ? 'admin/ark/default' : 'site/ark/default';
        return $view->url(
            $route,
            [
                'naan' => $ark->getNaan(),
                'name' => $ark->getName(),
                'qualifier' => $ark->getQualifier(),
            ],
            $options,
            true
        );
    }
}
