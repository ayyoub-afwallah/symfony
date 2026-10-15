<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\RateLimiter\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\CompoundRateLimiterFactory;
use Symfony\Component\RateLimiter\DataCollector\RateLimiterDataCollector;
use Symfony\Component\RateLimiter\Debug\RateLimiterTrace;
use Symfony\Component\RateLimiter\Debug\TraceableRateLimiterBuilder;
use Symfony\Component\RateLimiter\Debug\TraceableRateLimiterFactory;
use Symfony\Component\RateLimiter\DependencyInjection\RateLimiterTracePass;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\Policy\NoLimiter;
use Symfony\Component\RateLimiter\RateLimiterBuilder;
use Symfony\Component\RateLimiter\RateLimiterBuilderInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

class RateLimiterTracingTest extends TestCase
{
    public function testBuilderAndFactoryOperationsAreRecordedWithoutChangingResults()
    {
        $requests = new RequestStack();
        $request = Request::create('/');
        $requests->push($request);
        $trace = new RateLimiterTrace($requests);
        $builder = new TraceableRateLimiterBuilder(new RateLimiterBuilder(new InMemoryStorage()), $trace);

        $factory = $builder->fixedWindow('api', 2, '1 minute');
        $limiter = $factory->create('secret-key');
        $this->assertTrue($limiter->consume()->isAccepted());
        $this->assertFalse($limiter->consume(2)->isAccepted());
        $limiter->reset();
        $this->assertTrue($limiter->consume()->isAccepted());

        $operations = $trace->getOperations($request);
        $this->assertSame(['consume', 'consume', 'reset', 'consume'], array_column($operations, 'operation'));
        $this->assertSame([true, false, null, true], array_column($operations, 'accepted'));
        $this->assertSame('api', $operations[0]['limiter']);
        $this->assertSame('fixed_window', $operations[0]['policy']);
        $this->assertSame(['#1', '#1', '#1', '#1'], array_column($operations, 'key'));
        $this->assertStringNotContainsString('secret-key', json_encode($operations));
        $this->assertInstanceOf(TraceableRateLimiterFactory::class, $factory);
    }

    public function testCompiledContainerTracesConfiguredFactoriesAndInjectedBuilder()
    {
        $container = new ContainerBuilder();
        $container->register('request_stack', RequestStack::class)->setPublic(true);
        $container->register('storage', InMemoryStorage::class);
        $container->register('rate_limiter.trace', RateLimiterTrace::class)->addArgument(new Reference('request_stack'))->setPublic(true);
        $container->register('data_collector.rate_limiter', RateLimiterDataCollector::class)->addArgument(new Reference('rate_limiter.trace'));
        $container->register('limiter_builder', RateLimiterBuilder::class)->setArguments([new Reference('storage'), null])->setPublic(true);
        $container->register('limiter', RateLimiterFactory::class)
            ->setArguments([null, new Reference('storage'), null])
            ->setAbstract(true);
        $container->setDefinition('limiter.api', (new ChildDefinition('limiter'))
            ->replaceArgument(0, ['id' => 'api', 'policy' => 'fixed_window', 'limit' => 2, 'interval' => '1 minute'])
            ->addTag('rate_limiter', ['name' => 'api'])
            ->setPublic(true));
        $container->register('limiter.custom', TracingCustomFactory::class)
            ->addArgument(new Reference('limiter_builder'))
            ->addTag('rate_limiter', ['name' => 'custom'])
            ->setPublic(true);
        $container->register('limiter.plain', PlainCustomFactory::class)
            ->addTag('rate_limiter', ['name' => 'plain'])
            ->setPublic(true);
        $container->register('limiter.both', CompoundRateLimiterFactory::class)
            ->setArguments([new IteratorArgument(['api' => new Reference('limiter.api')])])
            ->addTag('rate_limiter', ['name' => 'both'])
            ->setPublic(true);

        (new RateLimiterTracePass())->process($container);
        $container->compile();

        $request = Request::create('/');
        $container->get('request_stack')->push($request);
        $this->assertInstanceOf(TraceableRateLimiterFactory::class, $container->get('limiter.api'));
        $this->assertInstanceOf(TraceableRateLimiterBuilder::class, $container->get('limiter_builder'));
        $container->get('limiter.api')->create('secret')->consume();
        $container->get('limiter_builder')->slidingWindow('dynamic', 3, '1 minute')->create('secret')->consume();
        $container->get('limiter.custom')->create('secret')->consume();
        $container->get('limiter.plain')->create('secret')->consume();
        $container->get('limiter.both')->create('secret')->consume();

        $operations = $container->get('rate_limiter.trace')->getOperations($request);
        $this->assertSame(['api', 'dynamic', 'custom_inner', 'plain', 'both', 'api'], array_column($operations, 'limiter'));
        $this->assertSame(['fixed_window', 'sliding_window', 'fixed_window', null, 'compound', 'fixed_window'], array_column($operations, 'policy'));
        $this->assertSame([0, 0, 0, 0, 0, 1], array_column($operations, 'depth'));
    }

