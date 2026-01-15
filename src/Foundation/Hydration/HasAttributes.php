<?php

declare(strict_types=1);

namespace JsonApiSdk\Foundation\Hydration;

use ReflectionClass;
use JsonApiSdk\Foundation\Hydration\Attributes\Property;

trait HasAttributes
{
    /**
     * Get all attributes as an array for serialization
     *
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        $reflectionClass = new ReflectionClass($this);
        $properties = $reflectionClass->getProperties();
        $attributes = [];

        foreach ($properties as $property) {
            $propertyAttributes = $property->getAttributes(Property::class);

            if (count($propertyAttributes) > 0) {
                $propertyName = $property->getName();

                // Skip if property is not initialized
                if (! $property->isInitialized($this)) {
                    continue;
                }

                $value = $property->getValue($this);

                /** @var Property $attr */
                $attr = $propertyAttributes[0]->newInstance();

                // Skip read-only properties when serializing (like id)
                if ($attr->isReadOnly) {
                    continue;
                }

                $attributes[$propertyName] = $value;
            }
        }

        return $attributes;
    }

    /**
     * Get a specific attribute value
     */
    public function getAttribute(string $key): mixed
    {
        return $this->$key ?? null;
    }

    /**
     * Set a specific attribute value
     */
    public function setAttribute(string $key, mixed $value): void
    {
        $this->$key = $value;
    }
}
