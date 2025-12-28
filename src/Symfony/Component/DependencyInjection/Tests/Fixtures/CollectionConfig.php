<?php

namespace Symfony\Component\DependencyInjection\Tests\Fixtures;

use Symfony\Component\DependencyInjection\Attribute\MapConfig;
use Symfony\Component\DependencyInjection\Tests\Fixtures\SubConfig;

#[MapConfig(entry: 'collection_config')]
class CollectionConfig
{
    /**
     * @param SubConfig[] $items
     */
    public function __construct(
        public array $items = [],
    ) {
    }
}
