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
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Test fixture for MapConfig with invalid default values (for testing validation failures).
 */
#[MapConfig(entry: 'invalid')]
class InvalidConfig
{
    public function __construct(
        #[Assert\Email]
        public readonly string $email,

        #[Assert\Range(min: 1, max: 100)]
        public readonly int $percentage,
    ) {
    }
}
