<?php

namespace Symfony\Component\DependencyInjection\Tests\Fixtures;

class CircularConfigB
{
    public function __construct(
        public readonly CircularConfigA $a
    ) {}
}
