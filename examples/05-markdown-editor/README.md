# Example 05 — Markdown Editor

> A full split-pane Markdown editor with live preview, native open/save dialogs,
> "recent files" in the tray, unsaved-changes protection, and HTML export.

## What it demonstrates

- **`AppBeforeQuitEvent`** — intercepts quit and asks "Unsaved changes?" if needed
- **`StorageManager`** — persists recent files, current file path, and preferences across sessions
- **Dynamic tray menu** with a nested "Recent Files" submenu built from live storage data
- **Window title sync** — filename + `•` indicator when the buffer is dirty
- **`WindowFocusedEvent`** — refreshes tray menu when the user switches back
- Native **open / save / save-as / folder** dialogs with file-type filters
- HTML export with `showItemInFolder` to reveal the file after export
- **Keyboard shortcuts** (Ctrl+S, Ctrl+Shift+S, Ctrl+O, Ctrl+N)
- **Auto-save** preference stored natively via `StorageManager`

## Run it

```bash
cd examples/05-markdown-editor
composer install
php bin/console native:install
php bin/console native:serve
```

## Architecture

```
AppReadyEvent
  └─▶ EditorNativeListener::onReady()
        ├─ creates tray icon
        └─ builds menu (New, Open, Recent Files submenu, Quit)

WindowFocusedEvent
  └─▶ EditorNativeListener::onFocus()
        ├─ syncWindowTitle()  →  "filename.md •" or "Untitled"
        └─ rebuildTrayMenu()  →  refreshes recent files list

AppBeforeQuitEvent
  └─▶ EditorNativeListener::onBeforeQuit()
        ├─ checks storage key "has_unsaved_changes"
        ├─ if dirty → dialog.ask("Quit anyway?")
        └─ if user cancels → event->prevent()

TrayMenuItemClickedEvent
  └─▶ EditorNativeListener::onMenuClick()
        ├─ "recent::/path/file.md" → open that file
        ├─ "new"   → focus or open window
        └─ "quit"  → app->quit()
```

## Key code

```php
// Quit guard: intercept if unsaved
#[AsNativeListener(AppBeforeQuitEvent::class)]
public function onBeforeQuit(AppBeforeQuitEvent $event): void
{
    if ($this->storage->get('has_unsaved_changes')) {
        $choice = $this->dialog->ask('Unsaved changes. Quit anyway?', '', ['Cancel', 'Quit']);
        if ($choice === 0) {
            $event->prevent();  // user clicked Cancel
        }
    }
}

// Dynamic "Recent Files" submenu from storage
$recentItems = array_map(
    fn($path) => new MenuItem(label: basename($path), id: 'recent::' . $path),
    $this->storage->get('recent_files', [])
);
$this->tray->menu('main', [
    // ...
    MenuItem::submenu('🕐 Recent Files', $recentItems),
]);

// Persist preferences across sessions
$this->storage->set('preferences', [
    'theme'     => 'dark',
    'font_size' => 15,
    'auto_save' => true,
]);
$prefs = $this->storage->get('preferences', $defaults);
```
