<?php

use JsonApiSdk\Generators\JsonApiDtoGenerator;
use JsonApiSdk\Generators\JsonApiRequestGenerator;
use JsonApiSdk\Generators\JsonApiConnectorGenerator;
use JsonApiSdk\Generators\JsonApiResourceGenerator;
use JsonApiSdk\Generators\JsonApiFactoryGenerator;
use JsonApiSdk\Generators\JsonApiPestTestGenerator;
use JsonApiSdk\Foundation\Hydration\Model;
use JsonApiSdk\Foundation\Hydration\Hydrator;
use JsonApiSdk\Foundation\Filtering\Operator;
use JsonApiSdk\Foundation\Pagination\JsonApiPaginator;
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
