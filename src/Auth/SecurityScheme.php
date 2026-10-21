<?php

declare(strict_types=1);

namespace Keel\Auth;

/**
 * How an authenticator is described in OpenAPI components.securitySchemes.
 */
final readonly class SecurityScheme
{
    /**
     * @param string               $name       key under securitySchemes, e.g. "bearer"
     * @param array<string, mixed> $definition the Security Scheme Object, e.g. ['type' => 'http', 'scheme' => 'bearer']
     */
    public function __construct(public string $name, public array $definition) {}

    public static function bearer(string $name = 'bearer', ?string $format = null): self
    {
        $definition = ['type' => 'http', 'scheme' => 'bearer'];
        if ($format !== null) {
            $definition['bearerFormat'] = $format;
        }

        return new self($name, $definition);
    }

    public static function apiKey(string $name, string $header): self
    {
        return new self($name, ['type' => 'apiKey', 'in' => 'header', 'name' => $header]);
    }
}
