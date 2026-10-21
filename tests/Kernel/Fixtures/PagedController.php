<?php

declare(strict_types=1);

namespace Keel\Tests\Kernel\Fixtures;

use Keel\Http\Attribute\FromQuery;
use Keel\Http\Pagination\Page;
use Keel\Http\Pagination\PageRequest;
use Keel\OpenApi\Attribute\Response;
use Keel\Routing\Attribute\Get;

final class PagedController
{
    /**
     * @return Page<Address>
     */
    #[Get('/addresses')]
    public function addresses(#[FromQuery] PageRequest $page): Page
    {
        $all = [];
        for ($i = 1; $i <= 45; $i++) {
            $all[] = new Address('City ' . $i, (string) (1000 + $i));
        }

        return Page::of(array_slice($all, $page->offset(), $page->limit()), $page, count($all));
    }

    /**
     * @return Page<Address>
     */
    #[Get('/declared')]
    #[Response(200, 'A page of addresses', Page::class, Address::class)]
    public function declared(#[FromQuery] PageRequest $page): Page
    {
        return Page::of([], $page, 0);
    }
}
