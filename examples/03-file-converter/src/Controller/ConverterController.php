<?php
// examples/03-file-converter/src/Controller/ConverterController.php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ImageConverterService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use SymfonyNativeBridge\Service\DialogManager;
use SymfonyNativeBridge\Service\NotificationManager;
use SymfonyNativeBridge\Service\StorageManager;
use SymfonyNativeBridge\Service\WindowManager;

class ConverterController extends AbstractController
{
    public function __construct(
        private readonly DialogManager       $dialog,
        private readonly NotificationManager $notification,
        private readonly StorageManager      $storage,
        private readonly WindowManager       $window,
        private readonly ImageConverterService $converter,
    ) {}

    #[Route('/', name: 'converter_home')]
    public function index(): Response
    {
        $history = $this->storage->get('conversion_history', []);

        return $this->render('converter/index.html.twig', [
            'history'       => array_slice(array_reverse($history), 0, 10),
            'last_output'   => $this->storage->get('last_output_dir', ''),
        ]);
    }

    /**
     * Step 1: User picks source files via native dialog
     */
    #[Route('/pick-files', name: 'pick_files', methods: ['POST'])]
    public function pickFiles(): Response
    {
        $paths = $this->dialog->openFile(
            title:    'Select images to convert',
            filters:  [
                ['name' => 'Images', 'extensions' => ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp']],
                ['name' => 'All Files', 'extensions' => ['*']],
            ],
            multiple: true,
        );

        return $this->json(['paths' => $paths ?? []]);
    }

    /**
     * Step 2: User picks output directory
     */
    #[Route('/pick-output', name: 'pick_output', methods: ['POST'])]
    public function pickOutput(): Response
    {
        $folder = $this->dialog->openFolder('Select output folder');

        if ($folder) {
            $this->storage->set('last_output_dir', $folder[0]);
        }

        return $this->json(['folder' => $folder[0] ?? null]);
    }

    /**
     * Step 3: Run the conversion
     */
    #[Route('/convert', name: 'convert', methods: ['POST'])]
    public function convert(Request $request): Response
    {
        $sources  = $request->request->all('sources');
        $outputDir = $request->request->getString('output_dir');
        $format   = $request->request->getString('format', 'png');

        if (empty($sources) || $outputDir === '') {
            return $this->json(['ok' => false, 'error' => 'Missing sources or output dir'], 400);
        }

        $results = [];
        $errors  = [];

        foreach ($sources as $src) {
            try {
                $dest      = $this->converter->convert($src, $outputDir, $format);
                $results[] = ['src' => $src, 'dest' => $dest, 'ok' => true];
            } catch (\Throwable $e) {
                $errors[]  = ['src' => $src, 'error' => $e->getMessage()];
            }
        }

        // Persist history
        $history   = $this->storage->get('conversion_history', []);
        $history[] = [
            'at'      => date('Y-m-d H:i'),
            'count'   => count($results),
            'format'  => $format,
            'output'  => $outputDir,
        ];
        $this->storage->set('conversion_history', array_slice($history, -50));

        // Native notification summary
        if (count($results) > 0) {
            $this->notification->send(
                title: '✅ Conversion complete',
                body:  sprintf(
                    '%d file(s) converted to %s. %s',
                    count($results),
                    strtoupper($format),
                    count($errors) > 0 ? count($errors) . ' error(s).' : '',
                ),
            );
        }

        return $this->json([
            'ok'      => true,
            'results' => $results,
            'errors'  => $errors,
        ]);
    }

    /**
     * Open the output folder in the native file manager
     */
    #[Route('/reveal', name: 'reveal', methods: ['POST'])]
    public function reveal(Request $request, \SymfonyNativeBridge\Service\AppManager $app): Response
    {
        $path = $request->request->getString('path');
        $app->showItemInFolder($path);

        return $this->json(['ok' => true]);
    }

    /**
     * Open a secondary "About" window (multi-window demo)
     */
    #[Route('/about', name: 'about', methods: ['POST'])]
    public function about(): Response
    {
        $this->window->open(
            url:     'http://127.0.0.1:8765/about-window',
            options: new \SymfonyNativeBridge\ValueObject\WindowOptions(
                width:     400,
                height:    300,
                title:     'About File Converter',
                resizable: false,
                frame:     true,
            ),
        );

        return $this->json(['ok' => true]);
    }

    #[Route('/about-window', name: 'about_window')]
    public function aboutWindow(): Response
    {
        return $this->render('converter/about.html.twig');
    }
}
