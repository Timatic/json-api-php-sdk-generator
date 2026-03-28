<?php

declare(strict_types=1);

namespace Timatic\JsonApiSdk\Foundation\JsonApi;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use ReflectionClass;
use Spatie\LaravelData\Data;
use Timatic\JsonApiSdk\Foundation\JsonApi\Attributes\JsonApiRelationship;

class JsonApiSerializer
{
    /**
     * Convert a Spatie Data object to JSON:API format.
     * Returns the data object (without the outer 'data' wrapper).
     *
     * @param  array<string, mixed>  $data
     */
    public static function toJsonApi(Data $data): array
    {
        $reflection = new ReflectionClass($data);

        $type = static::deriveType($reflection->getShortName());
        $result = ['type' => $type];

        $id = static::getProperty($data, 'id');
        if ($id !== null) {
            $result['id'] = $id;
        }

        $attributes = [];
        $relationships = [];

        $constructor = $reflection->getConstructor();

        if ($constructor) {
            foreach ($constructor->getParameters() as $param) {
                $name = $param->getName();

                if ($name === 'id') {
                    continue;
                }

                $relationAttrs = $param->getAttributes(JsonApiRelationship::class);

                if (! empty($relationAttrs)) {
                    $value = static::getProperty($data, $name);

                    if ($value === null) {
                        continue;
                    }

                    /** @var JsonApiRelationship $attr */
                    $attr = $relationAttrs[0]->newInstance();

                    if ($attr->type === RelationType::Many && $value instanceof Collection) {
                        $relationships[$name] = [
                            'data' => $value->map(fn (Data $item) => [
                                'type' => static::deriveType((new ReflectionClass($item))->getShortName()),
                                'id' => static::getProperty($item, 'id'),
                            ])->values()->all(),
                        ];
                    } elseif ($attr->type === RelationType::One && $value instanceof Data) {
                        $relationships[$name] = [
                            'data' => [
                                'type' => static::deriveType((new ReflectionClass($value))->getShortName()),
                                'id' => static::getProperty($value, 'id'),
                            ],
                        ];
                    }
                } else {
                    $value = static::getProperty($data, $name);

                    if ($value !== null) {
                        $attributes[$name] = $value instanceof Carbon ? $value->toIso8601String() : $value;
                    }
                }
            }
        }

        $result['attributes'] = $attributes;

        if (! empty($relationships)) {
            $result['relationships'] = $relationships;
        }

        return $result;
    }

    private static function deriveType(string $shortName): string
    {
        return Str::of($shortName)->camel()->plural()->toString();
    }

    private static function getProperty(object $object, string $name): mixed
    {
        return $object->{$name} ?? null;
    }
}
