<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\ValueObject;

final class WindowOptions
{
    public function __construct(
        public readonly int $width = 1200,
        public readonly int $height = 800,
        public readonly int $minWidth = 400,
        public readonly int $minHeight = 300,
        public readonly string $title = '',
        public readonly bool $resizable = true,
        public readonly bool $fullscreen = false,
        public readonly bool $frame = true,
        public readonly bool $transparent = false,
        public readonly string $backgroundColor = '#ffffff',
        public readonly bool $alwaysOnTop = false,
        public readonly ?string $label = null,    // Tauri-specific window label
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            width:           $data['width'] ?? 1200,
            height:          $data['height'] ?? 800,
            minWidth:        $data['minWidth'] ?? 400,
            minHeight:       $data['minHeight'] ?? 300,
            title:           $data['title'] ?? '',
            resizable:       $data['resizable'] ?? true,
            fullscreen:      $data['fullscreen'] ?? false,
            frame:           $data['frame'] ?? true,
            transparent:     $data['transparent'] ?? false,
            backgroundColor: $data['backgroundColor'] ?? '#ffffff',
            alwaysOnTop:     $data['alwaysOnTop'] ?? false,
            label:           $data['label'] ?? null,
        );
    }
}