    public function testNothingIsDecoratedWithoutTheDataCollector()
    {
        $container = new ContainerBuilder();
        $container->register('storage', InMemoryStorage::class);
        $container->register('limiter_builder', RateLimiterBuilder::class)->setArguments([new Reference('storage'), null])->setPublic(true);
        $container->register('limiter.plain', PlainCustomFactory::class)
            ->addTag('rate_limiter', ['name' => 'plain'])
            ->setPublic(true);

        (new RateLimiterTracePass())->process($container);
        $container->compile();

        $this->assertInstanceOf(RateLimiterBuilder::class, $container->get('limiter_builder'));
        $this->assertInstanceOf(PlainCustomFactory::class, $container->get('limiter.plain'));
    }

    public function testCompoundRecordsNestedCallsWithoutCountingThemTwice()
    {
        $requests = new RequestStack();
        $request = Request::create('/');
        $requests->push($request);
        $trace = new RateLimiterTrace($requests);
        $builder = new TraceableRateLimiterBuilder(new RateLimiterBuilder(new InMemoryStorage()), $trace);
        $compound = $builder->compound(
            $builder->fixedWindow('short', 1, '1 minute'),
            $builder->fixedWindow('long', 2, '1 minute'),
        )->create('secret');

        $this->assertTrue($compound->consume()->isAccepted());
        $this->assertFalse($compound->consume()->isAccepted());

        $collector = new RateLimiterDataCollector($trace);
        $collector->collect($request, new Response());
        $this->assertSame(2, $collector->getTopLevelCount());
        $this->assertSame(1, $collector->getRejectedCount());
        $this->assertSame(0, $collector->getErrorCount());
        $operations = $collector->getOperations();
        $this->assertSame(['compound', 'short', 'long', 'compound', 'short'], array_column($operations, 'limiter'));
        $this->assertSame(['compound', 'fixed_window', 'fixed_window', 'compound', 'fixed_window'], array_column($operations, 'policy'));
        $this->assertSame(['#1', '#1', '#1', '#1', '#1'], array_column($operations, 'key'));
        $this->assertSame($operations[0]['id'], $operations[1]['parent']);
        $this->assertSame($operations[0]['id'], $operations[2]['parent']);
        $this->assertSame($operations[3]['id'], $operations[4]['parent']);
        $this->assertSame([0, 1, 1, 0, 1], array_column($operations, 'depth'));

        $saved = unserialize(serialize($collector));
        $this->assertSame(2, $saved->getTopLevelCount());
        $this->assertSame($operations, $saved->getOperations());
    }

