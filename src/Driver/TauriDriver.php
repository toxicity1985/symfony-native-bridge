<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Driver;

use Symfony\Component\Process\Process;
use SymfonyNativeBridge\Bridge\IpcBridge;
use SymfonyNativeBridge\Exception\NativeException;
use SymfonyNativeBridge\ValueObject\DialogOptions;
use SymfonyNativeBridge\ValueObject\MenuItem;
use SymfonyNativeBridge\ValueObject\NotificationOptions;
use SymfonyNativeBridge\ValueObject\WindowOptions;

/**
 * TauriDriver communicates with a Tauri sidecar over a named pipe.
 *
 * The Tauri side is a Rust application that embeds a WebView and
 * exposes the same IPC protocol as the Electron runtime.
 *
 * Notable differences from Electron:
 * - IPC uses a named pipe (Unix socket / Windows named pipe) instead of WebSocket
 * - Window IDs are managed by Tauri's WebviewWindow labels
 * - Dialogs are handled by tauri-plugin-dialog
 * - Notifications go through tauri-plugin-notification
 * - Auto-updater uses tauri-plugin-updater
 */
class TauriDriver implements NativeDriverInterface
{
    private ?Process $tauriProcess = null;
    private ?int     $pid          = null;

    public function __construct(
        private readonly IpcBridge $ipcBridge,
        private readonly array $config,
    ) {}

    // -------------------------------------------------------------------------
    // Window
    // -------------------------------------------------------------------------

    public function openWindow(string $url, WindowOptions $options): string
    {
        return (string) $this->ipcBridge->call('window.open', [
            'url'         => $url,
            'label'       => $options->label ?? uniqid('win_'),
            'width'       => $options->width,
            'height'      => $options->height,
            'minWidth'    => $options->minWidth,
            'minHeight'   => $options->minHeight,
            'title'       => $options->title,
            'resizable'   => $options->resizable,
            'fullscreen'  => $options->fullscreen,
            'decorations' => $options->frame,     // Tauri uses "decorations"
            'transparent' => $options->transparent,
            'alwaysOnTop' => $options->alwaysOnTop,
        ]);
    }

    public function closeWindow(string $windowId): void
    {
        $this->ipcBridge->send('window.close', ['label' => $windowId]);
    }

    public function setWindowTitle(string $windowId, string $title): void
    {
        $this->ipcBridge->send('window.setTitle', ['label' => $windowId, 'title' => $title]);
    }

    public function resizeWindow(string $windowId, int $width, int $height): void
    {
        $this->ipcBridge->send('window.resize', ['label' => $windowId, 'width' => $width, 'height' => $height]);
    }

    public function minimizeWindow(string $windowId): void
    {
        $this->ipcBridge->send('window.minimize', ['label' => $windowId]);
    }

    public function maximizeWindow(string $windowId): void
    {
        $this->ipcBridge->send('window.maximize', ['label' => $windowId]);
    }

    public function setFullscreen(string $windowId, bool $fullscreen): void
    {
        $this->ipcBridge->send('window.setFullscreen', ['label' => $windowId, 'fullscreen' => $fullscreen]);
    }

    public function focusWindow(string $windowId): void
    {
        $this->ipcBridge->send('window.focus', ['label' => $windowId]);
    }

    public function listWindows(): array
    {
        return (array) $this->ipcBridge->call('window.list');
    }

    // -------------------------------------------------------------------------
    // Tray
    // -------------------------------------------------------------------------

    public function createTray(string $iconPath, string $tooltip = ''): string
    {
        return (string) $this->ipcBridge->call('tray.create', [
            'icon'    => $iconPath,
            'tooltip' => $tooltip,
        ]);
    }

    public function setTrayMenu(string $trayId, array $items): void
    {
        $this->ipcBridge->send('tray.setMenu', [
            'trayId' => $trayId,
            'items'  => array_map(fn(MenuItem $item) => $item->toArray(), $items),
        ]);
    }

    public function setTrayTooltip(string $trayId, string $tooltip): void
    {
        $this->ipcBridge->send('tray.setTooltip', ['trayId' => $trayId, 'tooltip' => $tooltip]);
    }

    public function destroyTray(string $trayId): void
    {
        $this->ipcBridge->send('tray.destroy', ['trayId' => $trayId]);
    }

    public function listTrays(): array
    {
        return (array) $this->ipcBridge->call('tray.list');
    }

    // -------------------------------------------------------------------------
    // Notifications
    // -------------------------------------------------------------------------

