<?php declare(strict_types=1);

namespace Ark\Name;

use Laminas\ServiceManager\AbstractPluginManager;

class PluginManager extends AbstractPluginManager
{
    protected $instanceOf = Plugin\PluginInterface::class;
}
