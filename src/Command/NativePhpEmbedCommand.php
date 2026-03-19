<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Downloads a prebuilt static PHP binary from static-php-cli
 * and places it in php-bin/ so it can be embedded in the Electron package.
 *
 * Source: https://github.com/crazywhalecc/static-php-cli
 * Binaries: https://dl.static-php.dev/static-php-cli/common/
 */
#[AsCommand(
    name: 'native:php-embed',
    description: 'Download a static PHP binary to embed in the native package',
)]
class NativePhpEmbedCommand extends Command
{
    // Extensions needed for a Symfony app:
    // ctype, dom, fileinfo, filter, iconv, mbstring, opcache,
    // openssl, pdo_sqlite, session, simplexml, tokenizer, xml, xmlwriter
    private const PHP_VERSION = '8.3.10';

    private const BINARIES = [
        'linux-x64'   => 'https://dl.static-php.dev/static-php-cli/common/php-%s-cli-linux-x86_64.tar.gz',
        'linux-arm64' => 'https://dl.static-php.dev/static-php-cli/common/php-%s-cli-linux-aarch64.tar.gz',
        'macos-x64'   => 'https://dl.static-php.dev/static-php-cli/common/php-%s-cli-macos-x86_64.tar.gz',
        'macos-arm64' => 'https://dl.static-php.dev/static-php-cli/common/php-%s-cli-macos-aarch64.tar.gz',
        'win-x64'     => 'https://dl.static-php.dev/static-php-cli/common/php-%s-cli-windows-x64.zip',
    ];

    private const OUTPUT_NAMES = [
        'linux-x64'   => 'php-linux-x64',
        'linux-arm64' => 'php-linux-arm64',
        'macos-x64'   => 'php-macos-x64',
        'macos-arm64' => 'php-macos-arm64',
        'win-x64'     => 'php-win-x64.exe',
    ];

    public function __construct(
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'platform',
                'p',
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL,
                'Platforms to download: linux-x64, linux-arm64, macos-x64, macos-arm64, win-x64',
                [$this->detectCurrentPlatform()],
            )
            ->addOption(
                'php-version',
                null,
                InputOption::VALUE_OPTIONAL,
                'PHP version to embed',
                self::PHP_VERSION,
            )
            ->addOption('all', null, InputOption::VALUE_NONE, 'Download for all platforms');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io         = new SymfonyStyle($input, $output);
        $phpVersion = $input->getOption('php-version');
        $platforms  = $input->getOption('all')
            ? array_keys(self::BINARIES)
            : $input->getOption('platform');

        $outDir = $this->projectDir . '/php-bin';
        if (!is_dir($outDir)) {
            mkdir($outDir, 0755, true);
        }

        $io->title('Embedding static PHP binary');
        $io->text(sprintf('PHP version : <info>%s</info>', $phpVersion));
        $io->text(sprintf('Platforms   : <info>%s</info>', implode(', ', $platforms)));
        $io->text(sprintf('Output dir  : <info>%s</info>', $outDir));
        $io->newLine();

        foreach ($platforms as $platform) {
            if (!isset(self::BINARIES[$platform])) {
                $io->warning("Unknown platform '{$platform}'. Valid: " . implode(', ', array_keys(self::BINARIES)));
                continue;
            }

            $url      = sprintf(self::BINARIES[$platform], $phpVersion);
            $destName = self::OUTPUT_NAMES[$platform];
            $destPath = $outDir . '/' . $destName;

            if (file_exists($destPath)) {
                $io->text("✓ <info>{$destName}</info> already exists, skipping. Use --force to re-download.");
                continue;
            }

            $io->text("Downloading <info>{$platform}</info>…");
            $io->text("  URL: {$url}");

            $archive = $this->download($url, $io);

            if ($archive === null) {
                $io->error("Failed to download PHP binary for {$platform}");
                continue;
            }

            // Extract php binary from archive
            $extracted = $this->extract($archive, $platform, $outDir);

            if ($extracted === null) {
                $io->error("Failed to extract PHP binary for {$platform}");
                @unlink($archive);
                continue;
            }

            // Rename to our standard name
            rename($extracted, $destPath);
            @unlink($archive);

            // Make executable on Unix
            if (!str_ends_with($destName, '.exe')) {
                chmod($destPath, 0755);
            }

            $size = round(filesize($destPath) / 1024 / 1024, 1);
            $io->text("  ✓ Saved to <info>{$destName}</info> ({$size} MB)");
        }

