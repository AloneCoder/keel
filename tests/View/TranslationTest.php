<?php

declare(strict_types=1);

namespace Keel\Tests\View;

use Keel\Container\Container;
use Keel\Container\Definitions;
use Keel\I18n\ArrayTranslator;
use Keel\I18n\I18nConfig;
use Keel\I18n\I18nProvider;
use Keel\I18n\LocaleAware;
use Keel\View\LatteConfig;
use Keel\View\LatteProvider;
use Keel\View\LatteTranslation;
use Keel\View\TwigConfig;
use Keel\View\TwigProvider;
use Keel\View\TwigTranslation;
use Keel\View\ViewRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TwigTranslation::class)]
#[CoversClass(LatteTranslation::class)]
#[CoversClass(TwigProvider::class)]
#[CoversClass(LatteProvider::class)]
final class TranslationTest extends TestCase
{
    private const string TEMPLATES = __DIR__ . '/Fixtures/templates';

    private static function definitions(): Definitions
    {
        $definitions = new Definitions();
        $definitions->instance(I18nConfig::class, new I18nConfig(dirname(__DIR__) . '/I18n/translations', ['en', 'ru']));
        new I18nProvider()->register($definitions);

        return $definitions;
    }

    public function testTwigGetsTransFilterAndFunctions(): void
    {
        $definitions = self::definitions();
        $definitions->instance(TwigConfig::class, new TwigConfig([self::TEMPLATES]));
        $definitions->load(new TwigProvider());
        $container = new Container($definitions);

        self::assertSame("<p>Hello, Ann! 2 items [en]</p>\n", $container->get(ViewRenderer::class)->render('trans.twig', ['name' => 'Ann', 'count' => 2]));
        $container->get(LocaleAware::class)->setLocale('ru');
        self::assertSame("<p>Привет, Ann! 5 товаров [ru]</p>\n", $container->get(ViewRenderer::class)->render('trans.twig', ['name' => 'Ann', 'count' => 5]));
    }

    public function testLatteGetsTransFilterAndFunctions(): void
    {
        $definitions = self::definitions();
        $temp = sys_get_temp_dir() . '/keel-tests/' . getmypid() . '/latte-i18n-' . uniqid();
        mkdir($temp, 0o775, true);
        $definitions->instance(LatteConfig::class, new LatteConfig(self::TEMPLATES, $temp, autoRefresh: true));
        $definitions->load(new LatteProvider());
        $container = new Container($definitions);

        self::assertSame("<p>Hello, Ann! 1 item [en]</p>\n", $container->get(ViewRenderer::class)->render('trans.latte', ['name' => 'Ann', 'count' => 1]));
        $translator = $container->get(LocaleAware::class);
        self::assertInstanceOf(ArrayTranslator::class, $translator);
        $translator->setLocale('ru');
        self::assertSame("<p>Привет, Ann! 3 товара [ru]</p>\n", $container->get(ViewRenderer::class)->render('trans.latte', ['name' => 'Ann', 'count' => 3]));
    }
}
