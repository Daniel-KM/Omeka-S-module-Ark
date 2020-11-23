<?php

namespace Ark\View\Helper;

use Ark\Ark as ArkArk;
use Ark\ArkManager;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Traversable;
use Laminas\View\Helper\AbstractHelper;

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
    public function __invoke(AbstractResourceEntityRepresentation $resource)
    {
        $this->resource = $resource;
        return $this;
    }

    /**
     * Get the ark for a resource.
     *
     * @return Ark|null
     */
    public function identifier()
    {
        return $this->arkManager
            ->getArk($this->resource);
    }

    /**
     * Return the ark url to a resource.
     *
     * @param array|Traversable $options Url options. Params are reused.
     * @param bool|null $admin If null, determined from the params.
     * @return string|null
     */
    public function url(array $options = [], $admin = null)
    {
        $ark = $this->identifier($this->resource);
        return empty($ark)
            ? ''
            : $this->urlFromArk($ark, $options, $admin);
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
            ? $view->status()->isAdminRequest()
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
