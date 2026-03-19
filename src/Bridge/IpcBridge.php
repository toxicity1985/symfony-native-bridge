<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Bridge;

use SymfonyNativeBridge\Exception\IpcException;
use WebSocket\Client as WsClient;
use WebSocket\ConnectionException;
use WebSocket\TimeoutException;

/**
 * IpcBridge — lazy WebSocket connection to the native runtime.
 *
 * Connection is established on the FIRST call()/send(), not at construction.
 * This allows the service to be injected normally by Symfony's DI container
 * even when no native runtime is running (e.g. during cache:clear, tests…).
 *
 * The IPC endpoint is read from the environment variable SYMFONY_IPC_PORT
 * (Electron) or SYMFONY_IPC_PIPE (Tauri), both injected by native:serve.
 *
 * If no runtime is present, calls silently no-op or throw IpcException
 * depending on $throwIfNotAvailable (default: false = silent no-op).
 */
class IpcBridge
{
    private const TIMEOUT      = 10;    // seconds per call
    private const CONNECT_WAIT = 5_000; // µs between lazy-connect retries

    private ?WsClient $wsClient   = null;
    private mixed     $pipeSocket = null;
    private bool      $connected  = false;

    /**
     * Cached endpoint resolved from env at first use.
     * null = not yet resolved, false = runtime not available.
     */
    private string|false|null $endpoint = null;