    public function testOperationsAreGroupedByLimiterAndKey()
    {
        $requests = new RequestStack();
        $request = Request::create('/');
        $requests->push($request);
        $trace = new RateLimiterTrace($requests);
        $builder = new TraceableRateLimiterBuilder(new RateLimiterBuilder(new InMemoryStorage()), $trace);
        $short = $builder->fixedWindow('short', 1, '1 minute');
        $compound = $builder->compound($short, $builder->slidingWindow('long', 5, '1 hour'))->create('a');
        $compound->consume();
        $compound->consume();
        $short->create('b')->consume();
        $builder->tokenBucket('bucket', 10, '1 second', 2)->create('a')->consume();

        $collector = new RateLimiterDataCollector($trace);
        $collector->collect($request, new Response());
        $limiters = $collector->getLimiters();

        $this->assertSame(['compound', 'short', 'long', 'bucket'], array_keys($limiters));
        $this->assertSame(['short', 'long'], $limiters['compound']['children']);
        $this->assertSame([2, 3, 1], [$limiters['compound']['calls'], $limiters['short']['calls'], $limiters['long']['calls']], 'The rejected compound call stops before "long".');
        $this->assertSame([1, 1, 0], [$limiters['compound']['rejected'], $limiters['short']['rejected'], $limiters['long']['rejected']]);
        $this->assertSame(['1 per 1 minute', '5 per 1 hour', 'burst 10, 2 per 1 second'], [$limiters['short']['settings'], $limiters['long']['settings'], $limiters['bucket']['settings']]);
        $this->assertNull($limiters['compound']['settings']);

        $this->assertSame(['#1', '#2'], array_keys($limiters['short']['keys']));
        $this->assertSame(['compound', 'compound'], array_column($limiters['short']['keys']['#1'], 'via'));
        $this->assertNull($limiters['short']['keys']['#2'][0]['via']);
        $this->assertSame([true, false], array_column($limiters['compound']['keys']['#1'], 'accepted'));
        $this->assertSame(['short', 'short'], array_column($limiters['compound']['keys']['#1'], 'decidedBy'), 'The most restrictive child decides an accepted call, the rejecting child a rejected one.');
    }

    public function testReservationsAndDisabledProfiling()
    {
        $requests = new RequestStack();
        $request = Request::create('/');
        $requests->push($request);
        $trace = new RateLimiterTrace($requests);
        $builder = new TraceableRateLimiterBuilder(new RateLimiterBuilder(new InMemoryStorage()), $trace);
        $reservation = $builder->tokenBucket('bucket', 2, '1 minute')->create('secret')->reserve();

        $this->assertTrue($reservation->getRateLimit()->isAccepted());
        $this->assertSame('reserve', $trace->getOperations($request)[0]['operation']);
        $this->assertNotNull($trace->getOperations($request)[0]['timeToAct']);

        $limiter = $builder->tokenBucket('future', 1, '1 minute')->create('secret');
        $limiter->reserve();
        $this->assertFalse($limiter->reserve()->getRateLimit()->isAccepted());
        $collector = new RateLimiterDataCollector($trace);
        $collector->collect($request, new Response());
        $this->assertSame(0, $collector->getRejectedCount(), 'A reservation for later is not a rejection.');

        $disabled = new RateLimiterTrace($requests, static fn () => true);
        $builder = new TraceableRateLimiterBuilder(new RateLimiterBuilder(new InMemoryStorage()), $disabled);
        $this->assertTrue($builder->fixedWindow('disabled', 1, '1 minute')->create()->consume()->isAccepted());
        $this->assertSame([], $disabled->getOperations($request));
    }

