<?php

declare(strict_types=1);

namespace JsonApiSdk\Generators\TestGenerators\Traits;

use Crescat\SaloonSdkGenerator\Data\Generator\Endpoint;
use Crescat\SaloonSdkGenerator\Helpers\NameHelper;
use Illuminate\Support\Str;

trait DtoHelperTrait
{
    /**
     * Get the DTO class name for an endpoint
     */
    protected function getDtoClassName(Endpoint $endpoint): string
    {
        // Use collection name to determine DTO
        if ($endpoint->collection) {
            $resourceName = NameHelper::resourceClassName($endpoint->collection);

            // Use Laravel's Str::singular() for correct singular form
            return Str::singular($resourceName);
        }

        // Fallback: try to parse from endpoint name
        $name = $endpoint->name ?: NameHelper::pathBasedName($endpoint);
        // Remove method prefix (get, post, patch)
        $name = preg_replace('/^(get|post|patch)/i', '', $name);

        // Use Laravel's Str::singular() for correct singular form
        return Str::singular(NameHelper::resourceClassName($name));
    }
}
