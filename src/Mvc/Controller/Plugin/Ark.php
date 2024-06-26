<?php declare(strict_types=1);

namespace Ark\Mvc\Controller\Plugin;

use Ark\ArkManager;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;

class Ark extends AbstractPlugin
{
    /**
     * @var \Ark\ArkManager
     */
    protected $arkManager;

    public function __construct(ArkManager $arkManager)
    {
        $this->arkManager = $arkManager;
    }

    /**
     * @return \Ark\Mvc\Controller\Plugin\Ark
     */
    public function __invoke(): self
    {
        return $this;
    }

    /**
     * Find a resource by its ark.
     *
     * @param string|array $ark
     */
    public function find($ark): ?AbstractResourceEntityRepresentation
    {
        if (is_array($ark)) {
            $ark = $this->buildArkFromArgs($ark);
        }
        return $this->arkManager->find($ark);
    }

    /**
     * Convert an ark from an associative array into a string.
     */
    protected function buildArkFromArgs(array $args): string
    {
        $naan = $args['naan'];
        $name = $args['name'];
        $qualifier = $args['qualifier'] ? '/' . $args['qualifier'] : '';
        return sprintf('ark:/%s/%s%s', $naan, $name, $qualifier);
    }
}
