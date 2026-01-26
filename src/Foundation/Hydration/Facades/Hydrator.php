<?php

declare(strict_types=1);

namespace Timatic\JsonApiSdk\Foundation\Hydration\Facades;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;
use Timatic\JsonApiSdk\Foundation\Hydration\Model;

/**
 * @method static Collection<int, Model> hydrateCollection(string $model, array<int, mixed> $data, array<int|string, mixed>|null $included = null)
 * @method static Model hydrate(string $model, array<string, mixed> $item, array<int|string, mixed>|null $included = null)
 */
class Hydrator extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \Timatic\JsonApiSdk\Foundation\Hydration\Hydrator::class;
    }
}
