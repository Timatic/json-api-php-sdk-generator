<?php

declare(strict_types=1);

namespace Timatic\JsonApiSdk\Generators\TestGenerators;

use Crescat\SaloonSdkGenerator\Data\Generator\ApiSpecification;
use Crescat\SaloonSdkGenerator\Data\Generator\Endpoint;
use Crescat\SaloonSdkGenerator\Data\Generator\GeneratedCode;
use Crescat\SaloonSdkGenerator\Helpers\DtoResolver;
use Crescat\SaloonSdkGenerator\Helpers\NameHelper;
use Illuminate\Support\Str;
use Timatic\JsonApiSdk\Generators\TestGenerators\Traits\DtoAssertions;
use Timatic\JsonApiSdk\Generators\TestGenerators\Traits\DtoHelperTrait;
use Timatic\JsonApiSdk\Generators\TestGenerators\Traits\MockJsonDataTrait;
use Timatic\JsonApiSdk\Generators\TestGenerators\Traits\ResourceTypeExtractorTrait;
use Timatic\JsonApiSdk\Generators\TestGenerators\Traits\TestDataGeneratorTrait;

class CollectionRequestTestGenerator
{
    use DtoAssertions;
    use DtoHelperTrait;
    use MockJsonDataTrait;
    use ResourceTypeExtractorTrait;
    use TestDataGeneratorTrait;

    protected ApiSpecification $specification;

    protected GeneratedCode $generatedCode;

    protected string $namespace;

    public function __construct(
        ApiSpecification $specification,
        GeneratedCode $generatedCode,
        string $namespace,
        DtoResolver $dtoResolver,
    ) {
        $this->specification = $specification;
        $this->generatedCode = $generatedCode;
        $this->namespace = $namespace;
        $this->dtoResolver = $dtoResolver;
    }

    public function isApplicable(Endpoint $endpoint): bool
    {
        return $endpoint->method->isGet() && empty($endpoint->pathParameters);
    }

    public function getStubPath(): string
    {
        return __DIR__.'/stubs/pest-collection-request-test-func.stub';
    }

    public function replaceStubVariables(string $functionStub, Endpoint $endpoint): string
    {
        $filterData = $this->generateFilterChainWithData($endpoint);
        $functionStub = str_replace('{{ filterChain }}', $filterData['chain'], $functionStub);

        if (! empty($filterData['assertions'])) {
            $functionStub = str_replace('{{ filterAssertionBlock }}', $filterData['assertions']."\n\t\t", $functionStub);
        } else {
            $functionStub = str_replace('{{ filterAssertionBlock }}', '', $functionStub);
        }

        $includeData = $this->generateIncludeChainWithData($endpoint);

        if (! empty($filterData['chain']) && ! empty($includeData['chain'])) {
            $functionStub = str_replace('{{ includeChain }}', "\n\t\t".$includeData['chain'], $functionStub);
        } else {
            $functionStub = str_replace('{{ includeChain }}', $includeData['chain'], $functionStub);
        }

        if (! empty($includeData['assertion'])) {
            $functionStub = str_replace('{{ includeAssertion }}', $includeData['assertion'], $functionStub);
        } else {
            $functionStub = str_replace('{{ includeAssertion }}', '', $functionStub);
        }

        if (! empty($includeData['relationshipAssertions'])) {
            $functionStub = str_replace('{{ relationshipAssertions }}', "\n\t\t".$includeData['relationshipAssertions'], $functionStub);
        } else {
            $functionStub = str_replace('{{ relationshipAssertions }}', '', $functionStub);
        }

        $nonFilterParams = $this->getNonFilterQueryParameters($endpoint);
        $functionStub = str_replace('{{ nonFilterParams }}', $nonFilterParams, $functionStub);

        $mockData = $this->generateMockData($endpoint);
        $mockResponseBody = $this->formatArrayAsPhp($mockData);

        $functionStub = preg_replace(
            "/MockResponse::fixture\('[^']+'\)/",
            "MockResponse::make($mockResponseBody, 200)",
            $functionStub
        );

        $dtoAssertions = $this->generateDtoAssertions($mockData);

        if (str_starts_with(trim($dtoAssertions), '//')) {
            $pattern = '/(.*\$response->status\(\)\)->toBe\(200\);.*?)(\n\s*\$dtoCollection = \$response->dto\(\);.*?{{ dtoAssertions }};)/s';
            $functionStub = preg_replace($pattern, '$1', $functionStub);
        } else {
            $functionStub = str_replace('{{ dtoAssertions }}', $dtoAssertions, $functionStub);
        }

        return $functionStub;
    }

