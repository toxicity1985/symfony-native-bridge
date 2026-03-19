<?php
// examples/04-system-monitor/src/Controller/MonitorController.php

declare(strict_types=1);

namespace App\Controller;

use App\Service\SystemStatsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class MonitorController extends AbstractController
{
    public function __construct(
        private readonly SystemStatsService $stats,
    ) {}

    #[Route('/', name: 'monitor_home')]
    public function index(): Response
    {
        return $this->render('monitor/index.html.twig', [
            'stats' => $this->stats->getStats(),
        ]);
    }

    /**
     * Polled every 2s via JS — returns fresh stats as JSON
     */
    #[Route('/stats', name: 'monitor_stats', methods: ['GET'])]
    public function statsJson(): Response
    {
        return $this->json($this->stats->getStats());
    }
}
