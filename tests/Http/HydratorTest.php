<?php

declare(strict_types=1);

namespace Keel\Tests\Http;

use Keel\Http\Exception\BadRequestException;
use Keel\Http\Hydrator;
use Keel\Tests\Kernel\Fixtures\Address;
use Keel\Tests\Kernel\Fixtures\Booking;
use Keel\Tests\Kernel\Fixtures\BrokenDto;
use Keel\Tests\Kernel\Fixtures\CreateUser;
use Keel\Tests\Kernel\Fixtures\DateRange;
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

    public function testIntegersOutOfRangeAreRejectedInsteadOfSaturating(): void
    {
        $hydrator = new Hydrator();
        $int = new \ReflectionFunction(static fn(int $i): int => $i)->getParameters()[0];

        self::assertSame(\PHP_INT_MAX, $hydrator->cast((string) \PHP_INT_MAX, $int, 'i'));
        self::assertSame(\PHP_INT_MIN, $hydrator->cast((string) \PHP_INT_MIN, $int, 'i'));
        self::assertSame(7, $hydrator->cast('007', $int, 'i'), 'leading zeros are still digits');
        self::assertSame(-0, $hydrator->cast('-0', $int, 'i'));
        self::assertSame(1_000_000, $hydrator->cast(1e6, $int, 'i'));

        foreach (['99999999999999999999', '9223372036854775808', '-9223372036854775809', "12\n", 1e20, 9.3e18, -1e19, 1.5, \NAN, \INF] as $bad) {
            try {
                $hydrator->cast($bad, $int, 'i');
                self::fail(var_export($bad, true) . ' must be rejected');
            } catch (BadRequestException $e) {
                self::assertSame(['i: must be an integer'], $e->errors, var_export($bad, true));
            }
        }
    }

    public function testUnionMembersAreTriedMostSpecificFirstRegardlessOfDeclarationOrder(): void
    {
        $hydrator = new Hydrator();
        $parameters = new \ReflectionFunction(static fn(int|string $a, string|int $b, int|float $c, bool|string $d, string|float $e): int => 0)->getParameters();

        self::assertSame(42, $hydrator->cast('42', $parameters[0], 'a'), 'int|string: a numeric string becomes an int');
        self::assertSame(42, $hydrator->cast('42', $parameters[1], 'b'), 'string|int reflects as the same type and behaves the same');
        self::assertSame('x', $hydrator->cast('x', $parameters[0], 'a'));
        self::assertSame(1.5, $hydrator->cast('1.5', $parameters[2], 'c'));
        self::assertSame(2, $hydrator->cast('2', $parameters[2], 'c'), 'int before float');
        self::assertTrue($hydrator->cast('true', $parameters[3], 'd'), 'bool before string');
        self::assertSame('maybe', $hydrator->cast('maybe', $parameters[3], 'd'));
        self::assertSame(3.0, $hydrator->cast('3', $parameters[4], 'e'), 'float before string');

        $user = $hydrator->hydrate(CreateUser::class, ['name' => 'a', 'email' => 'b', 'ref' => '42']);
        self::assertSame(42, $user->ref);
    }

    public function testNullForNonNullableIsAnError(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('Invalid request data');
        new Hydrator()->hydrate(SearchQuery::class, ['q' => null]);
    }


    public function testConstructorRejectionIsAFieldErrorWhileOtherConstructorFailuresPropagate(): void
    {
        try {
            new Hydrator()->hydrate(Booking::class, ['title' => 'x', 'range' => ['start' => 5, 'end' => 1]]);
            self::fail('expected a BadRequestException');
        } catch (BadRequestException $e) {
            self::assertSame(['range: end must not be before start'], $e->errors, 'nested DTO: prefixed with the field path');
        }

        try {
            new Hydrator()->hydrate(DateRange::class, ['start' => 5, 'end' => 1]);
            self::fail('expected a BadRequestException');
        } catch (BadRequestException $e) {
            self::assertSame(['end must not be before start'], $e->errors, 'top level: no prefix');
        }

        self::assertSame(4, new Hydrator()->hydrate(DateRange::class, ['start' => 1, 'end' => 5])->end - 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('lookup table not loaded');
        new Hydrator()->hydrate(BrokenDto::class, ['name' => 'x']);
    }
}
