<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Bridge;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SymfonyNativeBridge\Exception\IpcException;
use SymfonyNativeBridge\Exception\RuntimeAbsentException;
use SymfonyNativeBridge\Exception\RuntimeCrashedException;
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
 * If no runtime is present, calls silently no-op or throw typed exceptions
 * depending on $strict (default: false = silent no-op).
 *
 * If the runtime crashes mid-session, automatic reconnection is attempted
 * with exponential backoff (up to MAX_RECONNECT_ATTEMPTS times).
 */
class IpcBridge
{
    private const TIMEOUT      = 10;    // seconds per call
    private const CONNECT_WAIT = 5_000; // µs between lazy-connect retries

    protected const MAX_RECONNECT_ATTEMPTS = 5;
    protected const RECONNECT_BASE_DELAY   = 500_000; // µs (0.5 s)

    private ?WsClient $wsClient   = null;
    private mixed     $pipeSocket = null;
    private bool      $connected  = false;

    /**
     * Cached endpoint resolved from env at first use.
     * null = not yet resolved, false = runtime not available.
     */
    private string|false|null $endpoint = null;

    /**
     * Optional shared secret validated during WebSocket connection.
     * When set, the token is appended as a query parameter to the WebSocket URL
     * so Electron can reject unauthorised connections during the HTTP upgrade.
     */
    private ?string $token = null;

    /** Number of reconnect loop iterations made in the last crash episode. */
    protected int $reconnectAttempts = 0;

    protected readonly LoggerInterface $logger;

