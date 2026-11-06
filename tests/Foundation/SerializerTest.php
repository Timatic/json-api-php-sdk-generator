<?php

use Carbon\Carbon;
use Timatic\JsonApiSdk\Foundation\JsonApi\JsonApiSerializer;
use Timatic\JsonApiSdk\Tests\Foundation\Fixtures\DataTransferObjects\Author;
use Timatic\JsonApiSdk\Tests\Foundation\Fixtures\DataTransferObjects\Post;

test('it serializes a DTO to a JSON:API resource', function () {
    // Given
    $post = new Post(
        id: 'post-1',
        title: 'Hello world',
        publishedAt: Carbon::parse('2026-01-01T12:00:00+00:00'),
        author: new Author(id: 'author-1'),
    );

    // When
    $resource = JsonApiSerializer::toJsonApi($post);

    // Then
    expect($resource)->toBe([
        'type' => 'posts',
        'id' => 'post-1',
        'attributes' => [
            'title' => 'Hello world',
            'publishedAt' => '2026-01-01T12:00:00+00:00',
        ],
        'relationships' => [
            'author' => ['data' => ['type' => 'authors', 'id' => 'author-1']],
        ],
    ]);
});
