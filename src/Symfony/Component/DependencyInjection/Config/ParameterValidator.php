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

use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Validates configuration objects using Symfony Validator.
 *
 * Used as a configurator by MapParametersPass to validate DTOs with the #[MapParameters] attribute.
 *
 * @author Ayyoub Afwallah <ayyoubafanah@gmail.com>
 */
final class ParameterValidator
{
    public function __construct(
        private readonly ValidatorInterface $validator,
    ) {
    }

    /**
     * Validates the given configuration object.
     *
     * @throws ValidationFailedException if validation fails
     */
    public function validate(object $config): object
    {
        $violations = $this->validator->validate($config);

        if (0 === \count($violations)) {
            return $config;
        }

        $messages = [];
        foreach ($violations as $violation) {
            $messages[] = sprintf(' * %s: %s', $violation->getPropertyPath(), $violation->getMessage());
        }

        throw new InvalidArgumentException(sprintf("The configuration for \"%s\" is invalid:\n%s", get_debug_type($config), implode("\n", $messages)));
    }
}
