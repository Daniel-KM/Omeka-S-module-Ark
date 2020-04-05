<?php

namespace Ark\Service;

use Ark\Name\PluginManager;
use Zend\Mvc\Service\AbstractPluginManagerFactory;

class NamePluginManagerFactory extends AbstractPluginManagerFactory
{
    const PLUGIN_MANAGER_CLASS = PluginManager::class;
}
