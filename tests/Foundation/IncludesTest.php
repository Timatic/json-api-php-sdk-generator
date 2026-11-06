<?php

use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Timatic\JsonApiSdk\Tests\Foundation\Fixtures\FixtureConnector;
use Timatic\JsonApiSdk\Tests\Foundation\Fixtures\Requests\ListPostsRequest;

test('it hydrates included relationships', function () {
    // Given
    $mockClient = new MockClient([
        ListPostsRequest::class => MockResponse::make([
            'data' => [[
                'type' => 'posts',
                'id' => 'post-1',
                'attributes' => ['title' => 'Hello world'],
                'relationships' => [
                    'author' => ['data' => ['type' => 'authors', 'id' => 'author-1']],
                    'comments' => ['data' => [
                        ['type' => 'comments', 'id' => 'comment-1'],
                        ['type' => 'comments', 'id' => 'comment-2'],
                    ]],
                ],
            ]],
            'included' => [
                ['type' => 'authors', 'id' => 'author-1', 'attributes' => ['name' => 'Ada']],
                ['type' => 'comments', 'id' => 'comment-1', 'attributes' => ['body' => 'First']],
                ['type' => 'comments', 'id' => 'comment-2', 'attributes' => ['body' => 'Second']],
            ],
        ]),
    ]);
    $request = (new ListPostsRequest)->includeAuthor()->includeComments();

    // When
    $post = (new FixtureConnector)->withMockClient($mockClient)->send($request)->dto()->first();

    // Then
    expect($request->query()->get('include'))->toBe('author,comments')
        ->and($post->author->name)->toBe('Ada')
        ->and($post->comments->pluck('body')->all())->toBe(['First', 'Second']);
});
