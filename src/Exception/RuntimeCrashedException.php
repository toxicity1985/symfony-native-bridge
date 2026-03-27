<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Exception;

/**
 * Thrown in strict mode when the native runtime was connected but
 * communication was lost unexpectedly after all reconnect attempts failed.
 *
 * Unlike RuntimeAbsentException (no runtime present), this indicates a
 * real problem: the Electron/Tauri process crashed or became unreachable
 * during an active session.
 */
class RuntimeCrashedException extends IpcException
{
    public function __construct(
        string $message,
        public readonly int $reconnectAttemptsMade = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
