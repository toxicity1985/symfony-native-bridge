# SymfonyNativeBridge — Examples

Five ready-to-run demo applications, ordered from simplest to most complete.  
Each example is a self-contained Symfony project.

---

## Quick start (any example)

```bash
cd examples/<N>-<name>
composer install
php bin/console native:install   # installs Electron + copies main.js (one-time)
php bin/console native:serve     # starts PHP server + opens native window
```

To build a distributable binary:

```bash
php bin/console native:build --target=macos   # or windows / linux
```

---

## Examples at a glance

### 01 — Hello World ⚡

> The absolute minimum. Three buttons: notify, open a file, open a URL.

| Feature | Details |
|---|---|
| `NotificationManager` | Desktop notification |
| `DialogManager::openFile()` | Native file picker |
| `AppManager::openExternal()` | Open URL in browser |

```
php bin/console native:serve
```
→ One window, three buttons, zero complexity.

---

### 02 — Todo App ✅

> Persistent tasks with Doctrine + SQLite, tray badge, and JSON export.

| Feature | Details |
|---|---|
| Doctrine + SQLite | Local persistent storage |
| `TrayManager` + `#[AsNativeListener]` | Tray icon with pending count |
| `NotificationManager` | Alert when a task is marked done |
| `DialogManager::saveFile()` | Export todos to JSON |
| `AppReadyEvent` | Build tray on startup |
| `TrayMenuItemClickedEvent` | Route to filtered views |

**Highlights:**
- The tray tooltip shows live pending task count
- Completing a task sends a native notification
- Export produces a `todos.json` in a user-chosen location

---

### 03 — File Converter 🖼

> Convert images between PNG / JPG / WebP / GIF / BMP using GD, with a secondary window.

| Feature | Details |
|---|---|
| Multi-file open dialog | Extension filters, multiple selection |
| Folder picker | `DialogManager::openFolder()` |
| Secondary window | `WindowManager::open()` → About panel |
| `StorageManager` | Persist last output folder + history |
| `AppManager::showItemInFolder()` | Reveal output in Finder/Explorer |

**Highlights:**
- UI shows per-file success/error status after conversion
- Last used output folder is remembered across sessions
- "About" opens as a separate small non-resizable window

---

### 04 — System Monitor 🖥

> Real-time CPU / RAM / Disk dashboard, polling every 2s, with a live tray gauge.

| Feature | Details |
|---|---|
| `WindowFocusedEvent` | Refresh tray tooltip + trigger CPU alert |
| `WindowBlurredEvent` | Set tray to "background" hint |
| `TrayManager` | Live ASCII CPU gauge in menu |
| `NotificationManager` | One-shot high-CPU alert (> 90%) |
| PHP stats | `/proc/meminfo`, `sys_getloadavg`, `disk_free_space` |

**Highlights:**
- Tray tooltip shows current CPU % and updates on focus
- Tray menu contains an inline ASCII bar chart: `████░░░░░░ 42%`
- Alert fires once per threshold crossing, resets when CPU drops below 70%

---

### 05 — Markdown Editor ✏️

> Split-pane editor with live preview, recent files, unsaved-changes guard, and HTML export.

| Feature | Details |
|---|---|
| `AppBeforeQuitEvent` | `$event->prevent()` if buffer is dirty |
| `StorageManager` | Recent files, preferences, current file |
| `TrayMenuItemClickedEvent` | Nested "Recent Files" submenu |
| `WindowFocusedEvent` | Sync window title (`filename.md •`) |
| `DialogManager` | Open, save-as, export-html, folder dialogs |
| `AppManager::showItemInFolder()` | Reveal exported HTML |

**Highlights:**
- Window title tracks filename + dirty indicator (`•`)
- `Ctrl+S / Ctrl+Shift+S / Ctrl+O / Ctrl+N` keyboard shortcuts
- Auto-save preference (2s debounce) stored natively
- HTML export embeds `marked.js` for offline rendering

---

## Feature coverage matrix

| Feature | 01 | 02 | 03 | 04 | 05 |
|---|:---:|:---:|:---:|:---:|:---:|
| `NotificationManager` | ✓ | ✓ | ✓ | ✓ | |
| `DialogManager` | ✓ | ✓ | ✓ | | ✓ |
| `TrayManager` | | ✓ | | ✓ | ✓ |
| `WindowManager` (multi) | | | ✓ | | ✓ |
| `StorageManager` | | | ✓ | | ✓ |
| `AppManager` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `AppReadyEvent` | | ✓ | | ✓ | ✓ |
| `AppBeforeQuitEvent` | | | | | ✓ |
| `TrayMenuItemClickedEvent` | | ✓ | | ✓ | ✓ |
| `WindowFocusedEvent` | | | | ✓ | ✓ |
| `WindowBlurredEvent` | | | | ✓ | |
| `UpdateAvailableEvent` | | | | | |

---

## Tips

**Switching to Tauri** — change one line in `config/packages/symfony_native_bridge.yaml`:
```yaml
symfony_native_bridge:
    driver: tauri   # was: electron
```
Then re-run `php bin/console native:install`.

**Debug IPC messages** — set `SYMFONY_NATIVE_DEBUG=1` in your `.env` to log every IPC call to `var/log/native.log`.

**Custom Electron `main.js`** — create `electron/main.js` at the project root to override the bundled template.
