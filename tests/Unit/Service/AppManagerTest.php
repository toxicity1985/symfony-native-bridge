<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use SymfonyNativeBridge\Driver\NativeDriverInterface;
use SymfonyNativeBridge\Service\AppManager;

class AppManagerTest extends TestCase
{
    public function testGetNameReturnsFromConfig(): void
    {
        $driver  = $this->createMock(NativeDriverInterface::class);
        $manager = new AppManager($driver, ['name' => 'My App', 'version' => '2.0.0'], ['enabled' => false]);

        $this->assertSame('My App', $manager->getName());
        $this->assertSame('2.0.0', $manager->getVersion());
    }

    public function testCheckForUpdatesThrowsWhenDisabled(): void
    {
        $driver  = $this->createMock(NativeDriverInterface::class);
        $manager = new AppManager($driver, ['name' => 'App', 'version' => '1.0'], ['enabled' => false]);

        $this->expectException(\RuntimeException::class);
        $manager->checkForUpdates();
    }

    public function testCheckForUpdatesDelegatesToDriverWhenEnabled(): void
    {
        $driver = $this->createMock(NativeDriverInterface::class);
        $driver->expects($this->once())->method('checkForUpdates');

        $manager = new AppManager($driver, ['name' => 'App', 'version' => '1.0'], ['enabled' => true]);
        $manager->checkForUpdates();
    }
}
