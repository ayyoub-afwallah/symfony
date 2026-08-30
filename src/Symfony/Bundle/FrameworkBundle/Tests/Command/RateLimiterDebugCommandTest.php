<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bundle\FrameworkBundle\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Command\RateLimiterDebugCommand;
use Symfony\Component\Console\Tester\CommandCompletionTester;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

class RateLimiterDebugCommandTest extends TestCase
{
    public function testListRateLimiters()
    {
        $limiters = $this->createLimiters();
        $command = new RateLimiterDebugCommand($limiters);
        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('api', $output);
        $this->assertStringContainsString('login', $output);
        $this->assertStringContainsString('sliding_window', $output);
        $this->assertStringContainsString('fixed_window', $output);
    }

    public function testShowSingleLimiter()
    {
        $limiters = $this->createLimiters();
        $command = new RateLimiterDebugCommand($limiters);
        $tester = new CommandTester($command);
        $tester->execute(['name' => 'api']);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('sliding_window', $output);
        $this->assertStringContainsString('100', $output);
        $this->assertStringContainsString('1 minute(s)', $output);
    }

    public function testPeekLimiterState()
    {
        $limiters = $this->createLimiters();
        $command = new RateLimiterDebugCommand($limiters);
        $tester = new CommandTester($command);
        $tester->execute(['name' => 'api', '--key' => 'foo']);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('State for key', $output);
        $this->assertStringContainsString('Accepted', $output);
        $this->assertStringContainsString('yes', $output);
        $this->assertStringContainsString('Remaining', $output);
        $this->assertStringContainsString('100', $output);
    }

    public function testUnknownLimiter()
    {
        $limiters = $this->createLimiters();
        $command = new RateLimiterDebugCommand($limiters);
        $tester = new CommandTester($command);
        $tester->execute(['name' => 'nonexistent']);

        $this->assertSame(1, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('does not exist', $output);
        $this->assertStringContainsString('api', $output);
        $this->assertStringContainsString('login', $output);
    }

    public function testJsonFormat()
    {
        $limiters = $this->createLimiters();
        $command = new RateLimiterDebugCommand($limiters);
        $tester = new CommandTester($command);
        $tester->execute(['--format' => 'json']);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertCount(2, $decoded);
        $this->assertArrayHasKey('name', $decoded[0]);
        $this->assertArrayHasKey('policy', $decoded[0]);
    }

    public function testEmptyLimiters()
    {
        $limiters = new ServiceLocator([]);
        $command = new RateLimiterDebugCommand($limiters);
        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('No rate limiters', $output);
    }

    public function testCompletion()
    {
        $limiters = $this->createLimiters();
        $command = new RateLimiterDebugCommand($limiters);
        $tester = new CommandCompletionTester($command);

        $suggestions = $tester->complete(['']);
        $this->assertSame(['api', 'login'], $suggestions);
    }

    private function createLimiters(): ServiceLocator
    {
        return new ServiceLocator([
            'api' => fn () => new RateLimiterFactory(
                ['id' => 'api', 'policy' => 'sliding_window', 'limit' => 100, 'interval' => '1 minute'],
                new InMemoryStorage(),
            ),
            'login' => fn () => new RateLimiterFactory(
                ['id' => 'login', 'policy' => 'fixed_window', 'limit' => 5, 'interval' => '15 minutes'],
                new InMemoryStorage(),
            ),
        ]);
    }
}
