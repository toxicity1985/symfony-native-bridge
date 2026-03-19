<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Service;

use SymfonyNativeBridge\Driver\NativeDriverInterface;
use SymfonyNativeBridge\ValueObject\NotificationOptions;

readonly class NotificationManager
{
    public function __construct(
        private NativeDriverInterface $driver,
    ) {}

    public function send(string $title, string $body = '', ?string $icon = null): void
    {
        $this->driver->sendNotification(new NotificationOptions(
            title: $title,
            body: $body,
            icon: $icon,
        ));
    }

    public function sendOptions(NotificationOptions $options): void
    {
        $this->driver->sendNotification($options);
    }
}
