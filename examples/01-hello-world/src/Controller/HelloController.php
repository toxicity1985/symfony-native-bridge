<?php
// examples/01-hello-world/src/Controller/HelloController.php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use SymfonyNativeBridge\Service\AppManager;
use SymfonyNativeBridge\Service\NotificationManager;
use SymfonyNativeBridge\Service\DialogManager;

class HelloController extends AbstractController
{
    public function __construct(
        private readonly AppManager          $app,
        private readonly NotificationManager $notification,
        private readonly DialogManager       $dialog,
    ) {}

    #[Route('/', name: 'home')]
    public function index(): Response
    {
        return $this->render('hello/index.html.twig', [
            'app_name' => $this->app->getName(),
            'version'  => $this->app->getVersion(),
        ]);
    }

    /**
     * Called via AJAX when the user clicks "Send notification"
     */
    #[Route('/notify', name: 'notify', methods: ['POST'])]
    public function notify(): Response
    {
        $this->notification->send(
            title: 'Hello from Symfony!',
            body:  'Your first native notification 🎉',
        );

        return $this->json(['ok' => true]);
    }

    /**
     * Called via AJAX when the user clicks "Open file"
     */
    #[Route('/open-file', name: 'open_file', methods: ['POST'])]
    public function openFile(): Response
    {
        $paths = $this->dialog->openFile(
            title:   'Choose any file',
            filters: [['name' => 'All Files', 'extensions' => ['*']]],
        );

        return $this->json([
            'paths' => $paths ?? [],
        ]);
    }

    /**
     * Open the Symfony docs in the default browser
     */
    #[Route('/open-docs', name: 'open_docs', methods: ['POST'])]
    public function openDocs(): Response
    {
        $this->app->openExternal('https://symfony.com/doc/current/index.html');

        return $this->json(['ok' => true]);
    }
}
