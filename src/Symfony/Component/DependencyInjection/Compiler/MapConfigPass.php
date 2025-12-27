<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Attribute\MapConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Processes classes with the MapConfig attribute to map configuration parameters.
 *
 * This compiler pass:
 * - Finds all services tagged with 'di.map_config'
 * - Maps constructor parameters to container parameters based on the configured prefix
 * - Validates configuration if symfony/validator is available
 * - Sets services as private and autowired
 *
 * @author Ayyoub Afanah <ayyoubafanah@gmail.com>
 */
class MapConfigPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        foreach ($container->findTaggedServiceIds('di.map_config', true) as $serviceId => $attributes) {
            $definition = $container->getDefinition($serviceId);
            $class = $definition->getClass();

            if (!$class || !class_exists($class)) {
                continue;
            }

            $reflectionClass = $container->getReflectionClass($class);
            if (!$reflectionClass) {
                continue;
            }

            // Find the MapConfig attribute
            $mapConfigAttribute = null;
            foreach ($reflectionClass->getAttributes(MapConfig::class) as $attribute) {
                $mapConfigAttribute = $attribute->newInstance();
                break;
            }

            if (!$mapConfigAttribute) {
                continue;
            }

            $this->configureDefinition($container, $definition, $reflectionClass, $mapConfigAttribute);
        }
    }

    private function configureDefinition(ContainerBuilder $container, Definition $definition, \ReflectionClass $reflectionClass, MapConfig $attribute): void
    {
        // Set service as private and autowired (unless already explicitly set as public for testing)
        if (!$definition->isPublic()) {
            $definition->setPublic(false);
        }
        $definition->setAutowired(true);

        $constructor = $reflectionClass->getConstructor();
        if (!$constructor) {
            return;
        }

        // Resolve the configuration entry (supports dot notation for nested params)
        $entry = $attribute->entry;
        $configArray = $this->resolveConfigEntry($container, $entry);
        
        if ($configArray !== null && \is_array($configArray)) {
            // Map array keys to constructor parameters
            $this->mapArrayToConstructor($container, $definition, $constructor, $configArray, $reflectionClass, $entry);
            
            // Add validation if symfony/validator is available
            if ($this->shouldValidate($container, $reflectionClass)) {
                $this->addValidationConfigurator($container, $definition, $reflectionClass);
            }
            return;
        }

        // Fallback: Try to map flat parameters (e.g., "app.name", "app.env")
        $this->mapFlatParametersToConstructor($container, $definition, $constructor, $reflectionClass, $entry);

        // Add validation if symfony/validator is available
        if ($this->shouldValidate($container, $reflectionClass)) {
            $this->addValidationConfigurator($container, $definition, $reflectionClass);
        }
    }

    /**
     * Resolves a configuration entry, supporting dot notation for nested parameters.
     * 
     * Examples:
     * - 's3_standard' → returns value of parameter 's3_standard'
     * - 's3.standard' → returns value of parameter 's3', key 'standard'
     * - 's3.config.standard' → returns value of parameter 's3', keys 'config' then 'standard'
     */
    private function resolveConfigEntry(ContainerBuilder $container, string $entry): mixed
    {
        $parameterBag = $container->getParameterBag();
        
        // Try direct parameter lookup first
        if ($parameterBag->has($entry)) {
            return $parameterBag->get($entry);
        }
        
        // Try dot notation (e.g., 's3.standard' means parameter 's3', key 'standard')
        if (str_contains($entry, '.')) {
            $parts = explode('.', $entry, 2);
            $paramName = $parts[0];
            $path = $parts[1];
            
            if ($parameterBag->has($paramName)) {
                $value = $parameterBag->get($paramName);
                return $this->getNestedValue($value, $path);
            }
        }
        
        return null;
    }

    /**
     * Gets a nested value from an array using dot notation.
     */
    private function getNestedValue(mixed $array, string $path): mixed
    {
        if (!\is_array($array)) {
            return null;
        }
        
        $keys = explode('.', $path);
        $current = $array;
        
        foreach ($keys as $key) {
            if (!\is_array($current) || !\array_key_exists($key, $current)) {
                return null;
            }
            $current = $current[$key];
        }
        
        return $current;
    }

    /**
     * Converts camelCase to snake_case.
     */
    private function camelCaseToSnakeCase(string $input): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $input));
    }

    /**
     * Maps array values to constructor parameters.
     */
    private function mapArrayToConstructor(ContainerBuilder $container, Definition $definition, \ReflectionMethod $constructor, array $configArray, \ReflectionClass $reflectionClass, string $entry): void
    {
        foreach ($constructor->getParameters() as $parameter) {
            $parameterName = $parameter->getName();

            // Skip if already configured explicitly
            if (\array_key_exists($parameterName, $definition->getArguments()) || \array_key_exists('$'.$parameterName, $definition->getArguments())) {
                continue;
            }

            // Try to find the value in the config array
            // First try exact match (snake_case parameter name)
            if (\array_key_exists($parameterName, $configArray)) {
                $definition->setArgument('$'.$parameterName, $configArray[$parameterName]);
            }
            // Then try camelCase to snake_case conversion
            elseif (\array_key_exists($snakeCaseName = $this->camelCaseToSnakeCase($parameterName), $configArray)) {
                $definition->setArgument('$'.$parameterName, $configArray[$snakeCaseName]);
            }
            // Check if parameter has default value
            elseif ($parameter->isDefaultValueAvailable()) {
                // Use default value if key doesn't exist in array
                continue;
            } else {
                // Required parameter is missing from config array
                throw new InvalidArgumentException(sprintf(
                    'Cannot resolve configuration parameter "$%s" for class "%s". Expected key "%s" not found in entry "%s".',
                    $parameterName,
                    $reflectionClass->getName(),
                    $parameterName,
                    $entry
                ));
            }
        }
    }

    /**
     * Checks if validation should be applied to this configuration class.
     */
    private function shouldValidate(ContainerBuilder $container, \ReflectionClass $reflectionClass): bool
    {
        // Check if validator service exists
        if (!$container->has('validator')) {
            return false;
        }

        // Check if class has any validation constraints
        // Look for Symfony\Component\Validator\Constraint attributes on the class or its properties
        if ($reflectionClass->getAttributes(\Symfony\Component\Validator\Constraint::class, \ReflectionAttribute::IS_INSTANCEOF)) {
            return true;
        }

        // Check constructor parameters for validation attributes
        $constructor = $reflectionClass->getConstructor();
        if ($constructor) {
            foreach ($constructor->getParameters() as $parameter) {
                if ($parameter->getAttributes(\Symfony\Component\Validator\Constraint::class, \ReflectionAttribute::IS_INSTANCEOF)) {
                    return true;
                }
            }
        }

        // Check properties for validation attributes
        foreach ($reflectionClass->getProperties() as $property) {
            if ($property->getAttributes(\Symfony\Component\Validator\Constraint::class, \ReflectionAttribute::IS_INSTANCEOF)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Adds a configurator to validate the configuration object after instantiation.
     */
    private function addValidationConfigurator(ContainerBuilder $container, Definition $definition, \ReflectionClass $reflectionClass): void
    {
        // Add a configurator that validates the object after construction
        $definition->setConfigurator([new Reference('di.config_validator'), 'validate']);
        
        // Register the validator service if it doesn't exist
        if (!$container->has('di.config_validator')) {
            $validatorDef = new Definition(\Symfony\Component\DependencyInjection\Config\ConfigValidator::class);
            $validatorDef->setArguments([new Reference('validator')]);
            $validatorDef->setPublic(false);
            $container->setDefinition('di.config_validator', $validatorDef);
        }
    }

    /**
     * Maps flat container parameters to constructor parameters.
     */
    private function mapFlatParametersToConstructor(ContainerBuilder $container, Definition $definition, \ReflectionMethod $constructor, \ReflectionClass $reflectionClass, string $entry): void
    {
        $parameterBag = $container->getParameterBag();
        $foundAny = false;

        foreach ($constructor->getParameters() as $parameter) {
            $parameterName = $parameter->getName();

            // Skip if already configured explicitly
            if (\array_key_exists($parameterName, $definition->getArguments()) || \array_key_exists('$'.$parameterName, $definition->getArguments())) {
                continue;
            }

            // Construct candidate keys
            $exactKey = $entry . '.' . $parameterName;
            $snakeKey = $entry . '.' . $this->camelCaseToSnakeCase($parameterName);
            
            if ($parameterBag->has($exactKey)) {
                $definition->setArgument('$'.$parameterName, "%$exactKey%");
                $foundAny = true;
            } elseif ($parameterBag->has($snakeKey)) {
                $definition->setArgument('$'.$parameterName, "%$snakeKey%");
                $foundAny = true;
            } elseif ($parameter->isDefaultValueAvailable()) {
                continue;
            } else {
                throw new InvalidArgumentException(sprintf(
                    'Cannot resolve configuration parameter "$%s" for class "%s". Expected container parameter "%s" not found.',
                    $parameterName,
                    $reflectionClass->getName(),
                    $exactKey
                ));
            }
        }

        // If we didn't find any parameters and the entry itself doesn't exist (and resolved to null earlier),
        // we might be in a case where the configuration is just missing.
        // But since we fall back here only if array lookup failed, we've done our best.
    }
}
