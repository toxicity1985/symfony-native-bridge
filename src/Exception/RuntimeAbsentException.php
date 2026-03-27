<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Exception;

/**
 * Thrown in strict mode when the native runtime is not running.
 *
 * This is an expected condition — the application may be running in a
 * CLI-only or test context where no Electron/Tauri process is present.
 * Enable strict mode only when you want hard failures in those contexts.
 */
class RuntimeAbsentException extends IpcException {}
