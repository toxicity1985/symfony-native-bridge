<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Driver;

use SymfonyNativeBridge\Bridge\IpcBridge;
use SymfonyNativeBridge\Exception\NativeException;
use SymfonyNativeBridge\ValueObject\DialogOptions;
use SymfonyNativeBridge\ValueObject\MenuItem;
use SymfonyNativeBridge\ValueObject\NotificationOptions;
use SymfonyNativeBridge\ValueObject\WindowOptions;
use Symfony\Component\Process\Process;

class ElectronDriver implements NativeDriverInterface
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
            'url'             => $url,
            'width'           => $options->width,
            'height'          => $options->height,
            'minWidth'        => $options->minWidth,
            'minHeight'       => $options->minHeight,
            'title'           => $options->title,
            'resizable'       => $options->resizable,
            'fullscreen'      => $options->fullscreen,
            'frame'           => $options->frame,
            'transparent'     => $options->transparent,
            'backgroundColor' => $options->backgroundColor,
            'alwaysOnTop'     => $options->alwaysOnTop,
            'autoHideMenuBar' => $options->autoHideMenuBar,
        ]);
    }

    public function closeWindow(string $windowId): void
    {
        $this->ipcBridge->send('window.close', ['windowId' => $windowId]);
    }

    public function setWindowTitle(string $windowId, string $title): void
    {
        $this->ipcBridge->send('window.setTitle', ['windowId' => $windowId, 'title' => $title]);
    }

    public function resizeWindow(string $windowId, int $width, int $height): void
    {
        $this->ipcBridge->send('window.resize', ['windowId' => $windowId, 'width' => $width, 'height' => $height]);
    }

    public function minimizeWindow(string $windowId): void
    {
        $this->ipcBridge->send('window.minimize', ['windowId' => $windowId]);
    }

    public function maximizeWindow(string $windowId): void
    {
        $this->ipcBridge->send('window.maximize', ['windowId' => $windowId]);
    }

    public function setFullscreen(string $windowId, bool $fullscreen): void
    {
        $this->ipcBridge->send('window.setFullscreen', ['windowId' => $windowId, 'fullscreen' => $fullscreen]);
    }

    public function focusWindow(string $windowId): void
    {
        $this->ipcBridge->send('window.focus', ['windowId' => $windowId]);
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
        $this->ipcBridge->send('notification.send', [
            'title'     => $options->title,
            'body'      => $options->body,
            'icon'      => $options->icon,
            'sound'     => $options->sound,
            'urgency'   => $options->urgency,
            'actions'   => $options->actions,
        ]);
    }

    // -------------------------------------------------------------------------
    // Dialogs
    // -------------------------------------------------------------------------

    public function showOpenDialog(DialogOptions $options): ?array
    {
        $result = $this->ipcBridge->call('dialog.showOpenDialog', array_filter([
            'title'       => $options->title,
            'defaultPath' => $options->defaultPath,
            'filters'     => $options->filters ?: null,
            'properties'  => $options->properties,
            'buttonLabel' => $options->buttonLabel,
        ], fn($v) => $v !== null && $v !== []));

        if (!is_array($result)) {
            return null;
        }

        if ($result['canceled'] ?? false) {
            return null;
        }

        $paths = $result['filePaths'] ?? null;

        return is_array($paths) ? $paths : null;
    }

    public function showSaveDialog(DialogOptions $options): ?string
    {
        $result = $this->ipcBridge->call('dialog.showSaveDialog', array_filter([
            'title'       => $options->title,
            'defaultPath' => $options->defaultPath,
            'filters'     => $options->filters ?: null,
            'buttonLabel' => $options->buttonLabel,
        ], fn($v) => $v !== null && $v !== []));

        if (!is_array($result)) {
            return null;
        }

        if ($result['canceled'] ?? false) {
            return null;
        }

        $path = $result['filePath'] ?? null;

        return is_string($path) ? $path : null;
    }

    public function showMessageBox(string $title, string $message, array $buttons = ['OK'], string $type = 'info'): int
    {
        $result = $this->ipcBridge->call('dialog.showMessageBox', array_filter([
            'type'    => $type,
            'title'   => $title ?: null,
            'message' => $message,
            'buttons' => $buttons,
        ], fn($v) => $v !== null));

        if (!is_array($result)) {
            return 0;
        }

        return (int) ($result['response'] ?? 0);
    }

    // -------------------------------------------------------------------------
    // App & Shell
    // -------------------------------------------------------------------------

    public function quit(): void
    {
        $this->ipcBridge->send('app.quit');
    }

    public function relaunch(): void
    {
        $this->ipcBridge->send('app.relaunch');
    }

    public function getPath(string $name): string
    {
        return (string) $this->ipcBridge->call('app.getPath', ['name' => $name]);
    }

    public function openExternal(string $url): void
    {
        $this->ipcBridge->send('shell.openExternal', ['url' => $url]);
    }

    public function openPath(string $path): void
    {
        $this->ipcBridge->send('shell.openPath', ['path' => $path]);
    }

    public function trashItem(string $path): void
    {
        $this->ipcBridge->send('shell.trashItem', ['path' => $path]);
    }

    public function showItemInFolder(string $path): void
    {
        $this->ipcBridge->send('shell.showItemInFolder', ['path' => $path]);
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
        $this->ipcBridge->send('updater.install');
    }

    // -------------------------------------------------------------------------
    // Lifecycle & Build
    // -------------------------------------------------------------------------

    public function start(string $serverUrl, array $config): int
    {
        $electronBin = $this->resolveElectronBinary();
        $mainJsPath  = $this->resolveMainJs();
        $ipcPort     = $config['ipc_port'] ?? 9000;

        // Generate a fresh random token for this session.
        // Electron will reject WebSocket connections that don't present this token,
        // preventing other local processes from hijacking the IPC channel.
        $ipcToken = bin2hex(random_bytes(16));

        $env = array_merge(getenv() ?: [], [
            'SYMFONY_SERVER_URL' => $serverUrl,
            'SYMFONY_IPC_PORT'   => (string) $ipcPort,
            'SYMFONY_IPC_TOKEN'  => $ipcToken,
            'SYMFONY_APP_NAME'   => $config['app']['name'] ?? 'Symfony App',
            'ELECTRON_NO_ASAR'   => '1',
        ]);

        $process = new \Symfony\Component\Process\Process(
            [$electronBin, $mainJsPath],
            null,
            $env,
        );
        $process->setTimeout(null);
        $process->start();

        $this->electronProcess = $process;
        $this->pid = $process->getPid();

        // Give Electron time to open its IPC WebSocket
        $waited = 0;
        $connected = false;
        while ($waited < 8000 && $process->isRunning()) {
            usleep(200_000);
            $waited += 200;
            // Try to connect
            $sock = @fsockopen('127.0.0.1', $ipcPort, $errno, $errstr, 0.5);
            if ($sock !== false) {
                fclose($sock);
                $connected = true;
                break;
            }
        }

        if (!$connected) {
            $process->stop();
            throw new \SymfonyNativeBridge\Exception\NativeException(
                "Electron started but IPC port {$ipcPort} never opened. " .
                "Output: " . $process->getErrorOutput()
            );
        }

        $this->ipcBridge->connect("ws://127.0.0.1:{$ipcPort}/ipc", $ipcToken);

        return $this->pid ?? 0;
    }

    private ?Process $electronProcess = null;

    public function stop(): void
    {
        $this->ipcBridge->disconnect();

        if ($this->electronProcess !== null && $this->electronProcess->isRunning()) {
            $this->electronProcess->stop(3);
            $this->electronProcess = null;
        }

        $this->pid = null;
    }

    public function build(array $buildConfig): void
    {
        $electronBuilderBin = $this->resolveElectronBuilderBinary();
        $targets            = array_map('strtolower', $buildConfig['targets'] ?? ['current']);
        $outputDir          = $buildConfig['output_dir'] ?? 'dist';

        // Build the command as an array for Symfony Process (safer than shell string)
        $cmd = [$electronBuilderBin, '--publish', 'never'];

        // Platform flags — "current" means no explicit flag, electron-builder auto-detects
        foreach ($targets as $target) {
            match ($target) {
                'windows', 'win' => $cmd[] = '--win',
                'macos', 'mac'   => $cmd[] = '--mac',
                'linux'          => $cmd[] = '--linux',
                'current'        => null,
                default          => $cmd[] = '--' . $target,
            };
        }

        // Output directory via inline config override (avoids modifying package.json)
        $cmd[] = '-c.directories.output=' . $outputDir;

        $process = new Process($cmd, getcwd());
        $process->setTimeout(300);
        $process->run(function (string $type, string $buffer): void {
            echo $buffer;
        });

        if (!$process->isSuccessful()) {
            throw new NativeException(
                "electron-builder failed with exit code {$process->getExitCode()}\n" .
                $process->getErrorOutput()
            );
        }
    }

    public function getName(): string
    {
        return 'electron';
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private function resolveElectronBinary(): string
    {
        $candidates = [
            getcwd() . '/node_modules/.bin/electron',
            getcwd() . '/node_modules/.bin/electron.cmd',
        ];

        foreach ($candidates as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        throw new NativeException(
            'Electron binary not found. Run: php bin/console native:install'
        );
    }

    private function resolveElectronBuilderBinary(): string
    {
        $candidates = [
            getcwd() . '/node_modules/.bin/electron-builder',
            getcwd() . '/node_modules/.bin/electron-builder.cmd',
        ];

        foreach ($candidates as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        throw new NativeException(
            'electron-builder not found. Run: php bin/console native:install'
        );
    }

    private function resolveMainJs(): string
    {
        $custom = getcwd() . '/electron/main.js';
        if (file_exists($custom)) {
            return $custom;
        }

        // Fallback to the bundled template
        return dirname(__DIR__, 2) . '/resources/electron/main.js';
    }
}