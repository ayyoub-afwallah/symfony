<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\RateLimiter\Debug;

use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/** @internal */
final class TraceableRateLimiterFactory implements RateLimiterFactoryInterface
{
    public function __construct(
        private readonly RateLimiterFactoryInterface $inner,
        private readonly string $name,
        private readonly RateLimiterTrace $trace,
        private readonly ?string $policy = null,
        private readonly ?string $settings = null,
    ) {
    }

    public function create(?string $key = null): LimiterInterface
    {
        $limiter = $this->inner->create($key);

        return $this->trace->wrapLimiter($limiter, $this->name, $key, $this->policy, $this->settings);
    }
}
