<?php

use Timatic\JsonApiSdk\Tests\Foundation\Fixtures\DataTransferObjects\Post;

test('it makes a DTO with overridden attributes', function () {
    // When
    $post = Post::testFactory()->state(['title' => 'Hello world'])->make();

    // Then
    expect($post)->toBeInstanceOf(Post::class)
        ->title->toBe('Hello world')
        ->id->toBeNull();
});

test('it makes multiple DTOs with unique ids', function () {
    // When
    $posts = Post::testFactory()->withId()->count(3)->make();

    // Then
    expect($posts->pluck('id')->unique())->toHaveCount(3);
});
