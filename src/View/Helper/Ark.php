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
                return null;
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
     * @param bool|null $admin If null, determined from the params.
     * @return string|null
     */
    public function url(
        AbstractResourceEntityRepresentation $resource = null,
        array $options = [],
        $admin = null
    ) {
        $ark = $this->identifier($resource);
        if (empty($ark)) {
            return '';
        }
        return $this->urlFromArk($ark, $options, $admin);
    }

    /**
     * Quick return the ark url to a resource id.
     *
     * @param int $resourceId
     * @param string $resourceType "items", "item_sets" or "media" or variants.
     * @param array|Traversable $options Url options. Params are reused.
     * @param bool|null $admin If null, determined from the params.
     * @return string|null
     */
    public function urlFromResourceId(
        $resourceId,
        $resourceType = null,
        array $options = [],
        $admin = null
    ) {
        $ark = $this->identifierFromResourceId($resourceId, $resourceType);
        if (empty($ark)) {
            return null;
        }
        return $this->urlFromArk($ark, $options, $admin);
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
        return $this->url($resource, ['force_canonical' => true], null);
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
     * @param bool|null $admin If null, determined from the params.
     * @return string
     */
    protected function urlFromArk(ArkArk $ark, array $options = [], $admin = null)
    {
        $view = $this->getView();
        $isAdmin = is_null($admin)
            ? $view->params()->fromRoute('__ADMIN__')
            : $admin;
        if ($isAdmin) {
            return $view->url(
                'admin/ark/default',
                [
                    'naan' => $ark->getNaan(),
                    'name' => $ark->getName(),
                    'qualifier' => $ark->getQualifier(),
                ],
                $options,
                true
            );
        }

        $siteSlug = $view->params()->fromRoute('site-slug') ?: $view->defaultSiteSlug();
        if (empty($siteSlug)) {
            return '';
        }
        return $view->url(
            'site/ark/default',
            [
                'naan' => $ark->getNaan(),
                'name' => $ark->getName(),
                'qualifier' => $ark->getQualifier(),
                'site-slug' => $siteSlug,
            ],
            $options,
            true
        );
    }
}
