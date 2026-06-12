<?php

namespace Palliis\SharedHostingObservabilityBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'shared-hosting-observability:gc', description: 'Prune local telemetry disk buffers using conservative retention thresholds.')]
final class TelemetryGcCommand extends Command
{
    public function __construct(
        private string $projectDir,
        private string $spoolDir,
        private string $logSourcePath,
        private string $logStatePath,
        private int $defaultMaxAgeSeconds,
        private int $defaultMaxTotalBytes,
        private int $defaultLogMaxBytes,
    ) {
        parent::__construct();
        $this->defaultMaxAgeSeconds = max(0, $this->defaultMaxAgeSeconds);
        $this->defaultMaxTotalBytes = max(0, $this->defaultMaxTotalBytes);
        $this->defaultLogMaxBytes = max(0, $this->defaultLogMaxBytes);
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report candidates without deleting files.')
            ->addOption('max-age-seconds', null, InputOption::VALUE_REQUIRED, 'Delete trace spool files older than this age in seconds (0 disables age pruning).', $this->defaultMaxAgeSeconds)
            ->addOption('max-total-bytes', null, InputOption::VALUE_REQUIRED, 'Keep total trace spool size at or below this number of bytes by pruning oldest files first (0 disables size pruning).', $this->defaultMaxTotalBytes)
            ->addOption('log-max-bytes', null, InputOption::VALUE_REQUIRED, 'Truncate the log source once it exceeds this size and has been fully shipped (0 disables log cleanup).', $this->defaultLogMaxBytes);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $maxAgeSeconds = max(0, (int) $input->getOption('max-age-seconds'));
            $maxTotalBytes = max(0, (int) $input->getOption('max-total-bytes'));
            $logMaxBytes = max(0, (int) $input->getOption('log-max-bytes'));
            $dryRun = (bool) $input->getOption('dry-run');

            $traceSummary = $this->cleanupTraceSpool($io, $maxAgeSeconds, $maxTotalBytes, $dryRun);
            $logSummary = $this->cleanupLogSource($io, $logMaxBytes, $dryRun);
            $io->success(sprintf('Telemetry GC complete (%s; %s).', $traceSummary, $logSummary));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }

