<?php declare(strict_types=1);

namespace Ark\Qualifier;

use Laminas\ServiceManager\AbstractPluginManager;

class PluginManager extends AbstractPluginManager
{
    protected $instanceOf = Plugin\PluginInterface::class;
}
