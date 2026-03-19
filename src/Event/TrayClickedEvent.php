<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Event;

final class TrayClickedEvent extends NativeEvent
{
    public const NAME = 'native.tray.clicked';

    public function __construct(
        public readonly string $trayId,
        public readonly string $button,   // 'left' | 'right' | 'double'
    ) {}

    public static function getEventName(): string
    {
        return self::NAME;
    }
}
