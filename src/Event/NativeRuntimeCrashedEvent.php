<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Event;

/**
 * Dispatched when the native runtime (Electron/Tauri) was connected but
 * communication was unexpectedly lost.
 *
 * The bridge will attempt reconnection after this event is dispatched.
 * If all reconnect attempts fail and strict mode is enabled,
 * a RuntimeCrashedException will be thrown.
 */
final class NativeRuntimeCrashedEvent extends NativeEvent
{
    public const NAME = 'native.runtime.crashed';

    public function __construct(
        /** Human-readable reason for the connection loss. */
        public readonly string $reason,
        /** Estimated delay before the first reconnect attempt, in seconds. */
        public readonly float $nextRetryIn,
    ) {}

    public static function getEventName(): string
    {
        return self::NAME;
    }
}
