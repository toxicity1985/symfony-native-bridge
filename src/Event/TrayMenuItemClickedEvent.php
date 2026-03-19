<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Event;

final class TrayMenuItemClickedEvent extends NativeEvent
{
    public const NAME = 'native.tray.menu_item_clicked';

    public function __construct(
        public readonly string $trayId,
        public readonly string $menuItemId,
    ) {}

    public static function getEventName(): string
    {
        return self::NAME;
    }
}