    public function __construct(
        private readonly string $driver,
        private readonly bool   $throwIfNotAvailable = false,
    ) {}

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Send an action and wait for the response.
     * Returns null silently if the native runtime is not available.
     */
    public function call(string $action, array $payload = []): mixed
    {
        if (!$this->ensureConnected()) {
            return null;
        }

        $id = $this->uuid();

        $this->write(json_encode([
            'id'      => $id,
            'action'  => $action,
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR));

        return $this->waitForResponse($id);
    }

    /**
     * Fire-and-forget — no response expected.
     * Silently skipped if the native runtime is not available.
     */
    public function send(string $action, array $payload = []): void
    {
        if (!$this->ensureConnected()) {
            return;
        }

        $this->write(json_encode([
            'id'      => $this->uuid(),
            'action'  => $action,
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Called by ElectronDriver::start() once Electron is confirmed running.
     * Skips lazy-resolution and connects immediately to the known endpoint.
     */
    public function connect(string $socketPath): void
    {
        $this->endpoint = $socketPath;

        if ($this->driver === 'tauri') {
            $this->connectPipe($socketPath);
        } else {
            $this->connectWebSocket($socketPath);
        }

        $this->connected = true;
    }

    public function disconnect(): void
    {
        if ($this->wsClient !== null) {
            try { $this->wsClient->close(); } catch (\Throwable) {}
            $this->wsClient = null;
        }

        if ($this->pipeSocket !== null) {
            fclose($this->pipeSocket);
            $this->pipeSocket = null;
        }

        $this->connected  = false;
        $this->endpoint   = null; // allow re-connect on next call
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    // ── Lazy connection ───────────────────────────────────────────────────────

    /**
     * Ensure we have an active connection before sending anything.
     * Returns false (and optionally throws) if runtime is unavailable.
     */
    private function ensureConnected(): bool
    {
        if ($this->connected) {
            return true;
        }

        // Resolve endpoint from env if not already done
        if ($this->endpoint === null) {
            $this->endpoint = $this->resolveEndpointFromEnv();
        }

        // Runtime not available
        if ($this->endpoint === false) {
            if ($this->throwIfNotAvailable) {
                throw new IpcException(
                    'Native runtime is not available. ' .
                    'Run "php bin/console native:serve" first.'
                );
            }

            return false;
        }

        // Try to connect
        try {
            $this->connect($this->endpoint);
            return true;
        } catch (IpcException $e) {
            if ($this->throwIfNotAvailable) {
                throw $e;
            }

            return false;
        }
    }

    /**
     * Read IPC endpoint from environment variables injected by native:serve.
     * Returns false if the runtime is not running.
     */
    private function resolveEndpointFromEnv(): string|false
    {
        if ($this->driver === 'tauri') {
            $pipe = $_ENV['SYMFONY_IPC_PIPE'] ?? getenv('SYMFONY_IPC_PIPE');
            return ($pipe && file_exists($pipe)) ? $pipe : false;
        }

        // Electron: check SYMFONY_IPC_PORT and verify port is open
        $port = $_ENV['SYMFONY_IPC_PORT'] ?? getenv('SYMFONY_IPC_PORT');

        if (!$port) {
            return false;
        }

        // Quick TCP probe — is Electron actually listening?
        $sock = @fsockopen('127.0.0.1', (int) $port, $errno, $errstr, 0.3);
        if ($sock === false) {
            return false;
        }
        fclose($sock);

        return "ws://127.0.0.1:{$port}/ipc";
    }

    // ── Transport ─────────────────────────────────────────────────────────────

    private function connectWebSocket(string $url): void
    {
        try {
            $this->wsClient = new WsClient($url, [
                'timeout'       => self::TIMEOUT,
                'fragment_size' => 1_048_576,
            ]);
        } catch (ConnectionException $e) {
            throw new IpcException(
                "Cannot connect to Electron IPC at {$url}: " . $e->getMessage(),
                previous: $e,
            );
        }
    }

    private function connectPipe(string $pipePath): void
    {
        $socket = @fopen($pipePath, 'r+b');

        if ($socket === false) {
            throw new IpcException("Cannot open Tauri pipe at {$pipePath}");
        }

        stream_set_timeout($socket, self::TIMEOUT);
        stream_set_blocking($socket, false);
        $this->pipeSocket = $socket;
    }

    private function write(string $data): void
    {
        if ($this->wsClient !== null) {
            try {
                $this->wsClient->text($data);
            } catch (ConnectionException $e) {
                $this->connected = false;
                throw new IpcException('WebSocket write failed: ' . $e->getMessage(), previous: $e);
            }
            return;
        }

        if ($this->pipeSocket !== null) {
            if (fwrite($this->pipeSocket, $data . "\n") === false) {
                throw new IpcException('Pipe write failed');
            }
            fflush($this->pipeSocket);
            return;
        }

        throw new IpcException('IpcBridge has no active transport.');
    }

    private function read(): string
    {
        if ($this->wsClient !== null) {
            try {
                $raw = $this->wsClient->receive();
            } catch (TimeoutException) {
                return '';
            } catch (ConnectionException $e) {
                $this->connected = false;
                throw new IpcException('WebSocket read failed: ' . $e->getMessage(), previous: $e);
            }

            if ($raw === null) {
                return '';
            }

            // sirn-se/websocket-php v2 returns a Message object; v1.x returns string
            return is_string($raw) ? $raw : $raw->getContent();
        }

        if ($this->pipeSocket !== null) {
            $line = fgets($this->pipeSocket);
            return $line !== false ? trim($line) : '';
        }

        throw new IpcException('IpcBridge has no active transport.');
    }

    private function waitForResponse(string $id): mixed
    {
        $deadline = microtime(true) + self::TIMEOUT;

        while (microtime(true) < $deadline) {
            $raw = $this->read();

            if ($raw === '') {
                usleep(5_000);
                continue;
            }

            try {
                $msg = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            // Push event from runtime — dispatch and keep waiting for our response
            if (isset($msg['event']) && !array_key_exists('id', $msg)) {
                $this->dispatchPushEvent($msg);
                continue;
            }

            if (($msg['id'] ?? null) !== $id) {
                continue;
            }

            if (($msg['ok'] ?? true) === false) {
                throw new IpcException('Native error: ' . ($msg['error'] ?? 'unknown'));
            }

            return $msg['result'] ?? null;
        }

        throw new IpcException(
            "Timed out after " . self::TIMEOUT . "s waiting for response (id={$id})"
        );
    }

    /**
     * Overridden by SymfonyEventBridgeIpcBridge to dispatch Symfony events.
     */
    protected function dispatchPushEvent(array $msg): void {}

    private function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        );
    }
}