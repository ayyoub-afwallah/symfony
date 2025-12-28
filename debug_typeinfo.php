<?php

require_once 'vendor/autoload.php';

use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;
use Symfony\Component\TypeInfo\Type\ObjectType;

class DebugTarget
{
    public $noType;
    public string $stringType;
}

try {
    $resolver = TypeResolver::create();
    $reflectionClass = new ReflectionClass(DebugTarget::class);
    
    echo "Resolving 'stringType'...\n";
    $type = $resolver->resolve($reflectionClass->getProperty('stringType'));
    echo "Resolved: " . get_class($type) . "\n";
    
    echo "Resolving 'noType'...\n";
    $type = $resolver->resolve($reflectionClass->getProperty('noType'));
    echo "Resolved: " . get_class($type) . "\n";
    
} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
