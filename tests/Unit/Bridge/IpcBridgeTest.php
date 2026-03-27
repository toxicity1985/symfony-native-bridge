<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Tests\Unit\Bridge;

use PHPUnit\Framework\TestCase;
use SymfonyNativeBridge\Bridge\IpcBridge;
use SymfonyNativeBridge\Exception\IpcException;
use SymfonyNativeBridge\Exception\RuntimeAbsentException;

class IpcBridgeTest extends TestCase
{
    protected function setUp(): void
    {
        // Ensure no stale env vars bleed between tests
        putenv('SYMFONY_IPC_PORT');
        putenv('SYMFONY_IPC_PIPE');
        unset($_ENV['SYMFONY_IPC_PORT'], $_ENV['SYMFONY_IPC_PIPE']);
    }

    // ── strict mode ───────────────────────────────────────────────────────────

    public function testCallReturnsNullWhenRuntimeAbsentAndNonStrict(): void
    {
        $bridge = new IpcBridge('electron', strict: false);
        $this->assertNull($bridge->call('window.list'));
    }

    public function testCallThrowsRuntimeAbsentExceptionInStrictMode(): void
    {
        $bridge = new IpcBridge('electron', strict: true);

        $this->expectException(RuntimeAbsentException::class);
        $this->expectExceptionMessage('native:serve');

        $bridge->call('window.list');
    }

    public function testSendSilentlySkipsWhenRuntimeAbsentAndNonStrict(): void
    {
        $bridge = new IpcBridge('electron', strict: false);

        // Should not throw
        $bridge->send('app.quit');
        $this->assertTrue(true);
    }

    public function testSendThrowsRuntimeAbsentExceptionInStrictMode(): void
    {
        $bridge = new IpcBridge('electron', strict: true);

        $this->expectException(RuntimeAbsentException::class);

        $bridge->send('app.quit');
    }

    // ── callBatch ─────────────────────────────────────────────────────────────

    public function testCallBatchReturnsEmptyArrayForEmptyInput(): void
    {
        $bridge = new IpcBridge('electron');
        $this->assertSame([], $bridge->callBatch([]));
    }

    public function testCallBatchReturnsNullsWhenRuntimeAbsent(): void
    {
        $bridge = new IpcBridge('electron', strict: false);

        $result = $bridge->callBatch([
            ['action' => 'window.list'],
            ['action' => 'app.name'],
        ]);

        $this->assertSame([null, null], $result);
    }

    public function testCallBatchThrowsRuntimeAbsentExceptionInStrictMode(): void
    {
        $bridge = new IpcBridge('electron', strict: true);

        $this->expectException(RuntimeAbsentException::class);

        $bridge->callBatch([['action' => 'window.list']]);
    }

    // ── createCompanion ───────────────────────────────────────────────────────

    public function testCreateCompanionReturnsNewUnconnectedInstance(): void
    {
        $bridge    = new IpcBridge('electron');
        $companion = $bridge->createCompanion();

        $this->assertNotSame($bridge, $companion);
        $this->assertFalse($companion->isConnected());
    }

    public function testCreateCompanionPreservesStrictMode(): void
    {
        $strict    = new IpcBridge('electron', strict: true);
        $companion = $strict->createCompanion();

        $this->expectException(RuntimeAbsentException::class);
        $companion->call('window.list');
    }

    public function testCreateCompanionOnSubclassReturnsSameSubclass(): void
    {
        $dispatcher = new \Symfony\Component\EventDispatcher\EventDispatcher();
        $bridge     = new \SymfonyNativeBridge\Bridge\SymfonyEventBridgeIpcBridge('electron', $dispatcher);
        $companion  = $bridge->createCompanion();

        $this->assertInstanceOf(\SymfonyNativeBridge\Bridge\SymfonyEventBridgeIpcBridge::class, $companion);
    }

    // ── isConnected ───────────────────────────────────────────────────────────

    public function testIsConnectedReturnsFalseInitially(): void
    {
        $bridge = new IpcBridge('electron');
        $this->assertFalse($bridge->isConnected());
    }
}
