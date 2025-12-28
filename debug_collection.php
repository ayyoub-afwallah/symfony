<?php
require_once 'vendor/autoload.php';
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;
use Symfony\Component\TypeInfo\Type\CollectionType;
use Symfony\Component\TypeInfo\Type\GenericType;

class CollectionTarget {
    /** @var \Symfony\Component\DependencyInjection\Tests\Fixtures\SetterConfig[] */
    public array $list;
}

$resolver = TypeResolver::create();
$ref = new ReflectionProperty(CollectionTarget::class, 'list');
$type = $resolver->resolve($ref);

echo get_class($type) . "\n";
if ($type instanceof CollectionType || $type instanceof GenericType) {
   echo "Wrapped Type: " . get_class($type instanceof CollectionType ? $type->getCollectionKeyType() : $type->getVariableTypes()[0]) . "\n"; 
   // Note: exact method depends on TypeInfo version, checking properties via var_dump if needed
   print_r($type);
}
