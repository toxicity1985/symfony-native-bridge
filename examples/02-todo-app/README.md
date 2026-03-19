# Example 02 — Todo App

> A persistent task manager with a **system tray icon**, native notifications, and JSON export.

## What it demonstrates

- **Doctrine + SQLite** as local persistent storage
- **System tray** that shows the number of pending tasks
- **Native notifications** when a task is marked done
- **Save dialog** to export todos to a JSON file
- `#[AsNativeListener]` to wire tray behaviour on app startup

## Run it

```bash
cd examples/02-todo-app
composer install
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate
php bin/console native:install
php bin/console native:serve
```

## Architecture

```
AppReadyEvent
  └─▶ TodoNativeListener::onReady()
        ├─ creates tray icon
        ├─ sets tooltip "N tasks pending"
        └─ builds context menu (Show All, Pending, Quit)

POST /todos/{id}/toggle
  └─▶ TodoController::toggle()
        ├─ flips todo.done
        └─ if done → NotificationManager::send("✅ Task complete")

POST /todos/export
  └─▶ TodoController::export()
        ├─ DialogManager::saveFile() → pick destination
        ├─ writes JSON
        └─ NotificationManager::send("Export complete")
```

## Key code

```php
// Tray tooltip driven by live DB data
#[AsNativeListener(AppReadyEvent::class)]
public function onReady(AppReadyEvent $event): void
{
    $pending = $this->countPending();
    $this->tray->create('/assets/icon.png', "Todo — {$pending} pending", 'main');
    $this->rebuildMenu($pending);
}

// Native file save dialog from a controller
$savePath = $this->dialog->saveFile('Export Todos', 'todos.json', [
    ['name' => 'JSON', 'extensions' => ['json']],
]);
file_put_contents($savePath, json_encode($data, JSON_PRETTY_PRINT));
```