        $io->newLine();
        $io->success([
            'PHP binary ready in php-bin/.',
            'electron-builder will embed it automatically via the "files" config.',
            'bootstrap.js will use it instead of the system PHP.',
        ]);

        // Remind to add php-bin/ to electron-builder files
        if (!$this->isInBuildFiles()) {
            $io->note('Make sure "php-bin/**/*" is in the "files" section of your package.json build config.');
        }

        return Command::SUCCESS;
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function download(string $url, SymfonyStyle $io): ?string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'snb-php-') . (str_ends_with($url, '.zip') ? '.zip' : '.tar.gz');

        // Use curl if available, fallback to file_get_contents
        if ($this->commandExists('curl')) {
            $exitCode = null;
            passthru("curl -L --progress-bar -o " . escapeshellarg($tmpFile) . " " . escapeshellarg($url), $exitCode);
            if ($exitCode !== 0) {
                return null;
            }
        } else {
            $io->text('  (curl not found, using PHP stream — no progress bar)');
            $data = @file_get_contents($url);
            if ($data === false) {
                return null;
            }
            file_put_contents($tmpFile, $data);
        }

        return $tmpFile;
    }

    private function extract(string $archive, string $platform, string $outDir): ?string
    {
        $tmpDir = $outDir . '/.extract_tmp';
        @mkdir($tmpDir, 0755, true);

        if (str_ends_with($archive, '.zip')) {
            $zip = new \ZipArchive();
            if ($zip->open($archive) !== true) {
                return null;
            }
            $zip->extractTo($tmpDir);
            $zip->close();
        } else {
            // .tar.gz
            $exitCode = null;
            passthru("tar -xzf " . escapeshellarg($archive) . " -C " . escapeshellarg($tmpDir), $exitCode);
            if ($exitCode !== 0) {
                return null;
            }
        }

        // Find the php (or php.exe) binary in extracted files
        $phpBin = $this->findPhpInDir($tmpDir);

        if ($phpBin === null) {
            return null;
        }

        // Move to a temp path before cleanup
        $tmpBin = $outDir . '/.php_tmp_' . $platform;
        rename($phpBin, $tmpBin);

        // Cleanup extract dir
        $this->rmdir($tmpDir);

        return $tmpBin;
    }

    private function findPhpInDir(string $dir): ?string
    {
        $candidates = ['php', 'php8', 'php.exe'];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $file) {
            if ($file->isFile() && in_array($file->getFilename(), $candidates, true)) {
                return $file->getPathname();
            }
        }

        return null;
    }

    private function isInBuildFiles(): bool
    {
        $packageJson = $this->projectDir . '/package.json';
        if (!file_exists($packageJson)) {
            return false;
        }
        $content = file_get_contents($packageJson);
        return str_contains($content, 'php-bin');
    }

    private function detectCurrentPlatform(): string
    {
        $os   = PHP_OS_FAMILY;
        $arch = php_uname('m');

        $isArm = str_contains(strtolower($arch), 'arm') || str_contains(strtolower($arch), 'aarch');

        return match ($os) {
            'Darwin'  => $isArm ? 'macos-arm64' : 'macos-x64',
            'Windows' => 'win-x64',
            default   => $isArm ? 'linux-arm64' : 'linux-x64',
        };
    }

    private function commandExists(string $cmd): bool
    {
        $which = PHP_OS_FAMILY === 'Windows' ? 'where' : 'which';
        exec("{$which} {$cmd} 2>/dev/null", $out, $code);
        return $code === 0;
    }

    private function rmdir(string $dir): void
    {
        foreach (new \RecursiveIteratorIterator(
                     new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                     \RecursiveIteratorIterator::CHILD_FIRST,
                 ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
