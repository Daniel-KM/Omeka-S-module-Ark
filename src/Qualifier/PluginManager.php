<?php

namespace Ark\Qualifier;

use Zend\ServiceManager\AbstractPluginManager;

class PluginManager extends AbstractPluginManager
{
    protected $instanceOf = Plugin\PluginInterface::class;
}
