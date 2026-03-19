<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Driver;

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
    private ?int $pid = null;

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

    // -------------------------------------------------------------------------
    // Notifications
    // -------------------------------------------------------------------------

    public function sendNotification(NotificationOptions $options): void
    {
        // Tauri uses tauri-plugin-notification
        $this->ipcBridge->send('notification.send', [
            'title'    => $options->title,
            'body'     => $options->body,
            'icon'     => $options->icon,
            'sound'    => $options->sound,
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
        // Tauri dialog.message / dialog.ask / dialog.confirm
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

        return (int) ($result ?? 0);
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
        // Tauri uses path.appDataDir, path.homeDir, etc.
        $tauriPathMap = [
            'home'     => 'homeDir',
            'appData'  => 'appDataDir',
            'userData' => 'appLocalDataDir',
            'desktop'  => 'desktopDir',
            'documents'=> 'documentDir',
            'downloads'=> 'downloadDir',
            'temp'     => 'tempDir',
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
        $tauriBin  = $this->resolveTauriBinary();
        $pipePath  = $this->resolvePipePath();

        $env = array_merge(getenv(), [
            'SYMFONY_SERVER_URL' => $serverUrl,
            'SYMFONY_IPC_PIPE'   => $pipePath,
            'SYMFONY_APP_NAME'   => $config['app']['name'] ?? 'Symfony App',
        ]);

        $cmd        = escapeshellarg($tauriBin) . ' dev';
        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process   = proc_open($cmd, $descriptor, $pipes, null, $env);
        $status    = proc_get_status($process);
        $this->pid = $status['pid'];

        // Wait for Tauri to create the pipe
        $waited = 0;
        while (!file_exists($pipePath) && $waited < 10000) {
            usleep(100_000);
            $waited += 100;
        }

        if (!file_exists($pipePath)) {
            throw new NativeException("Tauri IPC pipe not created at {$pipePath} after 10s");
        }

        $this->ipcBridge->connect($pipePath);

        return $this->pid;
    }

    public function stop(): void
    {
        $this->ipcBridge->disconnect();

        if ($this->pid !== null) {
            posix_kill($this->pid, SIGTERM);
            $this->pid = null;
        }
    }

    public function build(array $buildConfig): void
    {
        $tauriBin = $this->resolveTauriBinary();
        $targets  = $buildConfig['targets'] ?? ['current'];

        // Tauri build targets are set in tauri.conf.json, but we can pass --target for cross-compilation
        $targetFlag = '';
        if ($targets !== ['current'] && count($targets) === 1) {
            $targetFlag = '--target ' . escapeshellarg($targets[0]);
        }

        $cmd = sprintf('%s build %s', escapeshellarg($tauriBin), $targetFlag);
        passthru($cmd, $exitCode);

        if ($exitCode !== 0) {
            throw new NativeException("tauri build failed with exit code {$exitCode}");
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
