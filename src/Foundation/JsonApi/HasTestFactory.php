<?php

declare(strict_types=1);

namespace Timatic\JsonApiSdk\Foundation\JsonApi;

trait HasTestFactory
{
    public static function testFactory(): mixed
    {
        $factoryClass = str_replace('\\Dto\\', '\\Factories\\', static::class).'Factory';

        if (class_exists($factoryClass)) {
            return $factoryClass::new();
        }

        throw new \RuntimeException("Factory [{$factoryClass}] not found for [".static::class."].");
    }
}
