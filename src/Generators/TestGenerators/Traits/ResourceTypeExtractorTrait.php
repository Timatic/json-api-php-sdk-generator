<?php

declare(strict_types=1);

namespace Timatic\JsonApiSdk\Generators\TestGenerators\Traits;

use Crescat\SaloonSdkGenerator\Data\Generator\ApiSpecification;
use Crescat\SaloonSdkGenerator\Data\Generator\Endpoint;
use Crescat\SaloonSdkGenerator\Helpers\NameHelper;
use Illuminate\Support\Str;

trait ResourceTypeExtractorTrait
{
    /**
     * The parsed ApiSpecification (must be provided by the class using this trait)
     */
    protected ApiSpecification $specification;

    /**
     * Get the resource type for JSON:API from endpoint
     */
    protected function getResourceTypeFromEndpoint(Endpoint $endpoint): string
    {
        if ($endpoint->collection) {
            $name = $endpoint->collection;
        } else {
            // Fallback: parse from endpoint path
            $path = $endpoint->pathAsString();
            // Extract first path segment (e.g., /budgets -> budgets)
            preg_match('#^/([^/]+)#', $path, $matches);

            if (count($matches) >= 2) {
                throw new \RuntimeException('Resource type for "'.$path.'" does not exist');
            }

            $name = $matches[1];
        }

        $camelName = NameHelper::safeVariableName($name);

        return Str::plural($camelName);
    }
}
