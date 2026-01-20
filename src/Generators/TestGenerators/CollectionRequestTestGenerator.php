<?php

declare(strict_types=1);

namespace JsonApiSdk\Generators\TestGenerators;

use Crescat\SaloonSdkGenerator\Data\Generator\ApiSpecification;
use Crescat\SaloonSdkGenerator\Data\Generator\Endpoint;
use Crescat\SaloonSdkGenerator\Data\Generator\GeneratedCode;
use Crescat\SaloonSdkGenerator\Helpers\NameHelper;
use JsonApiSdk\Generators\TestGenerators\Traits\DtoAssertions;
use JsonApiSdk\Generators\TestGenerators\Traits\DtoHelperTrait;
use JsonApiSdk\Generators\TestGenerators\Traits\MockJsonDataTrait;
use JsonApiSdk\Generators\TestGenerators\Traits\ResourceTypeExtractorTrait;
use JsonApiSdk\Generators\TestGenerators\Traits\TestDataGeneratorTrait;

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

    public function __construct(ApiSpecification $specification, GeneratedCode $generatedCode, string $namespace)
    {
        $this->specification = $specification;
        $this->generatedCode = $generatedCode;
        $this->namespace = $namespace;
    }

    /**
     * Check if endpoint is a GET collection request (implements Paginatable)
     */
    public function isCollectionRequest(Endpoint $endpoint): bool
    {
        // GET requests without path parameters are collection requests
        return $endpoint->method->isGet() && empty($endpoint->pathParameters);
    }

    /**
     * Get the stub path for collection request tests
     */
    public function getStubPath(): string
    {
        return __DIR__.'/stubs/pest-collection-request-test-func.stub';
    }

    /**
     * Replace stub variables with collection-specific content
     */
    public function replaceStubVariables(string $functionStub, Endpoint $endpoint): string
    {
        $filterData = $this->generateFilterChainWithData($endpoint);
        $functionStub = str_replace('{{ filterChain }}', $filterData['chain'], $functionStub);

        // Only include filter assertions block if there are filters
        if (! empty($filterData['assertions'])) {
            $filterAssertionBlock = $this->generateFilterAssertionBlock($filterData['assertions']);
            $functionStub = str_replace('{{ filterAssertionBlock }}', $filterAssertionBlock."\n\t\t", $functionStub);
        } else {
            $functionStub = str_replace('{{ filterAssertionBlock }}', '', $functionStub);
        }

        // Add include chain and assertions
        $includeData = $this->generateIncludeChainWithData($endpoint);

        // Add newline before include chain if there are filters
        if (! empty($filterData['chain']) && ! empty($includeData['chain'])) {
            $functionStub = str_replace('{{ includeChain }}', "\n\t\t".$includeData['chain'], $functionStub);
        } else {
            $functionStub = str_replace('{{ includeChain }}', $includeData['chain'], $functionStub);
        }

        // Only include assertion if there are includes
        if (! empty($includeData['assertion'])) {
            $functionStub = str_replace('{{ includeAssertion }}', $includeData['assertion'], $functionStub);
        } else {
            $functionStub = str_replace('{{ includeAssertion }}', '', $functionStub);
        }

        // Add relationship assertions if there are includes
        if (! empty($includeData['relationshipAssertions'])) {
            $functionStub = str_replace('{{ relationshipAssertions }}', "\n\t\t".$includeData['relationshipAssertions'], $functionStub);
        } else {
            $functionStub = str_replace('{{ relationshipAssertions }}', '', $functionStub);
        }

        // Add non-filter query parameters (like 'include')
        $nonFilterParams = $this->getNonFilterQueryParameters($endpoint);
        $functionStub = str_replace('{{ nonFilterParams }}', $nonFilterParams, $functionStub);

        $mockData = $this->generateMockData($endpoint);
        $mockResponseBody = $this->formatArrayAsPhp($mockData);

        $functionStub = preg_replace(
            "/MockResponse::fixture\('[^']+'\)/",
            "MockResponse::make($mockResponseBody, 200)",
            $functionStub
        );

        // Generate DTO assertions based on mock data
        $mockData = $this->generateMockData($endpoint);
        $dtoAssertions = $this->generateDtoAssertions($mockData);

        // If no valid assertions (comments only), remove the DTO validation block entirely
        if (str_starts_with(trim($dtoAssertions), '//')) {
            // Remove the entire DTO validation block
            $pattern = '/(.*\$response->status\(\)\)->toBe\(200\);.*?)(\n\s*\$dtoCollection = \$response->dto\(\);.*?{{ dtoAssertions }};)/s';
            $functionStub = preg_replace($pattern, '$1', $functionStub);
        } else {
            $functionStub = str_replace('{{ dtoAssertions }}', $dtoAssertions, $functionStub);
        }

        return $functionStub;
    }

    /**
     * Generate mock data for collection response
     */
    public function generateMockData(Endpoint $endpoint): array
    {
        // Get DTO class name from endpoint
        $dtoClassName = $this->getDtoClassName($endpoint);

        // Generate mock data based on DTO - must have properties
        $attributes = $this->generateMockAttributesFromDto($dtoClassName);
        if (empty($attributes) || $attributes === ['name' => 'Mock value']) {
            throw new \RuntimeException("DTO '{$dtoClassName}' has no properties - skipping test generation");
        }

        $resourceType = $this->getResourceTypeFromEndpoint($endpoint);

        // Get relationships for this endpoint
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

        // Add relationships and included data if this endpoint has includes
        if ($hasIncludes && ! empty($relationships)) {
            $included = [];
            $relationshipsData = [];

            foreach ($relationships as $index => $relationName) {
                $relatedModel = $this->detectRelatedModel($relationName);

                // Skip if the related model doesn't exist in the schema
                if (! isset($this->specification->components->schemas[$relatedModel])) {
                    continue;
                }

                $relationType = strtolower(\Illuminate\Support\Str::plural($relatedModel));

                // Check if this is a "Many" relationship
                $isMany = \Illuminate\Support\Str::plural($relationName) === $relationName;

                if ($isMany) {
                    // For "Many" relationships, data should be an array of objects
                    $relationshipsData[$relationName] = [
                        'data' => [
                            ['type' => $relationType, 'id' => "related-{$relationName}-1"],
                        ],
                    ];
                } else {
                    // For "One" relationships, data is a single object
                    $relationshipsData[$relationName] = [
                        'data' => ['type' => $relationType, 'id' => "related-{$relationName}-1"],
                    ];
                }

                // Add to included array with minimal attributes
                $included[] = [
                    'type' => $relationType,
                    'id' => "related-{$relationName}-1",
                    'attributes' => [],
                ];
            }

            // Add relationships to both data items
            $data['data'][0]['relationships'] = $relationshipsData;
            $data['data'][1]['relationships'] = $relationshipsData;

            // Add included array
            $data['included'] = $included;
        }

        return $data;
    }

    /**
     * Generate the complete filter assertion block
     */
    protected function generateFilterAssertionBlock(string $assertions): string
    {
        // Just return the assertions with proper indentation, no wrapper needed
        return $assertions;
    }

    /**
     * Generate a fluent filter chain with 2-3 representative examples and their assertions
     */
    protected function generateFilterChainWithData(Endpoint $endpoint): array
    {
        $filters = [];
        $assertions = [];
        $maxFilters = 3;
        $seenProperties = [];

        // Extract filter parameters from query parameters
        foreach ($endpoint->queryParameters as $parameter) {
            if (count($filters) >= $maxFilters) {
                break;
            }

            // Skip non-filter parameters
            if (! str_starts_with($parameter->name, 'filter[')) {
                continue;
            }

            // Parse filter[property][operator] or filter[property]
            preg_match('/filter\[([^\]]+)\](?:\[([^\]]+)\])?/', $parameter->name, $matches);
            $property = $matches[1] ?? null;
            $operator = $matches[2] ?? null;

            if (! $property) {
                continue;
            }

            // Skip if we already have a filter for this property (avoid duplicates with operators)
            if (isset($seenProperties[$property])) {
                continue;
            }

            // Only add filters without operators (simpler)
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

    /**
     * Generate a filter assertion for the given property and value
     */
    protected function generateFilterAssertion(string $property, string $value): string
    {
        // Handle both string and boolean values
        if ($value === 'true' || $value === 'false') {
            // Boolean values
            return "expect(\$query)->toHaveKey('filter[{$property}]', {$value});";
        }

        // String values - remove quotes for the assertion
        $assertionValue = trim($value, "'");

        return "expect(\$query)->toHaveKey('filter[{$property}]', '{$assertionValue}');";
    }

    /**
     * Get non-filter query parameters (like 'include')
     */
    protected function getNonFilterQueryParameters(Endpoint $endpoint): string
    {
        $params = [];

        foreach ($endpoint->queryParameters as $parameter) {
            // Skip filter parameters and include parameter (handled by HasIncludes trait)
            if (! str_starts_with($parameter->name, 'filter[') && $parameter->name !== 'include') {
                $paramName = NameHelper::safeVariableName($parameter->name);
                $value = match ($parameter->type) {
                    'string' => "'test string'",
                    'int', 'integer' => '123',
                    'bool', 'boolean' => 'true',
                    'array' => '[]',
                    default => 'null',
                };
                $params[] = "{$paramName}: {$value}";
            }
        }

        return implode(', ', $params);
    }

    /**
     * Check if endpoint has include query parameter
     */
    protected function hasIncludeParameter(Endpoint $endpoint): bool
    {
        foreach ($endpoint->queryParameters as $param) {
            if ($param->name === 'include') {
                return true;
            }
        }

        return false;
    }

    /**
     * Get relationships from the DTO schema
     */
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

    /**
     * Generate include method chain with data for testing
     */
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

        // Filter out relationships where the model doesn't exist
        $testRelationships = array_filter($relationships, function ($relationName) {
            $relatedModel = $this->detectRelatedModel($relationName);

            return isset($this->specification->components->schemas[$relatedModel]);
        });

        // Generate include method calls
        $includeCalls = [];
        foreach ($testRelationships as $relationName) {
            $methodName = 'include'.\Illuminate\Support\Str::studly($relationName);
            $includeCalls[] = "->{$methodName}()";
        }

        $includeChain = implode("\n\t\t", $includeCalls);

        // Generate assertion for include parameter
        $expectedInclude = implode(',', $testRelationships);
        $assertion = "expect(\$query)->toHaveKey('include', '{$expectedInclude}');";

        // Generate relationship hydration assertions
        $relationshipAssertions = $this->generateRelationshipAssertions($testRelationships);

        return [
            'chain' => $includeChain,
            'assertion' => $assertion,
            'relationshipAssertions' => $relationshipAssertions,
        ];
    }

    /**
     * Detect the related model class name from relationship name
     */
    protected function detectRelatedModel(string $relationName): string
    {
        // Convert to singular studly case (e.g., budgetType -> BudgetType, entries -> Entry)
        return \Illuminate\Support\Str::studly(\Illuminate\Support\Str::singular($relationName));
    }

    /**
     * Generate assertions to verify relationships are hydrated
     */
    protected function generateRelationshipAssertions(array $relationships): string
    {
        $assertions = [];

        foreach ($relationships as $relationName) {
            $relatedModel = $this->detectRelatedModel($relationName);
            $propertyName = \Illuminate\Support\Str::camel($relationName);

            // Skip if the related model doesn't exist in the schema
            if (! isset($this->specification->components->schemas[$relatedModel])) {
                continue;
            }

            // Check if this is likely a "Many" relationship (plural name)
            $isMany = \Illuminate\Support\Str::plural($relationName) === $relationName;

            if ($isMany) {
                // For collection relationships, verify it's not null
                $assertions[] = "->{$propertyName}->not->toBeNull()";
            } else {
                // For single relationships, verify it's an instance of the related model
                $assertions[] = "->{$propertyName}->toBeInstanceOf(\\{$this->namespace}\\Dto\\{$relatedModel}::class)";
            }
        }

        return implode("\n\t\t", $assertions);
    }
}
