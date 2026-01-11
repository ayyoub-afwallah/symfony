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
use Symfony\Component\DependencyInjection\Attribute\MapParameters;
use Symfony\Component\DependencyInjection\Compiler\MapParametersPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\BaseConstraintValidator;
use Symfony\Component\Validator\Constraints\NotBlank;

class MapParametersPassTest extends TestCase
{
    public function testProcessMapParameters()
    {
        $container = new ContainerBuilder();
        $container->setParameter('app.config.name', 'test_name');
        $container->setParameter('app.config.timeout', 30);
        
        $container->register(TestConfig::class)
            ->setPublic(true)
            ->addTag('di.map_parameters');

        $pass = new MapParametersPass();
        $pass->process($container);

        $definition = $container->getDefinition(TestConfig::class);
        $this->assertEquals('test_name', $definition->getArgument('$name'));
        $this->assertEquals(30, $definition->getArgument('$timeout'));
    }

    public function testProcessNestedMapParameters()
    {
        $container = new ContainerBuilder();
        $container->setParameter('app.nested', [
            'name' => 'nested_app',
            'database' => [
                'host' => 'localhost',
                'port' => 5432,
            ]
        ]);

        $container->register(NestedRootConfig::class)
            ->setPublic(true)
            ->addTag('di.map_parameters');

        $pass = new MapParametersPass();
        $pass->process($container);

        $definition = $container->getDefinition(NestedRootConfig::class);
        
        // $name should be mapped
        $this->assertEquals('nested_app', $definition->getArgument('$name'));
        
        // $database should be a Definition for NestedDbConfig
        $dbArg = $definition->getArgument('$database');
        $this->assertInstanceOf(\Symfony\Component\DependencyInjection\Definition::class, $dbArg);
        $this->assertEquals(NestedDbConfig::class, $dbArg->getClass());
        
        // Check arguments of the nested definition
        $this->assertEquals('localhost', $dbArg->getArgument('$host'));
        $this->assertEquals(5432, $dbArg->getArgument('$port'));
    }

    public function testValidationErrorAggregation()
    {
        $container = new ContainerBuilder();
        // Validation requires the validator service 
        $validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
        $container->set('validator', $validator);

        // Invalid config for ValidatedConfig1 (missing required param 'name' from container entirely)
        // Actually MapParametersPass throws missing param exception if not found.
        // Let's provide empty array to trigger NotBlank if possible, 
        // OR provide key but empty value.
        $container->setParameter('app.valid1', ['name' => '']); // Should fail NotBlank

        // Invalid config for ValidatedConfig2
        $container->setParameter('app.valid2', ['port' => 'not_int']); // Should fail validation if we had strict types, 
        // but here let's use a constraint.
        // Let's say ValidatedConfig2 has a 'port' that must be positive. 
        // We'll use a custom constraint or just NotBlank for simplicity.

        $container->register(ValidatedConfig1::class)->addTag('di.map_parameters');
        $container->register(ValidatedConfig2::class)->addTag('di.map_parameters');

        $pass = new MapParametersPass();

        try {
            $pass->process($container);
            $this->fail('Expected InvalidArgumentException was not thrown');
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
            $this->assertStringContainsString('Service "Symfony\Component\DependencyInjection\Tests\Compiler\ValidatedConfig1"', $message);
            $this->assertStringContainsString('Service "Symfony\Component\DependencyInjection\Tests\Compiler\ValidatedConfig2"', $message);
            $this->assertStringContainsString('This value should not be blank', $message);
        }
    }

    public function testCircularReferenceDetection()
    {
        $container = new ContainerBuilder();
        $container->setParameter('app.circular', [
            'child' => [
                'parent' => [] // This would imply recursion if we tried to map it back to parent type
            ]
        ]);
        
        // Note: MapParametersPass detects circular *class* references during recursion.
        // We need a structure where A has B, and B has A.
        
        $container->register(CircularA::class)->addTag('di.map_parameters');

        $pass = new MapParametersPass();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Circular reference detected');
        
        $pass->process($container);
    }

    public function testStrictUnmappedKeys()
    {
        $container = new ContainerBuilder();
        $container->setParameter('app.strict', [
            'name' => 'ok',
            'extra_key' => 'shouid_fail'
        ]);

        $container->register(StrictConfig::class)->addTag('di.map_parameters');

        $pass = new MapParametersPass();

        try {
            $pass->process($container);
            $this->fail('Expected exception for unmapped keys');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('unrecognized configuration keys: "extra_key"', $e->getMessage());
        }
    }

