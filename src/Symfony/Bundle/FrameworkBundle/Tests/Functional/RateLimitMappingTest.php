<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bundle\FrameworkBundle\Tests\Functional;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRateLimit;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Attribute\RateLimit as RateLimitAttribute;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\ResolverNotFoundException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\RateLimit;

class RateLimitMappingTest extends AbstractWebTestCase
{
    public function testImplicitInjectionUsesCompiledResolver()
    {
        $client = self::createClient(['test_case' => 'RateLimitMapping']);
        $client->request('GET', '/implicit');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('1', $client->getResponse()->getContent());
        self::assertFalse($client->getResponse()->headers->has('X-RateLimit-Limit'));

        $client->request('GET', '/implicit');
        self::assertSame('0', $client->getResponse()->getContent());
    }

    public function testFiltersResultsIndependentlyFromHeaders()
    {
        $client = self::createClient(['test_case' => 'RateLimitMapping']);
        $client->request('GET', '/filtered');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('1,3,8,1,same', $client->getResponse()->getContent());
        self::assertSame('4', $client->getResponse()->headers->get('X-RateLimit-Limit'));
        self::assertSame('3', $client->getResponse()->headers->get('X-RateLimit-Remaining'));
    }

    public function testRejectedLimiterPreventsControllerInvocation()
    {
        $client = self::createClient(['test_case' => 'RateLimitMapping']);
        $client->request('GET', '/limited');
        self::assertSame('0', $client->getResponse()->getContent());

        $client->request('GET', '/limited');
        self::assertSame(429, $client->getResponse()->getStatusCode());
        self::assertSame('0', $client->getResponse()->headers->get('X-RateLimit-Remaining'));
        self::assertNotNull($client->getResponse()->headers->get('Retry-After'));
    }

    public function testUnlimitedResultIsInjectedWithoutHeaders()
    {
        $client = self::createClient(['test_case' => 'RateLimitMapping']);
        $client->request('GET', '/unlimited');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('no reset', $client->getResponse()->getContent());
        self::assertFalse($client->getResponse()->headers->has('X-RateLimit-Limit'));
    }

    public function testMissingNamedResultUsesNull()
    {
        $client = self::createClient(['test_case' => 'RateLimitMapping']);
        $client->request('GET', '/nullable');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('missing', $client->getResponse()->getContent());
    }

