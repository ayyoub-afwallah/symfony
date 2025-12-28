<?php

namespace Symfony\Component\DependencyInjection\Tests\Fixtures;

use Symfony\Component\DependencyInjection\Attribute\MapConfig;

#[MapConfig(entry: 'nested_config')]
class NestedConfig
{
    public function __construct(
        public readonly string $name,
        public readonly SubConfig $sub,
    ) {}
}
