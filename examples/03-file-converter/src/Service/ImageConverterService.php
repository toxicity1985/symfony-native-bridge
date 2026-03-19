<?php
// examples/03-file-converter/src/Service/ImageConverterService.php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Converts images between formats using PHP's GD extension.
 * Supports: jpg, png, webp, gif, bmp
 */
class ImageConverterService
{
    private const SUPPORTED = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp'];

    public function convert(string $sourcePath, string $outputDir, string $targetFormat): string
    {
        if (!file_exists($sourcePath)) {
            throw new \RuntimeException("Source file not found: {$sourcePath}");
        }

        $targetFormat = strtolower($targetFormat);

        if (!in_array($targetFormat, self::SUPPORTED, true)) {
            throw new \RuntimeException("Unsupported target format: {$targetFormat}");
        }

        // Load the image
        $image = match (strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => imagecreatefromjpeg($sourcePath),
            'png'         => imagecreatefrompng($sourcePath),
            'webp'        => imagecreatefromwebp($sourcePath),
            'gif'         => imagecreatefromgif($sourcePath),
            'bmp'         => imagecreatefrombmp($sourcePath),
            default       => throw new \RuntimeException("Unsupported source format"),
        };

        if ($image === false) {
            throw new \RuntimeException("Failed to load image: {$sourcePath}");
        }

        // Preserve transparency for PNG and WebP
        if (in_array($targetFormat, ['png', 'webp'], true)) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }

        $baseName   = pathinfo($sourcePath, PATHINFO_FILENAME);
        $outputPath = rtrim($outputDir, '/\\') . DIRECTORY_SEPARATOR . "{$baseName}.{$targetFormat}";

        $ok = match ($targetFormat) {
            'jpg', 'jpeg' => imagejpeg($image, $outputPath, 90),
            'png'         => imagepng($image, $outputPath),
            'webp'        => imagewebp($image, $outputPath, 90),
            'gif'         => imagegif($image, $outputPath),
            'bmp'         => imagebmp($image, $outputPath),
        };

        imagedestroy($image);

        if (!$ok) {
            throw new \RuntimeException("Failed to write output: {$outputPath}");
        }

        return $outputPath;
    }
}
