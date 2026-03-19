# Example 01 — Hello World

> The simplest possible SymfonyNativeBridge application.

## What it demonstrates

- Rendering a Twig template in a native window
- Sending a desktop **notification** from a controller
- Opening a native **file picker** dialog
- Opening a URL in the **default browser**

## Run it

```bash
cd examples/01-hello-world
composer install
php bin/console native:install
php bin/console native:serve
```

## Files

```
src/Controller/HelloController.php   ← 4 routes, all native-aware
templates/hello/index.html.twig      ← simple dark UI with 3 buttons
config/packages/symfony_native_bridge.yaml
```

## Key points

Everything is standard Symfony. The only difference from a web app is that
the services `NotificationManager`, `DialogManager` and `AppManager` are
injected — they communicate with the Electron/Tauri window over IPC behind
the scenes.

```php
// Any controller, service, or command can use these
$this->notification->send('Hello!', 'World');
$paths = $this->dialog->openFile('Choose a file');
$this->app->openExternal('https://symfony.com');
```
