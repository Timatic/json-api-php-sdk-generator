<?php

declare(strict_types=1);

namespace JsonApiSdk\Generators\TestGenerators;

use Crescat\SaloonSdkGenerator\Data\Generator\ApiSpecification;
use Crescat\SaloonSdkGenerator\Data\Generator\Endpoint;
use Crescat\SaloonSdkGenerator\Data\Generator\GeneratedCode;
use JsonApiSdk\Generators\TestGenerators\Traits\DtoAssertions;
use JsonApiSdk\Generators\TestGenerators\Traits\DtoHelperTrait;
use JsonApiSdk\Generators\TestGenerators\Traits\MockJsonDataTrait;
use JsonApiSdk\Generators\TestGenerators\Traits\ResourceTypeExtractorTrait;
use JsonApiSdk\Generators\TestGenerators\Traits\TestDataGeneratorTrait;

class SingularGetRequestTestGenerator
{
    use DtoAssertions;
    use DtoHelperTrait;
    use MockJsonDataTrait;
    use ResourceTypeExtractorTrait;
    use TestDataGeneratorTrait;

    protected ApiSpecification $specification;

    protected GeneratedCode $generatedCode;

    public function __construct(ApiSpecification $specification, GeneratedCode $generatedCode)
    {
        $this->specification = $specification;
        $this->generatedCode = $generatedCode;
    }

    /**
     * Check if endpoint is a singular GET request (GET with path parameters)
     */
    public function isSingularGetRequest(Endpoint $endpoint): bool
    {
        // GET requests WITH path parameters are singular GET requests
        return $endpoint->method->isGet() && ! empty($endpoint->pathParameters);
    }

    /**
     * Get the stub path for singular GET request tests
     */
    public function getStubPath(): string
    {
        return __DIR__.'/stubs/pest-singular-get-request-test-func.stub';
    }

    /**
     * Replace stub variables with singular GET-specific content
     */
    public function replaceStubVariables(string $functionStub, Endpoint $endpoint): string
    {
        // Generate mock response body
        $mockData = $this->generateMockData($endpoint);
        $mockResponseBody = $this->formatArrayAsPhp($mockData);

        $functionStub = str_replace(
            '{{ mockResponse }}',
            "MockResponse::make($mockResponseBody, 200)",
            $functionStub
        );

        // Generate DTO assertions based on mock data
        $mockData = $this->generateMockData($endpoint);
        $dtoAssertions = $this->generateDtoAssertions($mockData);

        // If no valid assertions (comments only), remove the DTO validation block entirely
        if (str_starts_with(trim($dtoAssertions), '//')) {
            // Remove the entire DTO validation block
            $pattern = '/(.*\$response->status\(\)\)->toBe\(200\);.*?)(\n\s*\$dto = \$response->dto\(\);.*?{{ dtoAssertions }};)/s';
            $functionStub = preg_replace($pattern, '$1', $functionStub);
        } else {
            $functionStub = str_replace('{{ dtoAssertions }}', $dtoAssertions, $functionStub);
        }

        return $functionStub;
    }

    /**
     * Generate mock data for singular GET response
     */
    public function generateMockData(Endpoint $endpoint): array
    {
        // Get DTO class name from endpoint
        $dtoClassName = $this->getDtoClassName($endpoint);

        // Generate mock data based on DTO - must have properties
        $attributes = $this->generateMockAttributesFromDto($dtoClassName);

        $resourceType = $this->getResourceTypeFromEndpoint($endpoint);

        return [
            'data' => [
                'type' => $resourceType,
                'id' => 'mock-id-123',
                'attributes' => $attributes,
            ],
        ];
    }
}
