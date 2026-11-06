<?php

use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Timatic\JsonApiSdk\Tests\Foundation\Fixtures\FixtureConnector;
use Timatic\JsonApiSdk\Tests\Foundation\Fixtures\Requests\ListPostsRequest;

test('it follows links.next until the last page', function () {
    // Given
    $mockClient = new MockClient([
        MockResponse::make([
            'data' => [['type' => 'posts', 'id' => 'post-1', 'attributes' => ['title' => 'First']]],
            'links' => ['next' => 'https://api.test/posts?page[number]=2'],
        ]),
        MockResponse::make([
            'data' => [['type' => 'posts', 'id' => 'post-2', 'attributes' => ['title' => 'Second']]],
            'links' => ['next' => null],
        ]),
    ]);
    $connector = (new FixtureConnector)->withMockClient($mockClient);

    // When
    $posts = $connector->paginate(new ListPostsRequest)->setPerPageLimit(1)->dtoCollection();

    // Then
    expect($posts->pluck('title')->all())->toBe(['First', 'Second']);
    $mockClient->assertSent(fn ($request, $response) => $response->getPendingRequest()->query()->all() === ['page[number]' => 2, 'page[size]' => 1]);
});
