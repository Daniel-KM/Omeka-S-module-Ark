<?php

namespace Ark\Qualifier;

use Laminas\ServiceManager\AbstractPluginManager;

class PluginManager extends AbstractPluginManager
{
    protected $instanceOf = Plugin\PluginInterface::class;
}