    public function testEveryBuilderMethodCanProduceTracedFactories()
    {
        $requests = new RequestStack();
        $request = Request::create('/');
        $requests->push($request);
        $trace = new RateLimiterTrace($requests);
        $builder = new TraceableRateLimiterBuilder(new RateLimiterBuilder(new InMemoryStorage()), $trace);

        foreach (['fixed', 'sliding', 'bucket', 'noop'] as $policy) {
            $factory = match ($policy) {
                'fixed' => $builder->fixedWindow($policy, 2, '1 minute'),
                'sliding' => $builder->slidingWindow($policy, 2, '1 minute'),
                'bucket' => $builder->tokenBucket($policy, 2, '1 minute'),
                'noop' => $builder->noop(),
            };
            $this->assertInstanceOf(TraceableRateLimiterFactory::class, $factory);
            $factory->create('secret')->consume();
        }

        $this->assertSame(['fixed', 'sliding', 'bucket', 'noop'], array_column($trace->getOperations($request), 'limiter'));
        $this->assertSame(['fixed_window', 'sliding_window', 'token_bucket', 'no_limit'], array_column($trace->getOperations($request), 'policy'));
        $this->assertTrue($trace->getOperations($request)[3]['unlimited']);
    }

    public function testKeyLabelsAreSharedWithinARequestAndNotSavedAsRawKeys()
    {
        $requests = new RequestStack();
        $request = Request::create('/');
        $requests->push($request);
        $trace = new RateLimiterTrace($requests);
        $builder = new TraceableRateLimiterBuilder(new RateLimiterBuilder(new InMemoryStorage()), $trace);

        $builder->fixedWindow('first', 2, '1 minute')->create('private-key')->consume();
        $builder->fixedWindow('second', 2, '1 minute')->create('private-key')->consume();
        $builder->fixedWindow('first', 2, '1 minute')->create('another-key')->consume();

        $collector = new RateLimiterDataCollector($trace);
        $collector->collect($request, new Response());
        $this->assertSame(['#1', '#1', '#2'], array_column($collector->getOperations(), 'key'));
        $this->assertStringNotContainsString('private-key', serialize($collector));
        $this->assertStringNotContainsString('another-key', serialize($collector));
    }

    public function testSubrequestOperationsKeepTheirOwnParents()
    {
        $requests = new RequestStack();
        $parent = Request::create('/parent');
        $child = Request::create('/child');
        $requests->push($parent);
        $trace = new RateLimiterTrace($requests);
        $trace->record('parent', 'consume', 1, static function () use ($requests, $child, $trace) {
            $requests->push($child);
            try {
                $trace->record('child', 'consume', 1, static fn () => null);
            } finally {
                $requests->pop();
            }
        });

        $this->assertNull($trace->getOperations($parent)[0]['parent']);
        $this->assertNull($trace->getOperations($child)[0]['parent']);

        $collector = new RateLimiterDataCollector($trace);
        $collector->collect($child, new Response());
        $this->assertSame(['child'], array_column($collector->getOperations(), 'limiter'), 'Sub-request operations belong to the sub-request profile.');
        $collector->collect($parent, new Response());
        $this->assertSame(['parent'], array_column($collector->getOperations(), 'limiter'));
    }

    public function testExceptionIsRecordedAndRethrownWithoutItsMessage()
    {
        $requests = new RequestStack();
        $request = Request::create('/');
        $requests->push($request);
        $trace = new RateLimiterTrace($requests);
        $exception = new \RuntimeException('private details');

        try {
            $trace->record('api', 'consume', 1, static function () use ($exception) { throw $exception; });
            $this->fail('The original exception should be rethrown.');
        } catch (\RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $collector = new RateLimiterDataCollector($trace);
        $collector->collect($request, new Response());
        $this->assertSame(1, $collector->getErrorCount());
        $this->assertSame(\RuntimeException::class, $collector->getOperations()[0]['exception']);
        $this->assertStringNotContainsString('private details', json_encode($collector->getOperations()));
    }
}

class TracingCustomFactory implements RateLimiterFactoryInterface
{
    public function __construct(private RateLimiterBuilderInterface $builder)
    {
    }

    public function create(?string $key = null): LimiterInterface
    {
        return $this->builder->fixedWindow('custom_inner', 2, '1 minute')->create($key);
    }
}

class PlainCustomFactory implements RateLimiterFactoryInterface
{
    public function create(?string $key = null): LimiterInterface
    {
        return new NoLimiter();
    }
}
