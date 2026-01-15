<?php

declare(strict_types=1);

namespace JsonApiSdk\Generators\TestGenerators;

use Crescat\SaloonSdkGenerator\Data\Generator\ApiSpecification;
use Crescat\SaloonSdkGenerator\Data\Generator\Endpoint;
use Crescat\SaloonSdkGenerator\Data\Generator\GeneratedCode;
use Crescat\SaloonSdkGenerator\Helpers\NameHelper;
use JsonApiSdk\Generators\TestGenerators\Traits\DtoAssertions;
use JsonApiSdk\Generators\TestGenerators\Traits\DtoHelperTrait;
use JsonApiSdk\Generators\TestGenerators\Traits\ResourceTypeExtractorTrait;
use JsonApiSdk\Generators\TestGenerators\Traits\TestDataGeneratorTrait;

class MutationRequestTestGenerator
{
    use DtoAssertions;
    use DtoHelperTrait;
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
     * Check if endpoint is a mutation request (POST or PATCH)
     */
    public function isMutationRequest(Endpoint $endpoint): bool
    {
        return $endpoint->method->isPost() || $endpoint->method->isPatch();
    }

    /**
     * Get the stub path for mutation request tests
     */
    public function getStubPath(): string
    {
        return __DIR__.'/stubs/pest-mutation-test-func.stub';
    }

    /**
     * Replace stub variables with mutation-specific content
     */
    public function replaceStubVariables(string $functionStub, Endpoint $endpoint): string
    {
        // Generate DTO instantiation code
        $dtoInstantiation = $this->generateDtoInstantiation($endpoint);
        $functionStub = str_replace('{{ dtoInstantiation }}', $dtoInstantiation, $functionStub);

        // Generate body validation code
        $bodyValidation = $this->generateBodyValidation($endpoint);
        $functionStub = str_replace('{{ bodyValidation }}', $bodyValidation, $functionStub);

        // Generate method arguments (including $dto)
        $methodArguments = $this->generateMethodArguments($endpoint);
        $functionStub = str_replace('{{ mutationMethodArguments }}', $methodArguments, $functionStub);

        return $functionStub;
    }

    /**
     * Generate method arguments for resource method call
     */
    protected function generateMethodArguments(Endpoint $endpoint): string
    {
        $args = [];

        // Add path parameters first (e.g., budgetId for PATCH)
        foreach ($endpoint->pathParameters as $param) {
            $paramName = NameHelper::safeVariableName($param->name);
            // Add 'Id' suffix if not already present
            if (! str_ends_with($paramName, 'Id')) {
                $paramName .= 'Id';
            }

            // Generate typed value based on parameter type
            $value = $this->generateValue($paramName, $param->type);
            $formattedValue = $this->formatAsCode($value);
            $args[] = "{$paramName}: {$formattedValue}";
        }

        // Add $dto parameter last - use named argument if there are path params
        if (empty($endpoint->pathParameters)) {
            $args[] = '$dto';
        } else {
            $args[] = 'data: $dto';
        }

        return implode(', ', $args);
    }

    /**
     * Generate DTO instantiation code with sample data using factory
     */
    protected function generateDtoInstantiation(Endpoint $endpoint): string
    {
        $dtoClassName = $this->getDtoClassName($endpoint);
        $stateArray = $this->generateFactoryStateArray($endpoint);

        $lines = [];
        $lines[] = "    \$dto = \\{$this->namespace}\\Dto\\{$dtoClassName}::factory()->state([";
        $lines[] = $stateArray;
        $lines[] = '    ])->make();';

        return implode("\n", $lines);
    }

    /**
     * Generate factory state array with test data
     */
    protected function generateFactoryStateArray(Endpoint $endpoint): string
    {
        $filteredProperties = $this->getFilteredPropertiesForTest($endpoint);

        $lines = [];

        foreach ($filteredProperties as $propInfo) {
            $propName = $propInfo['name'];

            if ($propInfo['isDateTime']) {
                // Generate Carbon::parse() for DateTime fields
                $dateString = $this->generateValue($propName, $propInfo['type']);
                $lines[] = "        '{$propName}' => \\Carbon\\Carbon::parse('{$dateString}'),";
            } else {
                $value = $this->formatAsCode($this->generateValue($propName, $propInfo['type']));
                $lines[] = "        '{$propName}' => {$value},";
            }
        }

        // Fallback if no properties after filtering
        if (empty($lines)) {
            return "        'name' => 'test value',";
        }

        return implode("\n", $lines);
    }

