<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Driver;

use SymfonyNativeBridge\ValueObject\MenuItem;
use SymfonyNativeBridge\ValueObject\WindowOptions;
use SymfonyNativeBridge\ValueObject\DialogOptions;
use SymfonyNativeBridge\ValueObject\NotificationOptions;

interface NativeDriverInterface
{
    // -------------------------------------------------------------------------
    // Window
    // -------------------------------------------------------------------------

    /**
     * Open a new native window loading the given URL.
     */
    public function openWindow(string $url, WindowOptions $options): string;

    /**
     * Close a window by its ID.
     */
    public function closeWindow(string $windowId): void;

    /**
     * Set the title of a window.
     */
    public function setWindowTitle(string $windowId, string $title): void;

    /**
     * Resize a window.
     */
    public function resizeWindow(string $windowId, int $width, int $height): void;

    /**
     * Minimize a window.
     */
    public function minimizeWindow(string $windowId): void;

    /**
     * Maximize a window.
     */
    public function maximizeWindow(string $windowId): void;

    /**
     * Toggle fullscreen for a window.
     */
    public function setFullscreen(string $windowId, bool $fullscreen): void;

    /**
     * Focus a window.
     */
    public function focusWindow(string $windowId): void;

    /**
     * List all open window IDs.
     *
     * @return string[]
     */
    public function listWindows(): array;

    // -------------------------------------------------------------------------
    // Tray
    // -------------------------------------------------------------------------

    /**
     * Create a system tray icon.
     * Returns a unique tray ID.
     */
    public function createTray(string $iconPath, string $tooltip = ''): string;

    /**
     * Set or replace the context menu on a tray icon.
     *
     * @param MenuItem[] $items
     */
    public function setTrayMenu(string $trayId, array $items): void;

    /**
     * Update the tooltip on a tray icon.
     */
    public function setTrayTooltip(string $trayId, string $tooltip): void;

    /**
     * Remove a tray icon.
     */
    public function destroyTray(string $trayId): void;

    /**
     * List all active tray IDs.
     *
     * @return string[]
     */
    public function listTrays(): array;

    // -------------------------------------------------------------------------
    // Notifications
    // -------------------------------------------------------------------------

    /**
     * Display a desktop notification.
     */
    public function sendNotification(NotificationOptions $options): void;

    // -------------------------------------------------------------------------
    // Dialogs
    // -------------------------------------------------------------------------

    /**
     * Show a native "Open File" dialog.
     * Returns selected path(s) or null if cancelled.
     *
     * @return string[]|null
     */
    public function showOpenDialog(DialogOptions $options): ?array;

    /**
     * Show a native "Save File" dialog.
     * Returns the chosen path or null if cancelled.
     */
    public function showSaveDialog(DialogOptions $options): ?string;

    /**
     * Show a native message box (alert/confirm/prompt).
     * Returns the index of the button pressed.
     */
    public function showMessageBox(string $title, string $message, array $buttons = ['OK'], string $type = 'info'): int;

    // -------------------------------------------------------------------------
    // App & Shell
    // -------------------------------------------------------------------------

    /**
     * Quit the application.
     */
    public function quit(): void;

    /**
     * Relaunch the application.
     */
    public function relaunch(): void;

    /**
     * Get the path to a special system directory (home, appData, desktop, etc.)
     */
    public function getPath(string $name): string;

    /**
     * Open a URL in the default browser, or a file/folder in the native explorer.
     */
    public function openExternal(string $url): void;

    /**
     * Open a file using the default application for its type.
     */
    public function openPath(string $path): void;

    /**
     * Move a file to the system trash.
     */
    public function trashItem(string $path): void;

    /**
     * Reveal a file or folder in the native file manager.
     */
    public function showItemInFolder(string $path): void;

    /**
     * Read a value from persistent native key-value storage.
     */
    public function storeGet(string $key): mixed;

    /**
     * Write a value to persistent native key-value storage.
     */
    public function storeSet(string $key, mixed $value): void;

    /**
     * Delete a key from persistent native key-value storage.
     */
    public function storeDelete(string $key): void;

    // -------------------------------------------------------------------------
    // Clipboard
    // -------------------------------------------------------------------------

    /**
     * Read plain text from the system clipboard. Returns null when the
     * clipboard is empty or does not contain text.
     */
    public function clipboardReadText(): ?string;

    /**
     * Write plain text to the system clipboard.
     */
    public function clipboardWriteText(string $text): void;

    /**
     * Read an image from the system clipboard.
     * Returns a base64-encoded PNG string, or null if no image is present.
     */
    public function clipboardReadImage(): ?string;

    /**
     * Copy a local image file into the system clipboard.
     */
    public function clipboardWriteImage(string $path): void;

    /**
     * Clear the system clipboard.
     */
    public function clipboardClear(): void;

    // -------------------------------------------------------------------------
    // Protocol Handler (Deep-links)
    // -------------------------------------------------------------------------

    /**
     * Register a custom URL scheme as the OS default handler for this app.
     * After registration, clicking e.g. myapp:// links will launch this app
     * and fire a DeepLinkReceivedEvent via the IPC bridge.
     */
    public function registerProtocol(string $scheme): void;

    /**
     * Remove the custom URL scheme registration for this app.
     */
    public function unregisterProtocol(string $scheme): void;

    // -------------------------------------------------------------------------
    // Auto-Updater
    // -------------------------------------------------------------------------

    /**
     * Check for updates at the configured URL.
     */
    public function checkForUpdates(): void;

    /**
     * Download and install a pending update, then restart.
     */
    public function installUpdate(): void;

    // -------------------------------------------------------------------------
    // Build & Lifecycle
    // -------------------------------------------------------------------------

    /**
     * Start the native runtime (spawn Electron/Tauri process).
     * Returns the PID of the spawned process.
     */
    public function start(string $serverUrl, array $config): int;

    /**
     * Stop the native runtime.
     */
    public function stop(): void;

    /**
     * Build a distributable package (.exe / .dmg / .AppImage).
     */
    public function build(array $buildConfig): void;

    /**
     * Return the name of this driver ("electron" or "tauri").
     */
    public function getName(): string;
}
