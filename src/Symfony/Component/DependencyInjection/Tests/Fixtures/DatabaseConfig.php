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
 * Test fixture for MapConfig with validation constraints.
 */
#[MapConfig(entry: 'database')]
class DatabaseConfig
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(min: 3, max: 255)]
        public readonly string $host,

        #[Assert\NotBlank]
        public readonly string $name,

        #[Assert\Range(min: 1, max: 65535)]
        public readonly int $port = 3306,

        public readonly ?string $username = null,
        public readonly ?string $password = null,
    ) {
    }
}
