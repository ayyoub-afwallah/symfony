<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\DependencyInjection\Config;

use Symfony\Component\DependencyInjection\Exception\RuntimeException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Validates configuration objects using Symfony Validator.
 *
 * @author Ayyoub Afanah <ayyoubafanah@gmail.com>
 *
 * @internal
 */
class ConfigValidator
{
    public function __construct(
        private ValidatorInterface $validator,
    ) {
    }

    /**
     * Validates a configuration object and returns it if valid.
     *
     * @throws RuntimeException If validation fails
     */
    public function validate(object $config): object
    {
        $violations = $this->validator->validate($config);

        if (\count($violations) > 0) {
            $messages = [];
            foreach ($violations as $violation) {
                $messages[] = sprintf('%s: %s', $violation->getPropertyPath(), $violation->getMessage());
            }

            throw new RuntimeException(sprintf(
                "Configuration validation failed for \"%s\":\n  - %s",
                $config::class,
                implode("\n  - ", $messages)
            ));
        }

        return $config;
    }
}
