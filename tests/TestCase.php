<?php

namespace Timatic\JsonApiSdk\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Saloon\Laravel\SaloonServiceProvider;
use Spatie\LaravelData\LaravelDataServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            SaloonServiceProvider::class,
            LaravelDataServiceProvider::class,
        ];
    }
}
