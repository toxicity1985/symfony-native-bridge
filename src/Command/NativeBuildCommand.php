<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use SymfonyNativeBridge\Driver\NativeDriverInterface;

#[AsCommand(
    name: 'native:build',
    description: 'Build a distributable native desktop package',
)]
class NativeBuildCommand extends Command
{
    public function __construct(
        private readonly NativeDriverInterface $driver,
        private readonly array $buildConfig,
        private readonly array $appConfig,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'target',
                't',
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL,
                'Build targets: windows, macos, linux (default: current platform)',
                [],
            )
            ->addOption('output', 'o', InputOption::VALUE_OPTIONAL, 'Output directory', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $targets   = $input->getOption('target') ?: $this->buildConfig['targets'];
        $outputDir = $input->getOption('output') ?? $this->buildConfig['output_dir'];

        $io->title('Symfony Native Bridge — Build');
        $io->definitionList(
            ['Driver'  => $this->driver->getName()],
            ['App'     => sprintf('%s v%s', $this->appConfig['name'], $this->appConfig['version'])],
            ['Targets' => implode(', ', $targets)],
            ['Output'  => $outputDir],
        );

        $config = array_merge($this->buildConfig, [
            'targets'    => $targets,
            'output_dir' => $outputDir,
        ]);

        $io->text('Building…');

        try {
            $this->driver->build($config);
        } catch (\Throwable $e) {
            $io->error('Build failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf('Package built successfully → %s/', $outputDir));

        return Command::SUCCESS;
    }
}
