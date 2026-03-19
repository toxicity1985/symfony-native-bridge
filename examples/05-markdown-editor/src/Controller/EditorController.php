<?php
// examples/05-markdown-editor/src/Controller/EditorController.php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use SymfonyNativeBridge\Service\AppManager;
use SymfonyNativeBridge\Service\DialogManager;
use SymfonyNativeBridge\Service\NotificationManager;
use SymfonyNativeBridge\Service\StorageManager;
use SymfonyNativeBridge\Service\WindowManager;

class EditorController extends AbstractController
{
    public function __construct(
        private readonly DialogManager       $dialog,
        private readonly NotificationManager $notification,
        private readonly StorageManager      $storage,
        private readonly AppManager          $app,
        private readonly WindowManager       $window,
    ) {}

    // ── Main editor ──────────────────────────────────────────────────────────

    #[Route('/', name: 'editor_home')]
    public function index(): Response
    {
        $currentFile  = $this->storage->get('current_file');
        $recentFiles  = $this->storage->get('recent_files', []);
        $preferences  = $this->storage->get('preferences', $this->defaultPreferences());
        $content      = '';

        if ($currentFile && file_exists($currentFile)) {
            $content = file_get_contents($currentFile);
        }

        return $this->render('editor/index.html.twig', [
            'content'      => $content,
            'current_file' => $currentFile,
            'recent_files' => array_slice($recentFiles, 0, 8),
            'preferences'  => $preferences,
        ]);
    }

    // ── File operations ───────────────────────────────────────────────────────

    #[Route('/file/new', name: 'file_new', methods: ['POST'])]
    public function newFile(): Response
    {
        $this->storage->delete('current_file');

        return $this->json(['ok' => true]);
    }

    #[Route('/file/open', name: 'file_open', methods: ['POST'])]
    public function openFile(Request $request): Response
    {
        // Can be called with an explicit path (from "recent files") or via dialog
        $explicitPath = $request->request->getString('path');

        if ($explicitPath !== '') {
            $path = $explicitPath;
        } else {
            $paths = $this->dialog->openFile(
                title:   'Open Markdown File',
                filters: [
                    ['name' => 'Markdown', 'extensions' => ['md', 'markdown', 'txt']],
                    ['name' => 'All Files', 'extensions' => ['*']],
                ],
            );

            if ($paths === null) {
                return $this->json(['ok' => false, 'reason' => 'cancelled']);
            }

            $path = $paths[0];
        }

        if (!file_exists($path)) {
            return $this->json(['ok' => false, 'reason' => 'File not found']);
        }

        $content = file_get_contents($path);

        $this->storage->set('current_file', $path);
        $this->addToRecent($path);

        return $this->json([
            'ok'      => true,
            'path'    => $path,
            'content' => $content,
        ]);
    }

    #[Route('/file/save', name: 'file_save', methods: ['POST'])]
    public function saveFile(Request $request): Response
    {
        $content     = $request->request->getString('content');
        $currentFile = $this->storage->get('current_file');

        if ($currentFile === null) {
            // No current file → trigger Save As
            return $this->saveAs($request);
        }

        file_put_contents($currentFile, $content);

        return $this->json(['ok' => true, 'path' => $currentFile]);
    }

    #[Route('/file/save-as', name: 'file_save_as', methods: ['POST'])]
    public function saveAs(Request $request): Response
    {
        $content     = $request->request->getString('content');
        $currentFile = $this->storage->get('current_file');

        $savePath = $this->dialog->saveFile(
            title:       'Save Markdown File',
            defaultPath: $currentFile ?? 'untitled.md',
            filters:     [['name' => 'Markdown', 'extensions' => ['md', 'markdown']]],
        );

        if ($savePath === null) {
            return $this->json(['ok' => false, 'reason' => 'cancelled']);
        }

        file_put_contents($savePath, $content);

        $this->storage->set('current_file', $savePath);
        $this->addToRecent($savePath);

        return $this->json(['ok' => true, 'path' => $savePath]);
    }

    // ── Export ────────────────────────────────────────────────────────────────

    #[Route('/file/export-html', name: 'file_export_html', methods: ['POST'])]
    public function exportHtml(Request $request): Response
    {
        $content = $request->request->getString('content');

        $savePath = $this->dialog->saveFile(
            title:       'Export as HTML',
            defaultPath: 'document.html',
            filters:     [['name' => 'HTML', 'extensions' => ['html']]],
        );

        if ($savePath === null) {
            return $this->json(['ok' => false, 'reason' => 'cancelled']);
        }

        // Wrap converted markdown in a basic HTML shell
        $html = $this->renderView('editor/export.html.twig', [
            'content' => $content,
        ]);

        file_put_contents($savePath, $html);

        $this->notification->send('Export complete', "Saved to {$savePath}");
        $this->app->showItemInFolder($savePath);

        return $this->json(['ok' => true, 'path' => $savePath]);
    }

    // ── Preferences ───────────────────────────────────────────────────────────

    #[Route('/preferences', name: 'preferences', methods: ['POST'])]
    public function savePreferences(Request $request): Response
    {
        $prefs = [
            'theme'       => $request->request->getString('theme', 'dark'),
            'font_size'   => $request->request->getInt('font_size', 15),
            'line_numbers'=> $request->request->getBoolean('line_numbers', false),
            'word_wrap'   => $request->request->getBoolean('word_wrap', true),
            'auto_save'   => $request->request->getBoolean('auto_save', false),
        ];

        $this->storage->set('preferences', $prefs);

        return $this->json(['ok' => true, 'preferences' => $prefs]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function addToRecent(string $path): void
    {
        $recent   = $this->storage->get('recent_files', []);
        $recent   = array_values(array_filter($recent, fn($p) => $p !== $path));
        array_unshift($recent, $path);
        $this->storage->set('recent_files', array_slice($recent, 0, 20));
    }

    private function defaultPreferences(): array
    {
        return [
            'theme'        => 'dark',
            'font_size'    => 15,
            'line_numbers' => false,
            'word_wrap'    => true,
            'auto_save'    => false,
        ];
    }
}
