<?php

require_once 'vendor/autoload.php';

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Compiler\MapConfigPass;
use Symfony\Component\DependencyInjection\Tests\Fixtures\SetterConfig;

// Ensure we have the fixture loaded or use logic that matches it
// SetterConfig has no types on properties/methods!

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

try {
    $pass->process($container);
    $container->compile();
    $config = $container->get(SetterConfig::class);
    
    echo "Name: " . $config->name . "\n";
    echo "PublicProperty: " . $config->publicProperty . "\n";
    echo "SetterValue: " . $config->getSetterValue() . "\n";
} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
