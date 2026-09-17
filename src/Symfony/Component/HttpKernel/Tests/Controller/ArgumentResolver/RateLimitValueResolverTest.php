<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpKernel\Tests\Controller\ArgumentResolver;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver\RateLimitValueResolver;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\RateLimiter\AppliedRateLimit;
use Symfony\Component\HttpKernel\RateLimiter\PendingRateLimit;
use Symfony\Component\RateLimiter\RateLimit;

class RateLimitValueResolverTest extends TestCase
{
    public function testSkipsUnrelatedType()
    {
        $metadata = new ArgumentMetadata('foo', \stdClass::class, false, false, null, true);

        $this->assertSame([], (new RateLimitValueResolver())->resolve(Request::create('/'), $metadata));
    }

    public function testSkipsUntypedArgument()
    {
        $metadata = new ArgumentMetadata('foo', null, false, false, null);

        $this->assertSame([], (new RateLimitValueResolver())->resolve(Request::create('/'), $metadata));
    }

    public function testStagesTheBindingSlot()
    {
        $this->assertSame([PendingRateLimit::Placeholder], (new RateLimitValueResolver())->resolve(Request::create('/'), $this->makeMetadata()));
    }


    public function testStagesAPlaceholderForAnArgumentDefaultingToNull()
    {
        $metadata = new ArgumentMetadata('rateLimit', RateLimit::class, false, true, null);

        $this->assertSame([PendingRateLimit::Placeholder], (new RateLimitValueResolver())->resolve(Request::create('/'), $metadata));
    }

    public function testThrowsWhenTheArgumentIsNotNullable()
    {
        $metadata = $this->makeMetadata(nullable: false);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The "$rateLimit" argument of "App\Controller\FooController::bar" must be nullable: it is null when no #[RateLimit] attribute applies to the request.');

        (new RateLimitValueResolver())->resolve(Request::create('/'), $metadata);
    }

    public function testSubstitutesTheBindingRateLimit()
    {
        $rateLimit = new RateLimit(4, new \DateTimeImmutable('now'), true, 5, new \DateTimeImmutable('+1 minute'));
        $request = Request::create('/');
        $request->attributes->set('_rate_limit', new AppliedRateLimit($rateLimit, 1));

        $event = $this->makeArgumentsEvent($request, ['first', PendingRateLimit::Placeholder]);
        (new RateLimitValueResolver())->onKernelControllerArguments($event);

        $this->assertSame(['first', $rateLimit], $event->getArguments());
    }

    public function testSubstitutesNullWhenNoLimiterApplied()
    {
        $event = $this->makeArgumentsEvent(Request::create('/'), [PendingRateLimit::Placeholder]);

        (new RateLimitValueResolver())->onKernelControllerArguments($event);

        $this->assertSame([null], $event->getArguments());
    }

    public function testLeavesUnrelatedArgumentsAlone()
    {
        $event = $this->makeArgumentsEvent(Request::create('/'), ['first', null]);

        (new RateLimitValueResolver())->onKernelControllerArguments($event);

        $this->assertSame(['first', null], $event->getArguments());
    }



    public function testSubstitutesNullWhenTheRequestAttributeHoldsSomethingElse()
    {
        $request = Request::create('/');
        $request->attributes->set('_rate_limit', 'a route default');

        $event = $this->makeArgumentsEvent($request, [PendingRateLimit::Placeholder]);
        (new RateLimitValueResolver())->onKernelControllerArguments($event);

        $this->assertSame([null], $event->getArguments());
    }

    public function testRejectsAVariadicArgument()
    {
        $metadata = new ArgumentMetadata('rateLimit', RateLimit::class, true, false, null, true, [], 'App\\Controller\\FooController::bar');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The "$rateLimit" argument of "App\\Controller\\FooController::bar" must not be variadic: a single RateLimit applies to the request.');

        (new RateLimitValueResolver())->resolve(Request::create('/'), $metadata);
    }

    public function testRunsAfterTheControllerAttributesListener()
    {
        [, $priority] = RateLimitValueResolver::getSubscribedEvents()[KernelEvents::CONTROLLER_ARGUMENTS];

        $this->assertLessThan(-10000, $priority, 'every #[RateLimit] must be consumed before the binding limiter is read');
    }

    private function makeMetadata(bool $nullable = true, array $attributes = []): ArgumentMetadata
    {
        return new ArgumentMetadata('rateLimit', RateLimit::class, false, false, null, $nullable, $attributes, 'App\Controller\FooController::bar');
    }

    private function makeArgumentsEvent(Request $request, array $arguments): ControllerArgumentsEvent
    {
        return new ControllerArgumentsEvent(
            $this->createStub(HttpKernelInterface::class),
            static fn () => null,
            $arguments,
            $request,
            null,
        );
    }
}
