<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Tests\Unit\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use SymfonyNativeBridge\Driver\NativeDriverInterface;
use SymfonyNativeBridge\Service\ProtocolManager;

class ProtocolManagerTest extends TestCase
{
    private NativeDriverInterface&MockObject $driver;
    private ProtocolManager $manager;

    protected function setUp(): void
    {
        $this->driver  = $this->createMock(NativeDriverInterface::class);
        $this->manager = new ProtocolManager($this->driver);
    }

    public function testRegisterDelegatesToDriver(): void
    {
        $this->driver->expects($this->once())->method('registerProtocol')->with('myapp');

        $this->manager->register('myapp');

        $this->assertSame(['myapp'], $this->manager->getRegistered());
    }

    public function testRegisterIgnoresDuplicates(): void
    {
        $this->driver->expects($this->once())->method('registerProtocol');

        $this->manager->register('myapp');
        $this->manager->register('myapp');

        $this->assertCount(1, $this->manager->getRegistered());
    }

    public function testUnregisterDelegatesToDriverAndRemovesFromList(): void
    {
        $this->driver->method('registerProtocol');
        $this->driver->expects($this->once())->method('unregisterProtocol')->with('myapp');

        $this->manager->register('myapp');
        $this->manager->unregister('myapp');

        $this->assertSame([], $this->manager->getRegistered());
    }

    public function testMultipleSchemesRegisteredIndependently(): void
    {
        $this->driver->expects($this->exactly(2))->method('registerProtocol');

        $this->manager->register('myapp');
        $this->manager->register('myapp2');

        $this->assertSame(['myapp', 'myapp2'], $this->manager->getRegistered());
    }
}
