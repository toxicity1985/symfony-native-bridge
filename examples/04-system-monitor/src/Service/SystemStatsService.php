<?php
// examples/04-system-monitor/src/Service/SystemStatsService.php

declare(strict_types=1);

namespace App\Service;

/**
 * Reads real-time system stats using native shell commands.
 * Works on Linux, macOS and Windows.
 */
class SystemStatsService
{
    public function getStats(): array
    {
        return [
            'cpu'    => $this->getCpuUsage(),
            'memory' => $this->getMemory(),
            'disk'   => $this->getDisk(),
            'uptime' => $this->getUptime(),
            'os'     => PHP_OS_FAMILY,
            'php'    => PHP_VERSION,
        ];
    }

    private function getCpuUsage(): float
    {
        return match (PHP_OS_FAMILY) {
            'Windows' => $this->windowsCpu(),
            'Darwin'  => $this->macosCpu(),
            default   => $this->linuxCpu(),
        };
    }

    private function linuxCpu(): float
    {
        $load = sys_getloadavg();
        $cores = (int) shell_exec('nproc') ?: 1;
        return round(($load[0] / $cores) * 100, 1);
    }

    private function macosCpu(): float
    {
        $out = shell_exec("top -l 1 -s 0 | grep 'CPU usage'");
        if (preg_match('/(\d+\.\d+)% user/', (string) $out, $m)) {
            return (float) $m[1];
        }
        return 0.0;
    }

    private function windowsCpu(): float
    {
        $out = shell_exec('wmic cpu get LoadPercentage /value');
        if (preg_match('/LoadPercentage=(\d+)/', (string) $out, $m)) {
            return (float) $m[1];
        }
        return 0.0;
    }

    private function getMemory(): array
    {
        if (PHP_OS_FAMILY === 'Linux') {
            $meminfo = file_get_contents('/proc/meminfo');
            preg_match('/MemTotal:\s+(\d+)/', $meminfo, $total);
            preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $available);
            $totalKb     = (int) ($total[1] ?? 0);
            $availableKb = (int) ($available[1] ?? 0);
            $usedKb      = $totalKb - $availableKb;
            return [
                'total_mb' => round($totalKb / 1024),
                'used_mb'  => round($usedKb / 1024),
                'percent'  => $totalKb > 0 ? round($usedKb / $totalKb * 100, 1) : 0,
            ];
        }

        // Fallback: PHP's own memory as a rough indicator
        return [
            'total_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 1),
            'used_mb'  => round(memory_get_usage(true) / 1024 / 1024, 1),
            'percent'  => 0,
        ];
    }

    private function getDisk(): array
    {
        $path   = PHP_OS_FAMILY === 'Windows' ? 'C:' : '/';
        $total  = @disk_total_space($path) ?: 0;
        $free   = @disk_free_space($path)  ?: 0;
        $used   = $total - $free;

        return [
            'total_gb' => round($total / 1024 / 1024 / 1024, 1),
            'used_gb'  => round($used  / 1024 / 1024 / 1024, 1),
            'percent'  => $total > 0 ? round($used / $total * 100, 1) : 0,
        ];
    }

    private function getUptime(): string
    {
        if (PHP_OS_FAMILY === 'Linux' && file_exists('/proc/uptime')) {
            $seconds = (int) explode(' ', file_get_contents('/proc/uptime'))[0];
        } else {
            $seconds = 0;
        }

        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);

        return "{$h}h {$m}m";
    }
}
