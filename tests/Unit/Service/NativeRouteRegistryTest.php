<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use SymfonyNativeBridge\Exception\NativeException;
use SymfonyNativeBridge\Service\NativeRouteRegistry;

class NativeRouteRegistryTest extends TestCase
{
    private NativeRouteRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new NativeRouteRegistry();
    }

    public function testRegisterAndGet(): void
    {
        $this->registry->register('dashboard', 'app_dashboard', ['title' => 'Dashboard', 'width' => 1200]);

        $entry = $this->registry->get('dashboard');

        $this->assertSame('app_dashboard', $entry['route']);
        $this->assertSame('Dashboard', $entry['options']['title']);
        $this->assertSame(1200, $entry['options']['width']);
    }

    public function testNullOptionsAreStripped(): void
    {
        $this->registry->register('settings', 'app_settings', ['title' => null, 'width' => 800]);

        $entry = $this->registry->get('settings');

        $this->assertArrayNotHasKey('title', $entry['options']);
        $this->assertSame(800, $entry['options']['width']);
    }

    public function testHasReturnsTrueForRegisteredRoute(): void
    {
        $this->registry->register('dashboard', 'app_dashboard', []);

        $this->assertTrue($this->registry->has('dashboard'));
    }

    public function testHasReturnsFalseForUnknownRoute(): void
    {
        $this->assertFalse($this->registry->has('unknown'));
    }

    public function testGetThrowsForUnknownRoute(): void
    {
        $this->expectException(NativeException::class);
        $this->expectExceptionMessage("'unknown'");

        $this->registry->get('unknown');
    }

    public function testAllReturnsAllRegisteredRoutes(): void
    {
        $this->registry->register('a', 'route_a', []);
        $this->registry->register('b', 'route_b', ['width' => 800]);

        $all = $this->registry->all();

        $this->assertCount(2, $all);
        $this->assertArrayHasKey('a', $all);
        $this->assertArrayHasKey('b', $all);
    }
}
