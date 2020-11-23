<?php

namespace Ark\Mvc\Controller\Plugin;

use Ark\ArkManager;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;

class Ark extends AbstractPlugin
{
    /**
     * @var ArkManager
     */
    protected $arkManager;

    public function __construct(ArkManager $arkManager)
    {
        $this->arkManager = $arkManager;
    }

    /**
     * @return \Ark\Mvc\Controller\Plugin\Ark
     */
    public function __invoke()
    {
        return $this;
    }

    /**
     * Find a resource by its ark.
     *
     * @param string|array $ark
     * @return \Omeka\Api\Representation\AbstractResourceEntityRepresentation|null
     */
    public function find($ark)
    {
        if (is_array($ark)) {
            $ark = $this->buildArkFromArgs($ark);
        }
        return $this->arkManager->find($ark);
    }

    /**
     * Convert an ark from an associative array into a string.
     *
     * @param array $args
     * @return string
     */
    protected function buildArkFromArgs(array $args)
    {
        $naan = $args['naan'];
        $name = $args['name'];
        $qualifier = $args['qualifier'] ? '/' . $args['qualifier'] : '';
        return sprintf('ark:/%s/%s%s', $naan, $name, $qualifier);
    }
}
