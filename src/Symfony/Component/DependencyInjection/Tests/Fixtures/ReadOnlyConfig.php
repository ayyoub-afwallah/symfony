<?php

namespace Symfony\Component\DependencyInjection\Tests\Fixtures;

use Symfony\Component\DependencyInjection\Attribute\MapConfig;

#[MapConfig(entry: 'app.readonly_config')]
class ReadOnlyConfig
{
    public readonly string $readOnlyProp;

    public private(set) string $privateSetProp;

    public function __construct()
    {
    }
}
