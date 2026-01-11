<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\DependencyInjection\Attribute;

/**
 * Maps configuration parameters to a class constructor.
 *
 * Classes annotated with this attribute will have their constructor parameters
 * automatically populated from a container parameter entry.
 *
 * Maps configuration parameters from the container to a DTO class.
 *
 * When applied to a class, this attribute indicates that the class should be
 * automatically populated with values from a configuration entry in the container's
 * parameter bag. Supports constructor parameters, setters, and public properties.
 *
 * Example:
 *
 *     #[MapParameters(entry: 'database')]
 *     class DatabaseConfig {
 *         public function __construct(
 *             public readonly string $host,
 *             public readonly int $port = 3306,
 *         ) {}
 *     }
 *
 * With the following configuration:
 *
 *     parameters:
 *         database:
 *             host: localhost
 *             port: 5432
 *
 * The service will be autowired with host='localhost' and port=5432.
 *
 * @author Ayyoub Afanah <ayyoubafanah@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class MapParameters
{
    /**
     * @param string $entry The configuration entry path (e.g., 'app.database')
     */
    public function __construct(
        public readonly string $entry,
    ) {
    }
}
