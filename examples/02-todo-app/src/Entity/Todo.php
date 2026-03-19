<?php
// examples/02-todo-app/src/Entity/Todo.php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'todos')]
class Todo
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $title = '';

    #[ORM\Column]
    private bool $done = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $title)
    {
        $this->title     = $title;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getTitle(): string { return $this->title; }
    public function isDone(): bool { return $this->done; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function toggle(): void { $this->done = !$this->done; }
    public function setTitle(string $title): void { $this->title = $title; }
}
