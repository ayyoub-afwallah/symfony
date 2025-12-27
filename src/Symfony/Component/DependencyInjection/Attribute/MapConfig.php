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
 * Example 1 - Direct parameter:
 *     #[MapConfig(entry: 's3_standard')]
 *     class S3Config
 *     {
 *         public function __construct(
 *             public readonly string $region,
 *             public readonly string $bucket,
 *         ) {}
 *     }
 *
 * This maps to parameter 's3_standard' which should be an array:
 *     parameters:
 *         s3_standard:
 *             region: 'us-east-1'
 *             bucket: 'my-bucket'
 *
 * Example 2 - Nested path:
 *     #[MapConfig(entry: 's3.standard')]
 *     class S3Config { ... }
 *
 * This maps to parameter 's3' with key 'standard':
 *     parameters:
 *         s3:
 *             standard:
 *                 region: 'us-east-1'
 *                 bucket: 'my-bucket'
 *
 * @author Ayyoub Afanah <ayyoubafanah@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class MapConfig
{
    /**
     * @param string      $entry     The configuration entry path (e.g., 's3_standard' or 's3.standard')
     * @param string|null $envPrefix Optional environment variable prefix for additional configuration sources
     */
    public function __construct(
        public readonly string $entry,
        public readonly ?string $envPrefix = null,
    ) {
    }
}
