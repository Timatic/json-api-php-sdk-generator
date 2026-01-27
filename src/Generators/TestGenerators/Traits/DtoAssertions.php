<?php

namespace Timatic\JsonApiSdk\Generators\TestGenerators\Traits;

use Crescat\SaloonSdkGenerator\Data\Generator\GeneratedCode;
use Nette\PhpGenerator\Property;

trait DtoAssertions
{
    /**
     * The GeneratedCode instance (must be provided by the class using this trait)
     */
    protected GeneratedCode $generatedCode;

    /**
     * Generate DTO assertions based on the DTO class from generatedCode
     */
    protected function generateDtoAssertions(array $mockData): string
    {
        // Get attributes from the mock data
        $attributes = $mockData['data'][0]['attributes'] ?? $mockData['data']['attributes'] ?? [];

        if (empty($attributes)) {
            return '        // No attributes to validate';
        }

        $assertions = [];

        foreach ($attributes as $key => $value) {
            // Skip arrays completely
            if (is_array($value)) {
                continue;
            }

            $assertion = $this->generateAssertionForValue($key, $value);
            $assertions[] = $assertion;
        }

        // If no valid assertions after filtering, return comment
        if (empty($assertions)) {
            return '        // No simple attributes to validate (arrays skipped)';
        }

        return implode("\n", $assertions);
    }


    /**
     * Get DTO properties from generated code
     *
     * @return array<string, \Nette\PhpGenerator\Property>
     */
    protected function getDtoPropertiesFromGeneratedCode(string $dtoClassName): array
    {
        // Check if DTO exists in generated code
        if (! isset($this->generatedCode->dtoClasses[$dtoClassName])) {
            return [];
        }

        $phpFile = $this->generatedCode->dtoClasses[$dtoClassName];
        $properties = [];

        // Get the first namespace in the file
        $namespace = array_values($phpFile->getNamespaces())[0] ?? null;
        if (! $namespace) {
            return [];
        }

        // Get the first class in the namespace
        $classType = array_values($namespace->getClasses())[0] ?? null;
        if (! $classType) {
            return [];
        }

        // Extract properties from the class
        foreach ($classType->getProperties() as $property) {
            
            // Skip static properties
            if ($property->isStatic()) {
                continue;
            }

            // Skip relationship properties (they have Relationship attribute)
            $hasRelationshipAttribute = false;
            foreach ($property->getAttributes() as $attribute) {
                $attrName = $attribute->getName();
                // Check for both short name and full class name
                if ($attrName === 'Relationship' || str_ends_with($attrName, '\\Relationship')) {
                    $hasRelationshipAttribute = true;
                    break;
                }
            }

            if ($hasRelationshipAttribute) {
                continue;
            }
            
            $properties[$property->getName()] = $property;
        }

        return $properties;
    }

    /**
     * Get list of property names to skip when generating mock test data
     * Override this method in child classes to customize which properties to skip
     *
     * @return string[]
     */
    protected function getPropertiesToSkipInTests(): array
    {
        // Skip ID and timestamps - these are typically read-only
        return ['id', 'createdAt', 'updatedAt', 'deletedAt'];
    }

    /**
     * Generate mock attributes from DTO properties
     */
    protected function generateMockAttributesFromDto(string $dtoClassName): array
    {
        $properties = $this->getDtoPropertiesFromGeneratedCode($dtoClassName);

        if (empty($properties)) {
            return ['name' => 'Mock value'];
        }

        $attributes = [];

        foreach ($properties as $property) {

            // Skip properties that are typically read-only or handled separately
            if (in_array($property->getName(), $this->getPropertiesToSkipInTests())) {
                continue;
            }

            $attributes[$property->getName()] = $this->generateMockValueForDtoProperty($property);
        }

        return $attributes;
    }

    /**
     * Generate a mock value for a DTO property based on its type
     */
    protected function generateMockValueForDtoProperty(Property $property): mixed
    {
        $nullable = $property->isNullable();

        // Normalize type name (remove nullable prefix)
        $typeName = $property->getType();

        // Handle union types (e.g., "string|null", "string|float", "int|null")
        if (str_contains($typeName, '|')) {
            $types = explode('|', $typeName);
            // Filter out 'null' and 'mixed', get the first concrete type
            $concreteTypes = array_filter($types, fn ($t) => ! in_array(trim($t), ['null', 'mixed']));

            if (! empty($concreteTypes)) {
                // Use the first concrete type
                $typeName = trim(reset($concreteTypes));
            } else {
                // If all types are null/mixed, default to string
                $typeName = 'string';
            }
        }

        // DateTime fields
        if (str_contains($typeName, 'Carbon') || str_contains($typeName, 'DateTime')) {
            return '2025-11-22T10:40:04.065Z';
        }

        // Type-based generation (type takes precedence over name-based heuristics)
        if ($typeName === 'bool') {
            return true;
        }

        if ($typeName === 'int') {
            return 42;
        }

        if ($typeName === 'float') {
            return 3.14;
        }

        if ($typeName === 'array') {
            return [];
        }

        if ($typeName === 'object') {
            return (object) [];
        }

        if ($typeName === 'mixed') {
            return 'Mixed value';
        }

        // String type - apply name-based heuristics
        if ($typeName === 'string') {
            // ID fields
            if (str_ends_with($property->getName(), 'Id')) {
                return 'mock-id-123';
            }

            // Email fields
            if (str_contains($property->getName(), 'email') || str_contains($property->getName(), 'Email')) {
                return 'test@example.com';
            }

            return 'String value';
        }

        if($nullable){
            return null;
        }

        // Fallback for unknown types
        return 'Mock value';
    }

    /**
     * Generate an assertion for a specific attribute value
     */
    protected function generateAssertionForValue(string $key, mixed $value): string
    {
        // Handle different value types
        if (is_bool($value)) {
            $expected = $value ? 'true' : 'false';

            return "        ->{$key}->toBe({$expected})";
        }

        if (is_int($value)) {
            return "        ->{$key}->toBe({$value})";
        }

        if (is_float($value)) {
            return "        ->{$key}->toBe({$value})";
        }

        if (is_null($value)) {
            return "        ->{$key}->toBeNull()";
        }

        if (is_object($value)) {
            return "        ->{$key}->toBeInstanceOf(stdClass::class)";
        }

        if (is_array($value)) {
            return "        ->{$key}->toBeArray()";
        }

        // Check if it's a datetime string
        if (is_string($value) && $this->isDateTimeString($value)) {
            return "        ->{$key}->toEqual(new Carbon(\"{$value}\"))";
        }

        // Default: string value
        $escapedValue = addslashes($value);

        return "        ->{$key}->toBe(\"{$escapedValue}\")";
    }

    /**
     * Check if a string is a datetime format
     */
    protected function isDateTimeString(string $value): bool
    {
        // Check for ISO 8601 format (e.g., 2025-11-22T10:40:04.065Z)
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $value);
    }
}