    public function generateMockData(Endpoint $endpoint): array
    {
        $dtoClassName = $this->getDtoClassName($endpoint);
        $attributes = $this->generateMockAttributesFromDto($dtoClassName);
        if (empty($attributes) || $attributes === ['name' => 'Mock value']) {
            throw new \RuntimeException("DTO '{$dtoClassName}' has no properties - skipping test generation");
        }

        $resourceType = $this->getResourceTypeFromEndpoint($endpoint);

        $relationships = $this->getRelationshipsFromSchema($dtoClassName);
        $includeData = $this->generateIncludeChainWithData($endpoint);
        $hasIncludes = ! empty($includeData['chain']);

        $data = [
            'data' => [
                [
                    'type' => $resourceType,
                    'id' => 'mock-id-1',
                    'attributes' => $attributes,
                ],
                [
                    'type' => $resourceType,
                    'id' => 'mock-id-2',
                    'attributes' => $attributes,
                ],
            ],
        ];

        if ($hasIncludes && ! empty($relationships)) {
            $included = [];
            $relationshipsData = [];

            foreach ($relationships as $index => $relationName) {
                $relatedModel = $this->detectRelatedModel($relationName);

                if (! isset($this->specification->components->schemas[$relatedModel])) {
                    continue;
                }

                $relationType = strtolower(Str::plural($relatedModel));

                $isMany = Str::plural($relationName) === $relationName;

                if ($isMany) {
                    $relationshipsData[$relationName] = [
                        'data' => [
                            ['type' => $relationType, 'id' => "related-{$relationName}-1"],
                        ],
                    ];
                } else {
                    $relationshipsData[$relationName] = [
                        'data' => ['type' => $relationType, 'id' => "related-{$relationName}-1"],
                    ];
                }

                $included[] = [
                    'type' => $relationType,
                    'id' => "related-{$relationName}-1",
                    'attributes' => [],
                ];
            }

            $data['data'][0]['relationships'] = $relationshipsData;
            $data['data'][1]['relationships'] = $relationshipsData;
            $data['included'] = $included;
        }

        return $data;
    }

    protected function generateFilterChainWithData(Endpoint $endpoint): array
    {
        $filters = [];
        $assertions = [];
        $maxFilters = 3;
        $seenProperties = [];

        foreach ($endpoint->queryParameters as $parameter) {
            if (count($filters) >= $maxFilters) {
                break;
            }

            if (! str_starts_with($parameter->name, 'filter[')) {
                continue;
            }

            preg_match('/filter\[([^\]]+)\](?:\[([^\]]+)\])?/', $parameter->name, $matches);
            $property = $matches[1] ?? null;
            $operator = $matches[2] ?? null;

            if (! $property) {
                continue;
            }

            if (isset($seenProperties[$property])) {
                continue;
            }

            if (! $operator) {
                $value = $this->formatAsCode($this->generateValue($property));
                $filters[] = "->filter('{$property}', {$value})";

                // Generate assertion for this filter
                $assertions[] = $this->generateFilterAssertion($property, $value);

                $seenProperties[$property] = true;
            }
        }

        return [
            'chain' => implode("\n\t\t", $filters),
            'assertions' => implode("\n\t\t", $assertions),
        ];
    }

