<?php

declare(strict_types=1);

namespace Timatic\JsonApiSdk\Foundation\JsonApi\Attributes;

use Attribute;
use Spatie\LaravelData\Data;
use Timatic\JsonApiSdk\Foundation\JsonApi\RelationType;

#[Attribute]
readonly class JsonApiRelationship
{
    /**
     * @param  class-string<Data>  $model
     */
    public function __construct(public string $model, public RelationType $type) {}
}
