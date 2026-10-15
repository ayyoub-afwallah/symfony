<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\RateLimiter\DependencyInjection;

use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\RateLimiter\CompoundRateLimiterFactory;
use Symfony\Component\RateLimiter\Debug\RateLimiterTrace;
use Symfony\Component\RateLimiter\Debug\TraceableRateLimiterBuilder;
use Symfony\Component\RateLimiter\Debug\TraceableRateLimiterFactory;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/** @internal */
final class RateLimiterTracePass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('data_collector.rate_limiter')) {
            return;
        }

        if ($container->hasDefinition('limiter_builder')) {
            $container->register('debug.limiter_builder', TraceableRateLimiterBuilder::class)
                ->setDecoratedService('limiter_builder')
                ->setArguments([new Reference('debug.limiter_builder.inner'), new Reference('rate_limiter.trace')]);
        }

        foreach ($container->findTaggedServiceIds('rate_limiter') as $id => $tags) {
            $definition = $container->getDefinition($id);
            if ($definition->isAbstract()) {
                continue;
            }

            $config = $this->getConfig($definition);
            $container->register('debug.'.$id, TraceableRateLimiterFactory::class)
                ->setDecoratedService($id)
                ->setArguments([new Reference('debug.'.$id.'.inner'), $tags[0]['name'] ?? $id, new Reference('rate_limiter.trace'), $config['policy'] ?? null, RateLimiterTrace::describeConfig($config)]);
        }
    }

    private function getConfig(Definition $definition): array
    {
        if (CompoundRateLimiterFactory::class === $definition->getClass()) {
            return ['policy' => 'compound'];
        }

        if (RateLimiterFactory::class !== $definition->getClass() && !($definition instanceof ChildDefinition && 'limiter' === $definition->getParent())) {
            return [];
        }

        try {
            $config = $definition->getArgument(0);
        } catch (\OutOfBoundsException) {
            return [];
        }

        return \is_array($config) ? $config : [];
    }
}