    public function testPreNormalization()
    {
        // Verify snake_case config maps to camelCase property
        $container = new ContainerBuilder();
        $container->setParameter('app.snake', [
            'some_complicated_key' => 'value'
        ]);

        $container->register(SnakeConfig::class)->addTag('di.map_parameters');

        $pass = new MapParametersPass();
        $pass->process($container);

        $definition = $container->getDefinition(SnakeConfig::class);
        $this->assertEquals('value', $definition->getArgument('$someComplicatedKey'));
    }

    public function testCollectionSupportWithPromotedProperties()
    {
        $container = new ContainerBuilder();
        $container->setParameter('api.config', [
            'endpoints' => [
                ['url' => 'https://api.example.com/v1', 'timeout' => 30],
                ['url' => 'https://api.example.com/v2', 'timeout' => 45],
            ]
        ]);

        $container->register(CollectionConfig::class)->addTag('di.map_parameters');

        $pass = new MapParametersPass();
        $pass->process($container);

        $definition = $container->getDefinition(CollectionConfig::class);
        $endpointsArg = $definition->getArgument('$endpoints');
        
        $this->assertIsArray($endpointsArg);
        $this->assertCount(2, $endpointsArg);
        $this->assertInstanceOf(\Symfony\Component\DependencyInjection\Definition::class, $endpointsArg[0]);
        $this->assertEquals(EndpointItem::class, $endpointsArg[0]->getClass());
        $this->assertEquals('https://api.example.com/v1', $endpointsArg[0]->getArgument('$url'));
        $this->assertEquals(30, $endpointsArg[0]->getArgument('$timeout'));
    }

    public function testDefaultParameterValuesWithNull()
    {
        $container = new ContainerBuilder();
        $container->setParameter('db.config', [
            'host' => 'localhost',
            'port' => null,  // Explicit null should use default
            'ssl' => null,   // Explicit null should use default
        ]);

        $container->register(DatabaseConfigWithDefaults::class)->addTag('di.map_parameters');

        $pass = new MapParametersPass();
        $pass->process($container);

        $definition = $container->getDefinition(DatabaseConfigWithDefaults::class);
        
        // host should be set
        $this->assertEquals('localhost', $definition->getArgument('$host'));
        
        // port and ssl should NOT be in arguments (will use constructor defaults)
        $this->assertFalse($definition->hasArgument('$port'));
        $this->assertFalse($definition->hasArgument('$ssl'));
    }

    public function testNestedObjectValidation()
    {
        $container = new ContainerBuilder();
        $validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
        $container->set('validator', $validator);

        $container->setParameter('app.nested_validated', [
            'name' => 'app',
            'server' => [
                'host' => '',  // Should fail NotBlank validation
                'port' => 8080,
            ]
        ]);

        $container->register(NestedValidatedRoot::class)->addTag('di.map_parameters');

        $pass = new MapParametersPass();

        try {
            $pass->process($container);
            $this->fail('Expected validation error for nested object');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('NestedValidatedRoot', $e->getMessage());
            $this->assertStringContainsString('This value should not be blank', $e->getMessage());
        }
    }

    public function testEnumMapping()
    {
        $container = new ContainerBuilder();
        $container->setParameter('log.config', [
            'level' => 'error',
        ]);

        $container->register(LogConfig::class)->addTag('di.map_parameters');

        $pass = new MapParametersPass();
        $pass->process($container);

        $definition = $container->getDefinition(LogConfig::class);
        $levelArg = $definition->getArgument('$level');
        
        $this->assertInstanceOf(\Symfony\Component\DependencyInjection\Definition::class, $levelArg);
        $this->assertEquals(LogLevel::class, $levelArg->getClass());
        $this->assertEquals([LogLevel::class, 'tryFrom'], $levelArg->getFactory());
    }

    public function testDateTimeMapping()
    {
        $container = new ContainerBuilder();
        $container->setParameter('schedule.config', [
            'start_time' => '2024-01-01 09:00:00',
        ]);

        $container->register(ScheduleConfig::class)->addTag('di.map_parameters');

        $pass = new MapParametersPass();
        $pass->process($container);

        $definition = $container->getDefinition(ScheduleConfig::class);
        $startTimeArg = $definition->getArgument('$startTime');
        
        $this->assertInstanceOf(\Symfony\Component\DependencyInjection\Definition::class, $startTimeArg);
        $this->assertEquals(\DateTime::class, $startTimeArg->getClass());
    }

