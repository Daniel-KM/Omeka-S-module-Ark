<?php declare(strict_types=1);

namespace Ark\Service;

use Ark\Qualifier\PluginManager;
use Laminas\Mvc\Service\AbstractPluginManagerFactory;

class QualifierPluginManagerFactory extends AbstractPluginManagerFactory
{
    const PLUGIN_MANAGER_CLASS = PluginManager::class;
}
