<?php

declare(strict_types=1);

namespace JsonApiSdk\Foundation\Responses;

use Saloon\Http\Response;

class JsonApiResponse extends Response
{
    /**
     * Get the first item from a JSON:API collection response
     */
    public function firstItem(): mixed
    {
        $data = $this->json('data');

        if (is_array($data) && isset($data[0])) {
            return $data[0];
        }

        return $data;
    }

    /**
     * Check if response contains JSON:API errors
     */
    public function hasErrors(): bool
    {
        return $this->json('errors') !== null;
    }

    /**
     * Get JSON:API errors
     */
    public function errors(): array
    {
        return $this->json('errors', []);
    }

    /**
     * Get meta information from JSON:API response
     */
    public function meta(): array
    {
        return $this->json('meta', []);
    }

    /**
     * Get links from JSON:API response
     */
    public function links(): array
    {
        return $this->json('links', []);
    }

    /**
     * Get included resources from JSON:API response
     */
    public function included(): array
    {
        return $this->json('included', []);
    }
}
