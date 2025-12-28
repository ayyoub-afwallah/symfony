<?php

namespace Symfony\Component\DependencyInjection\Tests\Fixtures;

use Symfony\Component\DependencyInjection\Attribute\MapConfig;

#[MapConfig(entry: 'datetime_config')]
class DateTimeConfig
{
    public function __construct(
        public \DateTimeImmutable $date,
        public \DateTime $legacyDate,
    ) {
    }
}
