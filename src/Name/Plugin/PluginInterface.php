<?php declare(strict_types=1);

namespace Ark\Name\Plugin;

use Omeka\Api\Representation\AbstractResourceEntityRepresentation;

interface PluginInterface
{
    public function isFullArk(): bool;

    public function create(AbstractResourceEntityRepresentation $resource): ?string;
}
