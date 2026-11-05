<?php

declare(strict_types=1);

namespace Timatic\JsonApiSdk\Foundation\JsonApi;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use ReflectionClass;
use Spatie\LaravelData\Data;
use Timatic\JsonApiSdk\Foundation\JsonApi\Attributes\JsonApiRelationship;

class JsonApiTransformer
{
    /**
     * Flatten a JSON:API resource item to a flat array suitable for Data::from().
     * Resolves relationships from the included array.
     *
     * @param  class-string<Data>  $dtoClass
     * @param  array<string, mixed>  $item
     * @param  array<int, mixed>|null  $included
     * @return array<string, mixed>
     */
    public static function flatten(string $dtoClass, array $item, ?array $included = null): array
    {
        return self::flattenWithIndexed($dtoClass, $item, self::indexIncluded($included ?? []));
    }

    /**
     * @param  class-string<Data>  $dtoClass
     * @param  array<int, mixed>  $data
     * @param  array<int, mixed>|null  $included
     * @return array<int, array<string, mixed>>
     */
    public static function flattenCollection(string $dtoClass, array $data, ?array $included = null): array
    {
        $indexed = self::indexIncluded($included ?? []);

        return array_map(
            fn (array $item) => self::flattenWithIndexed($dtoClass, $item, $indexed),
            $data
        );
    }

    /**
     * @param  array<string, mixed>  $indexed  Keyed by "{id}-{type}"
     * @return array<string, mixed>
     */
    private static function flattenWithIndexed(string $dtoClass, array $item, array $indexed): array
    {
        $flat = ['id' => $item['id'] ?? null];

        foreach ($item['attributes'] ?? [] as $key => $value) {
            $flat[$key] = $value;
        }

        $relationships = $item['relationships'] ?? [];

        if (! empty($relationships)) {
            self::resolveRelationships($dtoClass, $relationships, $indexed, $flat);
        }

        return $flat;
    }

    /**
     * Index included resources by "{id}-{type}" key.
     *
     * @param  array<int, mixed>  $included
     * @return array<string, mixed>
     */
    private static function indexIncluded(array $included): array
    {
        return Arr::keyBy($included, fn ($item) => $item['id'].'-'.$item['type']);
    }

    /**
     * Resolve relationship references from included into the flat array.
     *
     * @param  class-string<Data>  $dtoClass
     * @param  array<string, mixed>  $relationships
     * @param  array<string, mixed>  $indexed
     * @param  array<string, mixed>  $flat
     */
    private static function resolveRelationships(string $dtoClass, array $relationships, array $indexed, array &$flat): void
    {
        $reflection = new ReflectionClass($dtoClass);
        $constructor = $reflection->getConstructor();

        if (! $constructor) {
            return;
        }

        foreach ($constructor->getParameters() as $param) {
            $attrs = $param->getAttributes(JsonApiRelationship::class);

            if (empty($attrs)) {
                continue;
            }

            $relationName = $param->getName();

            if (! isset($relationships[$relationName]['data'])) {
                continue;
            }

            /** @var JsonApiRelationship $attr */
            $attr = $attrs[0]->newInstance();
            $relationData = $relationships[$relationName]['data'];

            if ($attr->type === RelationType::Many) {
                $items = new Collection;

                foreach ($relationData as $ref) {
                    $includedItem = $indexed[$ref['id'].'-'.$ref['type']] ?? null;

                    if ($includedItem !== null) {
                        $nestedFlat = self::flattenWithIndexed($attr->model, $includedItem, $indexed);
                        $items->push($attr->model::from($nestedFlat));
                    }
                }

                $flat[$relationName] = $items;
            } elseif (is_array($relationData)) {
                $includedItem = $indexed[$relationData['id'].'-'.$relationData['type']] ?? null;

                if ($includedItem !== null) {
                    $nestedFlat = self::flattenWithIndexed($attr->model, $includedItem, $indexed);
                    $flat[$relationName] = $attr->model::from($nestedFlat);
                }
            }
        }
    }
}
