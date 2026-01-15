<?php

declare(strict_types=1);

namespace JsonApiSdk\Foundation\Hydration;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use ReflectionClass;
use JsonApiSdk\Foundation\Hydration\Attributes\Property;
use JsonApiSdk\Foundation\Hydration\Attributes\Relationship;

abstract class Model implements ModelInterface
{
    use HasAttributes;

    #[Property(isReadOnly: true)]
    public string $id;

    protected ?string $type = null;

    /**
     * Get a new factory instance for the model.
     */
    public static function factory(): mixed
    {
        $modelClass = static::class;
        $factoryClass = str_replace('\\Dto\\', '\\Factories\\', $modelClass).'Factory';

        if (class_exists($factoryClass)) {
            return $factoryClass::new();
        }

        throw new \RuntimeException("Factory [{$factoryClass}] not found for model [{$modelClass}].");
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(array $attributes = [])
    {
        $this->fill($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function fill(array $attributes): void
    {
        $propertyNames = $this->propertyNames();

        foreach ($attributes as $key => $value) {
            if (in_array($key, $propertyNames)) {
                $this->$key = $value;
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function propertyNames(): array
    {
        $reflectionClass = new ReflectionClass($this);
        $properties = $reflectionClass->getProperties();
        $propertyNames = [];

        foreach ($properties as $property) {
            $attributes = $property->getAttributes(Property::class);
            if (count($attributes) > 0) {
                $propertyNames[] = $property->getName();
            }
        }

        return $propertyNames;
    }

    public function type(): string
    {
        return $this->type ?? Str::of(
            (new ReflectionClass($this))->getShortName()
        )->camel()->plural()->toString();
    }

    /**
     * Get all relationships as an array for serialization
     *
     * @return array<string, mixed>
     */
    public function relationships(): array
    {
        $reflectionClass = new ReflectionClass($this);
        $properties = $reflectionClass->getProperties();
        $relationships = [];

        foreach ($properties as $property) {
            $relationshipAttributes = $property->getAttributes(Relationship::class);

            if (count($relationshipAttributes) > 0) {
                $propertyName = $property->getName();

                // Skip if property is not initialized
                if (! $property->isInitialized($this)) {
                    continue;
                }

                $value = $property->getValue($this);

                // Skip null values
                if ($value === null) {
                    continue;
                }

                /** @var Relationship $attr */
                $attr = $relationshipAttributes[0]->newInstance();

                // Serialize based on relationship type
                if ($attr->type === RelationType::Many) {
                    // For Many relationships, expect a Collection
                    if ($value instanceof Collection) {
                        $relationships[$propertyName] = [
                            'data' => $value->map(fn (Model $model) => [
                                'type' => $model->type(),
                                'id' => $model->id,
                            ])->all(),
                        ];
                    }
                } elseif ($attr->type === RelationType::One) {
                    // For One relationships, expect a Model instance
                    if ($value instanceof Model) {
                        $relationships[$propertyName] = [
                            'data' => [
                                'type' => $value->type(),
                                'id' => $value->id,
                            ],
                        ];
                    }
                }
            }
        }

        return $relationships;
    }

    /**
     * Convert Model to JSON:API data object.
     * Returns the data object without the 'data' wrapper.
     *
     * @return array<string, mixed>
     */
    public function toJsonApi(): array
    {
        $data = [
            'type' => $this->type(),
        ];

        if (isset($this->id)) {
            $data['id'] = $this->id;
        }

        $data['attributes'] = $this->attributes();

        $relationships = $this->relationships();
        if (! empty($relationships)) {
            $data['relationships'] = $relationships;
        }

        return $data;
    }
}
