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
        // First, try to get the schema name from the parsed response
        if ($endpoint->response && isset($endpoint->response['schema'])) {
            return $endpoint->response['schema'];
        }

        // Fallback: derive from collection name
        if ($endpoint->collection) {
            return Str::singular(NameHelper::safeClassName($endpoint->collection));
        }

        // Fallback: try to parse from endpoint name
        $name = $endpoint->name ?: NameHelper::pathBasedName($endpoint);
        $name = preg_replace('/^(get|post|patch)/i', '', $name);

        return Str::singular(NameHelper::safeClassName($name));
    }
}
