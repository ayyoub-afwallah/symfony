<?php

namespace Symfony\Component\DependencyInjection\Tests\Fixtures;

use Symfony\Component\DependencyInjection\Attribute\MapConfig;

#[MapConfig(entry: 'app.strict_config')]
class StrictConfig
{
    public function __construct(
        public readonly string $region,
        public readonly string $bucket,
    ) {}
}
