<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Event;

final class NotificationClickedEvent extends NativeEvent
{
    public const NAME = 'native.notification.clicked';

    public function __construct(
        public readonly string $title,
        public readonly ?string $action = null,
    ) {}

    public static function getEventName(): string
    {
        return self::NAME;
    }
}
