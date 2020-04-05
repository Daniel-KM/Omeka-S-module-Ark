<?php

namespace Ark\Service;

use Ark\Qualifier\PluginManager;
use Zend\Mvc\Service\AbstractPluginManagerFactory;

class QualifierPluginManagerFactory extends AbstractPluginManagerFactory
{
    const PLUGIN_MANAGER_CLASS = PluginManager::class;
}