    public function testSetterMapping()
    {
        $container = new ContainerBuilder();
        $container->setParameter('cache.config', [
            'ttl' => 3600,
        ]);

        $container->register(CacheConfigWithSetter::class)->addTag('di.map_parameters');

        $pass = new MapParametersPass();
        $pass->process($container);

        $definition = $container->getDefinition(CacheConfigWithSetter::class);
        $calls = $definition->getMethodCalls();
        
        $this->assertCount(1, $calls);
        $this->assertEquals('setTtl', $calls[0][0]);
        $this->assertEquals(3600, $calls[0][1][0]);
    }

    public function testPublicPropertyMapping()
    {
        $container = new ContainerBuilder();
        $container->setParameter('feature.config', [
            'enabled' => true,
        ]);

        $container->register(FeatureConfig::class)->addTag('di.map_parameters');

        $pass = new MapParametersPass();
        $pass->process($container);

        $definition = $container->getDefinition(FeatureConfig::class);
        $properties = $definition->getProperties();
        
        $this->assertArrayHasKey('enabled', $properties);
        $this->assertTrue($properties['enabled']);
    }

    public function testDotNotation()
    {
        $container = new ContainerBuilder();
        $container->setParameter('cloud', [
            'aws' => [
                's3' => [
                    'bucket' => 'my-bucket',
                    'region' => 'us-east-1',
                ]
            ]
        ]);

        $container->register(S3Config::class)->addTag('di.map_parameters');

        $pass = new MapParametersPass();
        $pass->process($container);

        $definition = $container->getDefinition(S3Config::class);
        $this->assertEquals('my-bucket', $definition->getArgument('$bucket'));
        $this->assertEquals('us-east-1', $definition->getArgument('$region'));
    }
}

#[MapParameters(entry: 'app.config')]
class TestConfig
{
    public function __construct(
        public string $name,
        public int $timeout,
    ) {}
}

#[MapParameters(entry: 'app.nested')]
class NestedRootConfig
{
    public function __construct(
        public string $name,
        public NestedDbConfig $database,
    ) {}
}

class NestedDbConfig
{
    public function __construct(
        public string $host,
        public int $port,
    ) {}
}

#[MapParameters(entry: 'app.valid1')]
class ValidatedConfig1
{
    public function __construct(
        #[NotBlank]
        public string $name,
    ) {}
}

#[MapParameters(entry: 'app.valid2')]
class ValidatedConfig2
{
    public function __construct(
        #[NotBlank]
        public string $port,
    ) {}
}

#[MapParameters(entry: 'app.circular')]
class CircularA
{
    public function __construct(
        public CircularB $child,
    ) {}
}

class CircularB
{
    public function __construct(
        public CircularA $parent,
    ) {}
}

#[MapParameters(entry: 'app.strict')]
class StrictConfig
{
    public function __construct(
        public string $name,
    ) {}
}

#[MapParameters(entry: 'app.snake')]
class SnakeConfig
{
    public function __construct(
        public string $someComplicatedKey,
    ) {}
}

#[MapParameters(entry: 'api.config')]
class CollectionConfig
{
    /** @var EndpointItem[] */
    public function __construct(
        public readonly array $endpoints,
    ) {}
}

class EndpointItem
{
    public function __construct(
        public readonly string $url,
        public readonly int $timeout,
    ) {}
}

#[MapParameters(entry: 'db.config')]
class DatabaseConfigWithDefaults
{
    public function __construct(
        public readonly string $host,
        public readonly int $port = 3306,
        public readonly bool $ssl = false,
    ) {}
}

#[MapParameters(entry: 'app.nested_validated')]
class NestedValidatedRoot
{
    public function __construct(
        public readonly string $name,
        public readonly NestedValidatedServer $server,
    ) {}
}

class NestedValidatedServer
{
    public function __construct(
        #[NotBlank]
        public readonly string $host,
        public readonly int $port,
    ) {}
}

enum LogLevel: string
{
    case DEBUG = 'debug';
    case INFO = 'info';
    case ERROR = 'error';
}

#[MapParameters(entry: 'log.config')]
class LogConfig
{
    public function __construct(
        public readonly LogLevel $level,
    ) {}
}

#[MapParameters(entry: 'schedule.config')]
class ScheduleConfig
{
    public function __construct(
        public readonly \DateTime $startTime,
    ) {}
}

#[MapParameters(entry: 'cache.config')]
class CacheConfigWithSetter
{
    private int $ttl;

    public function setTtl(int $ttl): void
    {
        $this->ttl = $ttl;
    }
}

#[MapParameters(entry: 'feature.config')]
class FeatureConfig
{
    public bool $enabled = false;
}

#[MapParameters(entry: 'cloud.aws.s3')]
class S3Config
{
    public function __construct(
        public readonly string $bucket,
        public readonly string $region,
    ) {}
}
