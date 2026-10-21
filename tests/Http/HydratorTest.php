<?php

declare(strict_types=1);

namespace Keel\Tests\Http;

use Keel\Http\Exception\BadRequestException;
use Keel\Http\Hydrator;
use Keel\Tests\Kernel\Fixtures\Address;
use Keel\Tests\Kernel\Fixtures\CreateUser;
use Keel\Tests\Kernel\Fixtures\Role;
use Keel\Tests\Kernel\Fixtures\SearchQuery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Hydrator::class)]
final class HydratorTest extends TestCase
{
    public function testHydratesNestedDtoWithCastsEnumsAndDefaults(): void
    {
        $user = new Hydrator()->hydrate(CreateUser::class, [
            'name' => 'Ann',
            'email' => 'ann@example.test',
            'age' => '31',
            'address' => ['city' => 'Oslo', 'zip' => 1234],
            'role' => 'admin',
            'tags' => ['a', 'b'],
            'ref' => 'abc',
        ]);

        self::assertSame('Ann', $user->name);
        self::assertSame(31, $user->age);
        self::assertInstanceOf(Address::class, $user->address);
        self::assertSame('1234', $user->address->zip);
        self::assertSame(Role::Admin, $user->role);
        self::assertSame(['a', 'b'], $user->tags);
        self::assertSame('abc', $user->ref, 'union int|string keeps a non-numeric string');

        $minimal = new Hydrator()->hydrate(CreateUser::class, ['name' => 'Bob', 'email' => 'b@x']);
        self::assertSame(0, $minimal->age);
        self::assertNull($minimal->address);
        self::assertSame(Role::User, $minimal->role);
    }

    public function testCollectsEveryErrorWithFieldPaths(): void
    {
        try {
            new Hydrator()->hydrate(CreateUser::class, [
                'email' => ['not', 'a', 'string'],
                'age' => 'old',
                'address' => ['city' => 'Oslo'],
                'role' => 'root',
                'tags' => 'x',
            ]);
            self::fail('expected 400');
        } catch (BadRequestException $e) {
            self::assertSame(400, $e->status);
            self::assertSame([
                'name: field is required',
                'email: must be a string',
                'age: must be an integer',
                'address.zip: field is required',
                'role: must be one of user, admin',
                'tags: must be an array',
            ], $e->errors);
        }
    }

    public function testBooleanAndNumberCoercionFromQueryStrings(): void
    {
        $query = new Hydrator()->hydrate(SearchQuery::class, ['q' => 'php', 'page' => '3', 'exact' => 'true']);

        self::assertSame(3, $query->page);
        self::assertTrue($query->exact);

        $query = new Hydrator()->hydrate(SearchQuery::class, ['q' => 'php', 'exact' => '0']);
        self::assertFalse($query->exact);

        $this->expectException(BadRequestException::class);
        new Hydrator()->hydrate(SearchQuery::class, ['q' => 'php', 'exact' => 'maybe']);
    }

    public function testNullForNonNullableIsAnError(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('Invalid request data');
        new Hydrator()->hydrate(SearchQuery::class, ['q' => null]);
    }
}
