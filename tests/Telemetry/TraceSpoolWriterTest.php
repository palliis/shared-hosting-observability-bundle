<?php

namespace Palliis\SharedHostingObservabilityBundle\Tests\Telemetry;

use Palliis\SharedHostingObservabilityBundle\Telemetry\TraceSpoolWriter;
use PHPUnit\Framework\TestCase;

final class TraceSpoolWriterTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sho_trace_spool_'.bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->tempDir) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            @unlink($this->tempDir.DIRECTORY_SEPARATOR.$entry);
        }
        @rmdir($this->tempDir);
    }

    public function testAppendSkipsTraceThatWouldExceedSpoolByteCap(): void
    {
        $writer = new TraceSpoolWriter(dirname(__DIR__, 2), $this->tempDir, 20);

        $writer->append([
            'resource' => ['service.name' => 'test'],
            'spans' => [['name' => 'larger-than-cap']],
        ]);

        self::assertSame([], glob($this->tempDir.DIRECTORY_SEPARATOR.'*.jsonl') ?: []);
    }

    public function testAppendAllowsTraceWithinSpoolByteCap(): void
    {
        $writer = new TraceSpoolWriter(dirname(__DIR__, 2), $this->tempDir, 200);

        $writer->append(['spans' => []]);

        self::assertCount(1, glob($this->tempDir.DIRECTORY_SEPARATOR.'*.jsonl') ?: []);
    }
}
