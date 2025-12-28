<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\DependencyInjection\Tests\Compiler;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\MapConfigPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;
use Symfony\Component\DependencyInjection\Tests\Fixtures\DatabaseConfig;
use Symfony\Component\DependencyInjection\Tests\Fixtures\EnumConfig;
use Symfony\Component\DependencyInjection\Tests\Fixtures\InvalidConfig;
use Symfony\Component\DependencyInjection\Tests\Fixtures\ModeEnum;
use Symfony\Component\DependencyInjection\Tests\Fixtures\NestedConfig;
use Symfony\Component\DependencyInjection\Tests\Fixtures\SetterConfig;
use Symfony\Component\DependencyInjection\Tests\Fixtures\SimpleConfig;
use Symfony\Component\DependencyInjection\Tests\Fixtures\SubConfig;
use Symfony\Component\Validator\Validation;

class MapConfigPassTest extends TestCase
{
    public function testProcessSimpleConfigWithoutValidation()
    {
        $container = new ContainerBuilder();
        
        // Set up parameters
        $container->setParameter('app.name', 'MyApp');
        $container->setParameter('app.environment', 'dev');
        $container->setParameter('app.debug', true);
        
        // Register the config service with the tag
        $container->register(SimpleConfig::class, SimpleConfig::class)
            ->addTag('di.map_config')
            ->setAutoconfigured(true)
            ->setPublic(true);
        
        // Process the container
        $pass = new MapConfigPass();
        $pass->process($container);
        
        // Verify the service is configured correctly (before compilation)
        $definition = $container->getDefinition(SimpleConfig::class);
        $this->assertTrue($definition->isPublic());
        $this->assertTrue($definition->isAutowired());
        
        // Verify arguments are set
        $arguments = $definition->getArguments();
        $this->assertArrayHasKey('$name', $arguments);
        $this->assertSame('%app.name%', $arguments['$name']);
        
        $container->compile();
        
        // Get the service and verify values
        $config = $container->get(SimpleConfig::class);
        $this->assertInstanceOf(SimpleConfig::class, $config);
        $this->assertSame('MyApp', $config->name);
        $this->assertSame('dev', $config->environment);
        $this->assertTrue($config->debug);
    }

    public function testProcessWithDefaultValues()
    {
        $container = new ContainerBuilder();
        
        // Only set required parameter
        $container->setParameter('app.name', 'TestApp');
        
        // Register the config service
        $container->register(SimpleConfig::class, SimpleConfig::class)
            ->addTag('di.map_config')
            ->setPublic(true);
        
        $pass = new MapConfigPass();
        $pass->process($container);
        
        $container->compile();
        
        // Get the service and verify default values are used
        $config = $container->get(SimpleConfig::class);
        $this->assertSame('TestApp', $config->name);
        $this->assertSame('prod', $config->environment); // default value
        $this->assertFalse($config->debug); // default value
    }

    public function testProcessWithCamelCaseToSnakeCaseConversion()
    {
        $container = new ContainerBuilder();
        
        // Set parameters with snake_case names
        $container->setParameter('database.host', 'localhost');
        $container->setParameter('database.port', 5432);
        $container->setParameter('database.name', 'testdb');
        
        $container->register(DatabaseConfig::class, DatabaseConfig::class)
            ->addTag('di.map_config')
            ->setPublic(true);
        
        $pass = new MapConfigPass();
        $pass->process($container);
        
        // Verify arguments are mapped correctly
        $definition = $container->getDefinition(DatabaseConfig::class);
        $arguments = $definition->getArguments();
        
        $this->assertArrayHasKey('$host', $arguments);
        $this->assertArrayHasKey('$port', $arguments);
        $this->assertArrayHasKey('$name', $arguments);
        $this->assertSame('%database.host%', $arguments['$host']);
        $this->assertSame('%database.port%', $arguments['$port']);
        $this->assertSame('%database.name%', $arguments['$name']);
    }

    public function testProcessThrowsExceptionForMissingRequiredParameter()
    {
        $container = new ContainerBuilder();
        
        // Missing required parameter 'app.name'
        $container->setParameter('app.environment', 'dev');
        
        $container->register(SimpleConfig::class, SimpleConfig::class)
            ->addTag('di.map_config')
            ->setPublic(true);
        
        $pass = new MapConfigPass();
        
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot resolve configuration parameter "$name"');
        $this->expectExceptionMessage('Expected container parameter "app.name" not found');
        
        $pass->process($container);
    }

