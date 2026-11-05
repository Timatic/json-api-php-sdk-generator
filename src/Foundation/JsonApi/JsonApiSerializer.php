<?php

declare(strict_types=1);

namespace Timatic\JsonApiSdk\Foundation\JsonApi;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use ReflectionClass;
use Spatie\LaravelData\Data;
use Timatic\JsonApiSdk\Foundation\JsonApi\Attributes\JsonApiRelationship;
use Timatic\JsonApiSdk\Foundation\JsonApi\Attributes\JsonApiType;

class JsonApiSerializer
{
    /**
     * Convert a Spatie Data object to JSON:API format.
     * Returns the data object (without the outer 'data' wrapper).
     *
     * @return array<string, mixed>
     */
    public static function toJsonApi(Data $data): array
    {
        $reflection = new ReflectionClass($data);

        $result = ['type' => self::resourceType($reflection)];

        $id = self::getProperty($data, 'id');
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
                    $resolved = self::resolveRelationship($data, $name, $relationAttrs[0]->newInstance());

                    if ($resolved !== null) {
                        $relationships[$name] = $resolved;
                    }
                } else {
                    $value = self::getProperty($data, $name);

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

    private static function resolveRelationship(Data $data, string $name, JsonApiRelationship $attr): ?array
    {
        $value = self::getProperty($data, $name);

        if ($value === null) {
            return null;
        }

        if ($attr->type === RelationType::Many && $value instanceof Collection) {
            return [
                'data' => $value->map(fn (Data $item) => self::resourceIdentifier($item))->values()->all(),
            ];
        }

        if ($attr->type === RelationType::One && $value instanceof Data) {
            return ['data' => self::resourceIdentifier($value)];
        }

        return null;
    }

    private static function resourceIdentifier(Data $item): array
    {
        return [
            'type' => self::resourceType(new ReflectionClass($item)),
            'id' => self::getProperty($item, 'id'),
        ];
    }

    /**
     * @param  ReflectionClass<Data>  $reflection
     */
    private static function resourceType(ReflectionClass $reflection): string
    {
        $attribute = $reflection->getAttributes(JsonApiType::class)[0] ?? null;

        return $attribute?->newInstance()->type
            ?? Str::of($reflection->getShortName())->camel()->plural()->toString();
    }

    private static function getProperty(object $object, string $name): mixed
    {
        return $object->{$name} ?? null;
    }
}