    public function testMissingRequiredResultNamesTheControllerArgument()
    {
        $client = self::createClient(['test_case' => 'RateLimitMapping']);
        $client->catchExceptions(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('"$limit"');
        $client->request('GET', '/required');
    }

    public function testMethodRestrictionSkipsConsumptionAndMapping()
    {
        $client = self::createClient(['test_case' => 'RateLimitMapping']);
        $client->request('GET', '/method');
        self::assertSame('missing', $client->getResponse()->getContent());

        $client->request('POST', '/method');
        self::assertSame('1', $client->getResponse()->getContent());
    }

    public function testGetMethodRestrictionAlsoAppliesToHeadRequests()
    {
        $client = self::createClient(['test_case' => 'RateLimitMapping']);
        $client->request('HEAD', '/head');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('9', $client->getResponse()->headers->get('X-RateLimit-Limit'));
        self::assertSame('8', $client->getResponse()->headers->get('X-RateLimit-Remaining'));
    }

    public function testHiddenRejectingSiblingDoesNotExposeEarlierResult()
    {
        $client = self::createClient(['test_case' => 'RateLimitMapping']);
        $client->request('GET', '/hidden-rejection');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('0', $client->getResponse()->getContent());
        self::assertSame('9', $client->getResponse()->headers->get('X-RateLimit-Limit'));

        $client->request('GET', '/hidden-rejection');
        self::assertSame(429, $client->getResponse()->getStatusCode());
        self::assertNotNull($client->getResponse()->headers->get('Retry-After'));
        self::assertFalse($client->getResponse()->headers->has('X-RateLimit-Limit'));
        self::assertFalse($client->getResponse()->headers->has('X-RateLimit-Remaining'));
        self::assertFalse($client->getResponse()->headers->has('X-RateLimit-Reset'));
    }

    public function testSubrequestReceivesMappedArgumentsWithoutRateLimitHeaders()
    {
        $client = self::createClient(['test_case' => 'RateLimitMapping']);
        $request = Request::create('/subrequest');

        $response = $client->getKernel()->handle($request, HttpKernelInterface::SUB_REQUEST);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('1,3,8,1,same', $response->getContent());
        self::assertFalse($response->headers->has('X-RateLimit-Limit'));
    }

    public function testMappingWorksWithOtherLimiterPolicies()
    {
        $client = self::createClient(['test_case' => 'RateLimitMapping']);

        foreach (['sliding', 'bucket', 'combined'] as $policy) {
            $client->request('GET', '/'.$policy);
            self::assertSame(200, $client->getResponse()->getStatusCode(), $policy);
            self::assertSame('combined' === $policy ? '1' : '4', $client->getResponse()->getContent(), $policy);
        }
    }

    public function testMissingResultUsesSignatureDefault()
    {
        $client = self::createClient(['test_case' => 'RateLimitMapping']);
        $client->request('GET', '/default');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('7', $client->getResponse()->getContent());
    }

    public function testAppliedResultPrecedesNullableAndDefaultFallbacks()
    {
        $client = self::createClient(['test_case' => 'RateLimitMapping']);

        foreach (['/nullable-match', '/default-match'] as $path) {
            $client->request('GET', $path);
            self::assertSame(200, $client->getResponse()->getStatusCode());
            self::assertSame('8', $client->getResponse()->getContent());
        }
    }

    public function testMappingComposesWithOtherDeferredArgumentsAndRejectionPrecedesPayloadErrors()
    {
        $client = self::createClient(['test_case' => 'RateLimitMapping']);
        $client->request('POST', '/composed?value=query', server: ['CONTENT_TYPE' => 'application/json'], content: '{"value":"body"}');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('0,query,body', $client->getResponse()->getContent());

        $client->request('POST', '/composed?value=query', server: ['CONTENT_TYPE' => 'application/json'], content: '{');
        self::assertSame(429, $client->getResponse()->getStatusCode());
        self::assertNotNull($client->getResponse()->headers->get('Retry-After'));
    }

    public function testMappingCanSelectCustomNamedResolver()
    {
        $client = self::createClient(['test_case' => 'RateLimitMapping']);
        $client->request('GET', '/custom-resolver');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('13', $client->getResponse()->getContent());
    }

    public function testMissingCustomResolverReportsItsName()
    {
        $client = self::createClient(['test_case' => 'RateLimitMapping']);
        $client->catchExceptions(false);

        $this->expectException(ResolverNotFoundException::class);
        $this->expectExceptionMessage('rate_limit_mapping.missing_resolver');
        $client->request('GET', '/missing-resolver');
    }

    public function testClassAndMethodAttributesBothContributeResults()
    {
        $client = self::createClient(['test_case' => 'RateLimitMapping']);
        $client->request('GET', '/class-and-method');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('1,8', $client->getResponse()->getContent());
        self::assertSame('9', $client->getResponse()->headers->get('X-RateLimit-Limit'));
        self::assertSame('8', $client->getResponse()->headers->get('X-RateLimit-Remaining'));
    }

    public function testUnlimitedAndBoundedResultsCanBeSelectedSeparately()
    {
        $client = self::createClient(['test_case' => 'RateLimitMapping']);
        $client->request('GET', '/unlimited-with-bounded');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('bounded,unlimited', $client->getResponse()->getContent());
        self::assertFalse($client->getResponse()->headers->has('X-RateLimit-Limit'));
    }

    public function testMappingAndHeadersCompareRemainingCallsInsteadOfRawTokens()
    {
        $client = self::createClient(['test_case' => 'RateLimitMapping']);
        foreach (['/token-cost', '/token-cost-reversed'] as $path) {
            $client->request('GET', $path);
            self::assertSame(200, $client->getResponse()->getStatusCode());
            self::assertSame('90', $client->getResponse()->getContent());
            self::assertSame('10', $client->getResponse()->headers->get('X-RateLimit-Limit'));
            self::assertSame('9', $client->getResponse()->headers->get('X-RateLimit-Remaining'));
        }
    }

    public function testRepeatedLimiterNameSelectsTighterKey()
    {
        $client = self::createClient(['test_case' => 'RateLimitMapping']);
        $client->request('GET', '/warm-limiter');
        self::assertSame('8', $client->getResponse()->getContent());
        $client->request('GET', '/warm-limiter');
        self::assertSame('7', $client->getResponse()->getContent());

        $client->request('GET', '/repeated-limiter');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('6', $client->getResponse()->getContent());
    }
}

class ImplicitRateLimitController
{
    #[RateLimitAttribute('private_a')]
    public function __invoke(RateLimit $limit): Response
    {
        return new Response((string) $limit->getRemainingTokens());
    }
}

class FilteredRateLimitController
{
    #[RateLimitAttribute('public_a', exposeHeaders: true)]
    #[RateLimitAttribute('private_a')]
    #[RateLimitAttribute('public_b', exposeHeaders: true)]
    public function __invoke(RateLimit $any, #[MapRateLimit(exposed: true)] RateLimit $exposed, #[MapRateLimit(limiter: 'public_a')] RateLimit $named, #[MapRateLimit(exposed: false)] RateLimit $hidden): Response
    {
        return new Response(implode(',', array_map(static fn (RateLimit $limit) => $limit->getRemainingTokens(), [$any, $exposed, $named, $hidden])).','.($any === $hidden ? 'same' : 'different'));
    }
}

class LimitedRateLimitController
{
    #[RateLimitAttribute('limited', exposeHeaders: true)]
    public function __invoke(RateLimit $limit): Response
    {
        return new Response((string) $limit->getRemainingTokens());
    }
}

class UnlimitedRateLimitController
{
    #[RateLimitAttribute('unlimited', exposeHeaders: true)]
    public function __invoke(#[MapRateLimit(exposed: true)] RateLimit $limit): Response
    {
        return new Response(null === $limit->getResetAt() ? 'no reset' : 'reset');
    }
}

class NullableRateLimitController
{
    #[RateLimitAttribute('public_a')]
    public function __invoke(#[MapRateLimit(limiter: 'unknown')] ?RateLimit $limit): Response
    {
        return new Response(null === $limit ? 'missing' : 'present');
    }
}

class NullableMatchingRateLimitController
{
    #[RateLimitAttribute('public_a')]
    public function __invoke(?RateLimit $limit): Response
    {
        return new Response(null === $limit ? 'missing' : (string) $limit->getRemainingTokens());
    }
}

class RequiredRateLimitController
{
    public function __invoke(#[MapRateLimit] RateLimit $limit): Response
    {
        return new Response('called');
    }
}

class MethodRateLimitController
{
    #[RateLimitAttribute('private_a', methods: ['POST'])]
    public function __invoke(?RateLimit $limit): Response
    {
        return new Response(null === $limit ? 'missing' : (string) $limit->getRemainingTokens());
    }
}

class HeadRateLimitController
{
    #[RateLimitAttribute('public_a', methods: ['GET'], exposeHeaders: true)]
    public function __invoke(RateLimit $limit): Response
    {
        return new Response((string) $limit->getRemainingTokens());
    }
}

class HiddenRejectingRateLimitController
{
    #[RateLimitAttribute('public_a', exposeHeaders: true)]
    #[RateLimitAttribute('limited')]
    public function __invoke(RateLimit $limit): Response
    {
        return new Response((string) $limit->getRemainingTokens());
    }
}

class SlidingRateLimitController
{
    #[RateLimitAttribute('sliding')]
    public function __invoke(RateLimit $limit): Response
    {
        return new Response((string) $limit->getRemainingTokens());
    }
}

class BucketRateLimitController
{
    #[RateLimitAttribute('bucket')]
    public function __invoke(RateLimit $limit): Response
    {
        return new Response((string) $limit->getRemainingTokens());
    }
}

class CombinedRateLimitController
{
    #[RateLimitAttribute('combined')]
    public function __invoke(RateLimit $limit): Response
    {
        return new Response((string) $limit->getRemainingTokens());
    }
}

class DefaultRateLimitController
{
    public function __invoke(RateLimit $limit = new RateLimit(7, new \DateTimeImmutable('@0'), true, 8)): Response
    {
        return new Response((string) $limit->getRemainingTokens());
    }
}

class DefaultMatchingRateLimitController
{
    #[RateLimitAttribute('public_a')]
    public function __invoke(RateLimit $limit = new RateLimit(7, new \DateTimeImmutable('@0'), true, 8)): Response
    {
        return new Response((string) $limit->getRemainingTokens());
    }
}

class ComposedRateLimitController
{
    #[RateLimitAttribute('limited')]
    public function __invoke(RateLimit $limit, #[MapQueryString] RateLimitMappingInput $query, #[MapRequestPayload] RateLimitMappingInput $payload): Response
    {
        return new Response($limit->getRemainingTokens().','.$query->value.','.$payload->value);
    }
}

class RateLimitMappingInput
{
    public function __construct(public string $value)
    {
    }
}

class CustomResolverRateLimitController
{
    public function __invoke(#[MapRateLimit(resolver: 'rate_limit_mapping.custom_resolver')] RateLimit $limit): Response
    {
        return new Response((string) $limit->getRemainingTokens());
    }
}

class MissingResolverRateLimitController
{
    public function __invoke(#[MapRateLimit(resolver: 'rate_limit_mapping.missing_resolver')] RateLimit $limit): Response
    {
        return new Response('called');
    }
}

class CustomRateLimitValueResolver implements ValueResolverInterface
{
    public function resolve(Request $request, ArgumentMetadata $argument): array
    {
        return [new RateLimit(13, new \DateTimeImmutable('+1 hour'), true, 14)];
    }
}

#[RateLimitAttribute('private_a')]
class ClassAndMethodRateLimitController
{
    #[RateLimitAttribute('public_a', exposeHeaders: true)]
    public function __invoke(#[MapRateLimit(exposed: false)] RateLimit $hidden, #[MapRateLimit(exposed: true)] RateLimit $shown): Response
    {
        return new Response($hidden->getRemainingTokens().','.$shown->getRemainingTokens());
    }
}

class UnlimitedWithBoundedRateLimitController
{
    #[RateLimitAttribute('unlimited', exposeHeaders: true)]
    #[RateLimitAttribute('private_a')]
    public function __invoke(RateLimit $selected, #[MapRateLimit(limiter: 'private_a')] RateLimit $bounded, #[MapRateLimit(limiter: 'unlimited')] RateLimit $unlimited): Response
    {
        return new Response(($selected === $bounded ? 'bounded' : 'other').','.(null === $unlimited->getResetAt() ? 'unlimited' : 'reset'));
    }
}

class TokenCostMappingController
{
    #[RateLimitAttribute('cheap', exposeHeaders: true)]
    #[RateLimitAttribute('expensive', tokens: 10, exposeHeaders: true)]
    public function __invoke(RateLimit $limit): Response
    {
        return new Response((string) $limit->getRemainingTokens());
    }
}

class ReversedTokenCostMappingController
{
    #[RateLimitAttribute('expensive', tokens: 10, exposeHeaders: true)]
    #[RateLimitAttribute('cheap', exposeHeaders: true)]
    public function __invoke(RateLimit $limit): Response
    {
        return new Response((string) $limit->getRemainingTokens());
    }
}

class WarmRateLimitController
{
    #[RateLimitAttribute('public_a', key: 'warm')]
    public function __invoke(RateLimit $limit): Response
    {
        return new Response((string) $limit->getRemainingTokens());
    }
}

class RepeatedRateLimitController
{
    #[RateLimitAttribute('public_a', key: 'warm')]
    #[RateLimitAttribute('public_a', key: 'cold')]
    public function __invoke(#[MapRateLimit(limiter: 'public_a')] RateLimit $limit): Response
    {
        return new Response((string) $limit->getRemainingTokens());
    }
}