    public function testProcessWithValidation()
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('Symfony Validator component is not available.');
        }

        $container = new ContainerBuilder();
        
        // Set up valid parameters
        $container->setParameter('database.host', 'localhost');
        $container->setParameter('database.port', 3306);
        $container->setParameter('database.name', 'mydb');
        
        // Register validator
        $validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
        
        $container->set('validator', $validator);
        $container->register('validator', get_class($validator))
            ->setSynthetic(true);
        
        $container->register(DatabaseConfig::class, DatabaseConfig::class)
            ->addTag('di.map_config')
            ->setPublic(true);
        
        $pass = new MapConfigPass();
        $pass->process($container);
        
        $container->compile();
        
        // Verify configurator was added
        $definition = $container->getDefinition(DatabaseConfig::class);
        $this->assertNotNull($definition->getConfigurator());
        
        // Get the service - should validate successfully
        $config = $container->get(DatabaseConfig::class);
        $this->assertInstanceOf(DatabaseConfig::class, $config);
        $this->assertSame('localhost', $config->host);
    }

    public function testProcessWithValidationFailure()
    {
        if (!class_exists(Validation::class)) {
            $this->markTestSkipped('Symfony Validator component is not available.');
        }

        $container = new ContainerBuilder();
        
        // Set up invalid parameters
        $container->setParameter('invalid.email', 'not-an-email'); // Invalid email
        $container->setParameter('invalid.percentage', 150); // Out of range
        
        // Register validator
        $validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
        
        $container->set('validator', $validator);
        $container->register('validator', get_class($validator))
            ->setSynthetic(true);
        
        $container->register(InvalidConfig::class, InvalidConfig::class)
            ->addTag('di.map_config')
            ->setPublic(true);
        
        $pass = new MapConfigPass();
        $pass->process($container);
        
        $container->compile();
        
        // Attempting to get the service should throw validation exception
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Configuration validation failed');
        
        $container->get(InvalidConfig::class);
    }

    public function testProcessSkipsServicesWithoutMapConfigAttribute()
    {
        $container = new ContainerBuilder();
        
        // Register a regular service with the tag but no attribute
        $container->register('regular.service', \stdClass::class)
            ->addTag('di.map_config');
        
        $pass = new MapConfigPass();
        $pass->process($container);
        
        // Should not throw any exception
        $this->assertTrue(true);
    }

    public function testProcessSkipsAlreadyConfiguredArguments()
    {
        $container = new ContainerBuilder();
        
        $container->setParameter('app.name', 'FromParameter');
        
        // Explicitly set an argument
        $container->register(SimpleConfig::class, SimpleConfig::class)
            ->addTag('di.map_config')
            ->setArgument('$name', 'ExplicitValue')
            ->setPublic(true);
        
        $pass = new MapConfigPass();
        $pass->process($container);
        
        $container->compile();
        
        // Verify the explicit value is preserved
        $config = $container->get(SimpleConfig::class);
        $this->assertSame('ExplicitValue', $config->name);
    }

    public function testProcessWithEnvironmentVariables()
    {
        $container = new ContainerBuilder();
        
        // Use environment variable syntax
        $container->setParameter('app.name', '%env(APP_NAME)%');
        
        $container->register(SimpleConfig::class, SimpleConfig::class)
            ->addTag('di.map_config')
            ->setPublic(true);
        
        $pass = new MapConfigPass();
        $pass->process($container);
        
        // Verify the parameter reference is set (not resolved yet)
        $definition = $container->getDefinition(SimpleConfig::class);
        $arguments = $definition->getArguments();
        $this->assertSame('%app.name%', $arguments['$name']);
    }

    public function testProcessWithSettersAndPublicProperties()
    {
        $container = new ContainerBuilder();

        $container->setParameter('setter_config', [
            'name' => 'Main',
            'public_property' => 'PublicVal',
            'setter_value' => 'SetterVal',
        ]);

        $container->register(SetterConfig::class, SetterConfig::class)
            ->addTag('di.map_config')
            ->setPublic(true);

        $pass = new MapConfigPass();
        $pass->process($container);

        $container->compile();

        $config = $container->get(SetterConfig::class);
        $this->assertInstanceOf(SetterConfig::class, $config);
        $this->assertSame('Main', $config->name);
        $this->assertSame('PublicVal', $config->publicProperty);
        $this->assertSame('SetterVal', $config->getSetterValue());
    }

    public function testProcessNestedObject()
    {
        $container = new ContainerBuilder();

        $container->setParameter('nested_config', [
            'name' => 'Parent',
            'sub' => [
                'sub_value' => 'Child',
                'count' => 5,
            ],
        ]);

        $container->register(NestedConfig::class, NestedConfig::class)
            ->addTag('di.map_config')
            ->setPublic(true);

        // SubConfig is NOT registered as a service, it should be inlined
        
        $pass = new MapConfigPass();
        $pass->process($container);

        $container->compile();

        $config = $container->get(NestedConfig::class);
        $this->assertInstanceOf(NestedConfig::class, $config);
        $this->assertSame('Parent', $config->name);
        $this->assertSame('Child', $config->sub->subValue);
        $this->assertSame(5, $config->sub->count);
    }

    public function testProcessEnum()
    {
        if (PHP_VERSION_ID < 80100) {
            $this->markTestSkipped('Enums are supported only on PHP 8.1+');
        }

        $container = new ContainerBuilder();

        $container->setParameter('enum_config', [
            'mode' => 'dev',
        ]);

        $container->register(EnumConfig::class, EnumConfig::class)
            ->addTag('di.map_config')
            ->setPublic(true);

        $pass = new MapConfigPass();
        $pass->process($container);

        $container->compile();

        $config = $container->get(EnumConfig::class);
        $this->assertInstanceOf(EnumConfig::class, $config);
        $this->assertSame(ModeEnum::DEV, $config->mode);
    }
}
