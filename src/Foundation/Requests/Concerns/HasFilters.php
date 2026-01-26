<?php

declare(strict_types=1);

namespace Timatic\JsonApiSdk\Foundation\Requests\Concerns;

use Timatic\JsonApiSdk\Foundation\Filtering\Operator;

trait HasFilters
{
    public function filter(string $property, mixed $value, Operator $operator = Operator::Equals): static
    {
        $key = 'filter['.$property.']';

        if ($operator !== Operator::Equals) {
            $key .= '['.$operator->value.']';
        }

        $this->query()->add($key, $value);

        return $this;
    }
}
