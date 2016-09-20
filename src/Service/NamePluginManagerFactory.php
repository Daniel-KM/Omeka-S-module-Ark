<?php

namespace Ark\Service;

use Zend\Mvc\Service\AbstractPluginManagerFactory;
use Ark\Name\PluginManager;

class NamePluginManagerFactory extends AbstractPluginManagerFactory
{
    const PLUGIN_MANAGER_CLASS = PluginManager::class;
}
