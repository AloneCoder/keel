<?php

declare(strict_types=1);

namespace Keel\Testing;

use PHPUnit\Framework\Assert;
use Psr\Http\Message\ResponseInterface;

/**
 * A response with fluent PHPUnit assertions. Needs phpunit/phpunit (a dev dependency of your app).
 */
final class TestResponse
{
    private ?string $body = null;

    public function __construct(public readonly ResponseInterface $response) {}

    public function status(): int
    {
        return $this->response->getStatusCode();
    }

    public function header(string $name): string
    {
        return $this->response->getHeaderLine($name);
    }

    public function body(): string
    {
        return $this->body ??= (string) $this->response->getBody();
    }

    /**
     * @return array<mixed>
     */
    public function json(): array
    {
        $decoded = json_decode($this->body(), true, 512, \JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \UnexpectedValueException('Response body is not a JSON object or array: ' . $this->body());
        }

        return $decoded;
    }

    /**
     * Value at a dot path ("user.address.city") or null when absent.
     */
    public function jsonPath(string $path): mixed
    {
        $value = $this->json();
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function assertStatus(int $expected): self
    {
        Assert::assertSame($expected, $this->status(), sprintf('Expected status %d, got %d. Body: %s', $expected, $this->status(), $this->excerpt()));

        return $this;
    }

    public function assertOk(): self
    {
        return $this->assertStatus(200);
    }

    public function assertHeader(string $name, ?string $expected = null): self
    {
        Assert::assertTrue($this->response->hasHeader($name), sprintf('Header "%s" is missing', $name));
        if ($expected !== null) {
            Assert::assertSame($expected, $this->header($name), sprintf('Header "%s" differs', $name));
        }

        return $this;
    }

    public function assertHeaderMissing(string $name): self
    {
        Assert::assertFalse($this->response->hasHeader($name), sprintf('Header "%s" should be absent', $name));

        return $this;
    }

    public function assertBodyContains(string $needle): self
    {
        Assert::assertStringContainsString($needle, $this->body());

        return $this;
    }

    /**
     * Every key of $subset must be present with the same value; nested arrays are compared recursively.
     *
     * @param array<mixed> $subset
     */
    public function assertJson(array $subset): self
    {
        self::assertSubset($subset, $this->json(), '');

        return $this;
    }

    public function assertJsonPath(string $path, mixed $expected): self
    {
        Assert::assertSame($expected, $this->jsonPath($path), sprintf('JSON path "%s" differs. Body: %s', $path, $this->excerpt()));

        return $this;
    }

    public function assertJsonMissing(string $path): self
    {
        Assert::assertNull($this->jsonPath($path), sprintf('JSON path "%s" should be absent', $path));

        return $this;
    }

    /**
     * Asserts an RFC 9457 Problem Details response.
     *
     * @param list<string>|null $errors expected "errors" entries, in order
     */
    public function assertProblem(int $status, ?string $detail = null, ?array $errors = null): self
    {
        $this->assertStatus($status);
        Assert::assertStringStartsWith('application/problem+json', $this->header('Content-Type'));
        Assert::assertSame($status, $this->jsonPath('status'));
        if ($detail !== null) {
            Assert::assertSame($detail, $this->jsonPath('detail'));
        }
        if ($errors !== null) {
            Assert::assertSame($errors, $this->jsonPath('errors'));
        }

        return $this;
    }

    /**
     * @param array<mixed> $subset
     * @param array<mixed> $actual
     */
    private static function assertSubset(array $subset, array $actual, string $path): void
    {
        foreach ($subset as $key => $expected) {
            $here = $path === '' ? (string) $key : $path . '.' . $key;
            Assert::assertArrayHasKey($key, $actual, sprintf('JSON key "%s" is missing', $here));
            if (is_array($expected) && is_array($actual[$key])) {
                self::assertSubset($expected, $actual[$key], $here);
            } else {
                Assert::assertSame($expected, $actual[$key], sprintf('JSON key "%s" differs', $here));
            }
        }
    }

    private function excerpt(): string
    {
        $body = $this->body();

        return strlen($body) > 300 ? substr($body, 0, 300) . '…' : $body;
    }
}
