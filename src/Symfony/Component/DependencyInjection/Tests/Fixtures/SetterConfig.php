<?php

namespace Symfony\Component\DependencyInjection\Tests\Fixtures;

use Symfony\Component\DependencyInjection\Attribute\MapConfig;

#[MapConfig(entry: 'setter_config')]
class SetterConfig
{
    public $publicProperty;
    
    private $setterValue;
    
    public function __construct(
        public readonly string $name,
    ) {
    }
    
    public function setSetterValue($value): void
    {
        $this->setterValue = $value;
    }
    
    public function getSetterValue()
    {
        return $this->setterValue;
    }
}
