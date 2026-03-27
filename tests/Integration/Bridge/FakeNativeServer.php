<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Tests\Integration\Bridge;

use WebSocket\Server as WsServer;

/**
 * A fake native runtime (Electron-like) WebSocket server for integration tests.
 *
 * Usage in child process:
 *   $server = new FakeNativeServer($port);
 *   $server->willRespondTo('window.open', 'win_1');
 *   $server->serveOne(); // blocks until one client connects, handles messages, then exits
 *
 * The server:
 *  - Accepts one client connection
 *  - Reads JSON messages
 *  - Sends back queued push events (before or after a response)
 *  - Sends {"id": ..., "ok": true, "result": ...} for known actions
 *  - Sends {"id": ..., "ok": false, "error": ...} for actions registered with willFail()
 *  - Ignores unknown actions (simulates no-response / timeout)
 */
class FakeNativeServer
{
    /** @var array<string, mixed> action => result */
    private array $responses = [];

    /** @var array<string, string> action => error message */
    private array $failures = [];

    /** @var list<array> Push events to send before responding to the next request */
    private array $pendingPushEvents = [];

    /** Last message received by the server (shared via socket pair) */
    private mixed $ipcWrite = null;

    private WsServer $server;

    public function __construct(private readonly int $port)
    {
        $this->server = new WsServer(['port' => $this->port, 'timeout' => 5]);
    }

    public function willRespondTo(string $action, mixed $result): self
    {
        $this->responses[$action] = $result;

        return $this;
    }

    public function willFail(string $action, string $error): self
    {
        $this->failures[$action] = $error;

        return $this;
    }

    public function willPushBefore(string $event, array $payload = []): self
    {
        $this->pendingPushEvents[] = ['event' => $event, 'payload' => $payload];

        return $this;
    }

    /**
     * Accept one client, handle messages until the client disconnects or times out.
     * Writes the last received raw message to $ipcWrite socket for parent assertions.
     *
     * @param resource $ipcWrite Socket to write received messages to (from stream_socket_pair)
     */
    public function serveOne(mixed $ipcWrite): never
    {
        $this->ipcWrite = $ipcWrite;

        // accept() starts listening and waits for first receive()/send()
        $this->server->accept();

        while (true) {
            try {
                $raw = $this->server->receive();
            } catch (\Throwable) {
                break;
            }

            if ($raw === null) {
                break;
            }

            // Forward raw message to parent for assertions
            if ($this->ipcWrite !== null) {
                @fwrite($this->ipcWrite, $raw . "\n");
                @fflush($this->ipcWrite);
            }

            try {
                $msg = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            $id     = $msg['id'] ?? null;
            $action = $msg['action'] ?? null;

            // Send queued push events first
            foreach ($this->pendingPushEvents as $push) {
                try {
                    $this->server->text(json_encode($push, JSON_THROW_ON_ERROR));
                } catch (\Throwable) {
                    break 2;
                }
            }
            $this->pendingPushEvents = [];

            if ($id === null || $action === null) {
                continue;
            }

            // Send canned error response
            if (isset($this->failures[$action])) {
                try {
                    $this->server->text(json_encode([
                        'id'    => $id,
                        'ok'    => false,
                        'error' => $this->failures[$action],
                    ], JSON_THROW_ON_ERROR));
                } catch (\Throwable) {
                    break;
                }
                continue;
            }

            // Send canned success response
            if (array_key_exists($action, $this->responses)) {
                try {
                    $this->server->text(json_encode([
                        'id'     => $id,
                        'ok'     => true,
                        'result' => $this->responses[$action],
                    ], JSON_THROW_ON_ERROR));
                } catch (\Throwable) {
                    break;
                }
                continue;
            }

            // Unknown action — do nothing (simulates timeout on client side)
        }

        $this->server->close();
        exit(0);
    }
}
