<?php

declare(strict_types=1);

namespace Timatic\JsonApiSdk\Foundation\Factories;

use Faker\Factory as FakerFactory;
use Faker\Generator;
use Illuminate\Support\Collection;
use Spatie\LaravelData\Data;

/**
 * @template TModel of Data
 */
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

    public function count(int $count): static
    {
        $this->count = $count;

        return $this;
    }

    public function state(array $attributes): static
    {
        $this->states = array_merge($this->states, $attributes);

        return $this;
    }

    public function withId(): static
    {
        $this->withId = true;

        return $this;
    }

    /**
     * @return TModel|Collection<int, TModel>
     */
    public function make(): Data|Collection
    {
        if ($this->count === 1) {
            return $this->makeOne();
        }

        return Collection::times($this->count, fn () => $this->makeOne());
    }

    /**
     * @return TModel|Collection<int, TModel>
     */
    public function create(): Data|Collection
    {
        return $this->make();
    }

    /**
     * @return TModel
     */
    protected function makeOne(): Data
    {
        $attributes = array_merge($this->definition(), $this->states);

        if ($this->withId && ! isset($attributes['id'])) {
            $attributes['id'] = $this->faker->uuid();
        }

        $modelClass = $this->modelClass();

        return new $modelClass(...$attributes);
    }

    abstract protected function definition(): array;

    abstract protected function modelClass(): string;
}
