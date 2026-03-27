<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Command;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;
use SymfonyNativeBridge\Bridge\IpcBridge;
use SymfonyNativeBridge\Driver\NativeDriverInterface;
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
        private readonly LoggerInterface       $logger = new NullLogger(),
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
        $routerScript = $publicDir . '/router.php';

        if (!file_exists($routerScript)) {
            $io->error("public/router.php not found. Are you running from the project root?");
            return Command::FAILURE;
        }

        $ipcPort = (int) $input->getOption('ipc-port');
        $cmd = [PHP_BINARY, '-S', "{$host}:{$port}", '-t', $publicDir];
        if ($routerScript) {
            $cmd[] = $routerScript;
        }

        $env = array_merge(
            array_filter($_SERVER, fn($v) => is_string($v)), // ← filtrer seulement strings
            [
                'APP_ENV'          => $_SERVER['APP_ENV'] ?? 'dev',
                'SYMFONY_IPC_PORT' => (string) $ipcPort,
            ]
        );

        $cmd = [PHP_BINARY, '-S', "{$host}:{$port}", '-t', $publicDir];

        if ($routerScript) {
            $cmd[] = $routerScript;
        }

        $phpServer = new Process(
            $cmd,
            getcwd(),
            $env
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

        // Connecter explicitement l'IpcBridge dans ce process CLI
        // (le driver l'a déjà connecté pour lui-même, mais le bridge du watcher
        //  est la même instance — on force la connexion si pas encore établie)
        if (!$this->ipcBridge->isConnected()) {
            try {
                $this->ipcBridge->connect("ws://127.0.0.1:{$ipcPort}/ipc");
            } catch (\Throwable $e) {
                $io->warning("Hot-reload IPC connection failed: {$e->getMessage()} — hot-reload disabled.");
                $hotReload = false;
            }
        }

        // 3. Hot-reload watcher + keep-alive loop
        $io->text('Press <comment>Ctrl+C</comment> to stop.');

        $watcher = null;
        if ($hotReload) {
            // Créer une instance IpcBridge DÉDIÉE pour le watcher
            // (le driver occupe déjà la connexion principale)
            $watcherBridge = $this->ipcBridge->createCompanion();
            try {
                $watcherBridge->connect("ws://127.0.0.1:{$ipcPort}/ipc");
                $watcher = new HotReloadWatcher(
                    ipcBridge:  $watcherBridge,
                    projectDir: getcwd(),
                    logger:     $this->logger,
                );
                $watcher->init();
                $io->text('Hot-reload active — watching <info>src/</info>, <info>templates/</info>, <info>config/</info>');
            } catch (\Throwable $e) {
                $io->warning("Hot-reload unavailable: {$e->getMessage()}");
            }
        }

        // Boucle principale : tick toutes les 500ms
        while ($phpServer->isRunning()) {
            // Vérifier les fichiers modifiés
            if ($watcher !== null) {
                $watcher->tick();
            }

            // Pomper la sortie du serveur PHP
            $phpServer->getIncrementalOutput();
            $phpServer->getIncrementalErrorOutput();

            usleep(500_000); // 500ms
        }

        // 4. Cleanup
        $io->newLine();
        $io->text('Shutting down…');

        $this->driver->stop();

        if ($phpServer->isRunning()) {
            $phpServer->stop(3);
        }

        $io->success('Stopped cleanly.');

        return Command::SUCCESS;
    }
}