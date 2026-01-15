<?php

declare(strict_types=1);

namespace JsonApiSdk\Foundation\Pagination;

use Illuminate\Support\Collection;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Saloon\PaginationPlugin\Paginator;

class JsonApiPaginator extends Paginator
{
    public function dtoCollection(): Collection
    {
        return parent::collect()->collect();
    }

    protected function isLastPage(Response $response): bool
    {
        return $response->json('links.next') === null;
    }

    protected function getPageItems(Response $response, Request $request): array
    {
        return $response->dto()->toArray();
    }

    protected function applyPagination(Request $request): Request
    {
        $request->query()->add('page[number]', $this->page);

        if (isset($this->perPageLimit)) {
            $request->query()->add('page[size]', $this->perPageLimit);
        }

        return $request;
    }
}
