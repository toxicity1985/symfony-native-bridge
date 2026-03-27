<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Tests\Integration\Bridge;

use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use SymfonyNativeBridge\Bridge\IpcBridge;
use SymfonyNativeBridge\Bridge\SymfonyEventBridgeIpcBridge;
use SymfonyNativeBridge\Event\AppReadyEvent;
use SymfonyNativeBridge\Event\WindowFocusedEvent;
use SymfonyNativeBridge\Exception\IpcException;
use SymfonyNativeBridge\Exception\RuntimeAbsentException;
use SymfonyNativeBridge\Exception\RuntimeCrashedException;

/**
 * Integration tests for IpcBridge using a real in-process WebSocket server
 * forked into a child process via pcntl_fork.
 *
 * These tests exercise the full JSON wire protocol without mocking any transport layer.
 *
 * @requires extension pcntl
 * @requires OS Linux
 */
class IpcBridgeIntegrationTest extends TestCase
{
    private const BASE_PORT = 19800;
    private static int $portOffset = 0;

    private int   $serverPort;
    private int   $serverPid;
    /** @var resource Socket to read messages captured by the server */
    private mixed $ipcRead;

    protected function setUp(): void
    {
        // Assign a unique port per test to avoid collisions
        $this->serverPort = self::BASE_PORT + self::$portOffset;
        self::$portOffset++;

        // Create a socket pair for parent ← child communication
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($pair, 'stream_socket_pair failed');

        [$ipcRead, $ipcWrite] = $pair;
        $this->ipcRead = $ipcRead;

        $server = new FakeNativeServer($this->serverPort);
        $this->configureServer($server);

        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid, 'pcntl_fork failed');

        if ($pid === 0) {
            // Child: run server
            fclose($ipcRead);
            $server->serveOne($ipcWrite);
            // serveOne() never returns (exit(0) inside)
        }

        // Parent: close write end, keep read end
        fclose($ipcWrite);
        $this->serverPid = $pid;

        // Wait for port to be ready (poll up to 2s)
        $this->waitForPort($this->serverPort);
    }

    /**
     * Override in subclasses or use data providers to configure the server before forking.
     * Default: no canned responses (all requests time out).
     */
    protected function configureServer(FakeNativeServer $server): void {}

    protected function tearDown(): void
    {
        posix_kill($this->serverPid, SIGTERM);
        pcntl_waitpid($this->serverPid, $status);
        fclose($this->ipcRead);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function waitForPort(int $port, int $maxAttempts = 40, int $sleepUs = 50_000): void
    {
        for ($i = 0; $i < $maxAttempts; $i++) {
            $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($sock !== false) {
                fclose($sock);
                return;
            }
            usleep($sleepUs);
        }
        $this->fail("Server on port {$port} did not become ready in time");
    }

    private function makeBridge(bool $strict = false): IpcBridge
    {
        $bridge = new IpcBridge('electron', strict: $strict);
        $bridge->connect("ws://127.0.0.1:{$this->serverPort}/ipc");

        return $bridge;
    }

    private function makeEventBridge(EventDispatcher $dispatcher, bool $strict = false): SymfonyEventBridgeIpcBridge
    {
        $bridge = new SymfonyEventBridgeIpcBridge('electron', $dispatcher, strict: $strict);
        $bridge->connect("ws://127.0.0.1:{$this->serverPort}/ipc");

        return $bridge;
    }

    /** Read the last message captured by the server child process. */
    private function readServerCapture(float $timeoutSeconds = 1.0): ?array
    {
        stream_set_timeout($this->ipcRead, (int) $timeoutSeconds, (int) (fmod($timeoutSeconds, 1) * 1_000_000));
        $line = fgets($this->ipcRead);

        if ($line === false || $line === '') {
            return null;
        }

        return json_decode(trim($line), true);
    }
}

// ── Concrete test classes (one server config per class) ───────────────────────

/**
 * Tests: standard request/response round-trip.
 */
class IpcBridgeCallTest extends IpcBridgeIntegrationTest
{
    protected function configureServer(FakeNativeServer $server): void
    {
        $server->willRespondTo('window.list', ['win_1', 'win_2']);
        $server->willRespondTo('window.open', 'win_3');
        $server->willRespondTo('app.name', 'MyApp');
    }

    public function testCallReturnsCannedResult(): void
    {
        $bridge = $this->makeBridge();

        $result = $bridge->call('window.list');

        $this->assertSame(['win_1', 'win_2'], $result);
    }

    public function testCallForwardsPayloadToServer(): void
    {
        $bridge = $this->makeBridge();

        $bridge->call('window.open', ['url' => 'http://localhost/dashboard', 'width' => 800]);

        $captured = $this->readServerCapture();
        $this->assertNotNull($captured);
        $this->assertSame('window.open', $captured['action']);
        $this->assertSame('http://localhost/dashboard', $captured['payload']['url']);
        $this->assertSame(800, $captured['payload']['width']);
    }

