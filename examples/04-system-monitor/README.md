# Example 04 — System Monitor

> A real-time CPU / memory / disk dashboard with a **live tray icon** and window focus events.

## What it demonstrates

- **Window focus/blur events** (`WindowFocusedEvent`, `WindowBlurredEvent`) to pause/resume live updates
- **Tray icon tooltip** updated dynamically with the current CPU %
- **Tray menu** with a text-based CPU gauge bar
- **Critical CPU alert** notification (fires once when CPU > 90%)
- Real-time polling via plain `fetch` + a PHP stats endpoint

## Run it

```bash
cd examples/04-system-monitor
composer install
php bin/console native:install
php bin/console native:serve
```

## Architecture

```
every 2s: JS polls GET /stats → PHP reads /proc/meminfo, sys_getloadavg → JSON

WindowFocusedEvent
  └─▶ MonitorNativeListener::onFocus()
        ├─ reads fresh CPU
        ├─ updates tray tooltip
        ├─ if CPU > 90% → NotificationManager::send("⚠️ High CPU")
        └─ rebuilds tray menu with ascii gauge

WindowBlurredEvent
  └─▶ MonitorNativeListener::onBlur()
        └─ sets tray tooltip to "background" hint
```

## Key code

```php
// Respond to window gaining focus
#[AsNativeListener(WindowFocusedEvent::class)]
public function onFocus(WindowFocusedEvent $event): void
{
    $cpu = $this->stats->getStats()['cpu'];
    $this->tray->tooltip('main', "System Monitor — CPU: {$cpu}%");

    if ($cpu > 90 && !$this->alertSent) {
        $this->notification->send('⚠️ High CPU', "CPU at {$cpu}%");
        $this->alertSent = true;
    }
}

// ASCII CPU gauge in tray menu
private function cpuBar(float $percent): string
{
    $filled = (int) round($percent / 10);
    return str_repeat('█', $filled) . str_repeat('░', 10 - $filled);
}
```
