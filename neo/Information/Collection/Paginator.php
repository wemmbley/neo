<?php

namespace Neo\Information\Collection;

class Paginator extends AbstractPaginator
{
    protected bool $hasMore = false;

    public function __construct(mixed $items, int $perPage, ?int $currentPage = null, array $options = [])
    {
        $this->setOptions($options);

        $this->perPage = max($perPage, 1);
        $this->currentPage = $this->setCurrentPage($currentPage);

        $items = $items instanceof Collection ? $items : new Collection($items);

        // The caller fetches perPage + 1 rows to detect whether another page exists.
        $this->hasMore = $items->count() > $this->perPage;
        $this->items = $items->slice(0, $this->perPage);
    }

    public function hasMorePages(): bool
    {
        return $this->hasMore;
    }

    public function nextPageUrl(): ?string
    {
        return $this->hasMorePages() ? $this->url($this->currentPage + 1) : null;
    }

    public function toArray(): array
    {
        return [
            'current_page' => $this->currentPage(),
            'data' => $this->items->toArray(),
            'first_page_url' => $this->url(1),
            'from' => $this->firstItem(),
            'next_page_url' => $this->nextPageUrl(),
            'path' => $this->path(),
            'per_page' => $this->perPage(),
            'prev_page_url' => $this->previousPageUrl(),
            'to' => $this->lastItem(),
        ];
    }
}
