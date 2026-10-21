<?php

declare(strict_types=1);

namespace Keel\Tests\Http;

use Keel\Http\Responder;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Responder::class)]
final class ResponderTest extends TestCase
{
    private function responder(): Responder
    {
        $factory = new Psr17Factory();

        return new Responder($factory, $factory);
    }

    public function testConvertsReturnValues(): void
    {
        $responder = $this->responder();

        $json = $responder->respond(['ok' => true, 'name' => 'Жюль', 'url' => 'a/b', 'f' => 1.0]);
        self::assertSame(200, $json->getStatusCode());
        self::assertSame('application/json', $json->getHeaderLine('Content-Type'));
        self::assertSame('{"ok":true,"name":"Жюль","url":"a/b","f":1.0}', (string) $json->getBody());

        $html = $responder->respond('<p>x</p>');
        self::assertSame('text/html; charset=utf-8', $html->getHeaderLine('Content-Type'));

        self::assertSame(204, $responder->respond(null)->getStatusCode());
        self::assertSame(201, $responder->respond(['id' => 1], 201)->getStatusCode());
        self::assertSame(202, $responder->respond(null, 202)->getStatusCode());

        $raw = new Psr17Factory()->createResponse(418);
        self::assertSame($raw, $responder->respond($raw));
        self::assertSame(409, $responder->respond($raw, 409)->getStatusCode());
    }

    public function testJsonSerializableObjects(): void
    {
        $object = new class implements \JsonSerializable {
            /** @return array<string, int> */
            public function jsonSerialize(): array
            {
                return ['x' => 1];
            }
        };

        self::assertSame('{"x":1}', (string) $this->responder()->respond($object)->getBody());
    }
}
