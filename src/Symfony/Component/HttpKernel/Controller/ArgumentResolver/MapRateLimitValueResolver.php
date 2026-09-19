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
use Symfony\Component\HttpKernel\Attribute\MapRateLimit;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\EventListener\RateLimitAttributeListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\RateLimiter\AppliedRateLimit;
use Symfony\Component\RateLimiter\RateLimit;

/**
 * Resolves RateLimit controller arguments, optionally filtered by #[MapRateLimit].
 *
 * @author Ayyoub AFW-ALLAH <ayyoub.afwallah@gmail.com>
 */
final class MapRateLimitValueResolver implements ValueResolverInterface, EventSubscriberInterface
{
    public function resolve(Request $request, ArgumentMetadata $argument): array
    {
        $attribute = $argument->getAttributesOfType(MapRateLimit::class, ArgumentMetadata::IS_INSTANCEOF)[0] ?? null;

        if (RateLimit::class !== $argument->getType()) {
            if (!$attribute) {
                return [];
            }

            throw new \LogicException(\sprintf('The "$%s" argument of "%s" must be typed as "%s".', $argument->getName(), $argument->getControllerName(), RateLimit::class));
        }

        if ($argument->isVariadic()) {
            throw new \LogicException(\sprintf('The "$%s" argument of "%s" must not be variadic.', $argument->getName(), $argument->getControllerName()));
        }

        $attribute ??= new MapRateLimit();
        $attribute->metadata = $argument;

        return [$attribute];
    }

    public function onKernelControllerArguments(ControllerArgumentsEvent $event): void
    {
        $arguments = $event->getArguments();
        $applied = $event->getRequest()->attributes->get(RateLimitAttributeListener::REQUEST_ATTRIBUTE, []);
        $applied = \is_array($applied) ? $applied : [];
        $changed = false;

        foreach ($arguments as $i => $argument) {
            if (!$argument instanceof MapRateLimit) {
                continue;
            }

            $selected = null;
            foreach ($applied as $candidate) {
                if (!$candidate instanceof AppliedRateLimit) {
                    continue;
                }

                // Keep only results with the requested header exposure.
                if (null !== $argument->exposed && $argument->exposed !== $candidate->exposed) {
                    continue;
                }

                // Keep only results created by the requested limiter.
                if (null !== $argument->limiter && $argument->limiter !== $candidate->limiter) {
                    continue;
                }

                if (!$selected || $candidate->getRemainingCalls() < $selected->getRemainingCalls()) {
                    $selected = $candidate;
                }
            }

            $arguments[$i] = $selected?->rateLimit ?? match (true) {
                $argument->metadata->hasDefaultValue() => $argument->metadata->getDefaultValue(),
                $argument->metadata->isNullable() => null,
                default => throw new \RuntimeException(\sprintf('Could not resolve the "$%s" argument of "%s": no matching rate limit was applied to the request.', $argument->metadata->getName(), $argument->metadata->getControllerName())),
            };
            $changed = true;
        }

        if ($changed) {
            $event->setArguments($arguments);
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER_ARGUMENTS => ['onKernelControllerArguments', -10100],
        ];
    }
}
