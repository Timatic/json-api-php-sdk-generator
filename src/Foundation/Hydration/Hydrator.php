<?php

declare(strict_types=1);

namespace JsonApiSdk\Foundation\Hydration;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionProperty;
use JsonApiSdk\Foundation\Hydration\Attributes\DateTime;
use JsonApiSdk\Foundation\Hydration\Attributes\Property;
use JsonApiSdk\Foundation\Hydration\Attributes\Relationship;
use Webmozart\Assert\Assert;

use function is_null;

class Hydrator
{
    /**
     * @param  class-string<Model>|Model  $model
     * @param  array<string, mixed>  $item
     * @param  array<int|string, mixed>|null  $included
     *
     * @throws ReflectionException
     */
    public function hydrate(string|Model $model, array $item, ?array $included = null): Model
    {
        if (is_null($included)) {
            $included = [];
        }

        $included = Arr::keyBy($included, fn ($includedItem) => $includedItem['id'].'-'.$includedItem['type']);

        if (is_string($model)) {
            $model = $this->getModelFromClassName($model);
        }

        $reflectionClass = new ReflectionClass($model);

        $fillable = Arr::pluck($this->filterProperties($reflectionClass, Property::class), 'name');

        $hydrator = function (Model $model, string $property, mixed $value) use ($fillable, $reflectionClass) {
            if (in_array($property, $fillable)) {
                $type = $reflectionClass->getProperty($property)->getType();

                $propertyReflectionAttributes = $reflectionClass->getProperty($property)->getAttributes(Property::class);
                Assert::count($propertyReflectionAttributes, 1);

                $propertyArguments = $propertyReflectionAttributes[0]->getArguments();
                if (isset($propertyArguments['hydrator'])) {
                    Assert::isCallable($propertyArguments['hydrator']);

                    $value = $propertyArguments['hydrator']($value);
                }

                $dateTimeAttributes = $reflectionClass->getProperty($property)->getAttributes(DateTime::class);
                if ($dateTimeAttributes !== []) {

                    if ($value === null && $reflectionClass->getProperty($property)->getType()?->allowsNull()) {
                        $value = null;
                    } else {
                        $value = Carbon::parse($value);
                    }
                }

                Assert::isInstanceOf($type, ReflectionNamedType::class);

                $model->$property = $value;
            }
        };

        $hydrator($model, 'id', $item['id']);

        foreach ($item['attributes'] as $attribute => $value) {
            $hydrator($model, $attribute, $value);
        }

        $this->hydrateRelations($reflectionClass, $item, $included, $model);

        return $model;
    }

    /**
     * @param  class-string<Model>  $model
     * @param  array<int, mixed>  $data
     * @param  array<int|string, mixed>|null  $included
     * @return Collection<int, Model>
     */
    public function hydrateCollection(string $model, array $data, ?array $included = null): Collection
    {
        $collection = new Collection;

        foreach ($data as $item) {
            $collection->add($this->hydrate($model, $item, $included));
        }

        return $collection;
    }

    /**
     * @param  ReflectionClass<Model>  $reflectionClass
     * @return array<int, ReflectionProperty>
     */
    private function filterProperties(ReflectionClass $reflectionClass, string ...$attributes): array
    {
        return array_values(array_filter(
            $reflectionClass->getProperties(),
            function (ReflectionProperty $value) use ($attributes) {
                $hasAttributes = true;

                foreach ($attributes as $attribute) {
                    $hasAttributes = (bool) count($value->getAttributes($attribute));
                }

                return $hasAttributes;
            }
        ));
    }

    /**
     * @param  ReflectionClass<Model>  $reflectionClass
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $included
     */
    protected function hydrateRelations(ReflectionClass $reflectionClass, array $item, array $included, Model $model): void
    {
        $relationProperties = Arr::keyBy($this->filterProperties($reflectionClass, Relationship::class), 'name');

        if (! array_key_exists('relationships', $item)) {
            return;
        }

        foreach ($item['relationships'] as $relationshipName => $relationship) {
            if (
                ! array_key_exists($relationshipName, $relationProperties)
                || ! array_key_exists('data', $relationship)
                || $relationship['data'] === null
            ) {
                continue;
            }

            /** @var Relationship $relationAttribute */
            $relationAttribute = $relationProperties[$relationshipName]
                ->getAttributes(Relationship::class)[0]->newInstance();

            $relationModel = $relationAttribute->model;

            if ($relationAttribute->type === RelationType::Many) {
                $hydratedRelation = new Collection;

                foreach ($relationship['data'] as $relationItem) {
                    $includedItem = $included[$relationItem['id'].'-'.$relationItem['type']] ?? null;

                    if (! is_null($includedItem)) {
                        $hydratedRelation->push(
                            $this->hydrate($relationModel, $includedItem, $included)
                        );
                    }
                }

                $model->{$relationshipName} = $hydratedRelation;
            } elseif ($relationAttribute->type === RelationType::One) {
                $relationItem = $relationship['data'];
                $includedItem = $included[$relationItem['id'].'-'.$relationItem['type']] ?? null;

                if (! is_null($includedItem)) {
                    $model->{$relationshipName} = $this->hydrate($relationModel, $includedItem, $included);
                }
            }
        }
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function getModelFromClassName(string $model): Model
    {
        $reflectionClass = new ReflectionClass($model);
        if ($reflectionClass->getConstructor()?->getNumberOfRequiredParameters() > 0) {
            return $reflectionClass->newInstanceWithoutConstructor();
        }

        return new $model;
    }
}
