<?php

namespace Symfony\Component\DependencyInjection\Tests\Fixtures;

use Symfony\Component\DependencyInjection\Attribute\MapConfig;

#[MapConfig(entry: 'app.circular_a')]
class CircularConfigA
{
    public function __construct(
        public readonly CircularConfigB $b
    ) {}
}
