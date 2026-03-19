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
use SymfonyNativeBridge\Bridge\IpcBridge;
use SymfonyNativeBridge\HotReload\HotReloadWatcher;

// =============================================================================
// native:serve
// =============================================================================

#[AsCommand(
    name: 'native:serve',
    description: 'Start the Symfony app in a native desktop window',
)]
class NativeServeCommand extends Command
{
    public function __construct(
        private readonly NativeDriverInterface $driver,
        private readonly array                 $appConfig,
        private readonly IpcBridge             $ipcBridge,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('host',      null, InputOption::VALUE_OPTIONAL, 'PHP built-in server host', '127.0.0.1')
            ->addOption('port',      'p',  InputOption::VALUE_OPTIONAL, 'PHP built-in server port', '8765')
            ->addOption('ipc-port',  null, InputOption::VALUE_OPTIONAL, 'IPC WebSocket port (Electron)', '9000')
            ->addOption('no-reload', null, InputOption::VALUE_NONE,     'Disable hot-reload file watcher');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $host      = $input->getOption('host');
        $port      = $input->getOption('port');
        $hotReload = !$input->getOption('no-reload');

        $serverUrl = "http://{$host}:{$port}";

        $io->title('Symfony Native Bridge');
        $io->text([
            sprintf('Driver     : <info>%s</info>', $this->driver->getName()),
            sprintf('App        : <info>%s</info> v%s', $this->appConfig['name'], $this->appConfig['version']),
            sprintf('Server     : <info>%s</info>', $serverUrl),
            sprintf('Hot-reload : <info>%s</info>', $hotReload ? 'enabled' : 'disabled'),
        ]);
        $io->newLine();

        // 1. Start PHP built-in server
        //    We pass the Symfony public/index.php as the router script so every
        //    request is handled by Symfony regardless of the URL path.
        $io->text('Starting PHP built-in server…');

        $publicDir   = getcwd() . '/public';
        $routerScript = $publicDir . '/index.php';

        if (!file_exists($routerScript)) {
            $io->error("public/index.php not found. Are you running from the project root?");
            return Command::FAILURE;
        }

        $ipcPort = (int) $input->getOption('ipc-port');

        $phpServer = new Process(
            [PHP_BINARY, '-S', "{$host}:{$port}", '-t', $publicDir, $routerScript],
            getcwd(),
            array_merge($_SERVER, [
                'APP_ENV'          => $_SERVER['APP_ENV'] ?? 'dev',
                'SYMFONY_IPC_PORT' => (string) $ipcPort,   // ← IpcBridge lazy-connect reads this
            ]),
        );
        $phpServer->setTimeout(null);   // run indefinitely
        $phpServer->start();

        // Give the server a moment to bind
        usleep(400_000);

        if (!$phpServer->isRunning()) {
            $io->error('PHP server failed to start: ' . $phpServer->getErrorOutput());
            return Command::FAILURE;
        }

        $io->text(sprintf('PHP server listening on <info>%s</info>', $serverUrl));

        // 2. Start native runtime
        $io->text(sprintf('Launching <info>%s</info>…', $this->driver->getName()));

        try {
            $pid = $this->driver->start($serverUrl, [
                'app'      => $this->appConfig,
                'ipc_port' => $ipcPort,
            ]);
            $io->success(sprintf('%s started (PID %d)', ucfirst($this->driver->getName()), $pid));
        } catch (\Throwable $e) {
            $phpServer->stop();
            $io->error('Failed to start native runtime: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // 3. Hot-reload watcher (fork a child process on Linux/macOS)
        $watcherPid  = null;
        $reloadPort  = $ipcPort + 1; // port dédié au watcher (ex: 9001)

        if ($hotReload && function_exists('pcntl_fork')) {
            $io->text('Starting <info>hot-reload</info> watcher (src/, templates/, config/)…');
            $watcherPid = pcntl_fork();

            if ($watcherPid === 0) {
                // Child process — connexion WebSocket dédiée sur le port hot-reload
                $watcherBridge = new \SymfonyNativeBridge\Bridge\IpcBridge('electron');

                $waited = 0;
                while ($waited < 5000) {
                    try {
                        $watcherBridge->connect("ws://127.0.0.1:{$reloadPort}/ipc");
                        break;
                    } catch (\Throwable) {
                        usleep(200_000);
                        $waited += 200;
                    }
                }

                $watcher = new HotReloadWatcher(
                    ipcBridge:  $watcherBridge,
                    projectDir: getcwd(),
                );
                $watcher->start();
                exit(0);
            }
        } elseif ($hotReload) {
            $io->text('<comment>Hot-reload requires pcntl extension — skipping.</comment>');
        }

        // 4. Keep running
        $io->text('Press <comment>Ctrl+C</comment> to stop.');

        try {
            $phpServer->wait(function (string $type, string $buffer) use ($output): void {
                if ($output->isVerbose()) {
                    $output->write($buffer);
                }
            });
        } catch (\Symfony\Component\Process\Exception\ProcessSignaledException $e) {
            // Ctrl+C — clean exit
        }

        // 5. Cleanup
        $io->newLine();
        $io->text('Shutting down…');

        if ($watcherPid !== null) {
            posix_kill($watcherPid, SIGTERM);
            pcntl_waitpid($watcherPid, $status);
        }

        $this->driver->stop();

        if ($phpServer->isRunning()) {
            $phpServer->stop(3);
        }

        $io->success('Stopped cleanly.');

        return Command::SUCCESS;
    }
}