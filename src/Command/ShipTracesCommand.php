<?php

namespace Palliis\SharedHostingObservabilityBundle\Command;

use Palliis\SharedHostingObservabilityBundle\Telemetry\HttpHeaderParser;
use Palliis\SharedHostingObservabilityBundle\Telemetry\OtlpTracePayloadBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(name: 'shared-hosting-observability:ship-traces', description: 'Ship spooled traces to an OTLP/HTTP trace backend.')]
final class ShipTracesCommand extends Command
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private OtlpTracePayloadBuilder $payloadBuilder,
        private HttpHeaderParser $headerParser,
        private string $projectDir,
        private string $spoolDir,
        private string $endpoint,
        private string $headers,
        private string $bearerToken,
        private float $timeoutSeconds,
        private int $minFileAgeSeconds,
        private int $batchSize,
    ) {
        parent::__construct();
        $this->timeoutSeconds = max(0.1, $this->timeoutSeconds);
        $this->minFileAgeSeconds = max(0, $this->minFileAgeSeconds);
        $this->batchSize = max(1, $this->batchSize);
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Read and build batches without sending or deleting files.')
            ->addOption('limit-files', null, InputOption::VALUE_REQUIRED, 'Maximum number of spool files to process.', 20);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('' === trim($this->endpoint)) {
            $io->error('The traces.otlp_endpoint option is not configured.');

            return Command::INVALID;
        }

        $dir = $this->resolvePath($this->spoolDir);
        if (!is_dir($dir)) {
            $io->success('Trace spool directory does not exist yet.');

            return Command::SUCCESS;
        }

        $lock = $this->openLock($dir);
        if (!$lock) {
            $io->warning('Another trace shipper is already running.');

            return Command::SUCCESS;
        }

        try {
            $files = $this->findEligibleFiles($dir, (int) $input->getOption('limit-files'));
            if ([] === $files) {
                $io->success('No trace spool files are ready to ship.');

                return Command::SUCCESS;
            }

            $dryRun = (bool) $input->getOption('dry-run');
            $sentTraces = 0;
            $batchSize = max(1, $this->batchSize);
            foreach ($files as $file) {
                $traces = $this->readTraceFile($file);
                if ([] === $traces) {
                    if (!$dryRun) {
                        @unlink($file);
                    }
                    continue;
                }

                foreach (array_chunk($traces, $batchSize) as $batch) {
                    if (!$dryRun) {
                        $this->sendBatch($batch);
                    }
                    $sentTraces += count($batch);
                }

                if (!$dryRun) {
                    @unlink($file);
                }
            }

            $io->success(sprintf('%s %d trace(s) from %d file(s).', $dryRun ? 'Prepared' : 'Shipped', $sentTraces, count($files)));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @return resource|null
     */
    private function openLock(string $dir)
    {
        $lock = @fopen($dir.DIRECTORY_SEPARATOR.'.ship-traces.lock', 'c');
        if (!$lock) {
            return null;
        }

        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return null;
        }

        return $lock;
    }

    /**
     * @return string[]
     */
    private function findEligibleFiles(string $dir, int $limit): array
    {
        $files = glob($dir.DIRECTORY_SEPARATOR.'*.jsonl') ?: [];
        $now = time();
        $eligible = [];

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            $mtime = filemtime($file);
            if (false === $mtime || ($now - $mtime) < $this->minFileAgeSeconds) {
                continue;
            }

            $eligible[] = $file;
        }

        sort($eligible);

        return array_slice($eligible, 0, max(1, $limit));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readTraceFile(string $file): array
    {
        $traces = [];
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (false === $lines) {
            return [];
        }

        foreach ($lines as $line) {
            $trace = json_decode($line, true);
            if (!is_array($trace) || empty($trace['spans']) || !is_array($trace['spans'])) {
                continue;
            }

            $traces[] = $trace;
        }

        return $traces;
    }

    /**
     * @param array<int, array<string, mixed>> $batch
     */
    private function sendBatch(array $batch): void
    {
        $payload = $this->payloadBuilder->build($batch);
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (false === $body) {
            throw new \RuntimeException('Failed to encode OTLP trace payload.');
        }

        $response = $this->httpClient->request('POST', $this->endpoint, [
            'headers' => $this->buildHeaders(),
            'body' => $body,
            'timeout' => $this->timeoutSeconds,
            'max_duration' => $this->timeoutSeconds,
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException(sprintf('Trace backend returned HTTP %d: %s', $statusCode, substr($response->getContent(false), 0, 500)));
        }
    }

    /**
     * @return array<string, string>
     */
    private function buildHeaders(): array
    {
        $headers = array_merge(
            ['Content-Type' => 'application/json'],
            $this->headerParser->parse($this->headers),
        );

        if ('' !== trim($this->bearerToken)) {
            $hasAuthorization = false;
            foreach (array_keys($headers) as $name) {
                if (0 === strcasecmp($name, 'Authorization')) {
                    $hasAuthorization = true;
                    break;
                }
            }

            if (!$hasAuthorization) {
                $headers['Authorization'] = sprintf('Bearer %s', $this->bearerToken);
            }
        }

        return $headers;
    }

    private function resolvePath(string $path): string
    {
        if (preg_match('#^(?:[A-Za-z]:[\\\\/]|/)#', $path)) {
            return $path;
        }

        return $this->projectDir.DIRECTORY_SEPARATOR.$path;
    }
}
