<?php

declare(strict_types=1);

namespace Timatic\JsonApiSdk\Tests\Foundation\Fixtures\DataTransferObjects;

use Spatie\LaravelData\Data;
use Timatic\JsonApiSdk\Foundation\JsonApi\Attributes\JsonApiType;

#[JsonApiType('authors')]
class Author extends Data
{
    public function __construct(
        public ?string $id = null,
        public ?string $name = null,
    ) {}
}
