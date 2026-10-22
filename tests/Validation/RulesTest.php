<?php

declare(strict_types=1);

namespace Keel\Tests\Validation;

use Keel\Http\Exception\BadRequestException;
use Keel\Http\Hydrator;
use Keel\OpenApi\SchemaBuilder;
use Keel\Validation\Alnum;
use Keel\Validation\Date;
use Keel\Validation\Each;
use Keel\Validation\Hostname;
use Keel\Validation\Ip;
use Keel\Validation\Json;
use Keel\Validation\NotBlank;
use Keel\Validation\Timezone;
use Keel\Validation\Unique;
use Keel\Validation\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Uuid::class)]
#[CoversClass(Ip::class)]
#[CoversClass(Date::class)]
#[CoversClass(Alnum::class)]
#[CoversClass(Each::class)]
#[CoversClass(Unique::class)]
#[CoversClass(Hostname::class)]
#[CoversClass(Json::class)]
#[CoversClass(Timezone::class)]
#[CoversClass(SchemaBuilder::class)]
final class RulesTest extends TestCase
{
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

    public function testValidProfilePasses(): void
    {
        $profile = new Hydrator()->hydrate(Profile::class, [
            'id' => '123E4567-e89b-12d3-a456-426614174000',
            'handle' => 'ann_1-x',
            'born' => '1990-02-28',
            'seenAt' => '2026-10-22T10:00:00+02:00',
            'ip' => '2001:db8::1',
            'ip4' => '203.0.113.5',
            'host' => 'api.example.com.',
            'settings' => '{"theme":"dark"}',
            'tz' => 'Europe/Berlin',
            'tags' => ['a', 'b'],
            'servers' => ['localhost', 'db-1'],
        ]);

        self::assertSame('ann_1-x', $profile->handle);
        self::assertSame(['a', 'b'], $profile->tags);
    }

    public function testEveryNewRuleReportsWithFieldPath(): void
    {
        try {
            new Hydrator()->hydrate(Profile::class, [
                'id' => '123e4567-e89b-12d3-a456-42661417400',
                'handle' => 'ann 1',
                'born' => '1990-02-30',
                'seenAt' => '2026-10-22 10:00:00',
                'ip' => '300.1.1.1',
                'ip4' => '::1',
                'host' => '-bad.example',
                'settings' => '{"theme":',
                'tz' => 'CET+1',
                'tags' => ['a', '', 'a'],
                'servers' => ['ok', '127.0.0.1'],
            ]);
            self::fail('expected 400');
        } catch (BadRequestException $e) {
            self::assertSame([
                'id: must be a UUID',
                'handle: must contain only letters, digits and "-_"',
                'born: must be a date in the format Y-m-d',
                'seenAt: must be a date in the format ' . DATE_ATOM,
                'ip: must be an IP address',
                'ip4: must be an IPv4 address',
                'host: must be a hostname',
                'settings: must be valid JSON',
                'tz: must be a time zone identifier',
                'tags: must not contain duplicates',
                'tags: [1]: must not be blank',
                'servers: [1]: must be a hostname',
            ], $e->errors);
        }
    }

    public function testRuleEdgeCases(): void
    {
        self::assertNull(new Uuid()->check('00000000-0000-0000-0000-000000000000'));
        self::assertNull(new Uuid()->check(42), 'non-strings are left to the type cast');
        self::assertNull(new Alnum()->check(''));
        self::assertNotNull(new Alnum()->check('a-b'));
        self::assertNull(new Date('d.m.Y')->check('31.12.2026'));
        self::assertNotNull(new Date('d.m.Y')->check('2026-12-31'));
        self::assertNotNull(new Date()->check('2026-1-5'), 'the format is exact');
        self::assertNull(new Hostname(requireTld: false)->check('localhost'));
        self::assertNotNull(new Hostname()->check('localhost'));
        self::assertNotNull(new Hostname()->check('example.123'));
        self::assertNotNull(new Hostname()->check(str_repeat('a', 64) . '.com'));
        self::assertNull(new Json()->check('null'));
        self::assertNull(new Timezone()->check('UTC'));
        self::assertNull(new Unique()->check([1, '1', [1], [1, 2]]), 'strict: 1 and "1" differ');
        self::assertNotNull(new Unique()->check([[1], [1]]));
        self::assertNull(new Each(new NotBlank())->check('not an array'));
        self::assertSame('[k]: must not be blank', new Each(new NotBlank())->check(['k' => null]));
        self::assertNull(new Each(new Uuid())->check([null]), 'null items skip rules other than NotBlank');

        try {
            new Ip(version: 5);
            self::fail('version accepted');
        } catch (\InvalidArgumentException) {
        }

        $this->expectException(\InvalidArgumentException::class);
        new Each();
    }

    public function testOpenApiSchemaReflectsTheRules(): void
    {
        $builder = new SchemaBuilder();
        $builder->classSchema(Profile::class);
        $properties = self::node($builder->schemas(), 'Profile', 'properties');
        $property = static fn(string $name): array => self::node($properties, $name);

        self::assertSame('uuid', $property('id')['format']);
        self::assertSame('^[A-Za-z0-9\-_]*$', $property('handle')['pattern']);
        self::assertSame('date', $property('born')['format']);
        self::assertSame('date-time', $property('seenAt')['format']);
        self::assertArrayNotHasKey('format', $property('ip'));
        self::assertSame('ipv4', $property('ip4')['format']);
        self::assertSame('hostname', $property('host')['format']);
        self::assertArrayNotHasKey('format', $property('settings'));
        self::assertTrue($property('tags')['uniqueItems']);
        self::assertSame(5, $property('tags')['maxItems']);
        self::assertSame(['type' => 'string', 'minLength' => 1, 'maxLength' => 10], $property('tags')['items']);
        self::assertSame(['type' => 'string', 'format' => 'hostname'], $property('servers')['items']);
    }
}
