<?php

use Crescat\SaloonSdkGenerator\CodeGenerator;
use Crescat\SaloonSdkGenerator\Data\Generator\Config;
use Crescat\SaloonSdkGenerator\Parsers\OpenApiParser;
use Timatic\JsonApiSdk\Generators\JsonApiConnectorGenerator;
use Timatic\JsonApiSdk\Generators\JsonApiDtoGenerator;
use Timatic\JsonApiSdk\Generators\JsonApiPestTestGenerator;
use Timatic\JsonApiSdk\Generators\JsonApiRequestGenerator;
use Timatic\JsonApiSdk\Generators\JsonApiResourceGenerator;

test('generated mutation tests assert the hydrated response DTO', function () {
    // Given
    $config = new Config(connectorName: 'TestConnector', namespace: 'Test\\Sdk', dtoNamespaceSuffix: 'DataTransferObjects');
    $codeGenerator = new CodeGenerator(
        config: $config,
        requestGenerator: new JsonApiRequestGenerator($config),
        resourceGenerator: new JsonApiResourceGenerator($config),
        dtoGenerator: new JsonApiDtoGenerator($config),
        connectorGenerator: new JsonApiConnectorGenerator($config),
        postProcessors: [new JsonApiPestTestGenerator],
    );

    // When
    $result = $codeGenerator->run(OpenApiParser::build(__DIR__.'/../fixtures/sample-api.yaml')->parse());

    // Then
    $articlesTest = collect($result->additionalFiles)->firstWhere('path', 'tests/Requests/ArticlesTest.php')->file;

    expect($articlesTest)
        ->toContain("'data' => ['type' => 'articles', 'id' => 'mock-id', 'attributes' => []]")
        ->toContain('expect($response->dto())->toBeInstanceOf(\Test\Sdk\DataTransferObjects\Article::class);');
});
