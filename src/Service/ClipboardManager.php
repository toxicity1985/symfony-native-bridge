<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Service;

use SymfonyNativeBridge\Driver\NativeDriverInterface;

/**
 * Read and write the system clipboard from PHP.
 *
 * Usage:
 *
 *     // Write
 *     $clipboard->writeText('Hello from PHP!');
 *
 *     // Read
 *     $text = $clipboard->readText();   // string|null
 *
 *     // Images (PNG base64-encoded)
 *     $clipboard->writeImage('/path/to/image.png');
 *     $base64 = $clipboard->readImage(); // string|null
 *
 *     // Clear
 *     $clipboard->clear();
 */
class ClipboardManager
{
    public function __construct(
        private readonly NativeDriverInterface $driver,
    ) {}

    public function readText(): ?string
    {
        return $this->driver->clipboardReadText();
    }

    public function writeText(string $text): void
    {
        $this->driver->clipboardWriteText($text);
    }

    /**
     * Returns the clipboard image as a base64-encoded PNG string, or null if
     * the clipboard does not contain an image.
     */
    public function readImage(): ?string
    {
        return $this->driver->clipboardReadImage();
    }

    /**
     * Copy a local image file into the clipboard.
     */
    public function writeImage(string $path): void
    {
        $this->driver->clipboardWriteImage($path);
    }

    public function clear(): void
    {
        $this->driver->clipboardClear();
    }

    /**
     * Returns true when the clipboard currently holds plain text.
     */
    public function hasText(): bool
    {
        return $this->readText() !== null && $this->readText() !== '';
    }

    /**
     * Returns true when the clipboard currently holds an image.
     */
    public function hasImage(): bool
    {
        return $this->readImage() !== null;
    }
}