    public function sendNotification(NotificationOptions $options): void
    {
        // Tauri uses tauri-plugin-notification
        $this->ipcBridge->send('notification.send', [
            'title' => $options->title,
            'body'  => $options->body,
            'icon'  => $options->icon,
            'sound' => $options->sound,
        ]);
    }

    // -------------------------------------------------------------------------
    // Dialogs
    // -------------------------------------------------------------------------

    public function showOpenDialog(DialogOptions $options): ?array
    {
        $result = $this->ipcBridge->call('dialog.open', [
            'title'       => $options->title,
            'defaultPath' => $options->defaultPath,
            'filters'     => $options->filters,
            'multiple'    => in_array('multiSelections', $options->properties ?? [], true),
            'directory'   => in_array('openDirectory', $options->properties ?? [], true),
        ]);

        if ($result === null) {
            return null;
        }

        return is_array($result) ? $result : [$result];
    }

    public function showSaveDialog(DialogOptions $options): ?string
    {
        $result = $this->ipcBridge->call('dialog.save', [
            'title'       => $options->title,
            'defaultPath' => $options->defaultPath,
            'filters'     => $options->filters,
        ]);

        return is_string($result) ? $result : null;
    }

    public function showMessageBox(string $title, string $message, array $buttons = ['OK'], string $type = 'info'): int
    {
        $kind = match ($type) {
            'warning' => 'warning',
            'error'   => 'error',
            default   => 'info',
        };

        $result = $this->ipcBridge->call('dialog.message', [
            'title'   => $title,
            'message' => $message,
            'kind'    => $kind,
            'buttons' => $buttons,
        ]);

        return is_int($result) ? $result : (int) ($result ?? 0);
    }

    // -------------------------------------------------------------------------
    // App & Shell
    // -------------------------------------------------------------------------

    public function quit(): void
    {
        $this->ipcBridge->send('app.exit', ['code' => 0]);
    }

    public function relaunch(): void
    {
        $this->ipcBridge->send('app.relaunch');
    }

    public function getPath(string $name): string
    {
        // Map Electron path names to Tauri equivalents
        $tauriPathMap = [
            'home'      => 'homeDir',
            'appData'   => 'appDataDir',
            'userData'  => 'appLocalDataDir',
            'desktop'   => 'desktopDir',
            'documents' => 'documentDir',
            'downloads' => 'downloadDir',
            'temp'      => 'tempDir',
        ];

        $tauriName = $tauriPathMap[$name] ?? $name;

        return (string) $this->ipcBridge->call('path.resolve', ['name' => $tauriName]);
    }

    public function openExternal(string $url): void
    {
        $this->ipcBridge->send('shell.open', ['path' => $url]);
    }

    public function openPath(string $path): void
    {
        $this->ipcBridge->send('shell.open', ['path' => $path]);
    }

    public function trashItem(string $path): void
    {
        $this->ipcBridge->send('fs.removeFile', ['path' => $path, 'trash' => true]);
    }

    public function showItemInFolder(string $path): void
    {
        $this->ipcBridge->send('shell.revealItemInDir', ['path' => $path]);
    }

    // -------------------------------------------------------------------------
    // Persistent Storage
    // -------------------------------------------------------------------------

    public function storeGet(string $key): mixed
    {
        return $this->ipcBridge->call('store.get', ['key' => $key]);
    }

    public function storeSet(string $key, mixed $value): void
    {
        $this->ipcBridge->send('store.set', ['key' => $key, 'value' => $value]);
    }

    public function storeDelete(string $key): void
    {
        $this->ipcBridge->send('store.delete', ['key' => $key]);
    }

    // -------------------------------------------------------------------------
    // Clipboard
    // -------------------------------------------------------------------------

    public function clipboardReadText(): ?string
    {
        // Tauri uses tauri-plugin-clipboard-manager
        $result = $this->ipcBridge->call('clipboard.readText');
        return is_string($result) && $result !== '' ? $result : null;
    }

    public function clipboardWriteText(string $text): void
    {
        $this->ipcBridge->send('clipboard.writeText', ['text' => $text]);
    }

    public function clipboardReadImage(): ?string
    {
        $result = $this->ipcBridge->call('clipboard.readImage');
        return is_string($result) && $result !== '' ? $result : null;
    }

    public function clipboardWriteImage(string $path): void
    {
        $this->ipcBridge->send('clipboard.writeImage', ['path' => $path]);
    }

    public function clipboardClear(): void
    {
        $this->ipcBridge->send('clipboard.clear');
    }

