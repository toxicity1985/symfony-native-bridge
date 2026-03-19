<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Event;

final class AppReadyEvent extends NativeEvent
{
    public const NAME = 'native.app.ready';

    public static function getEventName(): string
    {
        return self::NAME;
    }
}