    public function __construct(
        protected readonly string $driver,
        protected readonly bool   $throwIfNotAvailable = false,
        ?LoggerInterface          $logger = null,
        protected readonly bool   $strict = false,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Send an action and wait for the response.
     * Returns null silently if the native runtime is not available (non-strict mode).
     *
     * @throws RuntimeAbsentException  if strict=true and runtime is not running
     * @throws RuntimeCrashedException if strict=true and connection was lost and could not be restored
     * @throws IpcException            on native error or timeout
     */
    public function call(string $action, array $payload = []): mixed
    {
        if (!$this->ensureConnected()) {
            return null;
        }

        $id      = $this->uuid();
        $message = json_encode([
            'id'      => $id,
            'action'  => $action,
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR);

        $this->logger->debug('IPC call sent', ['id' => $id, 'action' => $action]);

        try {
            $this->write($message);
            $result = $this->waitForResponse($id);
            $this->logger->debug('IPC call received', ['id' => $id, 'action' => $action]);

            return $result;
        } catch (IpcException $e) {
            if (!$this->connected && $this->attemptReconnect()) {
                $this->logger->debug('Retrying IPC call after reconnect', ['id' => $id, 'action' => $action]);
                $this->write($message);
                $result = $this->waitForResponse($id);
                $this->logger->debug('IPC call received after reconnect', ['id' => $id, 'action' => $action]);

                return $result;
            }

            if ($this->strict) {
                throw new RuntimeCrashedException(
                    'Native runtime connection lost: ' . $e->getMessage(),
                    $this->reconnectAttempts,
                    $e,
                );
            }

            throw $e;
        }
    }

    /**
     * Send multiple actions in parallel and wait for all responses.
     *
     * All messages are written to the socket before waiting for any response,
     * so the round-trip time is the max of individual latencies rather than
     * their sum.
     *
     * @param list<array{action: string, payload?: array<string, mixed>}> $actions
     * @param bool $throwOnError When true (default), the first native error throws an IpcException
     *                           and aborts collection. When false, errors are stored as IpcException
     *                           instances in the result array at the failing index so callers can
     *                           inspect partial results.
     * @return list<mixed|IpcException> Results indexed in the same order as $actions.
     *                                  An entry is null if the runtime returned no result,
     *                                  or an IpcException if throwOnError=false and that action failed.
     *
     * @throws IpcException on timeout, or on native error when throwOnError=true
     */
    public function callBatch(array $actions, bool $throwOnError = true): array
    {
        if (empty($actions)) {
            return [];
        }

        if (!$this->ensureConnected()) {
            return array_fill(0, count($actions), null);
        }

        // Map id => position in $actions so we can fill results in order
        $pending = []; // id => index

        foreach ($actions as $index => $action) {
            $id      = $this->uuid();
            $message = json_encode([
                'id'      => $id,
                'action'  => $action['action'],
                'payload' => $action['payload'] ?? [],
            ], JSON_THROW_ON_ERROR);

            $this->logger->debug('IPC batch call sent', ['id' => $id, 'action' => $action['action']]);
            $this->write($message);
            $pending[$id] = $index;
        }

        $results  = array_fill(0, count($actions), null);
        $deadline = microtime(true) + self::TIMEOUT;

        while (!empty($pending) && microtime(true) < $deadline) {
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

            // Push event — dispatch and keep collecting responses
            if (isset($msg['event']) && !array_key_exists('id', $msg)) {
                $this->logger->debug('IPC push event received during batch', ['event' => $msg['event'] ?? 'unknown']);
                $this->dispatchPushEvent($msg);
                continue;
            }

            $msgId = $msg['id'] ?? null;
            if ($msgId === null || !isset($pending[$msgId])) {
                continue;
            }

            if (($msg['ok'] ?? true) === false) {
                $error = new IpcException('Native error: ' . ($msg['error'] ?? 'unknown'));
                if ($throwOnError) {
                    throw $error;
                }
                $results[$pending[$msgId]] = $error;
                unset($pending[$msgId]);
                continue;
            }

            $results[$pending[$msgId]] = $msg['result'] ?? null;
            unset($pending[$msgId]);
        }

        if (!empty($pending)) {
            $this->logger->error('IPC batch timed out', ['remaining' => count($pending), 'timeout' => self::TIMEOUT]);
            throw new IpcException(
                sprintf("Timed out after %ds waiting for %d batch response(s)", self::TIMEOUT, count($pending))
            );
        }

        $this->logger->debug('IPC batch complete', ['count' => count($actions)]);

        return $results;
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

        $this->logger->debug('IPC send', ['action' => $action]);

        $this->write(json_encode([
            'id'      => $this->uuid(),
            'action'  => $action,
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Called by ElectronDriver::start() once Electron is confirmed running.
     * Skips lazy-resolution and connects immediately to the known endpoint.
     *
     * @param string      $socketPath WebSocket URL (Electron) or pipe path (Tauri).
     * @param string|null $token      Shared secret to append as ?token= query parameter.
     *                                Must match the SYMFONY_IPC_TOKEN env var read by Electron.
     */
    public function connect(string $socketPath, ?string $token = null): void
    {
        $this->endpoint = $socketPath;

        if ($token !== null) {
            $this->token = $token;
        }

        if ($this->driver === 'tauri') {
            $this->connectPipe($socketPath);
        } else {
            $url = $this->token !== null
                ? $socketPath . '?token=' . urlencode($this->token)
                : $socketPath;
            $this->connectWebSocket($url);
        }

        $this->connected = true;
        $this->logger->debug('IpcBridge connected', [
            'endpoint'       => $socketPath,
            'driver'         => $this->driver,
            'authenticated'  => $this->token !== null,
        ]);
    }

    public function disconnect(): void
    {
        $this->logger->debug('IpcBridge disconnecting', ['endpoint' => $this->endpoint]);

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

    /**
     * Create a new, unconnected IpcBridge with the same driver / logger / strict settings.
     *
     * Use this when you need a second independent connection to the same runtime
     * (e.g. a dedicated hot-reload channel alongside the main IPC channel) while
     * keeping all configuration (logger, strict mode) consistent.
     */
    public function createCompanion(): static
    {
        return new static($this->driver, $this->throwIfNotAvailable, $this->logger, $this->strict);
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
            $this->logger->debug('Native runtime not available, skipping IPC call');

            if ($this->throwIfNotAvailable || $this->strict) {
                throw new RuntimeAbsentException(
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
            if ($this->throwIfNotAvailable || $this->strict) {
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

    // ── Reconnection ──────────────────────────────────────────────────────────

    /**
     * Attempt to re-establish the connection with exponential backoff.
     * Returns true if reconnection succeeded, false after all attempts fail.
     */
    private function attemptReconnect(): bool
    {
        if ($this->endpoint === null || $this->endpoint === false) {
            return false;
        }

        for ($attempt = 1; $attempt <= self::MAX_RECONNECT_ATTEMPTS; $attempt++) {
            $delay = (int) (self::RECONNECT_BASE_DELAY * (2 ** ($attempt - 1)));
            $this->logger->debug('Reconnect attempt', ['attempt' => $attempt, 'delay_us' => $delay]);

            usleep($delay);

            try {
                $this->wsClient   = null;
                $this->pipeSocket = null;
                $this->connected  = false;

                $this->connect($this->endpoint, $this->token);
                $this->reconnectAttempts = 0;
                $this->logger->debug('Reconnected successfully', ['attempt' => $attempt]);

                return true;
            } catch (IpcException) {
                // keep retrying
            }
        }

        $this->reconnectAttempts = self::MAX_RECONNECT_ATTEMPTS;
        $this->logger->error('Max reconnect attempts reached, giving up', [
            'max_attempts' => self::MAX_RECONNECT_ATTEMPTS,
        ]);

        return false;
    }

    /**
     * Called when the transport is unexpectedly lost.
     * Subclasses may override to dispatch domain events.
     */
    protected function onTransportLost(string $reason): void
    {
        $this->logger->error('Transport lost', ['reason' => $reason]);
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
            $this->logger->error('WebSocket connection failed', [
                'url'   => $url,
                'error' => $e->getMessage(),
            ]);
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
            $this->logger->error('Pipe connection failed', ['path' => $pipePath]);
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
                $this->onTransportLost($e->getMessage());
                throw new IpcException('WebSocket write failed: ' . $e->getMessage(), previous: $e);
            }

            return;
        }

        if ($this->pipeSocket !== null) {
            if (fwrite($this->pipeSocket, $data . "\n") === false) {
                $this->connected = false;
                $this->onTransportLost('Pipe write failed');
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
                $this->onTransportLost($e->getMessage());
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
                $this->logger->debug('IPC push event received', ['event' => $msg['event'] ?? 'unknown']);
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

        $this->logger->error('IPC call timed out', ['id' => $id, 'timeout' => self::TIMEOUT]);

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
