<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\HotReload;

use SymfonyNativeBridge\Bridge\IpcBridge;

/**
 * Watches PHP, Twig and YAML files for changes and triggers a window reload
 * in the native runtime via IPC.
 *
 * Runs in a dedicated thread/process spawned by native:serve in dev mode.
 * Uses stat()-based polling — no inotify extension required.
 */
class HotReloadWatcher
{
    private const POLL_INTERVAL_MS = 500_000; // 500ms
    private const EXTENSIONS       = ['php', 'twig', 'yaml', 'yml', 'env'];

    /** @var array<string, int> path => mtime */
    private array $snapshots = [];

    private bool $running = false;

    public function __construct(
        private readonly IpcBridge $ipcBridge,
        private readonly string    $projectDir,
        private readonly array     $watchDirs = ['src', 'templates', 'config'],
        private readonly array     $ignoreDirs = ['var', 'vendor', 'node_modules', '.git'],
    ) {}

    public function start(): void
    {
        $this->running   = true;
        $this->snapshots = $this->snapshot();

        echo "[HotReload] Watching " . implode(', ', $this->watchDirs) . "…\n";

        while ($this->running) {
            usleep(self::POLL_INTERVAL_MS);

            $current = $this->snapshot();
            $changed = $this->diff($this->snapshots, $current);

            if (!empty($changed)) {
                foreach ($changed as $file) {
                    echo "[HotReload] Changed: " . str_replace($this->projectDir . '/', '', $file) . "\n";
                }

                $this->reload($changed);
                $this->snapshots = $current;
            }
        }
    }

    public function stop(): void
    {
        $this->running = false;
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    /**
     * @return array<string, int> path => mtime
     */
    private function snapshot(): array
    {
        $files = [];

        foreach ($this->watchDirs as $dir) {
            $absDir = $this->projectDir . '/' . $dir;

            if (!is_dir($absDir)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST,
            );

            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                // Skip ignored dirs
                $path = $file->getPathname();
                foreach ($this->ignoreDirs as $ignore) {
                    if (str_contains($path, DIRECTORY_SEPARATOR . $ignore . DIRECTORY_SEPARATOR)) {
                        continue 2;
                    }
                }

                // Only watch relevant extensions
                if (!in_array($file->getExtension(), self::EXTENSIONS, true)) {
                    continue;
                }

                $files[$path] = $file->getMTime();
            }
        }

        return $files;
    }

    /**
     * Returns paths that were added or modified.
     *
     * @return string[]
     */
    private function diff(array $before, array $after): array
    {
        $changed = [];

        foreach ($after as $path => $mtime) {
            if (!isset($before[$path]) || $before[$path] !== $mtime) {
                $changed[] = $path;
            }
        }

        return $changed;
    }

    private function reload(array $changedFiles): void
    {
        // Clear Symfony's opcache for changed PHP files
        foreach ($changedFiles as $file) {
            if (str_ends_with($file, '.php') && function_exists('opcache_invalidate')) {
                opcache_invalidate($file, true);
            }
        }

        // Tell Electron to reload all windows
        if ($this->ipcBridge->isConnected()) {
            $this->ipcBridge->send('window.reloadAll');
        }
    }
}