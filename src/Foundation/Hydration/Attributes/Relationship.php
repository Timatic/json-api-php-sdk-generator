<?php

declare(strict_types=1);

namespace Timatic\JsonApiSdk\Foundation\Hydration\Attributes;

use Attribute;
use Timatic\JsonApiSdk\Foundation\Hydration\Model;
use Timatic\JsonApiSdk\Foundation\Hydration\RelationType;

#[Attribute]
readonly class Relationship
{
    /**
     * @param  class-string<Model>  $model
     */
    public function __construct(public string $model, public RelationType $type) {}
}
