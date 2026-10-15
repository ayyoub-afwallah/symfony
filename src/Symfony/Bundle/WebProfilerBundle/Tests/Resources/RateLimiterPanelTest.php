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
use Symfony\Bundle\WebProfilerBundle\Profiler\CodeExtension;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\DataCollector\RateLimiterDataCollector;
use Symfony\Component\RateLimiter\Debug\RateLimiterTrace;
use Symfony\Component\RateLimiter\Debug\TraceableRateLimiterBuilder;
use Symfony\Component\RateLimiter\Exception\MaxWaitDurationExceededException;
use Symfony\Component\RateLimiter\RateLimiterBuilder;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class RateLimiterPanelTest extends TestCase
{
    public function testSavedProfileIsRendered()
    {
        if (!class_exists(RateLimiterDataCollector::class)) {
            $this->markTestSkipped('The RateLimiter component is not installed.');
        }

        $requests = new RequestStack();
        $request = Request::create('/');
        $requests->push($request);
        $trace = new RateLimiterTrace($requests);
        $builder = new TraceableRateLimiterBuilder(new RateLimiterBuilder(new InMemoryStorage()), $trace);
        $api = $builder->fixedWindow('api', 2, '1 minute');
        $limiter = $api->create('user-42');
        $limiter->consume();
        $limiter->consume(2);
        $bucket = $builder->tokenBucket('reservation', 2, '1 minute')->create('user-42');
        $bucket->reserve(2);
        $bucket->reserve();
        $builder->noop()->create('user-42')->consume();
        $limiter->reset();
        $compound = $builder->compound($builder->fixedWindow('short', 1, '1 minute'))->create('user-42');
        $compound->consume();
        $compound->consume();
        $line = __LINE__ + 1;
        $api->create()->consume();
        $failing = $builder->tokenBucket('failing', 1, '1 hour')->create('user-42');
        $failing->reserve();
        try {
            $failing->reserve(1, 1);
        } catch (MaxWaitDurationExceededException) {
        }
        $collector = new RateLimiterDataCollector($trace);
        $collector->collect($request, new Response());
        $collector = unserialize(serialize($collector));

        $loader = new FilesystemLoader();
        $loader->addPath(\dirname(__DIR__, 2).'/Resources/views', 'WebProfiler');
        $twig = new Environment($loader, ['strict_variables' => true]);
        $twig->addExtension(new CodeExtension('', \dirname(__DIR__, 2), 'UTF-8'));
        $template = $twig->load('@WebProfiler/Collector/rate_limiter.html.twig');
        $panel = $template->renderBlock('panel', ['collector' => $collector]);
        $menu = $template->renderBlock('menu', ['collector' => $collector]);

        $this->assertMatchesRegularExpression('/Rate Limiter <small>6 limiters<\/small>/', $panel);
        $this->assertMatchesRegularExpression('/<span class="value">11<\/span>\s*<span class="label">Calls/', $panel);
        $this->assertMatchesRegularExpression('/<span class="value rate-limiter-warning">2<\/span>\s*<span class="label">Rejected/', $panel);
        $this->assertMatchesRegularExpression('/<span class="value rate-limiter-error">1<\/span>\s*<span class="label">Failed/', $panel);
        $this->assertStringContainsString('label-status-error', $menu);

        $timeline = $this->extract($panel, '/<table class="rate-limiter-table" id="rate-limiter-timeline">.*?<\/table>/s');
        $this->assertStringContainsString('<dt>From</dt><dd class="rate-limiter-mono"><abbr title="'.__FILE__.'">Tests/Resources/RateLimiterPanelTest.php:'.$line.'</abbr></dd>', $timeline);
        $this->assertMatchesRegularExpression('/data-toggle="\d+" data-expanded="1".*?<span class="label status-error">failed<\/span>.*?<dt>Exception<\/dt><dd class="rate-limiter-mono rate-limiter-exception"><abbr title="Symfony\\\\Component\\\\RateLimiter\\\\Exception\\\\MaxWaitDurationExceededException">MaxWaitDurationExceededException<\/abbr>: The rate limiter wait time/s', $timeline, 'A failed call is expanded and shows its exception.');
        $this->assertMatchesRegularExpression('/<strong>api<\/strong>\s*<span class="rate-limiter-key rate-limiter-mono" title="user-42">user-42<\/span>.*?consume\(1\)<\/code>.*?dot-success">accepted<\/span>.*?>1\/2<\/span>/s', $timeline);
        $this->assertMatchesRegularExpression('/consume\(2\)<\/code>.*?<span class="label status-warning">rejected<\/span>.*?is-problem.*?retry \d+ s.*?<dt>Reason<\/dt><dd class="rate-limiter-mono rate-limiter-reason">needed 2, 1 left<\/dd>/s', $timeline);
        $this->assertStringContainsString('<span class="rate-limiter-quiet">reset</span>', $timeline);
        $this->assertMatchesRegularExpression('/reserve\(2\)<\/code>.*?dot-info">reserved<\/span>.*?<td>now<\/td>/s', $timeline);
        $this->assertMatchesRegularExpression('/reserve\(1\)<\/code>.*?dot-info">reserved<\/span>.*?<td>in \d+ (s|min)<\/td>/s', $timeline);
        $this->assertMatchesRegularExpression('/dot-success">unlimited<\/span>.*?∞/s', $timeline);
        $this->assertMatchesRegularExpression('/data-toggle="\d+" data-expanded="1".*?<dt>Decided by<\/dt><dd class="rate-limiter-mono">short<\/dd>.*?└<\/span> <strong>short<\/strong>/s', $timeline, 'A rejected compound call is expanded and shows the sub-limiter that rejected it.');

        $section = $this->extract($panel, '/<span class="rate-limiter-mono">api<\/span>.*?<\/table>/s');
        $this->assertMatchesRegularExpression('/<small>fixed window · 2 per minute<\/small>\s*<span class="rate-limiter-problems">1 problem<\/span>/', $section);
        $this->assertMatchesRegularExpression('/<th colspan="5"[^>]*>user-42<\/th>.*<th colspan="5"[^>]*>no key<\/th>/s', $section, 'Each key gets a row when a limiter has several.');

        $this->assertStringContainsString('<small>token bucket · 1 per minute, burst 2</small>', $panel);
        $this->assertStringNotContainsString('<th colspan="5"', $this->extract($panel, '/<span class="rate-limiter-mono">reservation<\/span>.*?<\/table>/s'), 'No key row when a limiter has a single key.');
        $this->assertMatchesRegularExpression('/via <a href="#rate-limiter-call-\d+" data-timeline-link="\d+">#\d+<\/a>/', $this->extract($panel, '/<span class="rate-limiter-mono">short<\/span>.*?<\/table>/s'));
    }

    private function extract(string $panel, string $pattern): string
    {
        $this->assertMatchesRegularExpression($pattern, $panel);
        preg_match($pattern, $panel, $match);

        return $match[0];
    }
}
