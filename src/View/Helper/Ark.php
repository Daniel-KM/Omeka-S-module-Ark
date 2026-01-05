<?php declare(strict_types=1);

namespace Ark\View\Helper;

use Ark\Ark as ArkArk;
use Ark\ArkManager;
use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Traversable;

/**
 * Helper to get or create ark.
 */
class Ark extends AbstractHelper
{
    /**
     * @var \Ark\ArkManager
     */
    protected $arkManager;

    /**
     * @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation
     */
    protected $resource;

    public function __construct(ArkManager $arkManager)
    {
        $this->arkManager = $arkManager;
    }

    /**
     * Return this helper for ark.
     */
    public function __invoke(AbstractResourceEntityRepresentation $resource): self
    {
        $this->resource = $resource;
        return $this;
    }

    /**
     * Get the ark for a resource.
     */
    public function identifier(): ?ArkArk
    {
        return $this->arkManager
            ->getArk($this->resource);
    }

    /**
     * Return the ark url to a resource.
     *
     * @param array|Traversable $options Url options. Params are reused.
     * @param bool|null $admin If null, determined from the params.
     */
    public function url(array $options = [], ?bool $admin = null): ?string
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
     */
    protected function urlFromArk(ArkArk $ark, array $options = [], $admin = null): string
    {
        $view = $this->getView();
        $isAdmin = $admin === null
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

        $siteSlug = $view->params()->fromRoute('site-slug') ?: $view->defaultSite('slug');
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
