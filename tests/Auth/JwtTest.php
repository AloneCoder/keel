<?php

declare(strict_types=1);

namespace Keel\Tests\Auth;

use Keel\Auth\Identity;
use Keel\Auth\Jwt\ChainKeySet;
use Keel\Auth\Jwt\Der;
use Keel\Auth\Jwt\JwksKeySet;
use Keel\Auth\Jwt\Jwt;
use Keel\Auth\Jwt\JwtAuthenticator;
use Keel\Auth\Jwt\JwtConfig;
use Keel\Auth\Jwt\JwtException;
use Keel\Auth\Jwt\JwtKey;
use Keel\Auth\Jwt\JwtVerifier;
use Keel\Auth\Jwt\StaticKeySet;
use Keel\Cache\ArrayCache;
use Keel\Http\Exception\UnauthorizedException;
use Keel\Tests\Kernel\Fixtures\ArrayLogger;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(Jwt::class)]
#[CoversClass(JwtKey::class)]
#[CoversClass(JwtVerifier::class)]
#[CoversClass(JwtConfig::class)]
#[CoversClass(JwtAuthenticator::class)]
#[CoversClass(StaticKeySet::class)]
#[CoversClass(JwksKeySet::class)]
#[CoversClass(ChainKeySet::class)]
#[CoversClass(Der::class)]
final class JwtTest extends TestCase
{
    private const string SECRET = 'a-very-long-shared-secret-of-32-bytes!';

    private const int NOW = 1_800_000_000;

    /** @var array<string, array{private: \OpenSSLAsymmetricKey, public: string, jwk: array<string, string>}> */
    private static array $keys = [];

    private static string $edSecret;

    private static string $edPublic;

    public static function setUpBeforeClass(): void
    {
        $rsa = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        self::assertInstanceOf(\OpenSSLAsymmetricKey::class, $rsa);
        self::$keys['RSA'] = self::describe($rsa);
        foreach (['P-256' => 'prime256v1', 'P-384' => 'secp384r1', 'P-521' => 'secp521r1'] as $crv => $name) {
            $ec = openssl_pkey_new(['curve_name' => $name, 'private_key_type' => \OPENSSL_KEYTYPE_EC]);
            self::assertInstanceOf(\OpenSSLAsymmetricKey::class, $ec);
            self::$keys[$crv] = self::describe($ec, $crv);
        }
        $pair = sodium_crypto_sign_keypair();
        self::$edSecret = sodium_crypto_sign_secretkey($pair);
        self::$edPublic = sodium_crypto_sign_publickey($pair);
    }

    /**
     * @return array{private: \OpenSSLAsymmetricKey, public: string, jwk: array<string, string>}
     */
    private static function describe(\OpenSSLAsymmetricKey $key, ?string $curve = null): array
    {
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        self::assertIsString($details['key']);
        if ($curve === null) {
            self::assertIsArray($details['rsa']);
            ['n' => $n, 'e' => $e] = $details['rsa'];
            self::assertIsString($n);
            self::assertIsString($e);
            $jwk = ['kty' => 'RSA', 'n' => Jwt::base64UrlEncode($n), 'e' => Jwt::base64UrlEncode($e)];
        } else {
            self::assertIsArray($details['ec']);
            ['x' => $x, 'y' => $y] = $details['ec'];
            self::assertIsString($x);
            self::assertIsString($y);
            $length = Jwt::CURVES[$curve][1];
            $jwk = [
                'kty' => 'EC',
                'crv' => $curve,
                'x' => Jwt::base64UrlEncode(str_pad($x, $length, "\0", \STR_PAD_LEFT)),
                'y' => Jwt::base64UrlEncode(str_pad($y, $length, "\0", \STR_PAD_LEFT)),
            ];
        }

        return ['private' => $key, 'public' => $details['key'], 'jwk' => $jwk];
    }

