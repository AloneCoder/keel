<?php

declare(strict_types=1);

namespace Keel\Tests\Http\Fixtures;

use Keel\Http\Attribute\Deprecated;
use Keel\Http\EventStream;
use Keel\Http\Responder;
use Keel\Http\ServerTiming;
use Keel\Http\SseEvent;
use Keel\Routing\Attribute\Get;
use Psr\Http\Message\ResponseInterface;

final class ModernController
{
    /** @return array<string, string> */
    #[Get('/v1/users')]
    #[Deprecated(since: '2026-07-01', sunset: '2027-01-01T00:00:00Z', link: 'https://docs.test/v2', successor: 'https://api.test/v2/users')]
    public function legacy(): array
    {
        return ['version' => '1'];
    }

    /** @return array<string, string> */
    #[Get('/v1/old')]
    #[Deprecated]
    public function old(): array
    {
        return ['version' => '0'];
    }

    /** @return array<string, int> */
    #[Get('/timed')]
    public function timed(ServerTiming $timing): array
    {
        $answer = $timing->measure('db', static fn(): int => 42, 'Lookup');
        $timing->add('cache-miss');

        return ['answer' => $answer];
    }

    #[Get('/events')]
    public function events(): EventStream
    {
        return new EventStream(static function (): \Generator {
            yield new SseEvent(['n' => 1], event: 'tick', id: '1');
            yield 'plain text';
            yield SseEvent::comment('ping');
        }, ['X-Stream' => 'yes']);
    }

    #[Get('/ndjson')]
    public function ndjson(Responder $responder): ResponseInterface
    {
        return $responder->stream(static function (): \Generator {
            foreach ([['id' => 1], ['id' => 2]] as $row) {
                yield json_encode($row, \JSON_THROW_ON_ERROR) . "\n";
            }
        }, 'application/x-ndjson');
    }
}
