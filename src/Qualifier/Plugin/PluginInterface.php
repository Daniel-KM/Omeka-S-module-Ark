<?php

namespace Ark\Qualifier\Plugin;

interface PluginInterface
{
    public function create($resource);
    public function getResourceFromQualifier($resource, $qualifier);
}
