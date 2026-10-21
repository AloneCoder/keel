<?php

declare(strict_types=1);

namespace Keel\Tests\Http;

use Keel\Container\Definitions;
use Keel\Http\Pagination\Page;
use Keel\Http\Pagination\PageRequest;
use Keel\Kernel\Kernel;
use Keel\OpenApi\Generator;
use Keel\Routing\Router;
use Keel\Tests\Kernel\Fixtures\PagedController;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(Page::class)]
#[CoversClass(PageRequest::class)]
final class PaginationTest extends TestCase
{
    /**
     * @return array<mixed>
     */
    private static function json(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    public function testPageMathAndSerialization(): void
    {
        $request = new PageRequest(page: 3, perPage: 10);
        self::assertSame(20, $request->offset());
        self::assertSame(10, $request->limit());

        $page = Page::of(['a', 'b'], $request, 45);
        self::assertSame(5, $page->pages());
        self::assertTrue($page->hasNext());
        self::assertTrue($page->hasPrevious());
        self::assertSame(['items' => ['a', 'b'], 'page' => 3, 'perPage' => 10, 'total' => 45, 'pages' => 5], $page->jsonSerialize());

        self::assertSame(0, new Page([], 1, 20, 0)->pages(), 'nothing: zero pages');
        self::assertFalse(new Page([], 1, 20, 0)->hasNext());
        self::assertSame(1, new Page([], 1, 20, 20)->pages());
        self::assertSame(2, new Page([], 1, 20, 21)->pages());

        self::assertSame(1, new PageRequest()->page);
        self::assertSame(PageRequest::DEFAULT_PER_PAGE, new PageRequest()->perPage);

        $this->expectException(\InvalidArgumentException::class);
        new Page([], 0, 20, 0);
    }

    public function testQueryStringDrivesThePageAndIsValidated(): void
    {
        $kernel = Kernel::build(new Definitions(), [PagedController::class]);

        $body = self::json($kernel->handle(new ServerRequest('GET', '/addresses?page=3&perPage=20')));
        self::assertSame(3, $body['page']);
        self::assertSame(20, $body['perPage']);
        self::assertSame(45, $body['total']);
        self::assertSame(3, $body['pages']);
        $items = $body['items'];
        self::assertIsArray($items);
        self::assertCount(5, $items, 'the last page holds the remaining 5 of 45');
        self::assertSame(['city' => 'City 41', 'zip' => '1041'], $items[0]);

        $body = self::json($kernel->handle(new ServerRequest('GET', '/addresses')));
        $items = $body['items'];
        self::assertIsArray($items);
        self::assertSame([1, 20, 20], [$body['page'], $body['perPage'], count($items)], 'defaults');

        $response = $kernel->handle(new ServerRequest('GET', '/addresses?page=0&perPage=500'));
        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['page: must be at least 1', 'perPage: must be at most 100'], self::json($response)['errors']);
    }

    public function testOpenApiDocumentsThePageEnvelopeWithItsElementType(): void
    {
        $document = json_decode(json_encode(new Generator(Router::fromControllers(PagedController::class))->generate(), \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        $expected = [
            'type' => 'object',
            'properties' => [
                'items' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Address']],
                'page' => ['type' => 'integer', 'minimum' => 1],
                'perPage' => ['type' => 'integer', 'minimum' => 1],
                'total' => ['type' => 'integer', 'minimum' => 0],
                'pages' => ['type' => 'integer', 'minimum' => 0],
            ],
            'required' => ['items', 'page', 'perPage', 'total', 'pages'],
        ];

        self::assertSame($expected, self::node($document, 'paths', '/addresses', 'get', 'responses', '200', 'content', 'application/json', 'schema'), 'from @return Page<Address>');
        self::assertSame(
            [['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]], ['name' => 'perPage', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20]]],
            self::node($document, 'paths', '/addresses', 'get', 'parameters'),
        );
        self::assertSame($expected, self::node($document, 'paths', '/declared', 'get', 'responses', '200', 'content', 'application/json', 'schema'), 'from #[Response(type: Page::class, items: Address::class)]');
        self::assertArrayHasKey('Address', self::node($document, 'components', 'schemas'));
    }

    /**
     * @param array<mixed> $doc
     *
     * @return array<string, mixed>
     */
    private static function node(array $doc, string ...$keys): array
    {
        $value = $doc;
        foreach ($keys as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value, 'missing ' . implode('.', $keys));
            $value = $value[$key];
        }
        self::assertIsArray($value);

        /** @var array<string, mixed> $value */
        return $value;
    }
}
