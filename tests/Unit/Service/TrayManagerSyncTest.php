<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Tests\Unit\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use SymfonyNativeBridge\Driver\NativeDriverInterface;
use SymfonyNativeBridge\Service\TrayManager;

class TrayManagerSyncTest extends TestCase
{
    private NativeDriverInterface&MockObject $driver;
    private TrayManager $manager;

    protected function setUp(): void
    {
        $this->driver  = $this->createMock(NativeDriverInterface::class);
        $this->manager = new TrayManager($this->driver);
    }

    public function testSyncFromRuntimeAddsOrphanedTrayIds(): void
    {
        $this->driver->method('listTrays')->willReturn(['tray_old']);

        $this->manager->syncFromRuntime();

        // The orphaned tray is now accessible via its ID as label
        $this->driver->expects($this->once())->method('destroyTray')->with('tray_old');
        $this->manager->destroy('tray_old');
    }

    public function testSyncFromRuntimeDoesNotDuplicateAlreadyTrackedTrays(): void
    {
        $this->driver->method('createTray')->willReturn('tray_1');
        $this->manager->create('/icon.png', '', 'main');

        $this->driver->method('listTrays')->willReturn(['tray_1', 'tray_orphan']);
        $this->manager->syncFromRuntime();

        $destroyed = [];
        $this->driver->method('destroyTray')
            ->willReturnCallback(function (string $id) use (&$destroyed): void {
                $destroyed[] = $id;
            });

        // 'tray_1' is still accessible via its original 'main' label (not duplicated)
        $this->manager->destroy('main');

        // 'tray_orphan' is accessible via its ID as label
        $this->manager->destroy('tray_orphan');

        $this->assertSame(['tray_1', 'tray_orphan'], $destroyed);
    }

    public function testSyncFromRuntimeHandlesEmptyRuntime(): void
    {
        $this->driver->method('listTrays')->willReturn([]);

        $this->manager->syncFromRuntime(); // must not throw
        $this->assertTrue(true);
    }
}