    private function cleanupTraceSpool(SymfonyStyle $io, int $maxAgeSeconds, int $maxTotalBytes, bool $dryRun): string
    {
        $dir = $this->resolvePath($this->spoolDir);
        if (!is_dir($dir)) {
            return 'trace spool missing';
        }

        $lock = $this->openLock($dir.DIRECTORY_SEPARATOR.'.ship-traces.lock');
        if (!$lock) {
            $io->warning('Trace spool cleanup skipped because the trace shipper is running.');

            return 'trace cleanup skipped';
        }

        try {
            $files = $this->collectSpoolFiles($dir);
            if ([] === $files) {
                return 'no trace spool files';
            }

            $toDelete = $this->buildDeletionPlan($files, $maxAgeSeconds, $maxTotalBytes);
            if ([] === $toDelete) {
                return sprintf('trace spool ok: %d file(s), %d bytes', count($files), $this->totalBytes($files));
            }

            $deletedFiles = 0;
            $freedBytes = 0;
            foreach ($toDelete as $path => $meta) {
                if (!$dryRun && !@unlink($path)) {
                    throw new \RuntimeException(sprintf('Failed to delete spool file: %s', $path));
                }

                ++$deletedFiles;
                $freedBytes += $meta['size'];
            }

            return sprintf('%s %d trace spool file(s), freeing %d bytes', $dryRun ? 'would delete' : 'deleted', $deletedFiles, $freedBytes);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function cleanupLogSource(SymfonyStyle $io, int $logMaxBytes, bool $dryRun): string
    {
        if ($logMaxBytes <= 0) {
            return 'log cleanup disabled';
        }

        if (str_starts_with($this->logSourcePath, 'php://')) {
            return 'log source is a stream';
        }

        $sourcePath = $this->resolvePath($this->logSourcePath);
        if (!is_file($sourcePath)) {
            return 'log source missing';
        }

        $stat = stat($sourcePath);
        if (false === $stat) {
            throw new \RuntimeException(sprintf('Cannot stat log source file: %s', $sourcePath));
        }

        $size = (int) $stat['size'];
        if ($size <= $logMaxBytes) {
            return sprintf('log source ok: %d bytes', $size);
        }

        $statePath = $this->resolvePath($this->logStatePath);
        if (!is_file($statePath)) {
            $io->warning('Log cleanup skipped because shipper state does not exist yet.');

            return 'log cleanup skipped';
        }

        $stateDir = dirname($statePath);
        if (!is_dir($stateDir)) {
            $io->warning('Log cleanup skipped because shipper state directory does not exist.');

            return 'log cleanup skipped';
        }

        $lock = $this->openLock($stateDir.DIRECTORY_SEPARATOR.'.ship-logs.lock');
        if (!$lock) {
            $io->warning('Log cleanup skipped because the log shipper is running.');

            return 'log cleanup skipped';
        }

        try {
            clearstatcache(true, $sourcePath);
            $handle = @fopen($sourcePath, 'c+b');
            if (!$handle) {
                throw new \RuntimeException(sprintf('Cannot open log source file for cleanup: %s', $sourcePath));
            }

            try {
                if (!flock($handle, LOCK_EX)) {
                    throw new \RuntimeException(sprintf('Cannot lock log source file for cleanup: %s', $sourcePath));
                }

                try {
                    $stat = fstat($handle);
                    if (false === $stat) {
                        throw new \RuntimeException(sprintf('Cannot stat log source file: %s', $sourcePath));
                    }

                    $inode = (string) $stat['ino'];
                    $size = (int) $stat['size'];
                    if ($size <= $logMaxBytes) {
                        return sprintf('log source ok: %d bytes', $size);
                    }

                    $state = $this->loadState($statePath);
                    $offset = (int) ($state['offset'] ?? -1);
                    if (($state['inode'] ?? null) !== $inode || $offset !== $size) {
                        $io->warning(sprintf('Log cleanup skipped because the shipper has not fully consumed the current log file (%d/%d bytes).', max(0, $offset), $size));

                        return 'log cleanup skipped';
                    }

                    if (!$dryRun) {
                        if (!@ftruncate($handle, 0)) {
                            throw new \RuntimeException(sprintf('Failed to truncate log source file: %s', $sourcePath));
                        }

                        $this->saveState($statePath, $inode, 0);
                    }

                    return sprintf('%s log source, freeing %d bytes', $dryRun ? 'would truncate' : 'truncated', $size);
                } finally {
                    flock($handle, LOCK_UN);
                }
            } finally {
                fclose($handle);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @return resource|null
     */
    private function openLock(string $lockPath)
    {
        $lock = @fopen($lockPath, 'c');
        if (!$lock) {
            throw new \RuntimeException(sprintf('Cannot open lock file: %s', $lockPath));
        }

        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return null;
        }

        return $lock;
    }

    /**
     * @return array<int, array{path: string, size: int, mtime: int}>
     */
    private function collectSpoolFiles(string $dir): array
    {
        $paths = glob($dir.DIRECTORY_SEPARATOR.'*.jsonl') ?: [];
        $files = [];
        foreach ($paths as $path) {
            if (!is_file($path)) {
                continue;
            }

            $size = filesize($path);
            $mtime = filemtime($path);
            if (false === $size || false === $mtime) {
                continue;
            }

            $files[] = [
                'path' => $path,
                'size' => (int) $size,
                'mtime' => (int) $mtime,
            ];
        }

        usort($files, static fn (array $a, array $b): int => $a['mtime'] <=> $b['mtime']);

        return $files;
    }

    /**
     * @param array<int, array{path: string, size: int, mtime: int}> $files
     *
     * @return array<string, array{path: string, size: int, mtime: int}>
     */
    private function buildDeletionPlan(array $files, int $maxAgeSeconds, int $maxTotalBytes): array
    {
        $now = time();
        $deletions = [];
        $remainingTotal = $this->totalBytes($files);

        if ($maxAgeSeconds > 0) {
            foreach ($files as $file) {
                if (($now - $file['mtime']) <= $maxAgeSeconds) {
                    continue;
                }

                $deletions[$file['path']] = $file;
                $remainingTotal -= $file['size'];
            }
        }

        if ($maxTotalBytes > 0 && $remainingTotal > $maxTotalBytes) {
            foreach ($files as $file) {
                if (isset($deletions[$file['path']])) {
                    continue;
                }

                $deletions[$file['path']] = $file;
                $remainingTotal -= $file['size'];
                if ($remainingTotal <= $maxTotalBytes) {
                    break;
                }
            }
        }

        return $deletions;
    }

    /**
     * @param array<int, array{path: string, size: int, mtime: int}> $files
     */
    private function totalBytes(array $files): int
    {
        return array_sum(array_map(static fn (array $file): int => $file['size'], $files));
    }

    /**
     * @return array{inode?: string, offset?: int}
     */
    private function loadState(string $statePath): array
    {
        $state = json_decode((string) @file_get_contents($statePath), true);

        return is_array($state) ? $state : [];
    }

    private function saveState(string $statePath, string $inode, int $offset): void
    {
        @file_put_contents($statePath, json_encode([
            'inode' => $inode,
            'offset' => $offset,
            'updated_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ], JSON_UNESCAPED_SLASHES) ?: '{}', LOCK_EX);
    }

    private function resolvePath(string $path): string
    {
        if (preg_match('#^(?:[A-Za-z]:[\\\\/]|/)#', $path)) {
            return $path;
        }

        return $this->projectDir.DIRECTORY_SEPARATOR.$path;
    }
}
