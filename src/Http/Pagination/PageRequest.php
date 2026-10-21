<?php

declare(strict_types=1);

namespace Keel\Http\Pagination;

use Keel\Validation\Range;

/**
 * Page number and size from the query string: declare `#[FromQuery] PageRequest $page` on a
 * handler and ?page=3&perPage=50 arrives validated (page >= 1, 1 <= perPage <= 100). Subclass
 * with your own constructor to change the defaults or the bounds; offset() and limit() feed the
 * query.
 */
readonly class PageRequest
{
    public const int DEFAULT_PER_PAGE = 20;

    public const int MAX_PER_PAGE = 100;

    public function __construct(
        #[Range(min: 1)]
        public int $page = 1,
        #[Range(min: 1, max: self::MAX_PER_PAGE)]
        public int $perPage = self::DEFAULT_PER_PAGE,
    ) {}

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    public function limit(): int
    {
        return $this->perPage;
    }
}
