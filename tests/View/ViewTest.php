<?php

declare(strict_types=1);

namespace Keel\Tests\View;

use Keel\Container\Container;
use Keel\Container\Definitions;
use Keel\Kernel\Kernel;
use Keel\Testing\TestClient;
use Keel\Tests\View\Fixtures\PageController;
use Keel\View\LatteConfig;
use Keel\View\LatteProvider;
use Keel\View\LatteRenderer;
use Keel\View\TwigConfig;
use Keel\View\TwigProvider;
use Keel\View\TwigRenderer;
use Keel\View\ViewRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

#[CoversClass(TwigConfig::class)]
#[CoversClass(TwigProvider::class)]
#[CoversClass(TwigRenderer::class)]
#[CoversClass(LatteConfig::class)]
#[CoversClass(LatteProvider::class)]
#[CoversClass(LatteRenderer::class)]
final class ViewTest extends TestCase
{
    private const string TEMPLATES = __DIR__ . '/Fixtures/templates';

    public function testTwigProviderRendersWithGlobalsAndEscaping(): void
    {
        $definitions = new Definitions();
        $definitions->instance(TwigConfig::class, new TwigConfig([self::TEMPLATES], globals: ['app' => 'Keel'], debug: true));
        $definitions->load(new TwigProvider());
        $container = new Container($definitions);

        self::assertInstanceOf(TwigRenderer::class, $container->get(ViewRenderer::class));
        self::assertTrue($container->get(Environment::class)->isDebug());
        self::assertSame("<h1>Hi, &lt;Ann&gt; from Keel</h1>\n", $container->get(ViewRenderer::class)->render('hello.twig', ['greeting' => 'Hi', 'name' => '<Ann>']));

        $client = TestClient::forKernel(Kernel::build($definitions, [PageController::class]));
        $client->get('/page/twig')->assertOk()->assertHeader('Content-Type', 'text/html; charset=utf-8')->assertBodyContains('&lt;Ann&gt;');
    }

    public function testTwigCompiledCacheDirectory(): void
    {
        $cache = sys_get_temp_dir() . '/keel-tests/' . getmypid() . '/twig-' . uniqid();
        $definitions = new Definitions();
        $definitions->instance(TwigConfig::class, new TwigConfig([self::TEMPLATES], cache: $cache));
        $definitions->load(new TwigProvider());

        new Container($definitions)->get(ViewRenderer::class)->render('hello.twig', ['greeting' => 'Hi', 'name' => 'Bob', 'app' => 'x']);
        self::assertNotSame([], glob($cache . '/*') ?: []);
    }

    public function testLatteProviderRendersWithEscaping(): void
    {
        $temp = sys_get_temp_dir() . '/keel-tests/' . getmypid() . '/latte-' . uniqid();
        $definitions = new Definitions();
        $definitions->instance(LatteConfig::class, new LatteConfig(self::TEMPLATES, $temp, autoRefresh: true));
        $definitions->load(new LatteProvider());
        $container = new Container($definitions);

        self::assertInstanceOf(LatteRenderer::class, $container->get(ViewRenderer::class));
        self::assertSame("<h1>Hi, &lt;Ann&gt;</h1>\n", $container->get(ViewRenderer::class)->render('hello.latte', ['greeting' => 'Hi', 'name' => '<Ann>']));
        self::assertNotSame([], glob($temp . '/*') ?: []);

        TestClient::forKernel(Kernel::build($definitions, [PageController::class]))->get('/page/latte')->assertOk()->assertBodyContains('&lt;Ann&gt;');
    }
}
