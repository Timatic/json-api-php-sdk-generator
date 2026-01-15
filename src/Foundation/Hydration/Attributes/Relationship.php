<?php

declare(strict_types=1);

namespace JsonApiSdk\Foundation\Hydration\Attributes;

use Attribute;
use JsonApiSdk\Foundation\Hydration\Model;
use JsonApiSdk\Foundation\Hydration\RelationType;

#[Attribute]
readonly class Relationship
{
    /**
     * @param  class-string<Model>  $model
     */
    public function __construct(public string $model, public RelationType $type) {}
}
