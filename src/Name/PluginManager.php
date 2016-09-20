<?php

namespace Ark\Name;

use Zend\ServiceManager\AbstractPluginManager;

class PluginManager extends AbstractPluginManager
{
    protected $instanceOf = Plugin\PluginInterface::class;
}
