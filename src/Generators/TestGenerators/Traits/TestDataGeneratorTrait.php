<?php

declare(strict_types=1);

namespace Timatic\JsonApiSdk\Generators\TestGenerators\Traits;

use cebe\openapi\spec\Schema;
use Illuminate\Support\Str;

trait TestDataGeneratorTrait
{
    /**
     * Generate a test/mock value based on property name and type information
     *
     * @param  string  $propertyName  The property name
     * @param  Schema|array|string|null  $typeInfo  Schema object, spec array, type string, or null
     * @return mixed Actual PHP value (string, int, bool, array, etc.)
     */
    protected function generateValue(string $propertyName, Schema|array|string|null $typeInfo = null): mixed
    {
        // Extract type and format from various input formats
        [$type, $format, $example] = $this->extractTypeInfo($typeInfo);

        if ($example !== null) {
            return $example;
        }

        $nameIsBoolLike = str_starts_with($propertyName, 'is') || str_starts_with($propertyName, 'has');
        if ($type === 'boolean' || $type === 'bool' || ($nameIsBoolLike && $type !== 'string')) {
            return true;
        }

        if ($type === 'integer' || $type === 'int') {
            return 42;
        }

        if ($type === 'number' || $type === 'float') {
            return 3.14;
        }

        if ($format === 'date-time' || str_contains($propertyName, 'At') || str_contains($propertyName, 'Date')) {
            return '2025-01-15T10:30:00Z';
        }

        if (str_contains($propertyName, 'email') || str_contains($propertyName, 'Email')) {
            return 'test@example.com';
        }

        if (str_ends_with($propertyName, 'Id') && ($type === 'string' || $type === null)) {
            return Str::snake($propertyName).'-123';
        }

        if ($type === 'array') {
            return [];
        }

        if ($type === 'object') {
            return (object) [];
        }

        if ($type === 'string' || $type === null) {
            if ($propertyName === 'title') {
                return 'test title';
            }
            if ($propertyName === 'description') {
                return 'test description';
            }
            if ($propertyName === 'name') {
                return 'test name';
            }

            return 'test value';
        }

        return 'test value';
    }

    /**
     * Format a value as PHP code string for test generation
     *
     * @param  mixed  $value  The value to format
     * @return string PHP code representation
     */
    protected function formatAsCode(mixed $value): string
    {
        if (is_string($value)) {
            $escapedValue = addslashes($value);

            return "'{$escapedValue}'";
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            return '[]';
        }

        if (is_object($value)) {
            return '(object) []';
        }

        if (is_null($value)) {
            return 'null';
        }

        // Fallback
        return "'{$value}'";
    }

    protected function formatValueForAssertion(string $value): string
    {
        if (
            (str_starts_with($value, "'") && str_ends_with($value, "'")) ||
            $value === 'true' ||
            $value === 'false' ||
            is_numeric($value)
        ) {
            return $value;
        }

        return "'{$value}'";
    }

    /**
     * Extract type, format, and example from various input formats
     *
     * @return array{0: ?string, 1: ?string, 2: mixed} [type, format, example]
     */
    private function extractTypeInfo(Schema|array|string|null $typeInfo): array
    {
        if ($typeInfo instanceof Schema) {
            return [
                $typeInfo->type ?? null,
                $typeInfo->format ?? null,
                $typeInfo->example ?? null,
            ];
        }

        if (is_array($typeInfo)) {
            return [
                $typeInfo['type'] ?? null,
                $typeInfo['format'] ?? null,
                $typeInfo['example'] ?? null,
            ];
        }

        if (is_string($typeInfo)) {
            $normalizedType = ltrim($typeInfo, '?');

            if (str_contains($normalizedType, '|')) {
                $types = explode('|', $normalizedType);
                $concreteTypes = array_filter($types, fn ($t) => ! in_array(trim($t), ['null', 'mixed']));

                if (! empty($concreteTypes)) {
                    $normalizedType = trim(reset($concreteTypes));
                } else {
                    $normalizedType = 'string';
                }
            }

            if (str_contains($normalizedType, 'Carbon') || str_contains($normalizedType, 'DateTime')) {
                return ['string', 'date-time', null];
            }

            return [$normalizedType, null, null];
        }

        return [null, null, null];
    }
}
