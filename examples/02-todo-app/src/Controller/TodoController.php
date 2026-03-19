<?php
// examples/02-todo-app/src/Controller/TodoController.php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Todo;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use SymfonyNativeBridge\Service\NotificationManager;

#[Route('/todos')]
class TodoController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationManager    $notification,
    ) {}

    #[Route('', name: 'todo_list', methods: ['GET'])]
    public function list(): Response
    {
        $todos    = $this->em->getRepository(Todo::class)->findBy([], ['createdAt' => 'DESC']);
        $pending  = count(array_filter($todos, fn(Todo $t) => !$t->isDone()));
        $done     = count($todos) - $pending;

        return $this->render('todo/index.html.twig', [
            'todos'   => $todos,
            'pending' => $pending,
            'done'    => $done,
        ]);
    }

    #[Route('/add', name: 'todo_add', methods: ['POST'])]
    public function add(Request $request): Response
    {
        $title = trim((string) $request->request->get('title', ''));

        if ($title === '') {
            return $this->redirectToRoute('todo_list');
        }

        $todo = new Todo($title);
        $this->em->persist($todo);
        $this->em->flush();

        return $this->redirectToRoute('todo_list');
    }

    #[Route('/{id}/toggle', name: 'todo_toggle', methods: ['POST'])]
    public function toggle(Todo $todo): Response
    {
        $todo->toggle();
        $this->em->flush();

        // Native notification when a task is completed
        if ($todo->isDone()) {
            $this->notification->send(
                title: '✅ Task complete',
                body:  "\"{$todo->getTitle()}\" marked as done.",
            );
        }

        return $this->redirectToRoute('todo_list');
    }

    #[Route('/{id}/delete', name: 'todo_delete', methods: ['POST'])]
    public function delete(Todo $todo): Response
    {
        $this->em->remove($todo);
        $this->em->flush();

        return $this->redirectToRoute('todo_list');
    }

    /**
     * Export all todos to a JSON file chosen by the user via a save dialog.
     */
    #[Route('/export', name: 'todo_export', methods: ['POST'])]
    public function export(\SymfonyNativeBridge\Service\DialogManager $dialog): Response
    {
        $todos = $this->em->getRepository(Todo::class)->findBy([], ['createdAt' => 'DESC']);

        $savePath = $dialog->saveFile(
            title:       'Export Todos',
            defaultPath: 'todos.json',
            filters:     [['name' => 'JSON', 'extensions' => ['json']]],
        );

        if ($savePath === null) {
            return $this->json(['ok' => false, 'reason' => 'cancelled']);
        }

        $data = array_map(fn(Todo $t) => [
            'id'        => $t->getId(),
            'title'     => $t->getTitle(),
            'done'      => $t->isDone(),
            'createdAt' => $t->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ], $todos);

        file_put_contents($savePath, json_encode($data, JSON_PRETTY_PRINT));

        $this->notification->send('Export complete', "Saved to {$savePath}");

        return $this->json(['ok' => true, 'path' => $savePath]);
    }
}
