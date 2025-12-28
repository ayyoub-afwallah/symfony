<?php

namespace Symfony\Component\DependencyInjection\Tests\Fixtures;

use Symfony\Component\DependencyInjection\Attribute\MapConfig;

#[MapConfig(entry: 'kebab_config')]
class KebabConfig
{
    public function __construct(
        public string $appKey,
        public string $appSecret,
    ) {
    }
}
