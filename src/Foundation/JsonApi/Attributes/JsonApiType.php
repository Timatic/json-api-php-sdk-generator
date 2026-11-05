<?php

declare(strict_types=1);

namespace Timatic\JsonApiSdk\Foundation\JsonApi\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
readonly class JsonApiType
{
    public function __construct(public string $type) {}
}
