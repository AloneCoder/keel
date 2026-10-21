<?php

declare(strict_types=1);

namespace Keel\Tests\Routing;

use Keel\Routing\Exception\RoutingException;
use Keel\Routing\PathParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PathParser::class)]
final class PathParserTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string|null, 2: list<string>}>
     */
    public static function paths(): iterable
    {
        yield 'root' => ['/', null, []];
        yield 'static' => ['/users/list', null, []];
        yield 'placeholder' => ['/users/{id}', '/users/([^/]+)', ['id']];
        yield 'constrained' => ['/users/{id:\d+}', '/users/(\d+)', ['id']];
        yield 'two placeholders' => ['/u/{id:\d+}/p/{slug}', '/u/(\d+)/p/([^/]+)', ['id', 'slug']];
        yield 'capturing group neutralised' => ['/f/{name:(a|b)}', '/f/((?:a|b))', ['name']];
        yield 'nested braces in quantifier' => ['/y/{year:\d{4}}', '/y/(\d{4})', ['year']];
        yield 'dot in static part is escaped' => ['/file.json', null, []];
        yield 'dot before placeholder is escaped' => ['/v1.{v}', '/v1\.([^/]+)', ['v']];
        yield 'parenthesis inside a class is literal' => ['/p/{x:[(]+}', '/p/([(]+)', ['x']];
        yield 'escaped parenthesis is literal' => ['/p/{x:\(\d+\)}', '/p/(\(\d+\))', ['x']];
        yield 'group after an escaped backslash is neutralised' => ['/p/{x:\\\\(\d+)}', '/p/(\\\\(?:\d+))', ['x']];
        yield 'non-capturing and lookahead groups are kept' => ['/p/{x:(?:a|b)(?=c)c}', '/p/((?:a|b)(?=c)c)', ['x']];
    }

    public function testExposesSanitisedPatternsPerPlaceholder(): void
    {
        $parsed = new PathParser()->parse('/u/{id:\d+}/{name}/{t:(a|b)}');

        self::assertSame(['\d+', '[^/]+', '(?:a|b)'], $parsed['patterns']);
        self::assertSame([], new PathParser()->parse('/static')['patterns']);
    }

    /**
     * @param list<string> $variables
     */
    #[DataProvider('paths')]
    public function testParsesPlaceholders(string $path, ?string $regex, array $variables): void
    {
        $parsed = new PathParser()->parse($path);

        self::assertSame($regex, $parsed['regex']);
        self::assertSame($variables, $parsed['variables']);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function invalidPaths(): iterable
    {
        yield 'no leading slash' => ['users'];
        yield 'empty' => [''];
        yield 'duplicate placeholder' => ['/{a}/{a}'];
        yield 'invalid pattern' => ['/{a:[}'];
        yield 'unclosed brace' => ['/{a'];
        yield 'bad name' => ['/{1a}'];
        yield 'named group would shift the group count' => ['/{a:(?<n>\d+)}'];
        yield 'python-style named group' => ['/{a:(?P<n>\d+)}'];
        yield 'pattern that closes the enclosing group' => ['/{a:\d+)|(.*}'];
        yield 'delimiter inside pattern' => ['/{a:a~b}'];
    }

    #[DataProvider('invalidPaths')]
    public function testRejectsInvalidPaths(string $path): void
    {
        $this->expectException(RoutingException::class);
        new PathParser()->parse($path);
    }
}
