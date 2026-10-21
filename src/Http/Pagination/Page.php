<?php

declare(strict_types=1);

namespace Keel\Http\Pagination;

/**
 * One page of results. Returned from a handler it becomes
 * {"items": [...], "page": 2, "perPage": 20, "total": 93, "pages": 5}; declare the element type
 * for OpenAPI with `@return Page<Item>` or `#[Response(200, type: Page::class, items: Item::class)]`.
 *
 * @template T
 */
final readonly class Page implements \JsonSerializable
{
    /**
     * @param list<T> $items the items of this page only
     * @param int     $total items across every page
     */
    public function __construct(
        public array $items,
        public int $page,
        public int $perPage,
        public int $total,
    ) {
        if ($page < 1 || $perPage < 1 || $total < 0) {
            throw new \InvalidArgumentException(sprintf('A page needs page >= 1, perPage >= 1 and total >= 0, got %d, %d and %d', $page, $perPage, $total));
        }
    }

    /**
     * @template U
     *
     * @param list<U> $items
     *
     * @return self<U>
     */
    public static function of(array $items, PageRequest $request, int $total): self
    {
        return new self($items, $request->page, $request->perPage, $total);
    }

    /**
     * Number of pages; 0 when there is nothing.
     */
    public function pages(): int
    {
        return $this->total === 0 ? 0 : intdiv($this->total - 1, $this->perPage) + 1;
    }

    public function hasNext(): bool
    {
        return $this->page < $this->pages();
    }

    public function hasPrevious(): bool
    {
        return $this->page > 1;
    }

    /**
     * @return array{items: list<T>, page: int, perPage: int, total: int, pages: int}
     */
    public function jsonSerialize(): array
    {
        return [
            'items' => $this->items,
            'page' => $this->page,
            'perPage' => $this->perPage,
            'total' => $this->total,
            'pages' => $this->pages(),
        ];
    }
}
