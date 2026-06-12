<?php

namespace Palliis\SharedHostingObservabilityBundle\Command;

use Palliis\SharedHostingObservabilityBundle\Telemetry\HttpHeaderParser;
use Palliis\SharedHostingObservabilityBundle\Telemetry\OtlpLogPayloadBuilder;
use Palliis\SharedHostingObservabilityBundle\Telemetry\OtlpProtobufLogPayloadBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(name: 'shared-hosting-observability:ship-logs', description: 'Ship log records to an HTTP telemetry backend.')]
final class ShipLogsCommand extends Command
{
    private const FORMAT_JSONL = 'jsonl';
    private const FORMAT_OTLP_JSON = 'otlp_json';
    private const FORMAT_OTLP_PROTOBUF = 'otlp_protobuf';

    private string $format;

    public function __construct(
        private HttpClientInterface $httpClient,
        private OtlpLogPayloadBuilder $payloadBuilder,
        private OtlpProtobufLogPayloadBuilder $protobufPayloadBuilder,
        private HttpHeaderParser $headerParser,
        private string $projectDir,
        private string $sourcePath,
        private string $statePath,
        private string $endpoint,
        private string $headers,
        private string $bearerToken,
        private float $timeoutSeconds,
        private int $maxLines,
        private int $maxBytes,
        string $format = self::FORMAT_OTLP_PROTOBUF,
    ) {
        parent::__construct();
        $this->timeoutSeconds = max(0.1, $this->timeoutSeconds);
        $this->maxLines = max(1, $this->maxLines);
        $this->maxBytes = max(1024, $this->maxBytes);
        $this->format = $this->normalizeFormat($format);
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Read the next batch without sending or advancing the offset.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('' === trim($this->endpoint)) {
            $io->error('The logs.otlp_endpoint option is not configured.');

            return Command::INVALID;
        }

        if (str_starts_with($this->sourcePath, 'php://')) {
            $io->error('The logs.source_path option must point to a real file, not php://stderr or another stream.');

            return Command::INVALID;
        }

        $sourcePath = $this->resolvePath($this->sourcePath);
        if (!is_file($sourcePath)) {
            $io->warning(sprintf('Log source file does not exist: %s', $sourcePath));

            return Command::SUCCESS;
        }

        $statePath = $this->resolvePath($this->statePath);
        $stateDir = dirname($statePath);
        if (!is_dir($stateDir) && !@mkdir($stateDir, 0775, true) && !is_dir($stateDir)) {
            $io->error(sprintf('Cannot create log shipper state directory: %s', $stateDir));

            return Command::FAILURE;
        }

        $lock = $this->openLock($stateDir);
        if (!$lock) {
            $io->warning('Another log shipper is already running.');

            return Command::SUCCESS;
        }

        try {
            $state = $this->loadState($statePath);
            $stat = stat($sourcePath);
            if (false === $stat) {
                $io->error(sprintf('Cannot stat log source file: %s', $sourcePath));

                return Command::FAILURE;
            }

            $inode = (string) $stat['ino'];
            $size = (int) $stat['size'];
            $offset = (int) ($state['offset'] ?? 0);
            if (($state['inode'] ?? null) !== $inode || $offset > $size) {
                $offset = 0;
            }

            [$records, $newOffset] = $this->readBatch($sourcePath, $offset);
            if ([] === $records) {
                $this->saveState($statePath, $inode, $offset);
                $io->success('No new log lines to ship.');

                return Command::SUCCESS;
            }

            $dryRun = (bool) $input->getOption('dry-run');
            if (!$dryRun) {
                $this->sendBatch($records);
                $this->saveState($statePath, $inode, $newOffset);
            }

            $io->success(sprintf('%s %d log record(s).', $dryRun ? 'Prepared' : 'Shipped', count($records)));

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
        $lock = @fopen($dir.DIRECTORY_SEPARATOR.'.ship-logs.lock', 'c');
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
     * @return array{inode?: string, offset?: int}
     */
    private function loadState(string $statePath): array
    {
        if (!is_file($statePath)) {
            return [];
        }

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

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    private function readBatch(string $sourcePath, int $offset): array
    {
        $handle = fopen($sourcePath, 'rb');
        if (!$handle) {
            throw new \RuntimeException(sprintf('Cannot open log source file: %s', $sourcePath));
        }

        try {
            if ($offset > 0) {
                fseek($handle, $offset);
            }

            $records = [];
            $lineCount = 0;
            $bytes = 0;
            $newOffset = $offset;
            while (!feof($handle) && $lineCount < $this->maxLines && $bytes < $this->maxBytes) {
                $line = fgets($handle);
                if (false === $line) {
                    break;
                }

                if (!str_ends_with($line, "\n") && feof($handle)) {
                    break;
                }

                $decoded = json_decode(rtrim($line, "\r\n"), true);
                if (is_array($decoded)) {
                    $records[] = $decoded;
                    $bytes += strlen($line);
                    ++$lineCount;
                }
                $newOffset = (int) ftell($handle);
            }

            return [$records, $newOffset];
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $records
     */
    private function sendBatch(array $records): void
    {
        $body = $this->buildBody($records);

        $response = $this->httpClient->request('POST', $this->endpoint, [
            'headers' => $this->buildHeaders(),
            'body' => $body,
            'timeout' => $this->timeoutSeconds,
            'max_duration' => $this->timeoutSeconds,
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException(sprintf('Log backend returned HTTP %d: %s', $statusCode, substr($response->getContent(false), 0, 500)));
        }
    }

    /**
     * @return array<string, string>
     */
    private function buildHeaders(): array
    {
        $headers = array_merge(
            ['Content-Type' => $this->contentType()],
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

    /**
     * @param array<int, array<string, mixed>> $records
     */
    private function buildBody(array $records): string
    {
        if (self::FORMAT_OTLP_PROTOBUF === $this->format) {
            return $this->protobufPayloadBuilder->build($records);
        }

        if (self::FORMAT_OTLP_JSON === $this->format) {
            $body = json_encode($this->payloadBuilder->build($records), JSON_UNESCAPED_SLASHES);
            if (false === $body) {
                throw new \RuntimeException('Failed to encode OTLP log payload.');
            }

            return $body;
        }

        $lines = [];
        foreach ($records as $record) {
            $line = json_encode($record, JSON_UNESCAPED_SLASHES);
            if (false === $line) {
                throw new \RuntimeException('Failed to encode JSONL log payload.');
            }
            $lines[] = $line;
        }

        return implode("\n", $lines)."\n";
    }

    private function contentType(): string
    {
        return match ($this->format) {
            self::FORMAT_JSONL => 'application/stream+json',
            self::FORMAT_OTLP_JSON => 'application/json',
            self::FORMAT_OTLP_PROTOBUF => 'application/x-protobuf',
            default => throw new \LogicException(sprintf('Unsupported telemetry log format "%s".', $this->format)),
        };
    }

    private function normalizeFormat(string $format): string
    {
        $format = strtolower(trim($format));

        return match ($format) {
            self::FORMAT_JSONL, 'json_lines', 'ndjson' => self::FORMAT_JSONL,
            self::FORMAT_OTLP_JSON, 'otlp-json', 'otlp' => self::FORMAT_OTLP_JSON,
            self::FORMAT_OTLP_PROTOBUF, 'otlp-protobuf', 'protobuf', 'proto' => self::FORMAT_OTLP_PROTOBUF,
            default => throw new \InvalidArgumentException(sprintf('Unsupported TELEMETRY_LOGS_FORMAT "%s". Use "otlp_protobuf", "otlp_json", or "jsonl".', $format)),
        };
    }

    private function resolvePath(string $path): string
    {
        if (preg_match('#^(?:[A-Za-z]:[\\\\/]|/)#', $path)) {
            return $path;
        }

        return $this->projectDir.DIRECTORY_SEPARATOR.$path;
    }
}
