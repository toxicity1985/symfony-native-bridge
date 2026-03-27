<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\HotReload;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SymfonyNativeBridge\Bridge\IpcBridge;

/**
 * HotReloadWatcher — surveille les fichiers PHP/Twig/YAML et
 * déclenche un rechargement des fenêtres Electron via IPC.
 *
 * Fonctionne en mode "tick" : appelé toutes les 500ms dans la boucle
 * principale de native:serve, sans fork ni thread séparé.
 */
class HotReloadWatcher
{
    private const EXTENSIONS = ['php', 'twig', 'yaml', 'yml', 'env'];

    /** @var array<string, int> path => mtime */
    private array $snapshots = [];

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly IpcBridge $ipcBridge,
        private readonly string    $projectDir,
        private readonly array     $watchDirs  = ['src', 'templates', 'config'],
        private readonly array     $ignoreDirs = ['var', 'vendor', 'node_modules', '.git'],
        ?LoggerInterface           $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Prendre le snapshot initial — appeler une fois avant la boucle.
     */
    public function init(): void
    {
        $this->snapshots = $this->snapshot();
        $this->logger->debug('[HotReload] Watching {dirs}', [
            'dirs' => implode(', ', $this->watchDirs),
        ]);
    }

    /**
     * Vérifier les changements — appeler à chaque itération de la boucle.
     */
    public function tick(): void
    {
        $current = $this->snapshot();
        $changed = $this->diff($this->snapshots, $current);

        if (empty($changed)) {
            return;
        }

        foreach ($changed as $file) {
            $rel = str_replace($this->projectDir . '/', '', $file);
            $this->logger->info('[HotReload] Changed: {file}', ['file' => $rel]);
        }

        // Invalider l'opcache pour les fichiers PHP modifiés
        foreach ($changed as $file) {
            if (str_ends_with($file, '.php') && function_exists('opcache_invalidate')) {
                opcache_invalidate($file, true);
            }
        }

        // Envoyer window.reloadAll via l'IpcBridge déjà connecté
        $this->ipcBridge->send('window.reloadAll');

        // Mettre à jour le snapshot
        $this->snapshots = $current;
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    /** @return array<string, int> */
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
            );

            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                $path = $file->getPathname();

                foreach ($this->ignoreDirs as $ignore) {
                    if (str_contains($path, DIRECTORY_SEPARATOR . $ignore . DIRECTORY_SEPARATOR)) {
                        continue 2;
                    }
                }

                if (!in_array($file->getExtension(), self::EXTENSIONS, true)) {
                    continue;
                }

                $files[$path] = $file->getMTime();
            }
        }

        return $files;
    }

    /** @return string[] */
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
}
