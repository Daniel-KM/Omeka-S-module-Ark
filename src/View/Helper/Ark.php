<?php

namespace Ark\View\Helper;

use Ark\ArkManager;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
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

    public function getAbsoluteUrl($resource = null)
    {
        $resource = $resource ?: $this->resource;

        if (!$resource instanceof AbstractResourceEntityRepresentation) {
            return '';
        }

        $view = $this->getView();
        $ark = $this->arkManager->getArk($resource);

        if (!$ark) {
            return null;
        }

        $isAdmin = $view->params()->fromRoute('__ADMIN__');
        $route = $isAdmin ? 'admin/ark/default' : 'site/ark/default';
        return $view->serverUrl() . $view->url($route, [
            'naan' => $ark->getNaan(),
            'name' => $ark->getName(),
            'qualifier' => $ark->getQualifier(),
        ], true);
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
}
