<?php

declare(strict_types=1);

namespace Timatic\JsonApiSdk\Tests\Foundation\Fixtures\DataTransferObjects;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Spatie\LaravelData\Data;
use Timatic\JsonApiSdk\Foundation\JsonApi\Attributes\JsonApiRelationship;
use Timatic\JsonApiSdk\Foundation\JsonApi\Attributes\JsonApiType;
use Timatic\JsonApiSdk\Foundation\JsonApi\HasTestFactory;
use Timatic\JsonApiSdk\Foundation\JsonApi\RelationType;
use Timatic\JsonApiSdk\Tests\Foundation\Fixtures\Factories\PostFactory;

/**
 * @method static PostFactory testFactory()
 */
#[JsonApiType('posts')]
class Post extends Data
{
    /** @use HasTestFactory<PostFactory> */
    use HasTestFactory;

    public function __construct(
        public ?string $id = null,
        public ?string $title = null,
        public ?Carbon $publishedAt = null,
        #[JsonApiRelationship(Author::class, RelationType::One)]
        public ?Author $author = null,
        /** @var Collection<int, Comment>|null */
        #[JsonApiRelationship(Comment::class, RelationType::Many)]
        public ?Collection $comments = null,
    ) {}
}
