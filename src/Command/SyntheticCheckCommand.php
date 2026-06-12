<?php

namespace Palliis\SharedHostingObservabilityBundle\Command;

use Palliis\SharedHostingObservabilityBundle\Metrics\MetricsRegistry;
use Palliis\SharedHostingObservabilityBundle\Telemetry\TraceRecorder;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(name: 'shared-hosting-observability:synthetic-check', description: 'Run configured HTTP synthetic checks and emit log and metric signals.')]
final class SyntheticCheckCommand extends Command
{
    /**
     * @param array<string, string>|string $checks
     */
    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private MetricsRegistry $metrics,
        private ?TraceRecorder $traceRecorder,
        private string $baseUrl,
        private array|string $checks,
        private string $monitoringApiKey,
        private float $timeoutSeconds,
    ) {
        parent::__construct();
        $this->timeoutSeconds = max(0.1, $this->timeoutSeconds);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $checks = $this->parseChecks($this->checks);
        if ([] === $checks) {
            $io->error('The synthetics.checks option does not contain any checks.');

            return Command::INVALID;
        }

        $failures = 0;
        foreach ($checks as $name => $pathOrUrl) {
            $url = $this->buildUrl($pathOrUrl);
            $startedAt = microtime(true);
            $statusCode = 0;
            $success = false;
            $error = null;

            try {
                $response = $this->httpClient->request('GET', $url, [
                    'headers' => $this->buildHeaders(),
                    'timeout' => $this->timeoutSeconds,
                    'max_duration' => $this->timeoutSeconds,
                ]);
                $statusCode = $response->getStatusCode();
                $success = $statusCode >= 200 && $statusCode < 400;
                if (!$success) {
                    $error = substr($response->getContent(false), 0, 300);
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }

            $durationSeconds = microtime(true) - $startedAt;
            $this->metrics->recordSyntheticCheck($name, $durationSeconds, $statusCode, $success);
            try {
                $this->traceRecorder?->recordSyntheticCheck($name, $url, $durationSeconds, $statusCode, $success, $error);
            } catch (\Throwable) {
                // Observability must never break synthetic checks.
            }

            $context = [
                'service_type' => 'synthetic',
                'check' => $name,
                'url' => $url,
                'status_code' => $statusCode,
                'success' => $success,
                'duration_ms' => round($durationSeconds * 1000, 2),
                'error_type' => $success ? null : 'synthetic_failure',
                'error' => $error,
            ];
            $this->logger->log($success ? 'info' : 'error', 'Synthetic check completed', $context);

            if (!$success) {
                ++$failures;
            }
        }

        if ($failures > 0) {
            $io->error(sprintf('%d synthetic check(s) failed.', $failures));

            return Command::FAILURE;
        }

        $io->success(sprintf('%d synthetic check(s) passed.', count($checks)));

        return Command::SUCCESS;
    }

    /**
     * @param array<string, string>|string $checks
     *
     * @return array<string, string>
     */
    private function parseChecks(array|string $checks): array
    {
        if (is_array($checks)) {
            $parsed = [];
            foreach ($checks as $name => $pathOrUrl) {
                $name = trim((string) $name);
                $pathOrUrl = trim($pathOrUrl);
                if ('' !== $name && '' !== $pathOrUrl) {
                    $parsed[$name] = $pathOrUrl;
                }
            }

            return $parsed;
        }

        $parsed = [];
        foreach (explode(',', $checks) as $entry) {
            $entry = trim($entry);
            if ('' === $entry) {
                continue;
            }

            if (str_contains($entry, '=')) {
                [$name, $path] = explode('=', $entry, 2);
                $name = trim($name);
                $path = trim($path);
            } else {
                $path = $entry;
                $name = trim($entry, '/');
                $name = str_replace(['/', '-'], '_', $name);
            }

            if ('' === $name || '' === $path) {
                continue;
            }

            $parsed[$name] = $path;
        }

        return $parsed;
    }

    /**
     * @return array<string, string>
     */
    private function buildHeaders(): array
    {
        if ('' === trim($this->monitoringApiKey)) {
            return [];
        }

        return ['X-API-KEY' => $this->monitoringApiKey];
    }

    private function buildUrl(string $pathOrUrl): string
    {
        if (preg_match('#^https?://#i', $pathOrUrl)) {
            return $pathOrUrl;
        }

        return rtrim($this->baseUrl, '/').'/'.ltrim($pathOrUrl, '/');
    }
}
