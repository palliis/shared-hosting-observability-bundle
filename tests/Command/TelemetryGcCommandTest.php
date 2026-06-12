<?php

namespace Palliis\SharedHostingObservabilityBundle\Tests\Command;

use Palliis\SharedHostingObservabilityBundle\Command\TelemetryGcCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class TelemetryGcCommandTest extends TestCase
{
    private string $tmpDir;
    private string $spoolDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'motometeo_telemetry_gc_'.bin2hex(random_bytes(6));
        $this->spoolDir = $this->tmpDir.DIRECTORY_SEPARATOR.'var'.DIRECTORY_SEPARATOR.'telemetry'.DIRECTORY_SEPARATOR.'traces';
        mkdir($this->spoolDir, 0777, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeDir($this->tmpDir);
    }

    public function testDryRunDoesNotDeleteEligibleFiles(): void
    {
        $oldFile = $this->createTraceFile('trace-old.jsonl', 120, time() - 3600);

        $tester = new CommandTester($this->createCommand(60, 0, 0));
        $result = $tester->execute(['--dry-run' => true]);

        self::assertSame(0, $result);
        self::assertFileExists($oldFile);
        self::assertStringContainsString('would delete 1 trace spool file(s)', $tester->getDisplay());
    }

    public function testAgePruningDeletesOnlyOldFiles(): void
    {
        $oldFile = $this->createTraceFile('trace-old.jsonl', 120, time() - 3600);
        $recentFile = $this->createTraceFile('trace-recent.jsonl', 120, time() - 10);

        $tester = new CommandTester($this->createCommand(60, 0, 0));
        $result = $tester->execute([]);

        self::assertSame(0, $result);
        self::assertFileDoesNotExist($oldFile);
        self::assertFileExists($recentFile);
    }

    public function testSizePruningDeletesOldestFilesUntilWithinCap(): void
    {
        $first = $this->createTraceFile('trace-1.jsonl', 80, time() - 300);
        $second = $this->createTraceFile('trace-2.jsonl', 80, time() - 200);
        $third = $this->createTraceFile('trace-3.jsonl', 80, time() - 100);

        $tester = new CommandTester($this->createCommand(0, 160, 0));
        $result = $tester->execute([]);

        self::assertSame(0, $result);
        self::assertFileDoesNotExist($first);
        self::assertFileExists($second);
        self::assertFileExists($third);
    }

    public function testLogCleanupTruncatesFullyShippedOversizedLog(): void
    {
        $logPath = $this->tmpDir.DIRECTORY_SEPARATOR.'var'.DIRECTORY_SEPARATOR.'log'.DIRECTORY_SEPARATOR.'prod.log';
        $statePath = $this->tmpDir.DIRECTORY_SEPARATOR.'var'.DIRECTORY_SEPARATOR.'telemetry'.DIRECTORY_SEPARATOR.'state'.DIRECTORY_SEPARATOR.'logs.offset.json';
        $this->createLogFile($logPath, 120);
        $this->writeLogState($statePath, $logPath, 120);

        $tester = new CommandTester($this->createCommand(0, 0, 100));
        $result = $tester->execute([]);

        self::assertSame(0, $result);
        self::assertSame('', file_get_contents($logPath));

        $state = json_decode((string) file_get_contents($statePath), true);
        self::assertIsArray($state);
        self::assertSame(0, $state['offset']);
    }

    public function testLogCleanupSkipsUnshippedLogTail(): void
    {
        $logPath = $this->tmpDir.DIRECTORY_SEPARATOR.'var'.DIRECTORY_SEPARATOR.'log'.DIRECTORY_SEPARATOR.'prod.log';
        $statePath = $this->tmpDir.DIRECTORY_SEPARATOR.'var'.DIRECTORY_SEPARATOR.'telemetry'.DIRECTORY_SEPARATOR.'state'.DIRECTORY_SEPARATOR.'logs.offset.json';
        $this->createLogFile($logPath, 120);
        $this->writeLogState($statePath, $logPath, 80);

        $tester = new CommandTester($this->createCommand(0, 0, 100));
        $result = $tester->execute([]);

        self::assertSame(0, $result);
        self::assertSame(120, filesize($logPath));
        self::assertStringContainsString('shipper has not fully consumed', $tester->getDisplay());
    }

    public function testLogCleanupDryRunDoesNotTruncate(): void
    {
        $logPath = $this->tmpDir.DIRECTORY_SEPARATOR.'var'.DIRECTORY_SEPARATOR.'log'.DIRECTORY_SEPARATOR.'prod.log';
        $statePath = $this->tmpDir.DIRECTORY_SEPARATOR.'var'.DIRECTORY_SEPARATOR.'telemetry'.DIRECTORY_SEPARATOR.'state'.DIRECTORY_SEPARATOR.'logs.offset.json';
        $this->createLogFile($logPath, 120);
        $this->writeLogState($statePath, $logPath, 120);

        $tester = new CommandTester($this->createCommand(0, 0, 100));
        $result = $tester->execute(['--dry-run' => true]);

        self::assertSame(0, $result);
        self::assertSame(120, filesize($logPath));
        self::assertStringContainsString('would truncate log source', $tester->getDisplay());
    }

    private function createCommand(int $maxAgeSeconds, int $maxTotalBytes, int $logMaxBytes): TelemetryGcCommand
    {
        return new TelemetryGcCommand(
            $this->tmpDir,
            'var/telemetry/traces',
            'var/log/prod.log',
            'var/telemetry/state/logs.offset.json',
            $maxAgeSeconds,
            $maxTotalBytes,
            $logMaxBytes,
        );
    }

    private function createTraceFile(string $name, int $size, int $mtime): string
    {
        $path = $this->spoolDir.DIRECTORY_SEPARATOR.$name;
        file_put_contents($path, str_repeat('x', $size));
        touch($path, $mtime);

        return $path;
    }

    private function createLogFile(string $path, int $size): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($path, str_repeat('x', $size));
    }

    private function writeLogState(string $statePath, string $logPath, int $offset): void
    {
        $dir = dirname($statePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $stat = stat($logPath);
        self::assertIsArray($stat);

        file_put_contents($statePath, json_encode([
            'inode' => (string) $stat['ino'],
            'offset' => $offset,
            'updated_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ], JSON_UNESCAPED_SLASHES));
    }

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);
        if (false === $entries) {
            return;
        }

        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $item = $path.DIRECTORY_SEPARATOR.$entry;
            if (is_dir($item)) {
                $this->removeDir($item);
            } else {
                @unlink($item);
            }
        }

        @rmdir($path);
    }
}
