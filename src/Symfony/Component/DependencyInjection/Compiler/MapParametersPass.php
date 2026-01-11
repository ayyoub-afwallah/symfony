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

use Symfony\Component\Config\Resource\ClassExistenceResource;
use Symfony\Component\DependencyInjection\Attribute\MapParameters;
use Symfony\Component\DependencyInjection\Config\ParameterValidator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\TypeInfo\Type\CollectionType;
use Symfony\Component\TypeInfo\Type\ObjectType;
use Symfony\Component\TypeInfo\Type\UnionType;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Validation;

/**
 * Processes classes with the MapParameters attribute to map configuration parameters.
 *
 * @author Ayyoub Afanah <ayyoubafanah@gmail.com>
 */
class MapParametersPass implements CompilerPassInterface
{
    private ?TypeResolver $typeResolver = null;
    private array $hydrationStack = [];
    private array $errors = [];

    public function process(ContainerBuilder $container): void
    {
        $this->typeResolver ??= class_exists(TypeResolver::class) ? TypeResolver::create() : null;

        foreach ($container->findTaggedServiceIds('di.map_parameters', true) as $serviceId => $attributes) {
            $definition = $container->getDefinition($serviceId);
            
            if (!$class = $definition->getClass()) {
                continue;
            }

            if (!$reflectionClass = $container->getReflectionClass($class)) {
                continue;
            }

            if (!$mapConfigAttributes = $reflectionClass->getAttributes(MapParameters::class)) {
                continue;
            }try {
                $this->hydrationStack = [];
                $this->configureDefinition($container, $definition, $reflectionClass, $mapConfigAttributes[0]->newInstance());
                $this->validateAtBuildTime($container, $definition, $reflectionClass);
            } catch (\Exception $e) {
                $this->errors[] = sprintf('Service "%s" (%s):\n  %s', $serviceId, $class, $e->getMessage());
            } finally {
                $this->hydrationStack = [];
            }
        }

        if ($this->errors) {
            throw new InvalidArgumentException(sprintf("Configuration errors found:\n\n%s", implode("\n\n", $this->errors)));
        }
    }

    private function configureDefinition(ContainerBuilder $container, Definition $definition, \ReflectionClass $reflectionClass, MapParameters $attribute): void
    {
        $definition->setAutowired(true);
        if (!$definition->isPublic()) {
            $definition->setPublic(false);
        }

        $configArray = $this->resolveConfigEntry($container, $attribute->entry);
        $container->addResource(new ClassExistenceResource($reflectionClass->getName()));

        if (\is_array($configArray)) {
            $this->hydrateDefinition($container, $definition, $reflectionClass, $configArray, $attribute->entry);
        } else {
            $this->mapFlatParameters($container, $definition, $reflectionClass, $attribute->entry);
        }

        if ($this->shouldValidate($container, $reflectionClass)) {
            $this->addValidationConfigurator($container, $definition);
        }
    }

    private function hydrateDefinition(ContainerBuilder $container, Definition $definition, \ReflectionClass $reflectionClass, array $configArray, string $contextEntry): void
    {
        if (isset($this->hydrationStack[$className = $reflectionClass->getName()])) {
            throw new \RuntimeException(sprintf('Circular reference detected for class "%s".', $className));
        }

        $this->hydrationStack[$className] = true;

        try {
            $normalizedConfig = [];
            foreach ($configArray as $key => $value) {
                $normalizedConfig[$this->normalizeKey($key)] = ['originalKey' => $key, 'value' => $value];
            }

            $usedKeys = $reflectionClass->getConstructor()
                ? $this->mapConstructorParams($container, $definition, $reflectionClass->getConstructor(), $normalizedConfig, $contextEntry)
                : [];

            $this->mapProperties($container, $definition, $reflectionClass, $normalizedConfig, $usedKeys, $contextEntry);

            if ($this->shouldValidate($container, $reflectionClass)) {
                $this->addValidationConfigurator($container, $definition);
            }
        } finally {
            unset($this->hydrationStack[$className]);
        }
    }

