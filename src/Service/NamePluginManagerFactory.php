<?php declare(strict_types=1);

namespace Ark\Service;

use Ark\Name\PluginManager;
use Laminas\Mvc\Service\AbstractPluginManagerFactory;

class NamePluginManagerFactory extends AbstractPluginManagerFactory
{
    const PLUGIN_MANAGER_CLASS = PluginManager::class;
}
