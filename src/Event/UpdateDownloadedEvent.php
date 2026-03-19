<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Event;

final class UpdateDownloadedEvent extends NativeEvent
{
    public const NAME = 'native.updater.update_downloaded';

    public function __construct(public readonly string $version) {}

    public static function getEventName(): string
    {
        return self::NAME;
    }
}