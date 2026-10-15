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
        $compound = $builder->compound($builder->fixedWindow('short', 1, '1 minute'))->create('private-key');
        $compound->consume();
        $compound->consume();
        $collector = new RateLimiterDataCollector($trace);
        $collector->collect($request, new Response());
        $collector = unserialize(serialize($collector));

        $loader = new FilesystemLoader();
        $loader->addPath(\dirname(__DIR__, 2).'/Resources/views', 'WebProfiler');
        $template = (new Environment($loader, ['strict_variables' => true]))->load('@WebProfiler/Collector/rate_limiter.html.twig');
        $panel = $template->renderBlock('panel', ['collector' => $collector]);
        $menu = $template->renderBlock('menu', ['collector' => $collector]);

        $this->assertMatchesRegularExpression('/<span class="value">5<\/span>\s*<span class="label">Limiters/', $panel);
        $this->assertMatchesRegularExpression('/<span class="value">8<\/span>\s*<span class="label">Calls <span class="text-muted">\+ 2 inside compounds/', $panel);
        $this->assertMatchesRegularExpression('/<span class="value">2<\/span>\s*<span class="label">Rejected/', $panel);
        $this->assertMatchesRegularExpression('/Tightest limiter: <strong>reservation<\/strong> · key #1 · <strong class="rate-limiter-problem">0 \/ 2 left/', $panel);
        $this->assertStringNotContainsString('private-key', $panel);
        $this->assertStringContainsString('label-status-warning', $menu);

        $timeline = $this->extract($panel, '/<table class="rate-limiter-table" id="rate-limiter-timeline">.*?<\/table>/s');
        $this->assertMatchesRegularExpression('/<strong>api<\/strong>.*?consume\(1\)<\/code>.*?status-success">accepted<\/span>.*?1 \/ 2 left.*?resets <span title/s', $timeline);
        $this->assertMatchesRegularExpression('/consume\(2\)<\/code>.*?status-warning">rejected<\/span>.*?needed 2, had 1.*?retry <span title/s', $timeline);
        $this->assertStringContainsString('<span class="label status-neutral">reset</span>', $timeline);
        $this->assertMatchesRegularExpression('/reserve\(2\)<\/code>.*?status-success">reserved<\/span>.*?acts <span title="[^"]*">now/s', $timeline);
        $this->assertMatchesRegularExpression('/reserve\(1\)<\/code>.*?status-success">reserved<\/span>.*?acts <span title="[^"]*">in \d+ (s|min)</s', $timeline);
        $this->assertMatchesRegularExpression('/status-success">unlimited<\/span>.*?no limit/s', $timeline);
        $this->assertSame(2, substr_count($timeline, 'data-toggle-op='), 'Each compound call can be expanded.');
        $this->assertMatchesRegularExpression('/data-toggle-op="\d+" data-expanded="1".*?decided by short.*?<strong>short<\/strong><span class="rate-limiter-decided">decided<\/span>/s', $timeline, 'A rejected compound call is expanded and flags the sub-limiter that rejected it.');

        $api = $this->extract($panel, '/<h3>\s*api\s*<span.*?<\/table>/s');
        $this->assertMatchesRegularExpression('/<span class="label status-neutral">fixed_window<\/span>\s*<span class="text-muted text-small">2 per 1 minute<\/span>/', $api);
        $this->assertMatchesRegularExpression('/3 calls · 1 rejected or failed/', $api);
        $this->assertMatchesRegularExpression('/Key #1\s*<span class="text-muted">\s*· 3 calls · 1 rejected or failed · reset\s*<\/span>/', $api);

        $this->assertStringContainsString('burst 2, 1 per 1 minute', $this->extract($panel, '/<h3>\s*reservation\s*<span.*?<\/table>/s'));
        $this->assertMatchesRegularExpression('/via <a href="#rate-limiter-call-\d+" data-timeline-link="\d+">compound #\d+<\/a>/', $this->extract($panel, '/<h3>\s*short\s*<span.*?<\/table>/s'));
    }

    private function extract(string $panel, string $pattern): string
    {
        $this->assertMatchesRegularExpression($pattern, $panel);
        preg_match($pattern, $panel, $match);

        return $match[0];
    }
}
