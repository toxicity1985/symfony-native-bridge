<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;
use SymfonyNativeBridge\Driver\NativeDriverInterface;

#[AsCommand(
    name: 'native:install',
    description: 'Install native runtime dependencies (Electron or Tauri)',
)]
class NativeInstallCommand extends Command
{
    public function __construct(
        private readonly string $driver,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Force reinstall even if already installed');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io    = new SymfonyStyle($input, $output);
        $force = $input->getOption('force');

        $io->title(sprintf('Installing %s runtime', ucfirst($this->driver)));

        match ($this->driver) {
            'tauri'    => $this->installTauri($io, $force),
            default    => $this->installElectron($io, $force),
        };

        return Command::SUCCESS;
    }

    private function installElectron(SymfonyStyle $io, bool $force): void
    {
        // Check if npm is available
        if (!$this->commandExists('npm')) {
            $io->error('npm is not installed. Please install Node.js (>=18) first.');
            return;
        }

        $packageJsonPath = $this->projectDir . '/package.json';

        if (!file_exists($packageJsonPath) || $force) {
            $io->text('Writing package.json…');
            $appName = basename($this->projectDir);

            // Try to get git user info for author field
            $gitName  = trim((string) shell_exec('git config user.name  2>/dev/null'));
            $gitEmail = trim((string) shell_exec('git config user.email 2>/dev/null'));
            $authorName  = $gitName  ?: 'Your Name';
            $authorEmail = $gitEmail ?: 'you@example.com';

            file_put_contents($packageJsonPath, json_encode([
                'name'        => $appName,
                'version'     => '1.0.0',
                'description' => 'Symfony Native App',
                'main'        => 'electron/bootstrap.js',
                'homepage'    => 'https://example.com',
                'author'      => [
                    'name'  => $authorName,
                    'email' => $authorEmail,
                ],
                'dependencies' => [
                    'electron-store' => '^10.0.0',
                    'ws'             => '^8.0.0',
                ],
                'scripts'     => [
                    'start' => 'electron .',
                    'build' => 'electron-builder',
                ],
                'build' => [
                    'appId'       => 'com.example.' . $appName,
                    'productName' => ucfirst($appName),
                    'files'       => ['electron/**/*', 'node_modules/**/*'],
                    'extraResources' => [
                        [
                            'from' => '.',
                            'to'   => 'app',
                            'filter' => [
                                'public/**/*',
                                'src/**/*',
                                'config/**/*',
                                'templates/**/*',
                                'vendor/**/*',
                                'php-bin/**/*',
                                '.env',
                            ],
                        ],
                    ],
                    'linux'       => ['target' => ['AppImage', 'deb']],
                    'mac'         => ['target' => ['dmg']],
                    'win'         => ['target' => ['nsis']],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        $io->text('Installing Electron, electron-builder, electron-store and ws via npm…');

        $process = new Process([
            'npm', 'install', '--save-dev',
            'electron',
            'electron-builder',
            'electron-store',
            'ws',   // ← WebSocket server used by IPC bridge
        ]);
        $process->setWorkingDirectory($this->projectDir);
        $process->setTimeout(300);
        $process->run(fn($type, $data) => $io->text(trim($data)));

        if (!$process->isSuccessful()) {
            $io->error('npm install failed: ' . $process->getErrorOutput());
            return;
        }

        $electronDir = $this->projectDir . '/electron';
        foreach (['main.js', 'bootstrap.js'] as $file) {
            $target   = $electronDir . '/' . $file;
            $template = dirname(__DIR__, 2) . '/resources/electron/' . $file;
            if (!file_exists($target) || $force) {
                copy($template, $target);
            }
        }

        $io->success([
            'Electron installed successfully.',
            'Run: php bin/console native:serve',
        ]);
    }

    private function installTauri(SymfonyStyle $io, bool $force): void
    {
        if (!$this->commandExists('cargo')) {
            $io->error('Rust/Cargo is not installed. Visit https://rustup.rs to install it.');
            return;
        }

        if (!$this->commandExists('npm')) {
            $io->error('npm is not installed. Please install Node.js first.');
            return;
        }

        $io->text('Installing @tauri-apps/cli via npm…');

        $process = new Process(['npm', 'install', '--save-dev', '@tauri-apps/cli']);
        $process->setWorkingDirectory($this->projectDir);
        $process->setTimeout(300);
        $process->run(fn($type, $data) => $io->text($data));

        if (!$process->isSuccessful()) {
            $io->error('npm install failed: ' . $process->getErrorOutput());
            return;
        }

        // Copy tauri config template
        $tauriDir    = $this->projectDir . '/src-tauri';
        $configTarget = $tauriDir . '/tauri.conf.json';
        if (!file_exists($configTarget) || $force) {
            $template = dirname(__DIR__, 2) . '/resources/tauri/tauri.conf.json';
            @mkdir($tauriDir, 0755, true);
            copy($template, $configTarget);
            $io->text(sprintf('Wrote <info>%s</info>', $configTarget));
        }

        $io->success('Tauri installed. You can now run: php bin/console native:serve');
    }

    private function commandExists(string $command): bool
    {
        $which = PHP_OS_FAMILY === 'Windows' ? 'where' : 'which';

        return (new Process([$which, $command]))->run() === 0;
    }
}
