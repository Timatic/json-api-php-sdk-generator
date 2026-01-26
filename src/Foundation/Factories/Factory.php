<?php

namespace Timatic\JsonApiSdk\Foundation\Factories;

use Faker\Factory as FakerFactory;
use Faker\Generator;
use Illuminate\Support\Collection;
use Timatic\JsonApiSdk\Foundation\Hydration\Model;

abstract class Factory
{
    protected Generator $faker;

    protected int $count = 1;

    protected array $states = [];

    protected bool $withId = false;

    public function __construct()
    {
        $this->faker = FakerFactory::create();
    }

    public static function new(): static
    {
        /** @phpstan-ignore-next-line new.static */
        return new static;
    }

    /**
     * Set the number of models to generate.
     */
    public function count(int $count): static
    {
        $this->count = $count;

        return $this;
    }

    /**
     * Set custom attribute overrides.
     */
    public function state(array $attributes): static
    {
        $this->states = array_merge($this->states, $attributes);

        return $this;
    }

    /**
     * Generate models with unique UUIDs as IDs.
     */
    public function withId(): static
    {
        $this->withId = true;

        return $this;
    }

    /**
     * Generate one or more model instances.
     *
     * @return Model|Collection<int, Model>
     */
    public function make(): Model|Collection
    {
        if ($this->count === 1) {
            return $this->makeOne();
        }

        return Collection::times($this->count, fn () => $this->makeOne());
    }

    /**
     * Alias for make() for API consistency with Laravel factories.
     *
     * @return Model|Collection<int, Model>
     */
    public function create(): Model|Collection
    {
        return $this->make();
    }

    /**
     * Generate a single model instance.
     */
    protected function makeOne(): Model
    {
        $modelClass = $this->modelClass();
        $model = new $modelClass;

        $attributes = array_merge($this->definition(), $this->states);

        // Auto-generate UUID if withId() was called
        if ($this->withId && ! isset($attributes['id'])) {
            $attributes['id'] = $this->faker->uuid();
        }

        foreach ($attributes as $key => $value) {
            $model->{$key} = $value;
        }

        return $model;
    }

    /**
     * Define the default attributes for the model.
     */
    abstract protected function definition(): array;

    /**
     * Get the model class name.
     */
    abstract protected function modelClass(): string;
}
