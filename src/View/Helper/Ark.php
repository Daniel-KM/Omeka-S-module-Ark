<?php

namespace Ark\View\Helper;

use Zend\View\Helper\AbstractHelper;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Ark\ArkManager;

/**
 * Helper to get or create ark.
 */
class Ark extends AbstractHelper
{
    /**
     * @var ArkManager
     */
    protected $arkManager;

    protected $resource;

    public function __construct(ArkManager $arkManager)
    {
        $this->arkManager = $arkManager;
    }

    /**
     * Return the ark of a record.
     *
     * @param AbstractResourceEntityRepresentation $resource
     *
     * @return $this
     */
    public function __invoke($resource = null)
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

        return $view->serverUrl() . $view->url('site/ark/default', [
            'naan' => $ark->getNaan(),
            'name' => $ark->getName(),
            'qualifier' => $ark->getQualifier(),
        ], true);
    }

    public function isNoidDatabaseCreated()
    {
        return $this->arkManager->getArkNamePlugin()->isDatabaseCreated();
    }
}
