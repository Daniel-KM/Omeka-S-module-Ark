<?php

namespace Ark\Name\Plugin;

interface PluginInterface
{
    public function isFullArk();
    public function create($resource);
}
