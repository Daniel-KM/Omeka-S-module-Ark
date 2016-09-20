<?php

namespace Ark\Mvc\Controller\Plugin;

use Zend\Mvc\Controller\Plugin\AbstractPlugin;
use Ark\ArkManager;

class Ark extends AbstractPlugin
{
    protected $arkManager;

    public function __construct(ArkManager $arkManager)
    {
        $this->arkManager = $arkManager;
    }

    public function __invoke()
    {
        return $this;
    }

    public function find($ark)
    {
        if (is_array($ark)) {
            $ark = $this->buildArkFromArgs($ark);
        }
        return $this->arkManager->find($ark);
    }

    protected function buildArkFromArgs(array $args)
    {
        $naan = $args['naan'];
        $name = $args['name'];
        $qualifier = $args['qualifier'] ? '/' . $args['qualifier'] : '';

        return sprintf('ark:/%s/%s%s', $naan, $name, $qualifier);
    }
}
