<?php

declare(strict_types=1);

namespace Keel\Tests\Http;

use Keel\Http\BodyParser;
use Keel\Http\CallableHandler;
use Keel\Http\Exception\BadRequestException;
use Keel\Http\Exception\HttpException;
use Keel\Http\Exception\MethodNotAllowedException;
use Keel\Http\Exception\NotFoundException;
use Keel\Http\Middleware\MethodOverrideMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[CoversClass(BodyParser::class)]
#[CoversClass(MethodOverrideMiddleware::class)]
#[CoversClass(HttpException::class)]
#[CoversClass(MethodNotAllowedException::class)]
#[CoversClass(NotFoundException::class)]
final class MiddlewareAndParsingTest extends TestCase
{
    public function testJsonBodyIsDecodedAndFormBodyPassedThrough(): void
    {
        $parser = new BodyParser();

        $json = new ServerRequest('POST', '/', ['Content-Type' => 'application/json; charset=utf-8'], '{"a":1}');
        self::assertSame(['a' => 1], $parser->parse($json));

        $problem = new ServerRequest('POST', '/', ['Content-Type' => 'application/problem+json'], '{"b":2}');
        self::assertSame(['b' => 2], $parser->parse($problem));

        $empty = new ServerRequest('POST', '/', ['Content-Type' => 'application/json'], '');
        self::assertSame([], $parser->parse($empty));

        $form = new ServerRequest('POST', '/', ['Content-Type' => 'application/x-www-form-urlencoded'])->withParsedBody(['c' => '3']);
        self::assertSame(['c' => '3'], $parser->parse($form));
    }

    public function testMalformedJsonIs400(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('Malformed JSON');
        new BodyParser()->parse(new ServerRequest('POST', '/', ['Content-Type' => 'application/json'], '{"a":'));
    }

    public function testScalarJsonIs400(): void
    {
        $this->expectException(BadRequestException::class);
        new BodyParser()->parse(new ServerRequest('POST', '/', ['Content-Type' => 'application/json'], '"just a string"'));
    }

    public function testMethodOverrideOnlyForPostAndAllowedMethods(): void
    {
        $seen = [];
        $handler = new CallableHandler(static function (ServerRequestInterface $request) use (&$seen): ResponseInterface {
            $seen[] = $request->getMethod();

            return new Psr17Factory()->createResponse();
        });
        $middleware = new MethodOverrideMiddleware();

        $middleware->process(new ServerRequest('POST', '/', ['X-HTTP-Method-Override' => 'delete']), $handler);
        $middleware->process(new ServerRequest('POST', '/', ['X-HTTP-Method-Override' => 'GET']), $handler);
        $middleware->process(new ServerRequest('GET', '/', ['X-HTTP-Method-Override' => 'DELETE']), $handler);
        $middleware->process(new ServerRequest('POST', '/'), $handler);

        self::assertSame(['DELETE', 'POST', 'GET', 'POST'], $seen);
    }

    public function testHttpExceptionsCarryStatusHeadersAndReason(): void
    {
        $notFound = new NotFoundException();
        self::assertSame(404, $notFound->status);
        self::assertSame('Not Found', $notFound->getMessage());

        $allowed = new MethodNotAllowedException(['GET', 'POST']);
        self::assertSame(405, $allowed->status);
        self::assertSame(['Allow' => 'GET, POST'], $allowed->headers);

        self::assertSame('Unprocessable Content', HttpException::reasonPhrase(422));
        self::assertSame('HTTP 599', HttpException::reasonPhrase(599));
    }


    public function testJsonNestedDeeperThanTheDecoderLimitIs400(): void
    {
        $tooDeep = str_repeat('{"a":', 600) . '1' . str_repeat('}', 600);

        try {
            new BodyParser()->parse(new ServerRequest('POST', '/', ['Content-Type' => 'application/json'], $tooDeep));
            self::fail('expected a 400');
        } catch (BadRequestException $e) {
            self::assertSame(400, $e->status);
            self::assertStringStartsWith('Malformed JSON body: Maximum stack depth exceeded', $e->getMessage());
        }

        $fits = str_repeat('{"a":', 500) . '1' . str_repeat('}', 500);
        self::assertArrayHasKey('a', new BodyParser()->parse(new ServerRequest('POST', '/', ['Content-Type' => 'application/json'], $fits)));
    }
}
