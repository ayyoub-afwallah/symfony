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

use Symfony\Component\RateLimiter\RateLimiterBuilderInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/** @internal */
final class TraceableRateLimiterBuilder implements RateLimiterBuilderInterface
{
    public function __construct(
        private readonly RateLimiterBuilderInterface $inner,
        private readonly RateLimiterTrace $trace,
    ) {
    }

    public function slidingWindow(string $id, int $limit, \DateInterval|string $interval): RateLimiterFactoryInterface
    {
        return $this->wrap($this->inner->slidingWindow($id, $limit, $interval), $id, ['policy' => 'sliding_window', 'limit' => $limit, 'interval' => $interval]);
    }

    public function fixedWindow(string $id, int $limit, \DateInterval|string $interval, \DateTimeInterface|string|null $anchorAt = null): RateLimiterFactoryInterface
    {
        return $this->wrap($this->inner->fixedWindow($id, $limit, $interval, $anchorAt), $id, ['policy' => 'fixed_window', 'limit' => $limit, 'interval' => $interval]);
    }

    public function tokenBucket(string $id, int $limit, \DateInterval|string $interval, int $amount = 1): RateLimiterFactoryInterface
    {
        return $this->wrap($this->inner->tokenBucket($id, $limit, $interval, $amount), $id, ['policy' => 'token_bucket', 'limit' => $limit, 'rate' => ['interval' => $interval, 'amount' => $amount]]);
    }

    public function compound(RateLimiterFactoryInterface ...$factories): RateLimiterFactoryInterface
    {
        return $this->wrap($this->inner->compound(...$factories), 'compound', ['policy' => 'compound']);
    }

    public function noop(): RateLimiterFactoryInterface
    {
        return $this->wrap($this->inner->noop(), 'noop', ['policy' => 'no_limit']);
    }

    private function wrap(RateLimiterFactoryInterface $factory, string $name, array $config): RateLimiterFactoryInterface
    {
        return $this->trace->wrapFactory($factory, $name, $config['policy'], RateLimiterTrace::describeConfig($config));
    }
}
