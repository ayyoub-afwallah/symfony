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

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\Reservation;

/** @internal */
final class RateLimiterTrace
{
    private \WeakMap $operations;
    private \WeakMap $parents;
    private \WeakMap $keyLabels;
    private int $nextId = 0;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly ?\Closure $disabled = null,
    ) {
        $this->operations = new \WeakMap();
        $this->parents = new \WeakMap();
        $this->keyLabels = new \WeakMap();
    }

    /**
     * Summarizes a limiter configuration, e.g. "3 per 1 minute" or "burst 10, 2 per 1 second".
     */
    public static function describeConfig(array $config): ?string
    {
        $interval = static fn (\DateInterval|string $interval) => $interval instanceof \DateInterval
            ? (new \DateTimeImmutable('@0'))->add($interval)->getTimestamp().' seconds'
            : $interval;

        return match ($config['policy'] ?? null) {
            'fixed_window', 'sliding_window' => isset($config['limit'], $config['interval']) ? $config['limit'].' per '.$interval($config['interval']) : null,
            'token_bucket' => isset($config['limit'], $config['rate']['interval']) ? \sprintf('burst %s, %s per %s', $config['limit'], $config['rate']['amount'] ?? 1, $interval($config['rate']['interval'])) : null,
            default => null,
        };
    }

    public function wrapFactory(RateLimiterFactoryInterface $factory, string $name, ?string $policy = null, ?string $settings = null): RateLimiterFactoryInterface
    {
        return $factory instanceof TraceableRateLimiterFactory ? $factory : new TraceableRateLimiterFactory($factory, $name, $this, $policy, $settings);
    }

    public function wrapLimiter(LimiterInterface $limiter, string $name, ?string $key = null, ?string $policy = null, ?string $settings = null): LimiterInterface
    {
        return $limiter instanceof TraceableLimiter ? $limiter : new TraceableLimiter($limiter, $name, $this, $key, $policy, $settings);
    }

    public function record(string $name, string $operation, int $tokens, \Closure $call, ?string $key = null, ?string $policy = null, ?string $settings = null): mixed
    {
        if (!($request = $this->requestStack->getCurrentRequest()) || $this->disabled?->__invoke()) {
            return $call();
        }

        $id = ++$this->nextId;
        $keyLabels = $this->keyLabels[$request] ?? [];
        $keyIdentity = null === $key ? "\0" : "\1".$key;
        $keyLabel = $keyLabels[$keyIdentity] ??= '#'.(\count($keyLabels) + 1);
        $this->keyLabels[$request] = $keyLabels;
        $parents = $this->parents[$request] ?? [];
        $parent = end($parents) ?: null;
        $parents[] = $id;
        $this->parents[$request] = $parents;
        $start = microtime(true);
        $result = null;
        $failure = null;

        try {
            return $result = $call();
        } catch (\Throwable $e) {
            $failure = $e::class;
            throw $e;
        } finally {
            $parents = $this->parents[$request];
            array_pop($parents);
            $this->parents[$request] = $parents;
            $limit = $result instanceof Reservation ? $result->getRateLimit() : ($result instanceof RateLimit ? $result : null);
            $operations = $this->operations[$request] ?? [];
            $operations[] = [
                'id' => $id,
                'parent' => $parent,
                'limiter' => $name,
                'policy' => $policy,
                'settings' => $settings,
                'key' => $keyLabel,
                'operation' => $operation,
                'tokens' => $tokens,
                'time' => $start,
                'duration' => microtime(true) - $start,
                'accepted' => $limit?->isAccepted(),
                'remaining' => $limit?->getRemainingTokens(),
                'limit' => $limit?->getLimit(),
                'unlimited' => $limit && \PHP_INT_MAX === $limit->getLimit() && null === $limit->getResetAt(),
                'resetAt' => $limit?->getResetAt()?->getTimestamp(),
                'retryAfter' => $limit?->getRetryAfter()->getTimestamp(),
                'timeToAct' => $result instanceof Reservation ? $result->getTimeToAct() : null,
                'exception' => $failure,
            ];
            $this->operations[$request] = $operations;
        }
    }

    public function getOperations(Request $request): array
    {
        $operations = $this->operations[$request] ?? [];
        usort($operations, static fn (array $a, array $b) => $a['id'] <=> $b['id']);
        $depths = [];
        foreach ($operations as &$operation) {
            $operation['depth'] = null === $operation['parent'] ? 0 : ($depths[$operation['parent']] ?? 0) + 1;
            $depths[$operation['id']] = $operation['depth'];
        }

        return $operations;
    }

    public function reset(): void
    {
        $this->operations = new \WeakMap();
        $this->parents = new \WeakMap();
        $this->keyLabels = new \WeakMap();
        $this->nextId = 0;
    }
}
