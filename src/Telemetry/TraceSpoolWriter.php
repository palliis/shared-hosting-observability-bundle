<?php

namespace Palliis\SharedHostingObservabilityBundle\Telemetry;

final class TraceSpoolWriter
{
    public function __construct(
        private string $projectDir,
        private string $spoolDir,
        private int $maxSpoolBytes,
    ) {
        $this->maxSpoolBytes = max(0, $this->maxSpoolBytes);
    }

    /**
     * @param array<string, mixed> $trace
     */
    public function append(array $trace): void
    {
        $dir = $this->resolvePath($this->spoolDir);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        $line = json_encode($trace, JSON_UNESCAPED_SLASHES);
        if (false === $line) {
            return;
        }

        $line .= "\n";
        if ($this->maxSpoolBytes > 0 && strlen($line) > $this->maxSpoolBytes) {
            return;
        }

        $lock = $this->openLock($dir);
        if (!$lock) {
            return;
        }

        try {
            if ($this->maxSpoolBytes > 0 && ($this->directorySize($dir) + strlen($line)) > $this->maxSpoolBytes) {
                return;
            }

            $file = $dir.DIRECTORY_SEPARATOR.'traces-'.gmdate('YmdHi').'.jsonl';
            @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function resolvePath(string $path): string
    {
        if (preg_match('#^(?:[A-Za-z]:[\\\\/]|/)#', $path)) {
            return $path;
        }

        return $this->projectDir.DIRECTORY_SEPARATOR.$path;
    }

    /**
     * @return resource|null
     */
    private function openLock(string $dir)
    {
        $lock = @fopen($dir.DIRECTORY_SEPARATOR.'.trace-spool.lock', 'c');
        if (!$lock) {
            return null;
        }

        if (!flock($lock, LOCK_EX)) {
            fclose($lock);

            return null;
        }

        return $lock;
    }

    private function directorySize(string $dir): int
    {
        $bytes = 0;
        foreach (glob($dir.DIRECTORY_SEPARATOR.'*.jsonl') ?: [] as $file) {
            if (is_file($file)) {
                $size = filesize($file);
                if (false !== $size) {
                    $bytes += $size;
                }
            }
        }

        return $bytes;
    }
}
