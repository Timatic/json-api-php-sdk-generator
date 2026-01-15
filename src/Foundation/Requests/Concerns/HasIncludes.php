<?php

declare(strict_types=1);

namespace JsonApiSdk\Foundation\Requests\Concerns;

trait HasIncludes
{
    /**
     * Add one or more includes to the request
     */
    public function include(string ...$relationships): static
    {
        $this->query()->add('include', implode(',', $relationships));

        return $this;
    }

    /**
     * Add a single include to the request (for fluent method chaining)
     */
    protected function addInclude(string $relation): static
    {
        $includes = $this->getCurrentIncludes();
        $includes[] = $relation;

        return $this->include(...$includes);
    }

    /**
     * Get current includes from query string
     */
    protected function getCurrentIncludes(): array
    {
        $currentIncludeString = $this->query()->get('include');

        if ($currentIncludeString === null) {
            return [];
        }

        return explode(',', $currentIncludeString);
    }
}
