<?php

namespace Palliis\SharedHostingObservabilityBundle\Command;

use Palliis\SharedHostingObservabilityBundle\Metrics\MetricsRegistry;
use Palliis\SharedHostingObservabilityBundle\Telemetry\HttpHeaderParser;
use Palliis\SharedHostingObservabilityBundle\Telemetry\OtlpMetricPayloadBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(name: 'shared-hosting-observability:ship-metrics', description: 'Ship metrics to an OTLP/HTTP metrics backend.')]
final class ShipMetricsCommand extends Command
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private MetricsRegistry $metricsRegistry,
        private OtlpMetricPayloadBuilder $payloadBuilder,
        private HttpHeaderParser $headerParser,
        private string $endpoint,
        private string $headers,
        private string $bearerToken,
        private float $timeoutSeconds,
        private string $serviceName,
        private string $environment,
        private string $metricPrefix,
    ) {
        parent::__construct();
        $this->timeoutSeconds = max(0.1, $this->timeoutSeconds);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('' === trim($this->endpoint)) {
            $io->error('The metrics.otlp_endpoint option is not configured.');

            return Command::INVALID;
        }

        $snapshot = $this->metricsRegistry->snapshot();
        $body = json_encode($this->payloadBuilder->build($snapshot, $this->serviceName, $this->environment, $this->metricPrefix), JSON_UNESCAPED_SLASHES);
        if (false === $body) {
            $io->error('Failed to encode OTLP metrics payload.');

            return Command::FAILURE;
        }

        $response = $this->httpClient->request('POST', $this->endpoint, [
            'headers' => $this->buildHeaders(),
            'body' => $body,
            'timeout' => $this->timeoutSeconds,
            'max_duration' => $this->timeoutSeconds,
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 300) {
            $io->error(sprintf('Metrics backend returned HTTP %d: %s', $statusCode, substr($response->getContent(false), 0, 500)));

            return Command::FAILURE;
        }

        $io->success('Shipped OTLP metrics payload.');

        return Command::SUCCESS;
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
}
