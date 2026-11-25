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

    /** Общее число записей неизвестно, поэтому `last` нет. */
    public function links(): array
    {
        return [
            'first' => $this->url(1),
            'prev' => $this->previousPageUrl(),
            'next' => $this->nextPageUrl(),
        ];
    }

    public function pages(): array
    {
        return [
            'current_page' => $this->currentPage(),
            'per_page' => $this->perPage(),
        ];
    }
}
