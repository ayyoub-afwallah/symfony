<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bundle\FrameworkBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Contracts\Service\ServiceProviderInterface;

/**
 * A console command for retrieving information about rate limiters.
 *
 * @author Ayyoub AFW-ALLAH <ayyoub.afwallah@gmail.com>
 *
 * @final
 */
#[AsCommand(name: 'debug:rate-limiter', description: 'Display information about rate limiters')]
class RateLimiterDebugCommand extends Command
{
    /**
     * @param ServiceProviderInterface<RateLimiterFactoryInterface> $limiters
     */
    public function __construct(private ServiceProviderInterface $limiters)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDefinition([
                new InputArgument('name', InputArgument::OPTIONAL, 'The name of a rate limiter'),
                new InputOption('key', null, InputOption::VALUE_REQUIRED, 'Peek the live state for this key (does not consume a token)'),
                new InputOption('format', null, InputOption::VALUE_REQUIRED, 'The output format ("txt" or "json")', 'txt'),
            ])
            ->setHelp(<<<'EOF'
                The <info>%command.name%</info> command lists the configured rate limiters:

                  <info>php %command.full_name%</info>

                To show the full configuration of one limiter, pass its name:

                  <info>php %command.full_name% api</info>

                To peek the remaining quota for a given key without consuming a token:

                  <info>php %command.full_name% api --key='192.168.1.1~GET~/search'</info>
                EOF
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $names = array_keys($this->limiters->getProvidedServices());
        sort($names);

        if (!$names) {
            $io->getErrorStyle()->warning('No rate limiters are configured. Configure them under "framework.rate_limiter".');

            return self::SUCCESS;
        }

        $name = $input->getArgument('name');
        $json = 'json' === $input->getOption('format');

        // ---- list mode ----
        if (null === $name) {
            $rows = array_map(fn (string $n) => $this->describe($n), $names);

            if ($json) {
                $output->writeln(json_encode($rows, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

                return self::SUCCESS;
            }

            $io->title('Rate Limiters');
            $io->table(
                ['Name', 'Policy', 'Limit', 'Interval / Rate'],
                array_map(fn (array $r) => [$r['name'], $r['policy'], $r['limit'] ?? '-', $r['interval'] ?? '-'], $rows),
            );

            return self::SUCCESS;
        }

        // ---- single-limiter mode ----
        if (!$this->limiters->has($name)) {
            $io->getErrorStyle()->error(\sprintf('Rate limiter "%s" does not exist. Available: "%s".', $name, implode('", "', $names)));

            return self::FAILURE;
        }

        $info = $this->describe($name);

        if (null !== $key = $input->getOption('key')) {
            $info['peek'] = $this->peek($name, $key);
        }

        if ($json) {
            $output->writeln(json_encode($info, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $io->title(\sprintf('Rate Limiter "%s"', $name));
        $io->definitionList(
            ['Policy' => $info['policy']],
            ['Limit' => $info['limit'] ?? '(unbounded)'],
            ['Interval / Rate' => $info['interval'] ?? '-'],
            ...(isset($info['anchor_at']) ? [['Anchored at' => $info['anchor_at']]] : []),
        );

        if (isset($info['peek'])) {
            $p = $info['peek'];
            $io->section(\sprintf('State for key "%s"', $key));
            $io->definitionList(
                ['Accepted' => $p['accepted'] ? 'yes' : 'no'],
                ['Remaining' => $p['remaining']],
                ['Limit' => $p['limit']],
                ['Retry after' => $p['retry_after'].'s'],
            );
        }

        return self::SUCCESS;
    }

    /**
     * @return array{name: string, policy: string, limit?: int, interval?: string, anchor_at?: string}
     */
    private function describe(string $name): array
    {
        $factory = $this->limiters->get($name);
        $out = ['name' => $name];

        if (!$factory instanceof RateLimiterFactory) {
            // compound or custom factory: no single config to read
            $out['policy'] = 'compound';

            return $out;
        }

        $config = $factory->getConfig();
        $out['policy'] = $config['policy'];

        if (isset($config['limit'])) {
            $out['limit'] = $config['limit'];
        }
        if (isset($config['rate'])) {
            // token_bucket: describe as "token bucket"
            $out['interval'] = $this->describeRate($config['rate']);
        } elseif (isset($config['interval'])) {
            $out['interval'] = $this->describeInterval($config['interval']);
        }
        if (($config['anchor_at'] ?? null) instanceof \DateTimeInterface) {
            $out['anchor_at'] = $config['anchor_at']->format(\DateTimeInterface::ATOM);
        }

        return $out;
    }

    /**
     * @return array{accepted: bool, remaining: int, limit: int, retry_after: int}
     */
    private function peek(string $name, string $key): array
    {
        $rateLimit = $this->limiters->get($name)->create($key)->consume(0);

        return [
            'accepted' => $rateLimit->isAccepted(),
            'remaining' => $rateLimit->getRemainingTokens(),
            'limit' => $rateLimit->getLimit(),
            'retry_after' => max(0, $rateLimit->getRetryAfter()->getTimestamp() - time()),
        ];
    }

    private function describeInterval(\DateInterval $i): string
    {
        // \DateInterval from the factory is normalized; render the total seconds compactly
        $seconds = (new \DateTimeImmutable('@0'))->add($i)->getTimestamp();

        return match (true) {
            $seconds % 86400 === 0 => ($seconds / 86400).' day(s)',
            $seconds % 3600 === 0 => ($seconds / 3600).' hour(s)',
            $seconds % 60 === 0 => ($seconds / 60).' minute(s)',
            default => $seconds.' second(s)',
        };
    }

    private function describeRate(\Symfony\Component\RateLimiter\Policy\Rate $rate): string
    {
        // Rate has no public getters today; if this stays awkward, fall back to (string) casting
        // or add a __toString / getters to Rate in the same PR. Keep it minimal: "token bucket".
        return 'token bucket';
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestArgumentValuesFor('name')) {
            $suggestions->suggestValues(array_keys($this->limiters->getProvidedServices()));
        }
    }
}
