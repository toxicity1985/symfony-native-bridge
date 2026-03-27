<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Tests\Unit\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use SymfonyNativeBridge\Driver\NativeDriverInterface;
use SymfonyNativeBridge\Exception\NativeException;
use SymfonyNativeBridge\Service\NativeRouteRegistry;
use SymfonyNativeBridge\Service\WindowManager;
use SymfonyNativeBridge\ValueObject\WindowOptions;

class WindowManagerRouteTest extends TestCase
{
    private NativeDriverInterface&MockObject $driver;
    private NativeRouteRegistry $registry;
    private UrlGeneratorInterface&MockObject $urlGenerator;
    private WindowManager $manager;

    protected function setUp(): void
    {
        $this->driver       = $this->createMock(NativeDriverInterface::class);
        $this->registry     = new NativeRouteRegistry();
        $this->urlGenerator = $this->createMock(UrlGeneratorInterface::class);

        $this->manager = new WindowManager(
            $this->driver,
            ['width' => 1200, 'height' => 800, 'resizable' => true, 'frame' => true,
             'transparent' => false, 'alwaysOnTop' => false, 'title' => '', 'label' => null],
            $this->registry,
            $this->urlGenerator,
        );
    }

    public function testOpenRouteGeneratesUrlAndOpensWindow(): void
    {
        $this->registry->register('dashboard', 'app_dashboard', []);

        $this->urlGenerator
            ->expects($this->once())
            ->method('generate')
            ->with('app_dashboard', [], UrlGeneratorInterface::ABSOLUTE_URL)
            ->willReturn('http://localhost:8080/dashboard');

        $this->driver
            ->expects($this->once())
            ->method('openWindow')
            ->with('http://localhost:8080/dashboard', $this->isInstanceOf(WindowOptions::class))
            ->willReturn('win_1');

        $result = $this->manager->openRoute('dashboard');

        $this->assertSame('win_1', $result);
    }

    public function testOpenRouteForwardsRouteParameters(): void
    {
        $this->registry->register('product', 'app_product_show', []);

        $this->urlGenerator
            ->expects($this->once())
            ->method('generate')
            ->with('app_product_show', ['id' => 42], UrlGeneratorInterface::ABSOLUTE_URL)
            ->willReturn('http://localhost:8080/product/42');

        $this->driver->method('openWindow')->willReturn('win_2');

        $this->manager->openRoute('product', ['id' => 42]);
    }

    public function testOpenRouteMergesAttributeOptionsWithDefaults(): void
    {
        $this->registry->register('settings', 'app_settings', ['width' => 600, 'height' => 400, 'title' => 'Settings']);

        $this->urlGenerator->method('generate')->willReturn('http://localhost:8080/settings');

        $capturedOptions = null;
        $this->driver
            ->method('openWindow')
            ->willReturnCallback(function (string $url, WindowOptions $opts) use (&$capturedOptions) {
                $capturedOptions = $opts;
                return 'win_3';
            });

        $this->manager->openRoute('settings');

        $this->assertSame(600, $capturedOptions?->width);
        $this->assertSame(400, $capturedOptions?->height);
        $this->assertSame('Settings', $capturedOptions?->title);
    }

    public function testOpenRouteOverrideReplacesAttributeOptions(): void
    {
        $this->registry->register('dashboard', 'app_dashboard', ['width' => 1200]);

        $this->urlGenerator->method('generate')->willReturn('http://localhost:8080/dashboard');

        $override        = new WindowOptions(width: 320, height: 240);
        $capturedOptions = null;
        $this->driver
            ->method('openWindow')
            ->willReturnCallback(function (string $url, WindowOptions $opts) use (&$capturedOptions) {
                $capturedOptions = $opts;
                return 'win_4';
            });

        $this->manager->openRoute('dashboard', [], $override);

        $this->assertSame(320, $capturedOptions?->width);
    }

    public function testOpenRouteThrowsForUnknownName(): void
    {
        $this->expectException(NativeException::class);

        $this->manager->openRoute('nonexistent');
    }

    public function testOpenRouteThrowsWhenRouterNotAvailable(): void
    {
        $manager = new WindowManager($this->driver, []);

        $this->expectException(\LogicException::class);

        $manager->openRoute('dashboard');
    }
}
