<?php

declare(strict_types=1);

namespace Keel\Tests\I18n;

use Keel\Container\Container;
use Keel\Container\Definitions;
use Keel\Http\CallableHandler;
use Keel\I18n\ArrayTranslator;
use Keel\I18n\I18nConfig;
use Keel\I18n\I18nProvider;
use Keel\I18n\Locale;
use Keel\I18n\LocaleAware;
use Keel\I18n\LocaleMiddleware;
use Keel\I18n\PluralRules;
use Keel\I18n\Translator;
use Keel\Session\Session;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[CoversClass(I18nConfig::class)]
#[CoversClass(Locale::class)]
#[CoversClass(ArrayTranslator::class)]
#[CoversClass(PluralRules::class)]
#[CoversClass(LocaleMiddleware::class)]
#[CoversClass(I18nProvider::class)]
final class I18nTest extends TestCase
{
    /**
     * @param list<string> $locales
     */
    private static function config(
        array $locales = ['en', 'ru', 'pt-BR'],
        ?string $defaultLocale = null,
        ?string $fallbackLocale = null,
        string $path = __DIR__ . '/translations',
        bool $fromPath = false,
        ?string $queryParam = null,
        ?string $cookie = null,
    ): I18nConfig {
        return new I18nConfig($path, $locales, $defaultLocale, $fallbackLocale, fromPath: $fromPath, queryParam: $queryParam, cookie: $cookie);
    }

    public function testConfigNormalizesAndMatchesLocales(): void
    {
        $config = self::config(locales: ['en_us', 'RU', 'pt-br']);
        self::assertSame(['en-US', 'ru', 'pt-BR'], $config->locales);
        self::assertSame('en-US', $config->defaultLocale);
        self::assertSame('en-US', $config->match('en-GB'), 'by language');
        self::assertSame('pt-BR', $config->match('pt'));
        self::assertSame('ru', $config->match('ru-RU'));
        self::assertNull($config->match('de'));
        self::assertNull($config->match('x'));
        self::assertSame('zh-Hant-TW', I18nConfig::normalize('ZH-hant-tw'));

        self::assertSame('ru', self::config(defaultLocale: 'ru')->defaultLocale);
        foreach ([static fn() => self::config([]), static fn() => self::config(['e']), static fn() => self::config(defaultLocale: 'de'), static fn() => self::config(fallbackLocale: 'de'), static fn() => self::config(path: '')] as $bad) {
            try {
                $bad();
                self::fail('invalid config accepted');
            } catch (\InvalidArgumentException) {
            }
        }

        $locale = new Locale('pt-BR');
        self::assertSame('pt', $locale->language());
        self::assertSame('BR', $locale->region());
        self::assertNull(new Locale('ru')->region());
        self::assertSame('ru', (string) new Locale('ru'));
        $this->expectException(\InvalidArgumentException::class);
        new Locale('RU');
    }

    public function testTranslatesWithParamsPluralsDomainsAndFallback(): void
    {
        $translator = new ArrayTranslator(self::config());

        self::assertSame('en', $translator->locale());
        self::assertSame('Hello, Ann!', $translator->trans('hello', ['name' => 'Ann']));
        self::assertSame('Your cart is empty', $translator->trans('cart.empty'));
        self::assertSame('1 item', $translator->trans('cart.items', ['count' => 1]));
        self::assertSame('5 items', $translator->trans('cart.items', ['count' => 5]));
        self::assertSame('flat key wins', $translator->trans('nested.dotted'));
        self::assertSame('missing.key', $translator->trans('missing.key'), 'a missing message is its key');
        self::assertSame('bad.not.a.leaf', $translator->trans('bad.not.a.leaf'), 'a non-scalar node is not a message');
        self::assertSame('Nothing here', $translator->trans('not_found', domain: 'errors'));
        self::assertSame('not_found', $translator->trans('not_found'));
        self::assertTrue($translator->has('cart.items'));
        self::assertFalse($translator->has('cart.items', 'errors'));

        $translator->setLocale('ru-RU');
        self::assertSame('ru', $translator->locale());
        self::assertSame('Привет, Ann!', $translator->trans('hello', ['name' => 'Ann']));
        self::assertSame('1 товар', $translator->trans('cart.items', ['count' => 1]));
        self::assertSame('3 товара', $translator->trans('cart.items', ['count' => 3]));
        self::assertSame('11 товаров', $translator->trans('cart.items', ['count' => 11]));
        self::assertSame('22 товара', $translator->trans('cart.items', ['count' => '22']));
        self::assertSame('English only', $translator->trans('only_en'), 'falls back to the default locale');
        self::assertSame('Olá, Bo!', $translator->trans('hello', ['name' => 'Bo'], locale: 'pt-BR'));

        $translator->setLocale('de');
        self::assertSame('en', $translator->locale(), 'an unsupported locale means the default');
        $translator->setLocale('ru');
        $translator->reset();
        self::assertSame('en', $translator->locale());

        if (class_exists(\MessageFormatter::class)) {
            self::assertSame('no apples', $translator->trans('icu', ['count' => 0]));
            self::assertSame('1 apple', $translator->trans('icu', ['count' => 1]));
            self::assertSame('4 apples', $translator->trans('icu', ['count' => 4]));
        }

        $this->expectException(\InvalidArgumentException::class);
        $translator->trans('x', domain: '../etc');
    }