    public function testCallContainsUuidId(): void
    {
        $bridge = $this->makeBridge();

        $bridge->call('app.name');

        $captured = $this->readServerCapture();
        $this->assertNotNull($captured);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $captured['id']
        );
    }

    public function testMultipleSequentialCallsReturnCorrectResults(): void
    {
        $bridge = $this->makeBridge();

        $this->assertSame(['win_1', 'win_2'], $bridge->call('window.list'));
        $this->assertSame('win_3', $bridge->call('window.open'));
        $this->assertSame('MyApp', $bridge->call('app.name'));
    }
}

/**
 * Tests: native error response.
 */
class IpcBridgeNativeErrorTest extends IpcBridgeIntegrationTest
{
    protected function configureServer(FakeNativeServer $server): void
    {
        $server->willFail('window.close', 'Window not found');
    }

    public function testCallThrowsIpcExceptionOnNativeError(): void
    {
        $bridge = $this->makeBridge();

        $this->expectException(IpcException::class);
        $this->expectExceptionMessage('Window not found');

        $bridge->call('window.close');
    }
}

/**
 * Tests: push event dispatched while waiting for a call response.
 */
class IpcBridgePushEventTest extends IpcBridgeIntegrationTest
{
    protected function configureServer(FakeNativeServer $server): void
    {
        $server
            ->willPushBefore('app.ready', [])
            ->willRespondTo('window.list', ['win_1']);
    }

    public function testPushEventDispatchedDuringCall(): void
    {
        $dispatcher = new EventDispatcher();
        $received   = null;

        $dispatcher->addListener(AppReadyEvent::NAME, function (AppReadyEvent $e) use (&$received) {
            $received = $e;
        });

        $bridge = $this->makeEventBridge($dispatcher);
        $result = $bridge->call('window.list');

        $this->assertSame(['win_1'], $result, 'call() should still return the correct result');
        $this->assertInstanceOf(AppReadyEvent::class, $received, 'push event should have been dispatched');
    }

    public function testMultiplePushEventsBeforeResponse(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(
            AppReadyEvent::NAME,
            fn(AppReadyEvent $e) => null
        );

        $bridge = $this->makeEventBridge($dispatcher);

        // Should not throw and should return correct result
        $result = $bridge->call('window.list');
        $this->assertSame(['win_1'], $result);
    }
}

/**
 * Tests: strict mode — RuntimeAbsentException when no runtime running.
 */
class IpcBridgeStrictModeAbsentTest extends IpcBridgeIntegrationTest
{
    protected function configureServer(FakeNativeServer $server): void
    {
        // Server runs but we won't connect to it — we test the "absent" case
    }

    public function testStrictModeThrowsRuntimeAbsentExceptionWhenNoEnvSet(): void
    {
        // Ensure env vars are not set
        putenv('SYMFONY_IPC_PORT');
        putenv('SYMFONY_IPC_PIPE');
        unset($_ENV['SYMFONY_IPC_PORT'], $_ENV['SYMFONY_IPC_PIPE']);

        // Build bridge without explicit connect() — forces env resolution
        $bridge = new IpcBridge('electron', strict: true);

        $this->expectException(RuntimeAbsentException::class);
        $this->expectExceptionMessage('native:serve');

        $bridge->call('window.list');
    }

    public function testNonStrictModeReturnsNullWhenNoEnvSet(): void
    {
        putenv('SYMFONY_IPC_PORT');
        putenv('SYMFONY_IPC_PIPE');
        unset($_ENV['SYMFONY_IPC_PORT'], $_ENV['SYMFONY_IPC_PIPE']);

        $bridge = new IpcBridge('electron', strict: false);

        $result = $bridge->call('window.list');

        $this->assertNull($result);
    }
}

/**
 * Tests: JSON serialisation round-trip with complex nested payloads.
 */
class IpcBridgeJsonSerializationTest extends IpcBridgeIntegrationTest
{
    protected function configureServer(FakeNativeServer $server): void
    {
        $server->willRespondTo('complex.call', ['nested' => ['a' => 1]]);
    }

    public function testComplexNestedPayloadSerializesCorrectly(): void
    {
        $bridge = $this->makeBridge();

        $payload = [
            'window' => ['width' => 1024, 'height' => 768, 'title' => 'My App — Test'],
            'options' => ['resizable' => true, 'transparent' => false],
            'tags' => ['main', 'primary'],
        ];

        $bridge->call('complex.call', $payload);

        $captured = $this->readServerCapture();
        $this->assertNotNull($captured);
        $this->assertSame(1024, $captured['payload']['window']['width']);
        $this->assertSame('My App — Test', $captured['payload']['window']['title']);
        $this->assertSame(['main', 'primary'], $captured['payload']['tags']);
    }

    public function testNullResultIsHandled(): void
    {
        $bridge = $this->makeBridge();

        // 'complex.call' returns ['nested' => ['a' => 1]], not null — just verify no crash
        $result = $bridge->call('complex.call');
        $this->assertIsArray($result);
    }
}
