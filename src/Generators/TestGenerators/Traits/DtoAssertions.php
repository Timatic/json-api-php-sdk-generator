<?php

namespace Timatic\JsonApiSdk\Generators\TestGenerators\Traits;

use Crescat\SaloonSdkGenerator\Generators\Traits\DtoAssertions as BaseDtoAssertions;
use Nette\PhpGenerator\Property;

/**
 * JSON:API specific DTO assertions
 *
 * Extends the base DtoAssertions trait with JSON:API format handling
 */
trait DtoAssertions
{
    use BaseDtoAssertions;

    /**
     * Generate DTO assertions based on the DTO class from generatedCode
     * Overrides parent to handle JSON:API format (data.attributes structure)
     */
    protected function generateDtoAssertions(array $mockData): string
    {
        // Get attributes from the mock data (JSON:API format)
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
     * Override to skip JSON:API relationship properties
     */
    protected function shouldSkipProperty(Property $property): bool
    {
        // Skip relationship properties (they have Relationship attribute)
        foreach ($property->getAttributes() as $attribute) {
            $attrName = $attribute->getName();
            // Check for both short name and full class name
            if ($attrName === 'Relationship' || str_ends_with($attrName, '\\Relationship')) {
                return true;
            }
        }

        return false;
    }
}
