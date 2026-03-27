<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Tests\Unit\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use SymfonyNativeBridge\Driver\NativeDriverInterface;
use SymfonyNativeBridge\Service\StorageManager;

class StorageManagerTest extends TestCase
{
    private NativeDriverInterface&MockObject $driver;
    private StorageManager $manager;

    protected function setUp(): void
    {
        $this->driver  = $this->createMock(NativeDriverInterface::class);
        $this->manager = new StorageManager($this->driver);
    }

    public function testGetReturnsValueFromDriver(): void
    {
        $this->driver->method('storeGet')->with('key')->willReturn('value');
        $this->assertSame('value', $this->manager->get('key'));
    }

    public function testGetReturnsDefaultWhenDriverReturnsNull(): void
    {
        $this->driver->method('storeGet')->willReturn(null);
        $this->assertSame('fallback', $this->manager->get('missing', 'fallback'));
    }

    public function testHasReturnsFalseForNull(): void
    {
        $this->driver->method('storeGet')->willReturn(null);
        $this->assertFalse($this->manager->has('missing'));
    }

    public function testHasReturnsTrueForValue(): void
    {
        $this->driver->method('storeGet')->willReturn('something');
        $this->assertTrue($this->manager->has('key'));
    }

    public function testRememberStoresAndReturnsCalculatedValue(): void
    {
        $this->driver->method('storeGet')->willReturn(null);
        $this->driver->expects($this->once())->method('storeSet')->with('key', 42);

        $result = $this->manager->remember('key', fn() => 42);
        $this->assertSame(42, $result);
    }

    public function testRememberReturnsCachedValueWithoutCallingCallback(): void
    {
        $this->driver->method('storeGet')->willReturn('cached');
        $called = false;

        $result = $this->manager->remember('key', function () use (&$called) {
            $called = true;
            return 'fresh';
        });

        $this->assertSame('cached', $result);
        $this->assertFalse($called);
    }
}
