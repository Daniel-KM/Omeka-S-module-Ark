<?php

namespace Ark\Service;

use Zend\Mvc\Service\AbstractPluginManagerFactory;
use Ark\Qualifier\PluginManager;

class QualifierPluginManagerFactory extends AbstractPluginManagerFactory
{
    const PLUGIN_MANAGER_CLASS = PluginManager::class;
}
