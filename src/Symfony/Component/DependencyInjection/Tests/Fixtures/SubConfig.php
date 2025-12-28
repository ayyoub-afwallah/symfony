<?php

namespace Symfony\Component\DependencyInjection\Tests\Fixtures;

class SubConfig
{
    public function __construct(
        public readonly string $subValue,
        public readonly int $count = 0,
    ) {}
}
