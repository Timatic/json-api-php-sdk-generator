<?php

use Timatic\JsonApiSdk\Generators\JsonApiDtoGenerator;
use Timatic\JsonApiSdk\Generators\JsonApiRequestGenerator;
use Timatic\JsonApiSdk\Generators\JsonApiConnectorGenerator;
use Timatic\JsonApiSdk\Generators\JsonApiResourceGenerator;
use Timatic\JsonApiSdk\Generators\JsonApiFactoryGenerator;
use Timatic\JsonApiSdk\Generators\JsonApiPestTestGenerator;
use Timatic\JsonApiSdk\Foundation\Hydration\Model;
use Timatic\JsonApiSdk\Foundation\Hydration\Hydrator;
use Timatic\JsonApiSdk\Foundation\Filtering\Operator;
use Timatic\JsonApiSdk\Foundation\Pagination\JsonApiPaginator;
use Crescat\SaloonSdkGenerator\Data\Generator\Config;

test('generator classes can be instantiated', function () {
    $config = new Config(
        connectorName: 'TestConnector',
        namespace: 'Test\\Sdk',
    );

    expect(new JsonApiDtoGenerator($config))->toBeInstanceOf(JsonApiDtoGenerator::class);
    expect(new JsonApiRequestGenerator($config))->toBeInstanceOf(JsonApiRequestGenerator::class);
    expect(new JsonApiConnectorGenerator($config))->toBeInstanceOf(JsonApiConnectorGenerator::class);
    expect(new JsonApiResourceGenerator($config))->toBeInstanceOf(JsonApiResourceGenerator::class);
    expect(new JsonApiFactoryGenerator($config))->toBeInstanceOf(JsonApiFactoryGenerator::class);
    expect(new JsonApiPestTestGenerator($config))->toBeInstanceOf(JsonApiPestTestGenerator::class);
});

test('foundation classes exist', function () {
    expect(class_exists(Hydrator::class))->toBeTrue();
    expect(class_exists(JsonApiPaginator::class))->toBeTrue();
    expect(enum_exists(Operator::class))->toBeTrue();
});

test('operator enum has expected values', function () {
    expect(Operator::Equals->value)->toBe('eq');
    expect(Operator::NotEquals->value)->toBe('nq');
    expect(Operator::GreaterThan->value)->toBe('gt');
    expect(Operator::LessThan->value)->toBe('lt');
    expect(Operator::Contains->value)->toBe('contains');
});
