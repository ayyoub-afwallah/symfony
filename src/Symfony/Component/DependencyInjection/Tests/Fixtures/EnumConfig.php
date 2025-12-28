<?php

namespace Symfony\Component\DependencyInjection\Tests\Fixtures;

use Symfony\Component\DependencyInjection\Attribute\MapConfig;

#[MapConfig(entry: 'enum_config')]
class EnumConfig
{
    public function __construct(
        public readonly ModeEnum $mode,
    ) {}
}
