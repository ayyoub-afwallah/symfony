<?php

require_once 'vendor/autoload.php';

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Compiler\MapConfigPass;
use Symfony\Component\DependencyInjection\Attribute\MapConfig;
use Symfony\Component\DependencyInjection\Definition;

// We need to define classes dynamically or include them if files exist.
// Checking if we can define KebabConfigFixture safely.
if (!class_exists('Symfony\Component\DependencyInjection\Tests\Fixtures\KebabConfigFixture')) {
    eval('
    namespace Symfony\Component\DependencyInjection\Tests\Fixtures;
    use Symfony\Component\DependencyInjection\Attribute\MapConfig;

    #[MapConfig(entry: "app.config")]
    class KebabConfigFixture
    {
        public function __construct(
            public string $kebabCaseKey,
        ) {}
    }
    ');
}

$container = new ContainerBuilder();
$container->setParameter('app.config', [
    'kebab-case-key' => 'Success',
]);

$container->register(\Symfony\Component\DependencyInjection\Tests\Fixtures\KebabConfigFixture::class, \Symfony\Component\DependencyInjection\Tests\Fixtures\KebabConfigFixture::class)
    ->setPublic(true)
    ->addTag('di.map_config');

$pass = new MapConfigPass();

try {
    $pass->process($container);
    $container->compile();
    $service = $container->get(\Symfony\Component\DependencyInjection\Tests\Fixtures\KebabConfigFixture::class);
    echo "Result: " . $service->kebabCaseKey . "\n";
} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
