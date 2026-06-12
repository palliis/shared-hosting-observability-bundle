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

        if ($this->maxSpoolBytes > 0 && $this->directorySize($dir) >= $this->maxSpoolBytes) {
            return;
        }

        $file = $dir.DIRECTORY_SEPARATOR.'traces-'.gmdate('YmdHi').'.jsonl';
        $line = json_encode($trace, JSON_UNESCAPED_SLASHES);
        if (false === $line) {
            return;
        }

        @file_put_contents($file, $line."\n", FILE_APPEND | LOCK_EX);
    }

    private function resolvePath(string $path): string
    {
        if (preg_match('#^(?:[A-Za-z]:[\\\\/]|/)#', $path)) {
            return $path;
        }

        return $this->projectDir.DIRECTORY_SEPARATOR.$path;
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
