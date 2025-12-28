<?php

namespace Symfony\Component\DependencyInjection\Tests\Fixtures;

enum ModeEnum: string
{
    case DEV = 'dev';
    case PROD = 'prod';
}
