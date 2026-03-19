<?php
// examples/05-markdown-editor/src/EventListener/EditorNativeListener.php

declare(strict_types=1);

namespace App\EventListener;

use SymfonyNativeBridge\Attribute\AsNativeListener;
use SymfonyNativeBridge\Event\AppBeforeQuitEvent;
use SymfonyNativeBridge\Event\AppReadyEvent;
use SymfonyNativeBridge\Event\TrayMenuItemClickedEvent;
use SymfonyNativeBridge\Event\WindowFocusedEvent;
use SymfonyNativeBridge\Service\AppManager;
use SymfonyNativeBridge\Service\DialogManager;
use SymfonyNativeBridge\Service\StorageManager;
use SymfonyNativeBridge\Service\TrayManager;
use SymfonyNativeBridge\Service\WindowManager;
use SymfonyNativeBridge\ValueObject\MenuItem;

/**
 * Wires up the editor's native behaviour:
 *
 *  - System tray with "Recent Files" submenu
 *  - Window title  → filename (or "Untitled")
 *  - AppBeforeQuitEvent → "unsaved changes" confirm dialog
 */
class EditorNativeListener
{
    public function __construct(
        private readonly TrayManager    $tray,
        private readonly WindowManager  $window,
        private readonly AppManager     $app,
        private readonly DialogManager  $dialog,
        private readonly StorageManager $storage,
    ) {}

    // ── App ready ─────────────────────────────────────────────────────────────

    #[AsNativeListener(AppReadyEvent::class)]
    public function onReady(AppReadyEvent $event): void
    {
        $this->tray->create(
            iconPath: __DIR__ . '/../../assets/editor-tray.png',
            tooltip:  'Markdown Editor',
            label:    'main',
        );

        $this->rebuildTrayMenu();
        $this->syncWindowTitle();
    }

    // ── Window focus: sync title when user switches back ─────────────────────

    #[AsNativeListener(WindowFocusedEvent::class)]
    public function onFocus(WindowFocusedEvent $event): void
    {
        $this->syncWindowTitle();
        $this->rebuildTrayMenu();   // refresh "recent files" list
    }

    // ── Tray menu clicks ─────────────────────────────────────────────────────

    #[AsNativeListener(TrayMenuItemClickedEvent::class)]
    public function onMenuClick(TrayMenuItemClickedEvent $event): void
    {
        // "recent::/path/to/file.md"  →  open that specific file
        if (str_starts_with($event->menuItemId, 'recent::')) {
            $path = substr($event->menuItemId, 8);
            $this->focusOrOpenWith($path);
            return;
        }

        match ($event->menuItemId) {
            'new'          => $this->focusOrOpen('/'),
            'open'         => $this->focusOrOpen('/?action=open'),
            'show_window'  => $this->focusOrOpen('/'),
            'quit'         => $this->app->quit(),
            default        => null,
        };
    }

    // ── Quit guard: warn about unsaved changes ────────────────────────────────

    #[AsNativeListener(AppBeforeQuitEvent::class)]
    public function onBeforeQuit(AppBeforeQuitEvent $event): void
    {
        $hasUnsaved = (bool) $this->storage->get('has_unsaved_changes', false);

        if (!$hasUnsaved) {
            return; // nothing to guard, let quit proceed
        }

        $choice = $this->dialog->ask(
            message: 'You have unsaved changes. Quit anyway?',
            title:   'Unsaved Changes',
            buttons: ['Cancel', 'Quit Without Saving'],
        );

        if ($choice === 0) {
            $event->prevent(); // user clicked Cancel
        }
        // choice === 1 → let the quit proceed naturally
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function rebuildTrayMenu(): void
    {
        $recent      = $this->storage->get('recent_files', []);
        $currentFile = $this->storage->get('current_file');

        // Build "Recent Files" submenu (max 6 items)
        $recentItems = [];
        foreach (array_slice($recent, 0, 6) as $path) {
            $label = basename($path);
            if ($path === $currentFile) {
                $label = "• {$label}";   // mark current file
            }
            $recentItems[] = new MenuItem(
                label: $label,
                id:    'recent::' . $path,
            );
        }

        if (empty($recentItems)) {
            $recentItems[] = new MenuItem(label: 'No recent files', enabled: false);
        }

        $this->tray->menu('main', [
            new MenuItem(label: '✏️  Markdown Editor', enabled: false),
            MenuItem::separator(),
            new MenuItem(label: '🆕 New',    id: 'new'),
            new MenuItem(label: '📂 Open…',  id: 'open'),
            MenuItem::submenu('🕐 Recent Files', $recentItems),
            MenuItem::separator(),
            new MenuItem(label: '🔍 Show Window', id: 'show_window'),
            MenuItem::separator(),
            new MenuItem(label: '🚪 Quit',         id: 'quit'),
        ]);
    }

    private function syncWindowTitle(): void
    {
        $currentFile = $this->storage->get('current_file');
        $hasUnsaved  = (bool) $this->storage->get('has_unsaved_changes', false);

        $title = $currentFile
            ? basename($currentFile) . ($hasUnsaved ? ' •' : '')
            : 'Untitled' . ($hasUnsaved ? ' •' : '');

        $title .= ' — Markdown Editor';

        $windows = $this->window->all();
        if (!empty($windows)) {
            $this->window->setTitle($windows[0], $title);
        }
    }

    private function focusOrOpen(string $path = '/'): void
    {
        $windows = $this->window->all();
        if (!empty($windows)) {
            $this->window->focus($windows[0]);
        } else {
            $this->window->open('http://127.0.0.1:8765' . $path);
        }
    }

    private function focusOrOpenWith(string $filePath): void
    {
        // Store the file to open, then focus/open the window
        // The window will read this on next load via /file/open?path=...
        $this->storage->set('pending_open', $filePath);
        $this->focusOrOpen('/');
    }
}
