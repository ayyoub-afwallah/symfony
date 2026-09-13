<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpKernel\Controller\ArgumentResolver;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\RateLimiter\AppliedRateLimit;
use Symfony\Component\HttpKernel\RateLimiter\PendingRateLimit;
use Symfony\Component\RateLimiter\RateLimit;

/**
 * Yields the RateLimit that binds the request, out of every #[RateLimit] that applied to it.
 *
 * Arguments are resolved before the limiters run, so resolve() only stages a placeholder and
 * onKernelControllerArguments() swaps in the real value once RateLimitAttributeListener has
 * consumed them. The value stays null when no #[RateLimit] applies to the request (e.g. filtered
 * out by HTTP method), so the argument must be nullable.
 *
 * @author Ayyoub AFW-ALLAH <ayyoub.afwallah@gmail.com>
 */
final class RateLimitValueResolver implements ValueResolverInterface, EventSubscriberInterface
{
    /**
     * Same literal key as RateLimitAttributeListener's private RATE_LIMIT_ATTRIBUTE constant.
     */
    private const RATE_LIMIT_ATTRIBUTE = '_rate_limit';

    public function resolve(Request $request, ArgumentMetadata $argument): array
    {
        if (RateLimit::class !== $argument->getType()) {
            return [];
        }

        if ($argument->isVariadic()) {
            throw new \LogicException(\sprintf('The "$%s" argument of "%s" must not be variadic: a single RateLimit applies to the request.', $argument->getName(), $argument->getControllerName()));
        }

        if (!$argument->isNullable()) {
            throw new \LogicException(\sprintf('The "$%s" argument of "%s" must be nullable: it is null when no #[RateLimit] attribute applies to the request.', $argument->getName(), $argument->getControllerName()));
        }

        return [PendingRateLimit::Placeholder];
    }

    public function onKernelControllerArguments(ControllerArgumentsEvent $event): void
    {
        $arguments = $event->getArguments();
        $substituted = false;

        foreach ($arguments as $i => $argument) {
            if (PendingRateLimit::Placeholder !== $argument) {
                continue;
            }

            $applied = $event->getRequest()->attributes->get(self::RATE_LIMIT_ATTRIBUTE);
            $arguments[$i] = $applied instanceof AppliedRateLimit ? $applied->rateLimit : null;
            $substituted = true;
        }

        if ($substituted) {
            $event->setArguments($arguments);
        }
    }

    public static function getSubscribedEvents(): array
    {
        // Keep this priority lower than ControllerAttributesListener (-10000) so that every
        // #[RateLimit] has been consumed before the binding limiter is read.
        return [
            KernelEvents::CONTROLLER_ARGUMENTS => ['onKernelControllerArguments', -10100],
        ];
    }
}
