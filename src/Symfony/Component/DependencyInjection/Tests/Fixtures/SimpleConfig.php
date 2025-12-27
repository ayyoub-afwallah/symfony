<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\DependencyInjection\Tests\Fixtures;

use Symfony\Component\DependencyInjection\Attribute\MapConfig;

/**
 * Test fixture for MapConfig without validation.
 */
#[MapConfig(entry: 'app')]
class SimpleConfig
{
    public function __construct(
        public readonly string $name,
        public readonly string $environment = 'prod',
        public readonly bool $debug = false,
    ) {
    }
}
