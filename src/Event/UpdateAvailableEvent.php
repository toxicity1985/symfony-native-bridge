<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Event;

final class UpdateAvailableEvent extends NativeEvent
{
    public const NAME = 'native.updater.update_available';

    public function __construct(
        public readonly string $version,
        public readonly ?string $releaseNotes = null,
    ) {}

    public static function getEventName(): string
    {
        return self::NAME;
    }
}