    private function mapConstructorParams(ContainerBuilder $container, Definition $definition, \ReflectionMethod $constructor, array $normalizedConfig, string $entry): array
    {
        $usedKeys = [];

        foreach ($constructor->getParameters() as $parameter) {
            $paramName = $parameter->getName();

            if (\array_key_exists($paramName, $definition->getArguments()) || \array_key_exists('$'.$paramName, $definition->getArguments())) {
                continue;
            }

            $normalizedParamName = $this->normalizeKey($paramName);

            if (\array_key_exists($normalizedParamName, $normalizedConfig)) {
                $match = $normalizedConfig[$normalizedParamName];
                
                if (null === $match['value'] && $parameter->isDefaultValueAvailable()) {
                    $usedKeys[$match['originalKey']] = true;
                    continue;
                }
                
                $definition->setArgument('$'.$paramName, $this->resolveValue($container, $match['value'], $parameter, $entry.'.'.$match['originalKey']));
                $usedKeys[$match['originalKey']] = true;
            } elseif (!$parameter->isDefaultValueAvailable()) {
                throw new InvalidArgumentException(sprintf('Missing configuration parameter "$%s" in entry "%s".', $paramName, $entry));
            }
        }

        return $usedKeys;
    }

    private function mapProperties(ContainerBuilder $container, Definition $definition, \ReflectionClass $reflectionClass, array $normalizedConfig, array $usedKeys, string $contextEntry): void
    {
        $extraKeys = [];

        foreach ($normalizedConfig as $camelCaseKey => $info) {
            if (isset($usedKeys[$info['originalKey']])) {
                continue;
            }

            if ($this->tryMapSetter($container, $definition, $reflectionClass, $camelCaseKey, $info, $contextEntry)) {
                continue;
            }

            if ($this->tryMapProperty($container, $definition, $reflectionClass, $camelCaseKey, $info['originalKey'], $info['value'], $contextEntry)) {
                continue;
            }

            $extraKeys[] = $info['originalKey'];
        }

        if ($extraKeys) {
            throw new InvalidArgumentException(sprintf('Class "%s" has unrecognized configuration keys: "%s" in entry "%s".', $reflectionClass->getName(), implode('", "', $extraKeys), $contextEntry));
        }
    }

    private function tryMapSetter(ContainerBuilder $container, Definition $definition, \ReflectionClass $reflectionClass, string $camelCaseKey, array $info, string $contextEntry): bool
    {
        $setterName = 'set'.ucfirst($camelCaseKey);
        
        if (!$reflectionClass->hasMethod($setterName)) {
            return false;
        }

        $method = $reflectionClass->getMethod($setterName);
        if (!$method->isPublic() || $method->isStatic() || 1 !== $method->getNumberOfParameters()) {
            return false;
        }

        $definition->addMethodCall($setterName, [$this->resolveValue($container, $info['value'], $method->getParameters()[0], $contextEntry.'.'.$info['originalKey'])]);
        return true;
    }

    private function tryMapProperty(ContainerBuilder $container, Definition $definition, \ReflectionClass $reflectionClass, string $camelCaseKey, string $originalKey, mixed $value, string $contextEntry): bool
    {
        foreach ([$originalKey, $camelCaseKey] as $propName) {
            if (!$reflectionClass->hasProperty($propName)) {
                continue;
            }

            $prop = $reflectionClass->getProperty($propName);
            
            if ($prop->isPublic() && !$prop->isReadOnly() && !$prop->isProtectedSet() && !$prop->isPrivateSet()) {
                $definition->setProperty($propName, $this->resolveValue($container, $value, $prop, $contextEntry.'.'.$originalKey));
                return true;
            }
            
            if (\PHP_VERSION_ID >= 80400 && $prop->isPublic() && $prop->hasHooks() && $prop->hasHook(\PropertyHookType::Set)) {
                $definition->setProperty($propName, $this->resolveValue($container, $value, $prop, $contextEntry.'.'.$originalKey));
                return true;
            }
        }

        return false;
    }

    private function resolveValue(ContainerBuilder $container, mixed $value, \ReflectionParameter|\ReflectionProperty $target, string $contextEntry): mixed
    {
        $className = $this->getClassName($target);

        if ($className && enum_exists($className) && (new \ReflectionEnum($className))->isBacked()) {
            return (new Definition($className))->setFactory([$className, 'tryFrom'])->setArguments([$value]);
        }

        if (is_a($className, \DateTimeInterface::class, true)) {
            return new Definition($className, [$value]);
        }

        if ($this->typeResolver && \is_array($value) && $result = $this->resolveCollection($container, $target, $value, $contextEntry)) {
            return $result;
        }

        if (!\is_array($value) || !$className || (!class_exists($className) && !interface_exists($className, false))) {
            return $value;
        }

        return $this->resolveNestedObject($container, $value, $className, $contextEntry);
    }

