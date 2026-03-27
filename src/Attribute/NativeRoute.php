<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Attribute;

use Attribute;

/**
 * Declares a controller class as a native window route.
 *
 * Usage — minimal (Symfony route name auto-detected from #[Route] on methods):
 *
 *     #[NativeRoute('dashboard')]
 *     class DashboardController extends AbstractController { … }
 *
 * Usage — with explicit Symfony route name and window options:
 *
 *     #[NativeRoute('settings', route: 'app_settings', title: 'Settings', width: 800, height: 600)]
 *     class SettingsController extends AbstractController { … }
 *
 * Then open the window from anywhere in PHP:
 *
 *     $windowManager->openRoute('dashboard');
 *     $windowManager->openRoute('settings', ['tab' => 'general']);
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class NativeRoute
{
    public function __construct(
        /** Unique name used with WindowManager::openRoute() */
        public string  $name,
        /** Symfony route name to generate the URL. If empty, auto-detected from #[Route] on the controller. */
        public string  $route       = '',
        public ?string $title       = null,
        public ?int    $width       = null,
        public ?int    $height      = null,
        public ?bool   $resizable   = null,
        public ?bool   $frame       = null,
        public ?bool   $transparent = null,
        public ?bool   $alwaysOnTop = null,
        /** Fixed window label (id). Null = auto-generated. */
        public ?string $label       = null,
    ) {}
}