    protected function generateFilterAssertion(string $property, string $value): string
    {
        if ($value === 'true' || $value === 'false') {
            return "expect(\$query)->toHaveKey('filter[{$property}]', {$value});";
        }

        $assertionValue = trim($value, "'");

        return "expect(\$query)->toHaveKey('filter[{$property}]', '{$assertionValue}');";
    }

    protected function getNonFilterQueryParameters(Endpoint $endpoint): string
    {
        $params = [];

        foreach ($endpoint->queryParameters as $parameter) {
            if (! str_starts_with($parameter->name, 'filter[') && $parameter->name !== 'include') {
                $paramName = NameHelper::safeVariableName($parameter->name);
                $value = match ($parameter->type) {
                    'string' => "'test string'",
                    'int', 'integer' => '123',
                    'bool', 'boolean' => 'true',
                    'array' => '[]',
                    default => 'null',
                };
                $params[$paramName] = "{$paramName}: {$value}";
            }
        }

        return implode(', ', array_values($params));
    }

    protected function hasIncludeParameter(Endpoint $endpoint): bool
    {
        foreach ($endpoint->queryParameters as $param) {
            if ($param->name === 'include') {
                return true;
            }
        }

        return false;
    }

    protected function getRelationshipsFromSchema(string $dtoClassName): array
    {
        if (! isset($this->specification->components->schemas[$dtoClassName])) {
            return [];
        }

        $schema = $this->specification->components->schemas[$dtoClassName];

        if (! isset($schema->properties['relationships'])) {
            return [];
        }

        $relationships = $schema->properties['relationships'];

        if (! isset($relationships->properties) || ! is_array($relationships->properties)) {
            return [];
        }

        return array_keys($relationships->properties);
    }

    protected function generateIncludeChainWithData(Endpoint $endpoint): array
    {
        if (! $this->hasIncludeParameter($endpoint)) {
            return [
                'chain' => '',
                'assertion' => '',
                'relationshipAssertions' => '',
            ];
        }

        $dtoClassName = $this->getDtoClassName($endpoint);
        $relationships = $this->getRelationshipsFromSchema($dtoClassName);

        if (empty($relationships)) {
            return [
                'chain' => '',
                'assertion' => '',
                'relationshipAssertions' => '',
            ];
        }

        $testRelationships = array_filter($relationships, function ($relationName) {
            $relatedModel = $this->detectRelatedModel($relationName);

            return isset($this->specification->components->schemas[$relatedModel]);
        });

        if (empty($testRelationships)) {
            return [
                'chain' => '',
                'assertion' => '',
                'relationshipAssertions' => '',
            ];
        }

        $includeCalls = [];
        foreach ($testRelationships as $relationName) {
            $methodName = 'include'.Str::studly($relationName);
            $includeCalls[] = "->{$methodName}()";
        }

        $includeChain = implode("\n\t\t", $includeCalls);

        $expectedInclude = implode(',', $testRelationships);
        $assertion = "expect(\$query)->toHaveKey('include', '{$expectedInclude}');";
        $relationshipAssertions = $this->generateRelationshipAssertions($testRelationships);

        return [
            'chain' => $includeChain,
            'assertion' => $assertion,
            'relationshipAssertions' => $relationshipAssertions,
        ];
    }

    protected function detectRelatedModel(string $relationName): string
    {
        return Str::studly(Str::singular($relationName));
    }

    protected function generateRelationshipAssertions(array $relationships): string
    {
        $assertions = [];

        foreach ($relationships as $relationName) {
            $relatedModel = $this->detectRelatedModel($relationName);
            $propertyName = Str::camel($relationName);

            if (! isset($this->specification->components->schemas[$relatedModel])) {
                continue;
            }

            $isMany = Str::plural($relationName) === $relationName;

            if ($isMany) {
                $assertions[] = "->{$propertyName}->not->toBeNull()";
            } else {
                $assertions[] = "->{$propertyName}->toBeInstanceOf(\\{$this->namespace}\\DataTransferObjects\\{$relatedModel}::class)";
            }
        }

        return implode("\n\t\t", $assertions);
    }
}
