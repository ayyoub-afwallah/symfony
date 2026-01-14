<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bridge\Doctrine\Middleware\Debug;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Symfony\Component\Stopwatch\Stopwatch;

/**
 * @author Laurent VOULLEMIER <laurent.voullemier@gmail.com>
 *
 * @internal
 */
final class Driver extends AbstractDriverMiddleware
{
    public function __construct(
        DriverInterface $driver,
        private readonly DebugDataHolder $debugDataHolder,
        private readonly ?Stopwatch $stopwatch,
        private readonly string $connectionName,
    ) {
        parent::__construct($driver);
    }

    public function connect(array $params): ConnectionInterface
    {
        $connection = parent::connect($params);

        $isPrimary = null;
        if (isset($params['primary']) && $params['primary']) {
             // Does not happen with Doctrine DBAL 3, but maybe in the future?
             // Actually, the params array passed to connect() comes from the driver,
             // and usually doesn't contain 'primary' => true unless explicitly added.
        }

        // Detect PrimaryReadReplicaConnection (DBAL 3)
        // We use debug_backtrace because PrimaryReadReplicaConnection does not pass any flag to the driver
        // when creating the connection.
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (isset($frame['class'])
                && (is_a($frame['class'], 'Doctrine\DBAL\Connections\PrimaryReadReplicaConnection', true)
                    || is_a($frame['class'], 'Doctrine\DBAL\Connections\MasterSlaveConnection', true)
                )
            ) {
                if ('ensureConnectedToPrimary' === $frame['function']) {
                    $isPrimary = true;
                } elseif ('ensureConnectedToReplica' === $frame['function']) {
                    $isPrimary = false;
                }
                break;
            }
        }

        if ('void' !== (string) (new \ReflectionMethod(ConnectionInterface::class, 'commit'))->getReturnType()) {
            return new DBAL3\Connection(
                $connection,
                $this->debugDataHolder,
                $this->stopwatch,
                $this->connectionName,
                $isPrimary
            );
        }

        return new Connection(
            $connection,
            $this->debugDataHolder,
            $this->stopwatch,
            $this->connectionName,
            $isPrimary
        );
    }
}
