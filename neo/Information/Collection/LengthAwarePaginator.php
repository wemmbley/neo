<?php

namespace Neo\Information\Collection;

class LengthAwarePaginator extends AbstractPaginator
{
    protected int $total;
    protected int $lastPage;

    public function __construct(mixed $items, int $total, int $perPage, ?int $currentPage = null, array $options = [])
    {
        $this->setOptions($options);

        $this->total = $total;
        $this->perPage = max($perPage, 1);
        $this->lastPage = max((int) ceil($total / $this->perPage), 1);
        $this->currentPage = $this->setCurrentPage($currentPage);
        $this->items = $items instanceof Collection ? $items : new Collection($items);
    }

    public function total(): int
    {
        return $this->total;
    }

    public function lastPage(): int
    {
        return $this->lastPage;
    }

    public function onLastPage(): bool
    {
        return $this->currentPage >= $this->lastPage;
    }

    public function hasMorePages(): bool
    {
        return $this->currentPage < $this->lastPage;
    }

    public function nextPageUrl(): ?string
    {
        return $this->hasMorePages() ? $this->url($this->currentPage + 1) : null;
    }

    /**
     * Page link window (same algorithm as Laravel's UrlWindow).
     *
     * @return array<int, array<int,string>|string>
     */
    public function elements(): array
    {
        $last = $this->lastPage;
        $side = $this->onEachSide;
        $current = $this->currentPage;

        if ($last < ($side * 2) + 8) {
            return [$this->getUrlRange(1, $last)];
        }

        $window = $side + 4;
        $slider = null;

        if ($current <= $window) {
            $first = $this->getUrlRange(1, $window + $side);
            $end = $this->getUrlRange($last - 1, $last);
        } elseif ($current > ($last - $window)) {
            $first = $this->getUrlRange(1, 2);
            $end = $this->getUrlRange($last - ($window + ($side - 1)), $last);
        } else {
            $first = $this->getUrlRange(1, 2);
            $slider = $this->getUrlRange($current - $side, $current + $side);
            $end = $this->getUrlRange($last - 1, $last);
        }

        return array_values(array_filter([
            $first,
            is_array($slider) ? '...' : null,
            $slider,
            '...',
            $end,
        ]));
    }

    public function linkCollection(): Collection
    {
        $links = [[
            'url' => $this->previousPageUrl(),
            'label' => '&laquo; Previous',
            'active' => false,
        ]];

        foreach ($this->elements() as $element) {
            if (is_string($element)) {
                $links[] = ['url' => null, 'label' => '...', 'active' => false];
            } elseif (is_array($element)) {
                foreach ($element as $page => $url) {
                    $links[] = [
                        'url' => $url,
                        'label' => (string) $page,
                        'active' => $page === $this->currentPage,
                    ];
                }
            }
        }

        $links[] = [
            'url' => $this->nextPageUrl(),
            'label' => 'Next &raquo;',
            'active' => false,
        ];

        return new Collection($links);
    }

    public function toArray(): array
    {
        return [
            'current_page' => $this->currentPage(),
            'data' => $this->items->toArray(),
            'first_page_url' => $this->url(1),
            'from' => $this->firstItem(),
            'last_page' => $this->lastPage(),
            'last_page_url' => $this->url($this->lastPage()),
            'links' => $this->linkCollection()->toArray(),
            'next_page_url' => $this->nextPageUrl(),
            'path' => $this->path(),
            'per_page' => $this->perPage(),
            'prev_page_url' => $this->previousPageUrl(),
            'to' => $this->lastItem(),
            'total' => $this->total(),
        ];
    }
}
