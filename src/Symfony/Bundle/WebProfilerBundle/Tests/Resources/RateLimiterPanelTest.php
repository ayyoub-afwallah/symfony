<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bundle\WebProfilerBundle\Tests\Resources;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\DataCollector\RateLimiterDataCollector;
use Symfony\Component\RateLimiter\Debug\RateLimiterTrace;
use Symfony\Component\RateLimiter\Debug\TraceableRateLimiterBuilder;
use Symfony\Component\RateLimiter\RateLimiterBuilder;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class RateLimiterPanelTest extends TestCase
{
    public function testSavedProfileDisplaysRecordedOperationWithoutKey()
    {
        if (!class_exists(RateLimiterDataCollector::class)) {
            $this->markTestSkipped('The RateLimiter component is not installed.');
        }

        $requests = new RequestStack();
        $request = Request::create('/');
        $requests->push($request);
        $trace = new RateLimiterTrace($requests);
        $builder = new TraceableRateLimiterBuilder(new RateLimiterBuilder(new InMemoryStorage()), $trace);
        $limiter = $builder->fixedWindow('api', 2, '1 minute')->create('private-key');
        $limiter->consume();
        $limiter->consume(2);
        $bucket = $builder->tokenBucket('reservation', 2, '1 minute')->create('private-key');
        $bucket->reserve(2);
        $bucket->reserve();
        $builder->noop()->create('private-key')->consume();
        $limiter->reset();
        $collector = new RateLimiterDataCollector($trace);
        $collector->collect($request, new Response());
        $collector = unserialize(serialize($collector));

        $loader = new FilesystemLoader();
        $loader->addPath(\dirname(__DIR__, 2).'/Resources/views', 'WebProfiler');
        $template = (new Environment($loader, ['strict_variables' => true]))->load('@WebProfiler/Collector/rate_limiter.html.twig');
        $panel = $template->renderBlock('panel', ['collector' => $collector]);
        $menu = $template->renderBlock('menu', ['collector' => $collector]);

        $this->assertMatchesRegularExpression('/<span class="value">3<\/span>\s*<span class="label">Limiters/', $panel);
        $this->assertMatchesRegularExpression('/<span class="value">6<\/span>\s*<span class="label">Operations/', $panel);
        $this->assertMatchesRegularExpression('/<span class="value">1<\/span>\s*<span class="label">Rejected/', $panel);
        $this->assertStringNotContainsString('private-key', $panel);
        $this->assertStringContainsString('label-status-warning', $menu);

        $api = $this->section($panel, 'api');
        $this->assertMatchesRegularExpression('/<span class="label status-neutral">fixed_window<\/span>\s*<span class="text-muted text-small">2 per 1 minute<\/span>/', $api);
        $this->assertMatchesRegularExpression('/3 calls · 1 rejected/', $api);
        $this->assertMatchesRegularExpression('/<th>Window<\/th>/', $api);
        $this->assertMatchesRegularExpression('/class="colored font-normal">Key #1</', $api);
        $this->assertMatchesRegularExpression('/consume\(1\)<\/code>.*?status-success">accepted<\/span>.*?resets <span title/s', $api);
        $this->assertMatchesRegularExpression('/consume\(2\)<\/code>.*?status-warning">rejected<\/span>.*?1 <span class="text-muted">\/ 2<\/span>.*?retry <span title/s', $api);
        $this->assertStringContainsString('<span class="label status-neutral">reset</span>', $api);

        $bucket = $this->section($panel, 'reservation');
        $this->assertStringContainsString('burst 2, 1 per 1 minute', $bucket);
        $this->assertStringContainsString('<th>Tokens left</th>', $bucket);
        $this->assertMatchesRegularExpression('/reserve\(2\)<\/code>.*?status-success">reserved<\/span>.*?acts <span title="[^"]*">now/s', $bucket);
        $this->assertMatchesRegularExpression('/reserve\(1\)<\/code>.*?status-success">reserved<\/span>.*?acts <span title="[^"]*">in \d+ (s|min)</s', $bucket);

        $noop = $this->section($panel, 'noop');
        $this->assertStringContainsString('status-success">unlimited</span>', $noop);
        $this->assertStringNotContainsString('<th>Remaining</th>', $noop);
    }

    private function section(string $panel, string $limiter): string
    {
        $this->assertMatchesRegularExpression('/<h3>\s*'.preg_quote($limiter, '/').'\s*<span/', $panel, \sprintf('No section for limiter "%s".', $limiter));
        preg_match('/<h3>\s*'.preg_quote($limiter, '/').'\s*<span.*?<\/table>/s', $panel, $match);

        return $match[0];
    }
}
