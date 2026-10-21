<?php

declare(strict_types=1);

namespace Keel\Tests\Validation;

use Keel\Http\Exception\BadRequestException;
use Keel\Http\Hydrator;
use Keel\Validation\Choice;
use Keel\Validation\Count;
use Keel\Validation\Email;
use Keel\Validation\Length;
use Keel\Validation\NotBlank;
use Keel\Validation\Pattern;
use Keel\Validation\Range;
use Keel\Validation\Url;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Hydrator::class)]
#[CoversClass(NotBlank::class)]
#[CoversClass(Length::class)]
#[CoversClass(Range::class)]
#[CoversClass(Count::class)]
#[CoversClass(Pattern::class)]
#[CoversClass(Email::class)]
#[CoversClass(Url::class)]
#[CoversClass(Choice::class)]
final class ValidationTest extends TestCase
{
    public function testValidPayloadPasses(): void
    {
        $signUp = new Hydrator()->hydrate(SignUp::class, [
            'name' => 'Ann',
            'email' => 'ann@example.test',
            'age' => '30',
            'slug' => 'ann-1',
            'plan' => 'pro',
            'tags' => ['a', 'b'],
            'site' => 'https://example.test/x',
            'nickname' => 'annie',
            'score' => 0.75,
        ]);

        self::assertSame('Ann', $signUp->name);
        self::assertSame(30, $signUp->age);
        self::assertSame('pro', $signUp->plan);
    }

    public function testEveryRuleReportsWithFieldPath(): void
    {
        try {
            new Hydrator()->hydrate(SignUp::class, [
                'name' => ' ',
                'email' => 'not-an-email',
                'age' => 15,
                'slug' => 'Not A Slug',
                'plan' => 'enterprise',
                'tags' => ['a', 'b', 'c', 'd'],
                'site' => 'http://insecure.test',
                'nickname' => '',
                'score' => 0.1,
            ]);
            self::fail('expected 400');
        } catch (BadRequestException $e) {
            self::assertSame([
                'name: must not be blank',
                'name: must be at least 2 characters',
                'email: must be a valid email address',
                'age: must be at least 18',
                'slug: must be a slug',
                'plan: must be one of free, pro',
                'tags: must have at most 3 items',
                'site: must be a valid URL',
                'nickname: must not be blank',
                'score: must be at least 0.5',
            ], $e->errors);
        }
    }

    public function testRulesSkipNullExceptNotBlankAndSkipMissingFields(): void
    {
        $signUp = new Hydrator()->hydrate(SignUp::class, ['name' => 'Bob', 'email' => 'b@c.d', 'age' => 40, 'site' => null]);
        self::assertNull($signUp->site);
        self::assertNull($signUp->nickname, 'missing field keeps its default without validation');

        try {
            new Hydrator()->hydrate(SignUp::class, ['name' => 'Bob', 'email' => 'b@c.d', 'age' => 40, 'nickname' => null]);
            self::fail('expected 400');
        } catch (BadRequestException $e) {
            self::assertSame(['nickname: must not be blank'], $e->errors);
        }
    }

    public function testTypeErrorsSuppressRuleChecks(): void
    {
        try {
            new Hydrator()->hydrate(SignUp::class, ['name' => 'Bob', 'email' => 'b@c.d', 'age' => 'old']);
            self::fail('expected 400');
        } catch (BadRequestException $e) {
            self::assertSame(['age: must be an integer'], $e->errors, 'no Range message on top of the type error');
        }
    }
}