    private static function clock(int $now = self::NOW): ClockInterface
    {
        return new class ($now) implements ClockInterface {
            public function __construct(private readonly int $now) {}

            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('@' . $this->now);
            }
        };
    }

    /**
     * @param array<string, mixed> $claims
     *
     * @return array<string, mixed>
     */
    private static function claims(array $claims = []): array
    {
        return $claims + ['sub' => 'user-1', 'exp' => self::NOW + 300, 'iat' => self::NOW];
    }

    /**
     * @param array<string, mixed> $claims
     */
    private static function signer(array $claims): string
    {
        return Jwt::encode(self::claims($claims), self::SECRET);
    }

    private static function verifier(JwtConfig $config, ?int $now = null): JwtVerifier
    {
        return new JwtVerifier($config, $config->staticKeys() ?? new StaticKeySet(), self::clock($now ?? self::NOW));
    }

    private static function rejects(JwtVerifier $verifier, string $token, string $reason): void
    {
        try {
            $verifier->verify($token);
            self::fail('token accepted, expected: ' . $reason);
        } catch (JwtException $e) {
            self::assertSame($reason, $e->getMessage());
        }
    }

    public function testEveryAlgorithmSignsAndVerifies(): void
    {
        $cases = [
            'HS256' => [self::SECRET, JwtKey::secret(self::SECRET)],
            'HS512' => [self::SECRET, JwtKey::secret(self::SECRET)],
            'RS256' => [self::$keys['RSA']['private'], JwtKey::publicKey(self::$keys['RSA']['public'])],
            'RS384' => [self::$keys['RSA']['private'], JwtKey::fromJwk(self::$keys['RSA']['jwk'])],
            'RS512' => [self::$keys['RSA']['private'], JwtKey::fromJwk(self::$keys['RSA']['jwk'])],
            'ES256' => [self::$keys['P-256']['private'], JwtKey::fromJwk(self::$keys['P-256']['jwk'])],
            'ES384' => [self::$keys['P-384']['private'], JwtKey::publicKey(self::$keys['P-384']['public'])],
            'ES512' => [self::$keys['P-521']['private'], JwtKey::fromJwk(self::$keys['P-521']['jwk'])],
            'EdDSA' => [self::$edSecret, JwtKey::fromJwk(['kty' => 'OKP', 'crv' => 'Ed25519', 'x' => Jwt::base64UrlEncode(self::$edPublic)])],
        ];
        foreach ($cases as $algorithm => [$signing, $key]) {
            self::assertInstanceOf(JwtKey::class, $key, $algorithm);
            $config = new JwtConfig(algorithms: [$algorithm], secret: self::SECRET);
            $verifier = new JwtVerifier($config, new StaticKeySet($key), self::clock());
            $token = Jwt::encode(self::claims(['alg-test' => $algorithm]), $signing, $algorithm);

            self::assertSame($algorithm, $verifier->verify($token)['alg-test'], $algorithm);
            [$head, $body, $signature] = explode('.', $token);
            $tampered = $head . '.' . Jwt::base64UrlEncode(json_encode(self::claims(['alg-test' => 'x']), \JSON_THROW_ON_ERROR)) . '.' . $signature;
            self::rejects($verifier, $tampered, 'Invalid signature');
            self::assertNotSame('', $body);
        }
    }

    public function testEd25519PemAndKeyTypes(): void
    {
        $pem = "-----BEGIN PUBLIC KEY-----\n" . base64_encode("\x30\x2A\x30\x05\x06\x03\x2B\x65\x70\x03\x21\x00" . self::$edPublic) . "\n-----END PUBLIC KEY-----\n";
        $key = JwtKey::publicKey($pem, 'ed');
        self::assertSame('OKP', $key->type);
        self::assertSame('ed', $key->id);
        self::assertTrue($key->supports('EdDSA'));
        self::assertFalse($key->supports('ES256'));
        self::assertTrue(JwtKey::publicKey(self::$keys['P-256']['public'])->supports('ES256'));
        self::assertFalse(JwtKey::publicKey(self::$keys['P-256']['public'])->supports('ES384'), 'the curve fixes the algorithm');
        self::assertFalse(JwtKey::secret(self::SECRET, algorithm: 'HS256')->supports('HS512'), 'a key that names an algorithm verifies only that one');
        self::assertFalse(JwtKey::secret(self::SECRET)->verify('x', 'y', 'RS256'));

        foreach ([
            static fn() => JwtKey::secret('short'),
            static fn() => JwtKey::publicKey('not a key'),
            static fn() => JwtKey::secret(self::SECRET, algorithm: 'RS256'),
        ] as $i => $case) {
            try {
                $case();
                self::fail('case ' . $i . ' accepted');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testJwkFiltering(): void
    {
        self::assertNull(JwtKey::fromJwk(['kty' => 'RSA', 'use' => 'enc', 'n' => 'AQAB', 'e' => 'AQAB']), 'encryption keys are skipped');
        self::assertNull(JwtKey::fromJwk(['kty' => 'EC', 'crv' => 'secp256k1', 'x' => 'AA', 'y' => 'AA']));
        self::assertNull(JwtKey::fromJwk(['kty' => 'RSA', 'alg' => 'PS256', 'n' => 'AQAB', 'e' => 'AQAB']));
        self::assertNull(JwtKey::fromJwk(['kty' => 'RSA', 'n' => 'AQAB', 'e' => 'AQAB']), 'a 17-bit RSA key is refused');
        self::assertNull(JwtKey::fromJwk(['kty' => 'OKP', 'crv' => 'Ed25519', 'x' => 'AAAA']));
        self::assertNull(JwtKey::fromJwk(['kty' => 'oct', 'k' => Jwt::base64UrlEncode('short')]));
        self::assertNull(JwtKey::fromJwk(['kty' => 'EC', 'crv' => 'P-256', 'x' => '!!', 'y' => 'AA']));
        self::assertNull(JwtKey::fromJwk(['kty' => 'unknown']));
        self::assertInstanceOf(JwtKey::class, JwtKey::fromJwk(['kty' => 'oct', 'kid' => 'h', 'alg' => 'HS256', 'k' => Jwt::base64UrlEncode(self::SECRET)]));

        $set = StaticKeySet::fromJwks(['keys' => [self::$keys['RSA']['jwk'] + ['kid' => 'r1'], ['kty' => 'bogus'], 'not an array']]);
        self::assertCount(1, $set->all());
        self::assertCount(1, $set->keysFor('r1', 'RS256'));
        self::assertSame([], $set->keysFor('r2', 'RS256'));
        self::assertSame([], $set->keysFor(null, 'ES256'));
    }

    public function testClaimsValidation(): void
    {
        $config = new JwtConfig(algorithms: ['HS256'], secret: self::SECRET, issuer: ['https://idp.test/', 'https://idp2.test/'], audience: 'orders', leeway: 30, requiredClaims: ['tenant']);
        $verifier = self::verifier($config);
        $sign = self::signer(...);
        $valid = ['iss' => 'https://idp.test/', 'aud' => ['billing', 'orders'], 'tenant' => 't1'];

        self::assertSame('t1', $verifier->verify($sign($valid))['tenant']);
        self::assertSame('orders', $verifier->verify($sign(['aud' => 'orders'] + $valid))['aud']);
        self::assertSame(self::NOW - 20, $verifier->verify($sign(['exp' => self::NOW - 20] + $valid))['exp'], 'within the leeway');
        self::rejects($verifier, $sign(['exp' => self::NOW - 30] + $valid), 'Token expired');
        self::rejects($verifier, $sign(['nbf' => self::NOW + 31] + $valid), 'Token not valid yet');
        self::rejects($verifier, $sign(['iat' => self::NOW + 31] + $valid), 'Token issued in the future');
        self::rejects($verifier, $sign(['iss' => 'https://evil.test/'] + $valid), 'Unexpected issuer');
        self::rejects($verifier, $sign(['aud' => 'billing'] + $valid), 'Token is not meant for this audience');
        self::rejects($verifier, $sign(array_diff_key($valid, ['tenant' => 1])), 'Token lacks the "tenant" claim');
        self::rejects($verifier, Jwt::encode(['sub' => 'x'] + $valid, self::SECRET), 'Token has no expiration');
        self::rejects($verifier, $sign(['exp' => '2030-01-01'] + $valid), 'Claim "exp" must be a NumericDate');

        $lenient = self::verifier(new JwtConfig(algorithms: ['HS256'], secret: self::SECRET, requireExpiration: false));
        self::assertSame('x', $lenient->verify(Jwt::encode(['sub' => 'x'], self::SECRET))['sub']);
    }

    public function testAlgorithmAndHeaderAttacks(): void
    {
        $verifier = new JwtVerifier(new JwtConfig(algorithms: ['RS256'], publicKeys: ['r1' => self::$keys['RSA']['public']]), new StaticKeySet(JwtKey::publicKey(self::$keys['RSA']['public'], 'r1')), self::clock());

        // The classic confusion: sign HS256 with the RSA public key as the HMAC secret.
        $confused = Jwt::encode(self::claims(), self::$keys['RSA']['public'], 'HS256', 'r1');
        self::rejects($verifier, $confused, 'Algorithm "HS256" is not accepted');
        $both = new JwtVerifier(new JwtConfig(algorithms: ['RS256', 'HS256'], secret: self::SECRET), new StaticKeySet(JwtKey::publicKey(self::$keys['RSA']['public'], 'r1')), self::clock());
        self::rejects($both, $confused, 'Unknown key id "r1"');

        $none = Jwt::base64UrlEncode('{"alg":"none"}') . '.' . Jwt::base64UrlEncode(json_encode(self::claims(), \JSON_THROW_ON_ERROR)) . '.';
        self::rejects($verifier, $none, 'Algorithm "none" is not accepted');
        self::rejects($verifier, Jwt::encode(self::claims(), self::$keys['RSA']['private'], 'RS256', 'r1', ['crit' => ['exp']]), 'Critical header extensions are not supported');
        self::rejects($verifier, Jwt::encode(self::claims(), self::$keys['RSA']['private'], 'RS256', 'r9'), 'Unknown key id "r9"');
        self::assertSame('user-1', $verifier->verify(Jwt::encode(self::claims(), self::$keys['RSA']['private'], 'RS256'))['sub'], 'without kid every key of the type is tried');
        self::rejects($verifier, Jwt::encode(self::claims(), self::$keys['RSA']['private'], 'RS256', null, ['kid' => 5]), 'Key id must be a string');
        self::rejects($verifier, Jwt::base64UrlEncode('{"typ":"JWT"}') . '.e30.', 'Algorithm "null" is not accepted');
    }

    public function testParsing(): void
    {
        foreach ([
            'a.b' => 'A JWT has three dot-separated parts',
            'a+b.c.d' => 'Invalid base64url data',
            Jwt::base64UrlEncode('[1]') . '.e30.' => 'JWT header must be a JSON object',
            Jwt::base64UrlEncode('{') . '.e30.' => 'JWT header is not JSON',
            str_repeat('a', Jwt::MAX_LENGTH + 1) => 'Token too long',
        ] as $token => $reason) {
            try {
                Jwt::parse((string) $token);
                self::fail($reason);
            } catch (JwtException $e) {
                self::assertSame($reason, $e->getMessage());
            }
        }
        self::assertSame([], Jwt::parse(Jwt::base64UrlEncode('{"alg":"HS256"}') . '.e30.')['claims']);

        foreach ([
            static fn() => Jwt::encode([], self::SECRET, 'PS256'),
            static fn() => Jwt::encode([], '', 'HS256'),
            static fn() => Jwt::encode([], 'short', 'EdDSA'),
            static fn() => Jwt::encode([], 'not a pem', 'RS256'),
        ] as $i => $case) {
            try {
                $case();
                self::fail('case ' . $i . ' accepted');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testDerConversions(): void
    {
        $raw = str_repeat("\x00", 31) . "\x80" . str_repeat("\x01", 32);
        $der = Der::rawToEcdsa($raw, 32);
        self::assertSame($raw, Der::ecdsaToRaw($der, 32));
        foreach ([
            static fn() => Der::rawToEcdsa('short', 32),
            static fn() => Der::ecdsaToRaw("\x31\x00", 32),
            static fn() => Der::ecdsaToRaw("\x30\x06\x02\x01\x01\x03\x01\x01", 32),
            static fn() => Der::ecdsaToRaw("\x30\x7F", 32),
            static fn() => Der::ecdsaToRaw('', 32),
            static fn() => Der::ecdsaToRaw("\x30\x84\x00\x00\x00\x00", 32),
            static fn() => Der::ecdsaToRaw("\x30\x25\x02\x21" . str_repeat("\x01", 33) . "\x02\x00", 32),
            static fn() => Der::ecPublicKey('P-256', 'x', 'y'),
            static fn() => Der::ecPublicKey('P-999', 'x', 'y'),
        ] as $i => $case) {
            try {
                $case();
                self::fail('case ' . $i . ' accepted');
            } catch (JwtException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertNull(Der::ed25519FromPem(self::$keys['RSA']['public']));
        self::assertNull(Der::ed25519FromPem('garbage'));
    }

    public function testAuthenticator(): void
    {
        $logger = new ArrayLogger();
        $authenticator = new JwtAuthenticator(
            new JwtConfig(algorithms: ['HS256'], secret: self::SECRET, rolesClaim: 'realm_access.roles', realm: 'orders'),
            clock: self::clock(),
            logger: $logger,
        );
        $request = static fn(?string $authorization): ServerRequest => new ServerRequest('GET', '/', $authorization === null ? [] : ['Authorization' => $authorization]);

        self::assertNull($authenticator->authenticate($request(null)));
        self::assertNull($authenticator->authenticate($request('Basic dTpw')));

        $token = Jwt::encode(self::claims(['realm_access' => ['roles' => ['admin', 'support', 7]], 'scope' => 'orders:read orders:write']), self::SECRET);
        $principal = $authenticator->authenticate($request('Bearer ' . $token));
        self::assertInstanceOf(Identity::class, $principal);
        self::assertSame('user-1', $principal->id());
        self::assertSame(['admin', 'support'], $principal->roles());
        self::assertSame(['orders:read', 'orders:write'], $principal->get('scopes'));
        self::assertSame(self::NOW + 300, $principal->get('exp'));

        $scp = $authenticator->authenticate($request('bearer ' . Jwt::encode(self::claims(['sub' => 42, 'scp' => ['a', 'b']]), self::SECRET)));
        self::assertInstanceOf(Identity::class, $scp);
        self::assertSame('42', $scp->id());
        self::assertSame(['a', 'b'], $scp->get('scopes'));
        self::assertSame([], $scp->roles());

        foreach ([
            'Bearer' => 'error="invalid_request"',
            'Bearer a b' => 'error="invalid_request"',
            'Bearer not-a-jwt' => 'error="invalid_token"',
            'Bearer ' . Jwt::encode(self::claims(['exp' => self::NOW - 999]), self::SECRET) => 'error="invalid_token"',
            'Bearer ' . Jwt::encode(['exp' => self::NOW + 60], self::SECRET) => 'error="invalid_token"',
        ] as $header => $error) {
            try {
                $authenticator->authenticate($request($header));
                self::fail($header . ' accepted');
            } catch (UnauthorizedException $e) {
                self::assertSame(401, $e->status);
                self::assertSame('Bearer realm="orders", ' . $error, $e->headers['WWW-Authenticate']);
            }
        }
        self::assertContains('info: JWT rejected: Token expired', $logger->records);
        self::assertContains('info: JWT rejected: Token has no "sub" claim', $logger->records);
        self::assertSame(['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT'], $authenticator->securityScheme()->definition);
        self::assertSame('Bearer realm="orders"', $authenticator->challenge());
    }

    public function testJwksKeySetFetchesCachesAndRefreshes(): void
    {
        $http = new class implements ClientInterface {
            /** @var list<array<string, mixed>|int> */
            public array $answers = [];

            public int $calls = 0;

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->calls++;
                $answer = array_shift($this->answers) ?? 500;
                $factory = new Psr17Factory();

                return is_int($answer)
                    ? $factory->createResponse($answer)
                    : $factory->createResponse(200)->withBody($factory->createStream(json_encode($answer, \JSON_THROW_ON_ERROR)));
            }
        };
        $factory = new Psr17Factory();
        $cache = new ArrayCache();
        $now = self::NOW;
        $clock = new class ($now) implements ClockInterface {
            public function __construct(public int $now) {}

            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('@' . $this->now);
            }
        };
        $old = self::$keys['P-256']['jwk'] + ['kid' => 'old'];
        $new = self::$keys['P-384']['jwk'] + ['kid' => 'new'];
        $http->answers = [['keys' => [$old]], ['keys' => [$old, $new]]];
        $set = new JwksKeySet('https://idp.test/jwks', $http, $factory, $cache, 3600, 60, $clock);

        self::assertCount(1, $set->keysFor('old', 'ES256'));
        self::assertCount(1, $set->keysFor('old', 'ES256'));
        self::assertSame(1, $http->calls, 'kept in the worker');

        self::assertSame([], $set->keysFor('new', 'ES384'), 'right after a fetch an unknown kid waits for the cooldown');
        self::assertSame(1, $http->calls);
        $clock->now += 61;
        self::assertCount(1, $set->keysFor('new', 'ES384'), 'an unknown kid triggers a refetch');
        self::assertSame(2, $http->calls);
        self::assertSame([], $set->keysFor('forged', 'ES256'));
        self::assertSame(2, $http->calls, 'at most one refetch per interval');

        $other = new JwksKeySet('https://idp.test/jwks', $http, $factory, $cache, 3600, 60, $clock);
        self::assertCount(1, $other->keysFor('new', 'ES384'));
        self::assertSame(2, $http->calls, 'another worker reads the shared cache');

        $clock->now += 3601;
        $logger = new ArrayLogger();
        $failing = new JwksKeySet('https://idp.test/jwks', $http, $factory, null, 3600, 60, $clock, $logger);
        try {
            $failing->keysFor('old', 'ES256');
            self::fail('no keys and no provider must not look like a bad token');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('JWKS from https://idp.test/jwks is unavailable: HTTP 500', $e->getMessage());
        }

        $http->answers = [500];
        self::assertCount(1, $set->keysFor('old', 'ES256'), 'a failed refresh keeps the stale keys');
        $http->answers = [['nope' => 1]];
        $clock->now += 61;
        self::assertCount(1, $set->keysFor('new', 'ES384'));
    }

    public function testConfigAndKeySetChoice(): void
    {
        foreach ([
            static fn() => new JwtConfig(algorithms: [], secret: self::SECRET),
            static fn() => new JwtConfig(algorithms: ['PS256'], secret: self::SECRET),
            static fn() => new JwtConfig(algorithms: ['RS256']),
            static fn() => new JwtConfig(jwksUrl: 'http://idp.test/jwks'),
            static fn() => new JwtConfig(secret: self::SECRET, leeway: -1),
        ] as $i => $case) {
            try {
                $case();
                self::fail('case ' . $i . ' accepted');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame('http://localhost:8080/jwks', new JwtConfig(jwksUrl: 'http://localhost:8080/jwks')->jwksUrl);
        self::assertNull(new JwtConfig(jwksUrl: 'https://idp.test/jwks')->staticKeys());
        self::assertSame(['a'], new JwtConfig(secret: self::SECRET, issuer: 'a')->issuers);

        $this->expectException(\LogicException::class);
        new JwtAuthenticator(new JwtConfig(jwksUrl: 'https://idp.test/jwks'))->verifier();
    }

    public function testChainedKeySources(): void
    {
        $http = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $factory = new Psr17Factory();
                $jwks = ['keys' => [JwtTest::rsaJwk() + ['kid' => 'remote']]];

                return $factory->createResponse(200)->withBody($factory->createStream(json_encode($jwks, \JSON_THROW_ON_ERROR)));
            }
        };
        $authenticator = new JwtAuthenticator(
            new JwtConfig(algorithms: ['HS256', 'RS256'], secret: self::SECRET, jwksUrl: 'https://idp.test/jwks', requireExpiration: false),
            $http,
            new Psr17Factory(),
        );
        self::assertSame('local', $authenticator->verifier()->verify(Jwt::encode(['sub' => 'local'], self::SECRET))['sub']);
        self::assertSame('remote', $authenticator->verifier()->verify(Jwt::encode(['sub' => 'remote'], self::$keys['RSA']['private'], 'RS256', 'remote'))['sub']);
    }

    /**
     * @return array<string, string>
     */
    public static function rsaJwk(): array
    {
        return self::$keys['RSA']['jwk'];
    }
}