    /**
     * @return iterable<string, array{0: string, 1: int|float, 2: int}>
     */
    public static function plurals(): iterable
    {
        yield 'en 1' => ['en', 1, 0];
        yield 'en 0' => ['en', 0, 1];
        yield 'en 1.5' => ['en', 1.5, 1];
        yield 'fr 0' => ['fr', 0, 0];
        yield 'fr 2' => ['fr', 2, 1];
        yield 'ru 21' => ['ru-RU', 21, 0];
        yield 'ru 12' => ['ru', 12, 2];
        yield 'ru 1.5' => ['ru', 1.5, 1];
        yield 'pl 1' => ['pl', 1, 0];
        yield 'pl 22' => ['pl', 22, 1];
        yield 'pl 25' => ['pl', 25, 2];
        yield 'cs 3' => ['cs', 3, 1];
        yield 'cs 5' => ['cs', 5, 2];
        yield 'ja' => ['ja', 7, 0];
        yield 'ar 2' => ['ar', 2, 2];
        yield 'ar 15' => ['ar', 15, 4];
        yield 'lv 0' => ['lv', 0, 0];
        yield 'lv 1' => ['lv', 1, 1];
        yield 'lt 21' => ['lt', 21, 0];
        yield 'ro 5' => ['ro', 5, 1];
    }

    #[DataProvider('plurals')]
    public function testPluralRules(string $locale, int|float $count, int $expected): void
    {
        self::assertSame($expected, PluralRules::index($locale, $count));
    }

    public function testMiddlewarePicksTheLocaleInOrder(): void
    {
        $translator = new ArrayTranslator(self::config());
        $seen = [];
        $handler = new CallableHandler(static function (ServerRequestInterface $request) use (&$seen, $translator): ResponseInterface {
            $locale = $request->getAttribute(Locale::class);
            self::assertInstanceOf(Locale::class, $locale);
            $seen[] = $locale->value . '/' . $translator->locale();

            return new Psr17Factory()->createResponse();
        });
        $run = static function (I18nConfig $config, ServerRequestInterface $request) use ($handler, $translator): ResponseInterface {
            $translator->reset();

            return new LocaleMiddleware($config, $translator)->process($request, $handler);
        };

        $all = self::config(fromPath: true, queryParam: 'lang', cookie: 'lang');
        $response = $run($all, new ServerRequest('GET', '/ru/about'));
        self::assertSame('ru', $response->getHeaderLine('Content-Language'));
        self::assertFalse($response->hasHeader('Vary'));
        $run($all, new ServerRequest('GET', '/about?lang=pt'));
        $run($all, new ServerRequest('GET', '/about')->withAttribute(Session::class, new Session('x', ['locale' => 'ru'])));
        $run($all, new ServerRequest('GET', '/about')->withCookieParams(['lang' => 'pt-BR']));
        $negotiated = $run($all, new ServerRequest('GET', '/about', ['Accept-Language' => 'de, ru;q=0.8, pt;q=0.9']));
        self::assertSame('Accept-Language', $negotiated->getHeaderLine('Vary'));
        $run($all, new ServerRequest('GET', '/de/about?lang=xx', ['Accept-Language' => 'de']));
        self::assertSame(['ru/ru', 'pt-BR/pt-BR', 'ru/ru', 'pt-BR/pt-BR', 'pt-BR/pt-BR', 'en/en'], $seen);

        $middleware = new LocaleMiddleware(self::config());
        self::assertSame('ru', $middleware->negotiate('ru-RU,en;q=0.5'));
        self::assertSame('en', $middleware->negotiate('*'));
        self::assertSame('en', $middleware->negotiate('de;q=0.9, en;q=0.9'), 'equal q: the first listed');
        self::assertNull($middleware->negotiate('de, fr;q=0'));
        self::assertNull($middleware->negotiate(''));

        $kept = new LocaleMiddleware(self::config())->process(new ServerRequest('GET', '/'), new CallableHandler(static fn(): ResponseInterface => new Psr17Factory()->createResponse()->withHeader('Content-Language', 'xx')));
        self::assertSame('xx', $kept->getHeaderLine('Content-Language'), 'a handler-set header is kept');
    }

    public function testProviderRegistersTheTranslator(): void
    {
        $definitions = new Definitions();
        $definitions->instance(I18nConfig::class, self::config());
        new I18nProvider()->register($definitions);
        $container = new Container($definitions);

        self::assertInstanceOf(ArrayTranslator::class, $container->get(Translator::class));
        self::assertSame($container->get(Translator::class), $container->get(LocaleAware::class));
    }
}
