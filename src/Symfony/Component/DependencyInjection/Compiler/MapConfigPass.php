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
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;
use Symfony\Component\TypeInfo\Type\ObjectType;

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
    private ?TypeResolver $typeResolver = null;
    private array $hydrationStack = [];

    public function process(ContainerBuilder $container): void
    {
        if (null === $this->typeResolver && class_exists(TypeResolver::class)) {
            $this->typeResolver = TypeResolver::create();
        }

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

            try {
                $this->hydrationStack = []; // Reset stack for each service
                $this->configureDefinition($container, $definition, $reflectionClass, $mapConfigAttribute);
            } finally {
                $this->hydrationStack = [];
            }
        }
    }

    private function configureDefinition(ContainerBuilder $container, Definition $definition, \ReflectionClass $reflectionClass, MapConfig $attribute): void
    {
        // Set service as private and autowired (unless already explicitly set as public for testing)
        if (!$definition->isPublic()) {
            $definition->setPublic(false);
        }
        $definition->setAutowired(true);

        // Resolve the configuration entry (supports dot notation for nested params)
        $entry = $attribute->entry;
        $configArray = $this->resolveConfigEntry($container, $entry);
        
        if ($configArray !== null && \is_array($configArray)) {
            $this->hydrateDefinition($container, $definition, $reflectionClass, $configArray, $entry);
            
            // Ensure we track the class existence so that the container is recompiled if the DTO changes
            $container->addResource(new \Symfony\Component\Config\Resource\ClassExistenceResource($reflectionClass->getName()));
            return;
        }

        // Fallback: Try to map flat parameters (e.g., "app.name", "app.env")
        $this->mapFlatParametersToConstructor($container, $definition, $reflectionClass->getConstructor(), $reflectionClass, $entry);

        // Add validation if symfony/validator is available
        if ($this->shouldValidate($container, $reflectionClass)) {
            $this->addValidationConfigurator($container, $definition, $reflectionClass);
        }

        // Ensure we track the class existence so that the container is recompiled if the DTO changes
        $container->addResource(new \Symfony\Component\Config\Resource\ClassExistenceResource($reflectionClass->getName()));
    }

    private function hydrateDefinition(ContainerBuilder $container, Definition $definition, \ReflectionClass $reflectionClass, array $configArray, string $contextEntry): void
    {
        $className = $reflectionClass->getName();

        if (isset($this->hydrationStack[$className])) {
            throw new \RuntimeException(sprintf('Circular reference detected for class "%s" during MapConfig processing.', $className));
        }

        $this->hydrationStack[$className] = true;

        try {
            $constructor = $reflectionClass->getConstructor();
            
            // optimizations: Pre-normalize keys to camelCase for O(1) lookups
            $normalizedConfig = [];
            foreach ($configArray as $key => $value) {
                $normalizedKey = $this->normalizeKey($key);
                // If multiple keys normalize to the same value (e.g. snake_case and kebab-case),
                // the last one overrides. This is acceptable behavior.
                $normalizedConfig[$normalizedKey] = [
                    'originalKey' => $key,
                    'value' => $value
                ];
            }

            $usedKeys = [];

            if ($constructor) {
                $usedKeys = $this->mapArrayToConstructor($container, $definition, $constructor, $normalizedConfig, $reflectionClass, $contextEntry);
            }
            
            // Map remaining keys to setters and public properties
            $this->mapArrayToProperties($container, $definition, $reflectionClass, $normalizedConfig, $usedKeys, $contextEntry);

            // Add validation if symfony/validator is available
            if ($this->shouldValidate($container, $reflectionClass)) {
                $this->addValidationConfigurator($container, $definition, $reflectionClass);
            }
        } finally {
             unset($this->hydrationStack[$className]);
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
     * 
     * @return array List of configuration keys used for constructor injection
     */
    private function mapArrayToConstructor(ContainerBuilder $container, Definition $definition, \ReflectionMethod $constructor, array $normalizedConfig, \ReflectionClass $reflectionClass, string $entry): array
    {
        $usedKeys = [];

        foreach ($constructor->getParameters() as $parameter) {
            $parameterName = $parameter->getName();

            // Skip if already configured explicitly
            if (\array_key_exists($parameterName, $definition->getArguments()) || \array_key_exists('$'.$parameterName, $definition->getArguments())) {
                continue;
            }

            // O(1) lookup using normalized map
            // $parameterName is expected to be camelCase in the class definition (standard PHP practice)
            // If the class uses snake_case for properties, normalizeKey would have kept them as snake_case if they had no capitals?
            // Wait, normalizeKey logic: lcfirst(str_replace(['_', '-'], '', ucwords($input, '_-')))
            // 'some_param' -> 'Some_Param' -> 'SomeParam' -> 'someParam'.
            // If the property is $some_param (snake case in PHP code):
            // normalizeKey('some_param') -> 'someParam'.
            // So if I lookup 'some_param' (the PHP variable name) in $normalizedConfig (keyed by 'someParam'), FAIL.
            // I must normalize the PHP parameter name too.
            
            $normalizedParamName = $this->normalizeKey($parameterName);
            
            if (\array_key_exists($normalizedParamName, $normalizedConfig)) {
                $match = $normalizedConfig[$normalizedParamName];
                $originalKey = $match['originalKey'];
                $value = $match['value'];
                
                $resolvedValue = $this->resolveValue($container, $value, $parameter, $entry . '.' . $originalKey);
                $definition->setArgument('$'.$parameterName, $resolvedValue);
                $usedKeys[$originalKey] = true;
                continue;
            }

            // Check if parameter has default value
            if ($parameter->isDefaultValueAvailable()) {
                continue;
            }

            // Required parameter is missing from config array
            throw new InvalidArgumentException(sprintf(
                'Cannot resolve configuration parameter "$%s" for class "%s". Expected key "%s" (or snake/kebab case) not found in entry "%s".',
                $parameterName,
                $reflectionClass->getName(),
                $parameterName,
                $entry
            ));
        }

        return $usedKeys;
    }

    /**
     * Maps remaining array values to setters and public properties.
     */
    private function mapArrayToProperties(ContainerBuilder $container, Definition $definition, \ReflectionClass $reflectionClass, array $normalizedConfig, array $usedKeys, string $contextEntry): void
    {
        $extraKeys = [];

        foreach ($normalizedConfig as $camelCaseKey => $info) {
             $originalKey = $info['originalKey'];
             $value = $info['value'];

            if (isset($usedKeys[$originalKey])) {
                continue;
            }

            // 1. Try Setter (setProperty)
            $setterName = 'set' . ucfirst($camelCaseKey);
            if ($reflectionClass->hasMethod($setterName)) {
                $method = $reflectionClass->getMethod($setterName);
                if ($method->isPublic() && !$method->isStatic()) {
                    // Check if there is only one parameter
                    if ($method->getNumberOfParameters() === 1) {
                         $param = $method->getParameters()[0];
                         $resolvedValue = $this->resolveValue($container, $value, $param, $contextEntry . '.' . $originalKey);
                         $definition->addMethodCall($setterName, [$resolvedValue]);
                         continue;
                    }
                }
            }

            // 2. Try Public Property
            // Check exact name first (of the original key, e.g. if property is snake_case)
            if ($reflectionClass->hasProperty($originalKey)) {
                $prop = $reflectionClass->getProperty($originalKey);
                if ($prop->isPublic() && !$prop->isReadOnly() && !($prop->isProtectedSet() || $prop->isPrivateSet())) {
                    $resolvedValue = $this->resolveValue($container, $value, $prop, $contextEntry . '.' . $originalKey);
                    $definition->setProperty($originalKey, $resolvedValue);
                    continue;
                }
            }
            // Check camelCase name
            if ($reflectionClass->hasProperty($camelCaseKey)) {
                $prop = $reflectionClass->getProperty($camelCaseKey);
                if ($prop->isPublic() && !$prop->isReadOnly() && !($prop->isProtectedSet() || $prop->isPrivateSet())) {
                    $resolvedValue = $this->resolveValue($container, $value, $prop, $contextEntry . '.' . $originalKey);
                    $definition->setProperty($camelCaseKey, $resolvedValue);
                    continue;
                }
            }

            // If we reached here, the key is unused
            $extraKeys[] = $originalKey;
        }

        if (!empty($extraKeys)) {
            throw new InvalidArgumentException(sprintf(
                'Class "%s" has unrecognized configuration keys: "%s" in entry "%s". Verified that these keys match public properties or setter methods.',
                $reflectionClass->getName(),
                implode('", "', $extraKeys),
                $contextEntry
            ));
        }
    }

    /**
     * Resolves a value, handling nested object hydration.
     */
    private function resolveValue(ContainerBuilder $container, mixed $value, \ReflectionParameter|\ReflectionProperty $target, string $contextEntry): mixed
    {
        $className = null;

        if ($this->typeResolver) {
            try {
                $type = $this->typeResolver->resolve($target);
                if ($type instanceof ObjectType) {
                    $className = $type->getClassName();
                }
            } catch (\Throwable $e) {
                // Ignore TypeInfo exceptions and fall back to native reflection
            }
        }

        if (null === $className) {
            $type = $target->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $className = $type->getName();
            }
        }



        // Handle Enums (PHP 8.1+)
        if ($className && enum_exists($className)) {
            $r = new \ReflectionEnum($className);
            if ($r->isBacked()) {
                // Always use a factory definition for Enums to ensure correct runtime resolution
                $def = new Definition($className);
                $def->setFactory([$className, 'tryFrom']);
                $def->setArguments([$value]);
                return $def;
            }
        }

        // Handle DateTimeInterface
        if (is_a($className, \DateTimeInterface::class, true)) {
            return new Definition($className, [$value]);
        }
        
        // Handle Collections (array of objects)
        if ($this->typeResolver && \is_array($value)) {
             try {
                 $type = $this->typeResolver->resolve($target);
                 if ($type instanceof \Symfony\Component\TypeInfo\Type\CollectionType) {
                     $itemType = $type->getCollectionValueType();
                     
                     $itemClassName = null;
                     if ($itemType instanceof ObjectType) {
                         $itemClassName = $itemType->getClassName();
                     } elseif ($itemType instanceof \Symfony\Component\TypeInfo\Type\UnionType) {
                         foreach ($itemType->getTypes() as $subType) {
                             if ($subType instanceof ObjectType) {
                                 $itemClassName = $subType->getClassName();
                                 break;
                             }
                         }
                     }
                     
                     if ($itemClassName) {
                         
                         $result = [];
                         foreach ($value as $k => $v) {
                             $result[$k] = $this->resolveNestedObject($container, $v, $itemClassName, $contextEntry . "[$k]");
                         }
                         return $result;
                     }
                 }
                 // Legacy or GenericType fallback might be needed in future version but CollectionType is the standard now in 7.2
             } catch (\Throwable $e) {
                 // Ignore
             }
        }

        if (!\is_array($value)) {
            // If the value is not an array, we can't hydrate a nested object.
            // But we must return the value as-is (it might be a service ID or compatible type).
            return $value;
        }

        if (!class_exists($className) && !interface_exists($className, false)) {
            return $value;
        }
        
        return $this->resolveNestedObject($container, $value, $className, $contextEntry);
    }
    
    private function resolveNestedObject(ContainerBuilder $container, array $value, string $className, string $contextEntry): Definition
    {
        // Recursively hydrate the nested object
        $nestedClass = $container->getReflectionClass($className);
        if (!$nestedClass) {
            // Fallback definition? No, we need reflection to hydrate
             $def = new Definition($className);
             return $def;
        }

        $nestedDefinition = new Definition($className);
        $nestedDefinition->setAutowired(true);
        
        $this->hydrateDefinition($container, $nestedDefinition, $nestedClass, $value, $contextEntry);
        
        return $nestedDefinition;
    }




    /**
     * Converts properties to camelCase (supports snake_case and kebab-case).
     */
    private function normalizeKey(string $input): string
    {
        return lcfirst(str_replace(['_', '-'], '', ucwords($input, '_-')));
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
