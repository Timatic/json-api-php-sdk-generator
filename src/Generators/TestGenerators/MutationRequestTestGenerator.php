<?php

declare(strict_types=1);

namespace Timatic\JsonApiSdk\Generators\TestGenerators;

use Crescat\SaloonSdkGenerator\Data\Generator\ApiSpecification;
use Crescat\SaloonSdkGenerator\Data\Generator\Endpoint;
use Crescat\SaloonSdkGenerator\Data\Generator\GeneratedCode;
use Crescat\SaloonSdkGenerator\Helpers\DtoResolver;
use Crescat\SaloonSdkGenerator\Helpers\NameHelper;
use Timatic\JsonApiSdk\Generators\TestGenerators\Traits\DtoAssertions;
use Timatic\JsonApiSdk\Generators\TestGenerators\Traits\DtoHelperTrait;
use Timatic\JsonApiSdk\Generators\TestGenerators\Traits\ResourceTypeExtractorTrait;
use Timatic\JsonApiSdk\Generators\TestGenerators\Traits\TestDataGeneratorTrait;

class MutationRequestTestGenerator
{
    use DtoAssertions;
    use DtoHelperTrait;
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
        return $endpoint->method->isPost() || $endpoint->method->isPatch();
    }

    public function getRequestBodyDtoClassName(Endpoint $endpoint): ?string
    {
        return $this->dtoResolver->resolveRequestBodyDtoFqn($endpoint);
    }

    public function getStubPath(): string
    {
        return __DIR__.'/stubs/pest-mutation-test-func.stub';
    }

    public function replaceStubVariables(string $functionStub, Endpoint $endpoint): string
    {
        $dtoInstantiation = $this->generateDtoInstantiation($endpoint);
        $functionStub = str_replace('{{ dtoInstantiation }}', $dtoInstantiation, $functionStub);

        $bodyValidation = $this->generateBodyValidation($endpoint);
        $functionStub = str_replace('{{ bodyValidation }}', $bodyValidation, $functionStub);

        $methodArguments = $this->generateMethodArguments($endpoint);
        $functionStub = str_replace('{{ mutationMethodArguments }}', $methodArguments, $functionStub);

        return $functionStub;
    }

    protected function generateMethodArguments(Endpoint $endpoint): string
    {
        $args = [];

        foreach ($endpoint->pathParameters as $param) {
            $paramName = NameHelper::safeVariableName($param->name);
            $value = $this->generateValue($paramName, $param->type);
            $formattedValue = $this->formatAsCode($value);
            $args[] = "{$paramName}: {$formattedValue}";
        }

        foreach ($endpoint->queryParameters as $param) {
            if ($param->nullable) {
                continue;
            }

            $paramName = NameHelper::safeVariableName($param->name);
            $value = $this->generateValue($paramName, $param->type);
            $formattedValue = $this->formatAsCode($value);
            $args[] = "{$paramName}: {$formattedValue}";
        }

        if (empty($endpoint->pathParameters) && empty($endpoint->queryParameters)) {
            $args[] = '$dto';
        } else {
            $args[] = 'data: $dto';
        }

        return implode(', ', $args);
    }

    protected function generateDtoInstantiation(Endpoint $endpoint): string
    {
        $dtoClassName = $this->getDtoClassName($endpoint);
        $stateArray = $this->generateFactoryStateArray($endpoint);

        $lines = [];
        $lines[] = "    \$dto = \\{$this->namespace}\\Dto\\{$dtoClassName}::testFactory()->state([";
        $lines[] = $stateArray;
        $lines[] = '    ])->make();';

        return implode("\n", $lines);
    }

    protected function generateFactoryStateArray(Endpoint $endpoint): string
    {
        $filteredProperties = $this->getFilteredPropertiesForTest($endpoint);

        $lines = [];

        foreach ($filteredProperties as $propInfo) {
            $propName = $propInfo['name'];

            if ($propInfo['isDateTime']) {
                $dateString = $this->generateValue($propName, $propInfo['type']);
                $lines[] = "        '{$propName}' => \\Carbon\\Carbon::parse('{$dateString}'),";
            } else {
                $value = $this->formatAsCode($this->generateValue($propName, $propInfo['type']));
                $lines[] = "        '{$propName}' => {$value},";
            }
        }

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

        foreach ($properties as $property) {
            $propName = $property->getName();
            $propType = $property->getType();

            if (in_array($propName, ['id', 'createdAt', 'updatedAt', 'deletedAt'])) {
                continue;
            }
            
            if (! in_array($propType, ['string', 'int', 'float', 'bool', 'null'])) {
                continue;
            }
                
            $isDateTime = $propType && str_contains($propType, 'Carbon');

            $filtered[] = [
                'name' => $propName,
                'type' => $propType,
                'isDateTime' => $isDateTime,
            ];
        }

        return array_slice($filtered, 0, 4);
    }

    protected function generateBodyValidation(Endpoint $endpoint): string
    {
        $resourceType = $this->getResourceTypeFromEndpoint($endpoint);

        $attributeValidations = $this->generateAttributeValidationsFromDto($endpoint);

        $lines = [];

        $lines[] = '    $mockClient->assertSent(function (Request $request) {';
        $lines[] = '        expect($request->body()->all())';
        $lines[] = "            ->toHaveKey('data')";

        if ($attributeValidations) {
            $lines[] = "            ->data->type->toBe('{$resourceType}')";
            $lines[] = '            ->data->attributes->scoped(fn ($attributes) => $attributes';
            $lines[] = $attributeValidations;
            $lines[] = '            );';
        } else {
            $lines[] = "            ->data->type->toBe('{$resourceType}');";
        }

        $lines[] = '';
        $lines[] = '        return true;';
        $lines[] = '    });';

        return implode("\n", $lines);
    }

    protected function generateAttributeValidationsFromDto(Endpoint $endpoint): string
    {
        $filteredProperties = $this->getFilteredPropertiesForTest($endpoint);
        $lines = [];

        foreach ($filteredProperties as $propInfo) {
            $propName = $propInfo['name'];

            if ($propInfo['isDateTime']) {
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