    /**
     * Filter properties for test generation (skip timestamps, check DateTime)
     *
     * @return array<array{name: string, type: ?string, isDateTime: bool}>
     */
    protected function getFilteredPropertiesForTest(Endpoint $endpoint): array
    {
        $dtoClassName = $this->getDtoClassName($endpoint);
        $properties = $this->getDtoPropertiesFromGeneratedCode($dtoClassName);

        $filtered = [];

        foreach ($properties as $propInfo) {
            $propName = $propInfo['name'];

            // Skip read-only/auto-managed fields
            if (in_array($propName, ['id', 'createdAt', 'updatedAt', 'deletedAt'])) {
                continue;
            }

            // Check if this is a Carbon/DateTime field
            $isDateTime = $propInfo['type'] && str_contains($propInfo['type'], 'Carbon');

            $filtered[] = [
                'name' => $propName,
                'type' => $propInfo['type'],
                'isDateTime' => $isDateTime,
            ];
        }

        return array_slice($filtered, 0, 4);
    }

    /**
     * Generate DTO property assignments
     */
    protected function generateDtoProperties(Endpoint $endpoint): string
    {
        $filteredProperties = $this->getFilteredPropertiesForTest($endpoint);

        $lines = [];

        foreach ($filteredProperties as $propInfo) {
            $propName = $propInfo['name'];

            if ($propInfo['isDateTime']) {
                // Generate Carbon::parse() for DateTime fields
                $dateString = $this->generateValue($propName, $propInfo['type']);
                $lines[] = "    \$dto->{$propName} = \\Carbon\\Carbon::parse('{$dateString}');";
            } else {
                $value = $this->formatAsCode($this->generateValue($propName, $propInfo['type']));
                $lines[] = "    \$dto->{$propName} = {$value};";
            }
        }

        // Fallback if no properties after filtering
        if (empty($lines)) {
            return "    \$dto->name = 'test value';";
        }

        return implode("\n", $lines);
    }

    /**
     * Generate body validation code
     */
    protected function generateBodyValidation(Endpoint $endpoint): string
    {
        $resourceType = $this->getResourceTypeFromEndpoint($endpoint);

        $attributeValidations = $this->generateAttributeValidationsFromDto($endpoint);

        $lines = [];

        $lines[] = '    $mockClient->assertSent(function (Request $request) {';
        $lines[] = '        expect($request->body()->all())';
        $lines[] = "            ->toHaveKey('data')";

        // Generate attribute validations
        if ($attributeValidations) {
            $lines[] = "            ->data->type->toBe('{$resourceType}')";
            $lines[] = '            ->data->attributes->scoped(fn ($attributes) => $attributes';
            $lines[] = $attributeValidations;
            $lines[] = '            );';
        } else {
            // No attributes to validate, just close the chain
            $lines[] = "            ->data->type->toBe('{$resourceType}');";
        }

        $lines[] = '';
        $lines[] = '        return true;';
        $lines[] = '    });';

        return implode("\n", $lines);
    }

    /**
     * Generate attribute validation chain from DTO properties
     */
    protected function generateAttributeValidationsFromDto(Endpoint $endpoint): string
    {
        $filteredProperties = $this->getFilteredPropertiesForTest($endpoint);
        $lines = [];

        foreach ($filteredProperties as $propInfo) {
            $propName = $propInfo['name'];

            if ($propInfo['isDateTime']) {
                // Generate toEqual(new \Carbon\Carbon(...)) assertion for DateTime fields
                $dateString = $this->generateValue($propName, $propInfo['type']);
                $lines[] = "                ->{$propName}->toEqual(new \\Carbon\\Carbon('{$dateString}'))";
            } else {
                $value = $this->formatAsCode($this->generateValue($propName, $propInfo['type']));
                $assertionValue = $this->formatValueForAssertion($value);
                $lines[] = "                ->{$propName}->toBe({$assertionValue})";
            }
        }

        return implode("\n", $lines);
    }
}
