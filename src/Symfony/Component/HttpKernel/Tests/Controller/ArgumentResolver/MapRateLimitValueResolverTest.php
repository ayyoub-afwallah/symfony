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
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRateLimit;
use Symfony\Component\HttpKernel\Attribute\RateLimit as RateLimitAttribute;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver\MapRateLimitValueResolver;
use Symfony\Component\HttpKernel\Controller\ControllerResolver;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\EventListener\ControllerAttributesListener;
use Symfony\Component\HttpKernel\EventListener\RateLimitAttributeListener;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\RateLimiter\AppliedRateLimit;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Contracts\Service\ServiceProviderInterface;

class MapRateLimitValueResolverTest extends TestCase
{
    public function testResolveSkipsOtherTypesUnlessMapped()
    {
        $resolver = new MapRateLimitValueResolver();
        $request = Request::create('/');

        $this->assertSame([], $resolver->resolve($request, new ArgumentMetadata('id', 'int', false, false, null)));

        $this->expectException(\LogicException::class);
        $resolver->resolve($request, new ArgumentMetadata('id', 'int', false, false, null, false, [new MapRateLimit()]));
    }

    public function testResolveAcceptsRequiredButNotVariadicRateLimit()
    {
        $resolver = new MapRateLimitValueResolver();

        $this->assertInstanceOf(MapRateLimit::class, $resolver->resolve(Request::create('/'), new ArgumentMetadata('rateLimit', RateLimit::class, false, false, null))[0]);

        try {
            $resolver->resolve(Request::create('/'), new ArgumentMetadata('rateLimit', RateLimit::class, true, false, null, true));
            $this->fail('Expected a LogicException for a variadic argument.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('must not be variadic', $e->getMessage());
        }
    }

    public function testMissingRateLimitUsesDefaultOrNullAndRejectsRequiredArgument()
    {
        $resolver = new MapRateLimitValueResolver();
        $request = Request::create('/');
        $default = new RateLimit(1, new \DateTimeImmutable('+1 minute'), true, 1);
        $arguments = [
            $resolver->resolve($request, new ArgumentMetadata('withDefault', RateLimit::class, false, true, $default))[0],
            $resolver->resolve($request, new ArgumentMetadata('nullable', RateLimit::class, false, false, null, true))[0],
        ];
        $event = new ControllerArgumentsEvent($this->createStub(HttpKernelInterface::class), static fn () => null, $arguments, $request, null);

        $resolver->onKernelControllerArguments($event);

        $this->assertSame([$default, null], $event->getArguments());

        $required = $resolver->resolve($request, new ArgumentMetadata('required', RateLimit::class, false, false, null, false, [], 'Controller::action'))[0];
        $event = new ControllerArgumentsEvent($this->createStub(HttpKernelInterface::class), static fn () => null, [$required], $request, null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Controller::action');
        $resolver->onKernelControllerArguments($event);
    }

    public function testResolveStagesPlainAndMappedArguments()
    {
        $resolver = new MapRateLimitValueResolver();
        $request = Request::create('/');
        $plain = new ArgumentMetadata('rateLimit', RateLimit::class, false, false, null, true);
        $mapped = new ArgumentMetadata('rateLimit', RateLimit::class, false, false, null, true, [new MapRateLimit(exposed: true, limiter: 'api')]);

        $this->assertInstanceOf(MapRateLimit::class, $resolver->resolve($request, $plain)[0]);
        $this->assertSame($mapped->getAttributes()[0], $resolver->resolve($request, $mapped)[0]);
    }

    public function testMapsTheMostRestrictiveMatchingRateLimit()
    {
        $request = Request::create('/');
        $exposed = $this->applied(new RateLimit(8, new \DateTimeImmutable('+1 minute'), true, 10), 'api', true);
        $hidden = $this->applied(new RateLimit(1, new \DateTimeImmutable('+1 minute'), true, 10), 'internal', false);
        $alsoExposed = $this->applied(new RateLimit(3, new \DateTimeImmutable('+1 minute'), true, 10), 'other', true);
        $request->attributes->set(RateLimitAttributeListener::REQUEST_ATTRIBUTE, [$exposed, $hidden, $alsoExposed]);
        $event = new ControllerArgumentsEvent($this->createStub(HttpKernelInterface::class), static fn () => null, [new MapRateLimit(), new MapRateLimit(exposed: true)], $request, null);

        (new MapRateLimitValueResolver())->onKernelControllerArguments($event);

        $this->assertSame($hidden->rateLimit, $event->getArguments()[0]);
        $this->assertSame($alsoExposed->rateLimit, $event->getArguments()[1]);
    }

    public function testMapsHiddenOrNamedRateLimitAndReturnsNullWhenNoneMatches()
    {
        $request = Request::create('/');
        $firstLogin = $this->applied(new RateLimit(8, new \DateTimeImmutable('+1 minute'), true, 10), 'login', true);
        $secondLogin = $this->applied(new RateLimit(4, new \DateTimeImmutable('+1 minute'), true, 10), 'login', false);
        $request->attributes->set(RateLimitAttributeListener::REQUEST_ATTRIBUTE, [$firstLogin, $secondLogin]);
        $missing = (new MapRateLimitValueResolver())->resolve($request, new ArgumentMetadata('missing', RateLimit::class, false, false, null, true, [new MapRateLimit(limiter: 'missing')]))[0];
        $event = new ControllerArgumentsEvent($this->createStub(HttpKernelInterface::class), static fn () => null, [new MapRateLimit(exposed: false), new MapRateLimit(limiter: 'login', exposed: false), new MapRateLimit(limiter: 'login'), $missing], $request, null);

        (new MapRateLimitValueResolver())->onKernelControllerArguments($event);

        $this->assertSame($secondLogin->rateLimit, $event->getArguments()[0]);
        $this->assertSame($secondLogin->rateLimit, $event->getArguments()[1]);
        $this->assertSame($secondLogin->rateLimit, $event->getArguments()[2]);
        $this->assertNull($event->getArguments()[3]);
    }

    public function testMapsByRemainingCallsRatherThanRemainingTokens()
    {
        $request = Request::create('/');
        $cheap = $this->applied(new RateLimit(99, new \DateTimeImmutable('+1 minute'), true, 100), 'cheap', false);
        $expensive = $this->applied(new RateLimit(90, new \DateTimeImmutable('+1 minute'), true, 100), 'expensive', false, 10);
        $request->attributes->set(RateLimitAttributeListener::REQUEST_ATTRIBUTE, [$cheap, $expensive]);
        $event = new ControllerArgumentsEvent($this->createStub(HttpKernelInterface::class), static fn () => null, [new MapRateLimit()], $request, null);

        (new MapRateLimitValueResolver())->onKernelControllerArguments($event);

        $this->assertSame($expensive->rateLimit, $event->getArguments()[0]);
    }

    public function testMapsNoLimitRateLimitWithoutResetTime()
    {
        $request = Request::create('/');
        $unlimited = $this->applied(new RateLimit(PHP_INT_MAX, new \DateTimeImmutable('+1 minute'), true, PHP_INT_MAX), 'unlimited', true);
        $request->attributes->set(RateLimitAttributeListener::REQUEST_ATTRIBUTE, [$unlimited]);
        $event = new ControllerArgumentsEvent($this->createStub(HttpKernelInterface::class), static fn () => null, [new MapRateLimit(), new MapRateLimit(exposed: true)], $request, null);

        (new MapRateLimitValueResolver())->onKernelControllerArguments($event);

        $this->assertSame($unlimited->rateLimit, $event->getArguments()[0]);
        $this->assertSame($unlimited->rateLimit, $event->getArguments()[1]);
        $this->assertNull($unlimited->rateLimit->getResetAt());
    }

    public function testMapsRateLimitThroughARealKernelDispatch()
    {
        $factory = new RateLimiterFactory(['id' => 'api', 'policy' => 'fixed_window', 'limit' => 5, 'interval' => '1 minute'], new InMemoryStorage());
        $locator = $this->createStub(ServiceProviderInterface::class);
        $locator->method('has')->willReturn(true);
        $locator->method('get')->willReturn($factory);
        $locator->method('getProvidedServices')->willReturn(['api' => RateLimiterFactoryInterface::class]);

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new ControllerAttributesListener([
            KernelEvents::CONTROLLER_ARGUMENTS => [RateLimitAttribute::class => true],
        ]));
        $dispatcher->addSubscriber(new RateLimitAttributeListener($locator));
        $dispatcher->addSubscriber(new MapRateLimitValueResolver());

        $kernel = new HttpKernel($dispatcher, new ControllerResolver(), null, new ArgumentResolver(null, [new MapRateLimitValueResolver()]));
        $request = Request::create('/');
        $request->attributes->set('_controller', new MappedRateLimitController());

        $this->assertSame('4', $kernel->handle($request)->getContent());
    }

    private function applied(RateLimit $rateLimit, string $limiter, bool $exposed, int $tokens = 1): AppliedRateLimit
    {
        return new AppliedRateLimit($rateLimit, $tokens, $limiter, $exposed);
    }
}

class MappedRateLimitController
{
    #[RateLimitAttribute('api', exposeHeaders: true)]
    public function __invoke(RateLimit $rateLimit): Response
    {
        return new Response((string) $rateLimit->getRemainingTokens());
    }
}
