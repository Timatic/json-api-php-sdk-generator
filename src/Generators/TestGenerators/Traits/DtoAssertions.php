<?php

namespace Timatic\JsonApiSdk\Generators\TestGenerators\Traits;

use Crescat\SaloonSdkGenerator\Generators\Traits\DtoAssertions as BaseDtoAssertions;
use Nette\PhpGenerator\Parameter;
use Nette\PhpGenerator\PromotedParameter;

trait DtoAssertions
{
    use BaseDtoAssertions;

    protected function generateDtoAssertions(array $mockData): string
    {
        $attributes = $mockData['data'][0]['attributes'] ?? $mockData['data']['attributes'] ?? [];

        if (empty($attributes)) {
            return '        // No attributes to validate';
        }

        $assertions = [];

        foreach ($attributes as $key => $value) {
            if (is_array($value)) {
                continue;
            }

            $assertions[] = $this->generateAssertionForValue($key, $value);
        }

        if (empty($assertions)) {
            return '        // No simple attributes to validate (arrays skipped)';
        }

        return implode("\n", $assertions);
    }

    protected function shouldSkipParameter(Parameter|PromotedParameter $parameter): bool
    {
        foreach ($parameter->getAttributes() as $attribute) {
            $attrName = $attribute->getName();

            if ($attrName === 'JsonApiRelationship' || str_ends_with($attrName, '\\JsonApiRelationship')) {
                return true;
            }
        }

        return false;
    }
}