    private function getClassName(\ReflectionParameter|\ReflectionProperty $target): ?string
    {
        if ($this->typeResolver) {
            try {
                $type = $this->typeResolver->resolve($target);
                if ($type instanceof ObjectType) {
                    return $type->getClassName();
                }
            } catch (\Throwable) {
            }
        }

        $type = $target->getType();
        return ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) ? $type->getName() : null;
    }

    private function resolveCollection(ContainerBuilder $container, \ReflectionParameter|\ReflectionProperty $target, array $value, string $contextEntry): ?array
    {
        if (!($type = $this->typeResolver->resolve($target)) instanceof CollectionType) {
            return null;
        }

        if (!$itemClassName = $this->getItemClassName($type->getCollectionValueType())) {
            $itemClassName = $this->extractClassFromVarDoc($target);
        }
        
        if (!$itemClassName) {
            return null;
        }

        $result = [];
        foreach ($value as $k => $v) {
            $result[$k] = $this->resolveNestedObject($container, $v, $itemClassName, $contextEntry."[$k]");
        }
        return $result;
    }

    private function getItemClassName(mixed $itemType): ?string
    {
        if ($itemType instanceof ObjectType) {
            return $itemType->getClassName();
        }

        if ($itemType instanceof UnionType) {
            foreach ($itemType->getTypes() as $subType) {
                if ($subType instanceof ObjectType) {
                    return $subType->getClassName();
                }
            }
        }

        return null;
    }

    private function extractClassFromVarDoc(\ReflectionParameter|\ReflectionProperty $target): ?string
    {
        if ($target instanceof \ReflectionParameter) {
            $declaringClass = $target->getDeclaringClass();
            
            if ($target->isPromoted() && $declaringClass?->hasProperty($target->getName())) {
                if (($docComment = $declaringClass->getProperty($target->getName())->getDocComment())
                    && preg_match('/@var\s+([a-zA-Z_\\\\][a-zA-Z0-9_\\\\]*)\[\]/', $docComment, $matches)) {
                    return $this->resolveClassName($matches[1], $declaringClass);
                }
            }
            
            if (($docComment = $target->getDeclaringFunction()->getDocComment())
                && preg_match('/@param\s+([a-zA-Z_\\\\][a-zA-Z0-9_\\\\]*)\[\]\s+\$'.preg_quote($target->getName()).'\b/', $docComment, $matches)) {
                return $this->resolveClassName($matches[1], $declaringClass);
            }
        } elseif (($docComment = $target->getDocComment())
            && preg_match('/@var\s+([a-zA-Z_\\\\][a-zA-Z0-9_\\\\]*)\[\]/', $docComment, $matches)) {
            return $this->resolveClassName($matches[1], $target->getDeclaringClass());
        }

        return null;
    }

    private function resolveClassName(string $className, ?\ReflectionClass $declaringClass): ?string
    {
        if ($className[0] !== '\\' && $declaringClass) {
            $fullClassName = $declaringClass->getNamespaceName()
                ? $declaringClass->getNamespaceName().'\\'.$className
                : $className;
            
            if (class_exists($fullClassName) || interface_exists($fullClassName, false)) {
                return $fullClassName;
            }
        }
        
        return (class_exists($className) || interface_exists($className, false)) ? $className : null;
    }

    private function resolveNestedObject(ContainerBuilder $container, array $value, string $className, string $contextEntry): Definition
    {
        if (!$nestedClass = $container->getReflectionClass($className)) {
            return new Definition($className);
        }

        $nestedDefinition = (new Definition($className))->setAutowired(true);
        $this->hydrateDefinition($container, $nestedDefinition, $nestedClass, $value, $contextEntry);

        return $nestedDefinition;
    }

    private function resolveConfigEntry(ContainerBuilder $container, string $entry): mixed
    {
        $parameterBag = $container->getParameterBag();

        if ($parameterBag->has($entry)) {
            return $parameterBag->get($entry);
        }

        if (str_contains($entry, '.')) {
            [$paramName, $path] = explode('.', $entry, 2);
            if ($parameterBag->has($paramName)) {
                return $this->getNestedValue($parameterBag->get($paramName), $path);
            }
        }

        return null;
    }

    private function getNestedValue(mixed $array, string $path): mixed
    {
        if (!\is_array($array)) {
            return null;
        }

        foreach (explode('.', $path) as $key) {
            if (!\is_array($array) || !\array_key_exists($key, $array)) {
                return null;
            }
            $array = $array[$key];
        }

        return $array;
    }

    private function mapFlatParameters(ContainerBuilder $container, Definition $definition, \ReflectionClass $reflectionClass, string $entry): void
    {
        if (!$constructor = $reflectionClass->getConstructor()) {
            return;
        }

        $parameterBag = $container->getParameterBag();

        foreach ($constructor->getParameters() as $parameter) {
            $paramName = $parameter->getName();

            if (\array_key_exists($paramName, $definition->getArguments()) || \array_key_exists('$'.$paramName, $definition->getArguments())) {
                continue;
            }

            $candidates = [$entry.'.'.$paramName, $entry.'.'.strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $paramName))];

            foreach ($candidates as $key) {
                if ($parameterBag->has($key)) {
                    $definition->setArgument('$'.$paramName, "%$key%");
                    continue 2;
                }
            }

            if (!$parameter->isDefaultValueAvailable()) {
                throw new InvalidArgumentException(sprintf('Cannot resolve configuration parameter "$%s" for class "%s". Expected container parameter "%s" not found.', $paramName, $reflectionClass->getName(), $candidates[0]));
            }
        }
    }

    private function normalizeKey(string $input): string
    {
        return lcfirst(str_replace(['_', '-'], '', ucwords($input, '_-')));
    }

    private function shouldValidate(ContainerBuilder $container, \ReflectionClass $reflectionClass): bool
    {
        if (!$container->has('validator')) {
            return false;
        }

        if ($reflectionClass->getAttributes(Constraint::class, \ReflectionAttribute::IS_INSTANCEOF)) {
            return true;
        }

        if (($constructor = $reflectionClass->getConstructor()) && array_filter($constructor->getParameters(), fn($p) => $p->getAttributes(Constraint::class, \ReflectionAttribute::IS_INSTANCEOF))) {
            return true;
        }

        return (bool) array_filter($reflectionClass->getProperties(), fn($p) => $p->getAttributes(Constraint::class, \ReflectionAttribute::IS_INSTANCEOF));
    }

    private function addValidationConfigurator(ContainerBuilder $container, Definition $definition): void
    {
        $definition->setConfigurator([new Reference('di.parameter_validator'), 'validate']);

        if (!$container->has('di.parameter_validator')) {
            $container->setDefinition('di.parameter_validator', (new Definition(ParameterValidator::class))
                ->setArguments([new Reference('validator')])
                ->setPublic(false)
            );
        }
    }

    private function validateAtBuildTime(ContainerBuilder $container, Definition $definition, \ReflectionClass $reflectionClass): void
    {
        if (!class_exists(Validation::class) || !$this->shouldValidate($container, $reflectionClass)) {
            return;
        }

        try {
            $resolvedArgs = [];
            foreach ($definition->getArguments() as $k => $v) {
                $resolved = $container->getParameterBag()->resolveValue($v);
                if ((is_string($resolved) && str_contains($resolved, '%env(')) || $resolved instanceof Reference || $resolved instanceof Definition) {
                    return;
                }
                $resolvedArgs[$k] = $resolved;
            }

            $instance = $this->instantiate($reflectionClass, $resolvedArgs, $definition, $container);
            $violations = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator()->validate($instance);

            if (\count($violations) > 0) {
                $messages = [];
                foreach ($violations as $violation) {
                    $messages[] = sprintf(' * %s: %s', $violation->getPropertyPath(), $violation->getMessage());
                }
                $this->errors[] = sprintf('Service "%s" (%s):\n  %s', $reflectionClass->getName(), $reflectionClass->getName(), implode("\n  ", $messages));
            }
        } catch (\Throwable) {
        }
    }

    private function instantiate(\ReflectionClass $reflectionClass, array $resolvedArgs, Definition $definition, ContainerBuilder $container): object
    {
        $constructorArgs = [];
        if ($constructor = $reflectionClass->getConstructor()) {
            foreach ($constructor->getParameters() as $param) {
                $key = '$'.$param->getName();
                if (\array_key_exists($key, $resolvedArgs)) {
                    $constructorArgs[] = $resolvedArgs[$key];
                } elseif ($param->isDefaultValueAvailable()) {
                    $constructorArgs[] = $param->getDefaultValue();
                } else {
                    throw new \RuntimeException('Cannot instantiate for validation');
                }
            }
        }

        $instance = $reflectionClass->newInstanceArgs($constructorArgs);

        foreach ($definition->getMethodCalls() as [$method, $args]) {
            $instance->$method(...$container->getParameterBag()->resolveValue($args));
        }

        foreach ($definition->getProperties() as $prop => $val) {
            $instance->$prop = $container->getParameterBag()->resolveValue($val);
        }

        return $instance;
    }
}
