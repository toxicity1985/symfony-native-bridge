# SymfonyNativeBridge

> Build native desktop applications with Symfony — powered by **Electron** or **Tauri**.

The spiritual equivalent of [NativePHP](https://nativephp.com) for the Symfony ecosystem.  
Write standard Symfony controllers, services and events — ship a `.exe`, `.dmg`, or `.AppImage`.

---

## How it works

```
┌──────────────────────────────────────────────┐
│           Your Symfony Application            │
│  Controllers · Twig · API Platform · etc.    │
└─────────────────────┬────────────────────────┘
                      │ HTTP (loopback)
┌─────────────────────▼────────────────────────┐
│         SymfonyNativeBridgeBundle             │
│                                              │
│  WindowManager   TrayManager                 │
│  NotificationManager   DialogManager         │
│  AppManager      StorageManager              │
└─────────────────────┬────────────────────────┘
                      │ WebSocket / named pipe (IPC)
┌─────────────────────▼────────────────────────┐
│         Electron / Tauri (runtime)            │
│  BrowserWindow · Tray · Notification         │
│  shell · dialog · autoUpdater · store        │
└──────────────────────────────────────────────┘
```

1. `native:serve` starts a PHP built-in server on `127.0.0.1:8765`
2. It spawns the native runtime (Electron or Tauri), which opens a `BrowserWindow` pointing at that URL
3. PHP and the runtime communicate over a **WebSocket IPC** (Electron) or **named pipe** (Tauri)
4. PHP services call `driver->call('window.open', …)` → native JS/Rust handles it → returns the result
5. Native events (tray clicks, window focus, …) are pushed back to PHP and dispatched as **Symfony events**

---

## Requirements

| | Electron | Tauri |
|---|---|---|
| **Node.js** | ≥ 18 | ≥ 18 |
| **Rust / Cargo** | ✗ not needed | ✓ required |
| **PHP** | ≥ 8.2 | ≥ 8.2 |
| **Symfony** | ^6.4 or ^7 | ^6.4 or ^7 |

---

## Installation

```bash
composer require toxicity/symfony-native-bridge
```

Register the bundle in `config/bundles.php`:

```php
return [
    // ...
    SymfonyNativeBridge\SymfonyNativeBridgeBundle::class => ['all' => true],
];
```

Install the native runtime (one-time):

```bash
# Electron (default)
php bin/console native:install

# Tauri
php bin/console native:install --driver=tauri
```

---

## Configuration

```yaml
# config/packages/symfony_native_bridge.yaml
symfony_native_bridge:
    driver: electron          # "electron" | "tauri"

    app:
        name:        "My App"
        version:     "1.0.0"
        identifier:  "com.example.my-app"

    window:
        width:   1280
        height:  800

    updater:
        enabled: false
        url:     ~

    build:
        output_dir: dist
        targets:    [current]   # or [windows, macos, linux]
```

---

## Usage

### Start in development

```bash
php bin/console native:serve
```

This starts both the PHP server and the Electron/Tauri window in one command.

### Build for distribution

```bash
# Current platform
php bin/console native:build

# Cross-compile (requires appropriate toolchain)
php bin/console native:build --target=windows --target=macos --target=linux
```

---

## Services

All services are **autowirable** — just type-hint in your constructor.

### WindowManager

```php
use SymfonyNativeBridge\Service\WindowManager;
use SymfonyNativeBridge\ValueObject\WindowOptions;

class MyController
{
    public function __construct(private WindowManager $window) {}

    public function openSettings(): void
    {
        $id = $this->window->open(
            url: 'http://127.0.0.1:8765/settings',
            options: new WindowOptions(width: 600, height: 400, title: 'Settings'),
        );
    }
}
```

### TrayManager

```php
use SymfonyNativeBridge\Service\TrayManager;
use SymfonyNativeBridge\ValueObject\MenuItem;

// Create tray icon
$this->tray->create('/path/to/icon.png', 'My App', 'main');

// Set context menu
$this->tray->menu('main', [
    new MenuItem(label: 'Open',  id: 'open'),
    MenuItem::separator(),
    new MenuItem(label: 'Quit',  id: 'quit', role: 'quit'),
]);
```

### NotificationManager

```php
use SymfonyNativeBridge\Service\NotificationManager;

$this->notification->send('Task complete', 'Your export is ready.');
```

### DialogManager

```php
use SymfonyNativeBridge\Service\DialogManager;

// File picker
$paths = $this->dialog->openFile('Choose a CSV', filters: [
    ['name' => 'CSV Files', 'extensions' => ['csv']],
]);

// Confirm dialog
if ($this->dialog->confirm('Delete this item?')) {
    // ...
}

// Save dialog
$dest = $this->dialog->saveFile('Save As', defaultPath: '~/export.pdf');
```

### StorageManager (persistent key-value store)

```php
use SymfonyNativeBridge\Service\StorageManager;

$this->storage->set('theme', 'dark');
$theme = $this->storage->get('theme', 'light'); // 'dark'

// Memoize
$token = $this->storage->remember('auth_token', fn() => $this->auth->generateToken());
```

### AppManager

```php
use SymfonyNativeBridge\Service\AppManager;

$this->app->getPath('appData');           // /home/user/.config/my-app
$this->app->openExternal('https://…');    // opens in default browser
$this->app->showItemInFolder('/path/to'); // opens file manager
$this->app->checkForUpdates();            // triggers auto-updater
$this->app->quit();
```

---

## Listening to native events

Use `#[AsNativeListener]` — no YAML needed:

```php
use SymfonyNativeBridge\Attribute\AsNativeListener;
use SymfonyNativeBridge\Event\AppReadyEvent;
use SymfonyNativeBridge\Event\TrayMenuItemClickedEvent;
use SymfonyNativeBridge\Event\AppBeforeQuitEvent;

class AppBootstrap
{
    public function __construct(
        private TrayManager $tray,
        private AppManager  $app,
    ) {}

    #[AsNativeListener(AppReadyEvent::class)]
    public function onReady(AppReadyEvent $event): void
    {
        $this->tray->create('/assets/icon.png', 'My App', 'main');
    }

    #[AsNativeListener(TrayMenuItemClickedEvent::class)]
    public function onMenuClick(TrayMenuItemClickedEvent $event): void
    {
        if ($event->menuItemId === 'quit') {
            $this->app->quit();
        }
    }

    #[AsNativeListener(AppBeforeQuitEvent::class)]
    public function onQuit(AppBeforeQuitEvent $event): void
    {
        // Prevent accidental quit
        $event->prevent();
    }
}
```

### Full list of native events

| Event class | Constant | Triggered when |
|---|---|---|
| `AppReadyEvent` | `native.app.ready` | Runtime is up and first window is open |
| `AppBeforeQuitEvent` | `native.app.before_quit` | User tries to quit (call `$event->prevent()` to cancel) |
| `AppActivatedEvent` | `native.app.activated` | macOS: app clicked in Dock |
| `WindowFocusedEvent` | `native.window.focused` | A window gains focus |
| `WindowBlurredEvent` | `native.window.blurred` | A window loses focus |
| `WindowClosedEvent` | `native.window.closed` | A window is closed |
| `WindowResizedEvent` | `native.window.resized` | A window is resized |
| `WindowMovedEvent` | `native.window.moved` | A window is moved |
| `TrayClickedEvent` | `native.tray.clicked` | Tray icon clicked (`button`: left/right/double) |
| `TrayMenuItemClickedEvent` | `native.tray.menu_item_clicked` | A tray menu item is clicked |
| `UpdateAvailableEvent` | `native.updater.update_available` | A new version is found |
| `UpdateDownloadedEvent` | `native.updater.update_downloaded` | Update fully downloaded |
| `NotificationClickedEvent` | `native.notification.clicked` | User clicks a notification |

---

## Architecture

### Driver abstraction

Both runtimes implement the same `NativeDriverInterface`, so you can swap Electron ↔ Tauri by changing one line in your config:

```yaml
symfony_native_bridge:
    driver: tauri   # was: electron
```

### IPC Protocol

Each PHP → runtime message:
```json
{ "id": "<uuid>", "action": "window.open", "payload": { "url": "…", "width": 1200 } }
```

Each runtime → PHP response:
```json
{ "id": "<uuid>", "ok": true, "result": "win_42" }
```

Push events from runtime → PHP (no `id`):
```json
{ "event": "tray.clicked", "payload": { "trayId": "tray_1", "button": "right" } }
```

---

## Running tests

```bash
composer install
vendor/bin/phpunit
```

---

## Roadmap

- [ ] Symfony Messenger transport for async native calls
- [ ] Hot-reload support in `native:serve` dev mode
- [ ] `#[NativeRoute]` attribute for URL-less window routing
- [ ] PHP binary embedding & cross-compilation guide
- [ ] Multi-window management with named window registry
- [ ] macOS Menu Bar app mode (no Dock icon)
- [ ] Deep-link / protocol handler registration
- [ ] Clipboard API service

---

## License

MIT