    // -------------------------------------------------------------------------
    // Protocol Handler (Deep-links)
    // -------------------------------------------------------------------------

    public function registerProtocol(string $scheme): void
    {
        // Tauri uses tauri-plugin-deep-link
        $this->ipcBridge->send('protocol.register', ['scheme' => $scheme]);
    }

    public function unregisterProtocol(string $scheme): void
    {
        $this->ipcBridge->send('protocol.unregister', ['scheme' => $scheme]);
    }

    // -------------------------------------------------------------------------
    // Auto-Updater
    // -------------------------------------------------------------------------

    public function checkForUpdates(): void
    {
        $this->ipcBridge->send('updater.check');
    }

    public function installUpdate(): void
    {
        $this->ipcBridge->send('updater.downloadAndInstall');
    }

    // -------------------------------------------------------------------------
    // Lifecycle & Build
    // -------------------------------------------------------------------------

    public function start(string $serverUrl, array $config): int
    {
        $tauriBin = $this->resolveTauriBinary();
        $pipePath = $this->resolvePipePath();

        // Generate a session token so Tauri can reject unauthorised pipe connections
        $ipcToken = bin2hex(random_bytes(16));

        $env = array_merge(getenv() ?: [], [
            'SYMFONY_SERVER_URL' => $serverUrl,
            'SYMFONY_IPC_PIPE'   => $pipePath,
            'SYMFONY_IPC_TOKEN'  => $ipcToken,
            'SYMFONY_APP_NAME'   => $config['app']['name'] ?? 'Symfony App',
        ]);

        $process = new Process([$tauriBin, 'dev'], null, $env);
        $process->setTimeout(null);
        $process->start();

        $this->tauriProcess = $process;
        $this->pid          = $process->getPid();

        // Wait for Tauri to create the named pipe (max 10s)
        $waited = 0;
        while (!file_exists($pipePath) && $waited < 10_000) {
            if (!$process->isRunning()) {
                throw new NativeException(
                    "Tauri process exited before creating the IPC pipe.\n" .
                    $process->getErrorOutput()
                );
            }
            usleep(100_000);
            $waited += 100;
        }

        if (!file_exists($pipePath)) {
            $process->stop(3);
            throw new NativeException(
                "Tauri IPC pipe not created at {$pipePath} after 10s.\n" .
                $process->getErrorOutput()
            );
        }

        $this->ipcBridge->connect($pipePath, $ipcToken);

        return $this->pid ?? 0;
    }

    public function stop(): void
    {
        $this->ipcBridge->disconnect();

        if ($this->tauriProcess !== null && $this->tauriProcess->isRunning()) {
            $this->tauriProcess->stop(3);
            $this->tauriProcess = null;
        }

        $this->pid = null;

        // Clean up the pipe file on Unix
        $pipePath = $this->resolvePipePath();
        if (PHP_OS_FAMILY !== 'Windows' && file_exists($pipePath)) {
            @unlink($pipePath);
        }
    }

    public function build(array $buildConfig): void
    {
        $tauriBin = $this->resolveTauriBinary();
        $targets  = $buildConfig['targets'] ?? ['current'];

        $cmd = [$tauriBin, 'build'];

        // Pass explicit target only for cross-compilation (single target supported by Tauri CLI)
        if ($targets !== ['current'] && count($targets) === 1) {
            $cmd[] = '--target';
            $cmd[] = $targets[0];
        }

        $process = new Process($cmd, getcwd());
        $process->setTimeout(600); // builds can be slow
        $process->run(fn(string $type, string $buffer) => print($buffer));

        if (!$process->isSuccessful()) {
            throw new NativeException(
                "tauri build failed with exit code {$process->getExitCode()}\n" .
                $process->getErrorOutput()
            );
        }
    }

    public function getName(): string
    {
        return 'tauri';
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private function resolveTauriBinary(): string
    {
        $candidates = [
            getcwd() . '/node_modules/.bin/tauri',
            getcwd() . '/node_modules/.bin/tauri.cmd',
            // cargo-tauri installed globally via `cargo install tauri-cli`
            trim((string) shell_exec('which cargo-tauri 2>/dev/null')),
        ];

        foreach ($candidates as $path) {
            if ($path !== '' && is_executable($path)) {
                return $path;
            }
        }

        throw new NativeException(
            'Tauri CLI not found. Run: php bin/console native:install'
        );
    }

    private function resolvePipePath(): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return '\\\\.\\pipe\\symfony-native-bridge';
        }

        return sys_get_temp_dir() . '/symfony-native-bridge.sock';
    }
}
