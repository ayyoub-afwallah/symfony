<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\RateLimiter\DataCollector;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;
use Symfony\Component\RateLimiter\Debug\RateLimiterTrace;

/** @internal */
final class RateLimiterDataCollector extends DataCollector
{
    public function __construct(private readonly RateLimiterTrace $trace)
    {
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        $this->data['operations'] = $this->trace->getOperations($request);
    }

    public function getName(): string
    {
        return 'rate_limiter';
    }

    public function getOperations(): array
    {
        return $this->data['operations'] ?? [];
    }

    public function getTopLevelCount(): int
    {
        return \count($this->getTopLevelOperations());
    }

    public function getRejectedCount(): int
    {
        return \count(array_filter($this->getTopLevelOperations(), static fn (array $operation) => 'reserve' !== $operation['operation'] && false === $operation['accepted']));
    }

    public function getErrorCount(): int
    {
        return \count(array_filter($this->getTopLevelOperations(), static fn (array $operation) => null !== $operation['exception']));
    }

    public function getTotalDuration(): float
    {
        return array_sum(array_column($this->getTopLevelOperations(), 'duration'));
    }

    /**
     * @return array<string, array{name: string, policy: ?string, settings: ?string, calls: int, rejected: int, errors: int, children: list<string>, keys: array<string, list<array>>}>
     */
    public function getLimiters(): array
    {
        $operations = array_column($this->getOperations(), null, 'id');
        $limiters = [];

        foreach ($operations as $operation) {
            $children = array_values(array_filter($operations, static fn (array $child) => $operation['id'] === $child['parent']));
            $operation['via'] = null !== $operation['parent'] ? $operations[$operation['parent']]['limiter'] ?? null : null;
            $operation['decidedBy'] = $children ? $this->findDecidingChild($operation, $children) : null;

            $limiter = &$limiters[$operation['limiter']];
            $limiter ??= ['name' => $operation['limiter'], 'policy' => $operation['policy'], 'settings' => $operation['settings'] ?? null, 'calls' => 0, 'rejected' => 0, 'errors' => 0, 'children' => [], 'keys' => []];
            ++$limiter['calls'];
            $limiter['rejected'] += (int) ('reserve' !== $operation['operation'] && false === $operation['accepted']);
            $limiter['errors'] += (int) (null !== $operation['exception']);
            $limiter['children'] = array_values(array_unique([...$limiter['children'], ...array_column($children, 'limiter')]));
            $limiter['keys'][$operation['key']][] = $operation;
            unset($limiter);
        }

        return $limiters;
    }

    private function findDecidingChild(array $operation, array $children): ?string
    {
        foreach ($children as $child) {
            if (null !== $operation['exception'] ? null !== $child['exception'] : false === $operation['accepted'] && false === $child['accepted']) {
                return $child['limiter'];
            }
        }

        if (true !== $operation['accepted']) {
            return null;
        }

        usort($children, static fn (array $a, array $b) => ($a['remaining'] ?? \PHP_INT_MAX) <=> ($b['remaining'] ?? \PHP_INT_MAX));

        return $children[0]['limiter'];
    }

    private function getTopLevelOperations(): array
    {
        return array_filter($this->getOperations(), static fn (array $operation) => null === $operation['parent']);
    }

    public function reset(): void
    {
        $this->data = [];
        if (isset($this->trace)) {
            $this->trace->reset();
        }
    }
}
