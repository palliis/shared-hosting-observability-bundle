<?php

namespace Palliis\SharedHostingObservabilityBundle\Telemetry;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class TraceRecorder
{
    private const TRACEPARENT_PATTERN = '/^[0-9a-f]{2}-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/i';

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $spans = [];

    /**
     * @var array{trace_id: string, root_span_id: string, parent_span_id: string|null, inbound_sampled: bool, start_time_unix_nano: int}|null
     */
    private ?array $context = null;
    private bool $providerFailure = false;
    private bool $droppedSpans = false;

    public function __construct(
        private bool $enabled,
        private string $serviceName,
        private string $serviceNamespace,
        private string $environment,
        private string $excludedPathRegex,
        private float $defaultSampleRate,
        private float $errorSampleRate,
        private int $slowThresholdMs,
        private int $maxSpansPerTrace,
        private TraceSpoolWriter $spoolWriter,
    ) {
        $this->defaultSampleRate = $this->clampSampleRate($this->defaultSampleRate);
        $this->errorSampleRate = $this->clampSampleRate($this->errorSampleRate);
        $this->maxSpansPerTrace = max(0, $this->maxSpansPerTrace);
    }

    public function startRequest(Request $request): void
    {
        $this->context = null;
        $this->spans = [];
        $this->providerFailure = false;
        $this->droppedSpans = false;

        if (!$this->enabled || $this->isExcludedPath($request->getPathInfo())) {
            return;
        }

        [$traceId, $parentSpanId, $inboundSampled] = $this->parseTraceparent($request->headers->get('traceparent'));
        $rootSpanId = $this->generateSpanId();

        $this->context = [
            'trace_id' => $traceId ?? $this->generateTraceId(),
            'root_span_id' => $rootSpanId,
            'parent_span_id' => $parentSpanId,
            'inbound_sampled' => $inboundSampled,
            'start_time_unix_nano' => $this->nowNanos(),
        ];

        $request->attributes->set('_telemetry_trace_id', $this->context['trace_id']);
        $request->attributes->set('_telemetry_span_id', $rootSpanId);
    }

    public function recordProviderRequest(
        string $serviceType,
        string $provider,
        float $durationSeconds,
        int $statusCode,
        bool $success,
    ): void {
        if (!$this->context) {
            return;
        }

        if (!$success) {
            $this->providerFailure = true;
        }

        $endTime = $this->nowNanos();
        $durationNanos = max(0, (int) round($durationSeconds * 1_000_000_000));
        $startTime = max(0, $endTime - $durationNanos);

        $attributes = [
            'sho.service_type' => $serviceType,
            'server.address' => $provider,
            'http.response.status_code' => $statusCode,
            'error' => !$success,
        ];

        $this->addSpan([
            'trace_id' => $this->context['trace_id'],
            'span_id' => $this->generateSpanId(),
            'parent_span_id' => $this->context['root_span_id'],
            'name' => sprintf('provider.%s.%s', $serviceType, $provider),
            'kind' => 'SPAN_KIND_CLIENT',
            'start_time_unix_nano' => $startTime,
            'end_time_unix_nano' => $endTime,
            'attributes' => $attributes,
            'status' => $success ? null : [
                'code' => 'STATUS_CODE_ERROR',
                'message' => sprintf('Provider request failed with status %d', $statusCode),
            ],
        ]);
    }

    public function completeRequest(Request $request, Response $response): void
    {
        if (!$this->context) {
            return;
        }

        $endTime = $this->nowNanos();
        $durationMs = max(0, (int) round(($endTime - $this->context['start_time_unix_nano']) / 1_000_000));
        $failed = $this->providerFailure || $response->getStatusCode() >= 500;
        $slow = $durationMs >= $this->slowThresholdMs;

        if (!$this->shouldSample($failed, $slow)) {
            $this->context = null;
            $this->spans = [];

            return;
        }

        $attributes = [
            'http.request.method' => $request->getMethod(),
            'url.path' => $request->getPathInfo(),
            'http.response.status_code' => $response->getStatusCode(),
            'http.route' => (string) $request->attributes->get('_route', ''),
            'sho.trace.duration_ms' => $durationMs,
            'sho.trace.slow' => $slow,
            'sho.trace.provider_failure' => $this->providerFailure,
            'sho.trace.spans_dropped' => $this->droppedSpans,
        ];

        $this->addSpan([
            'trace_id' => $this->context['trace_id'],
            'span_id' => $this->context['root_span_id'],
            'parent_span_id' => $this->context['parent_span_id'],
            'name' => (string) $request->attributes->get('_route', $request->getMethod().' '.$request->getPathInfo()),
            'kind' => 'SPAN_KIND_SERVER',
            'start_time_unix_nano' => $this->context['start_time_unix_nano'],
            'end_time_unix_nano' => $endTime,
            'attributes' => $attributes,
            'status' => $failed ? [
                'code' => 'STATUS_CODE_ERROR',
                'message' => sprintf('Request completed with HTTP %d', $response->getStatusCode()),
            ] : null,
        ]);

        $trace = [
            'resource' => [
                'service.name' => $this->serviceName,
                'service.namespace' => $this->serviceNamespace,
                'deployment.environment' => $this->environment,
            ],
            'spans' => $this->spans,
        ];

        $this->appendTraceToSpool($trace);
        $this->context = null;
        $this->spans = [];
    }

    public function recordSyntheticCheck(
        string $name,
        string $url,
        float $durationSeconds,
        int $statusCode,
        bool $success,
        ?string $error,
    ): void {
        if (!$this->enabled) {
            return;
        }

        $startTime = $this->nowNanos();
        $durationNanos = max(0, (int) round($durationSeconds * 1_000_000_000));
        $endTime = max($startTime, $startTime + $durationNanos);

        $trace = [
            'resource' => [
                'service.name' => $this->serviceName,
                'service.namespace' => $this->serviceNamespace,
                'deployment.environment' => $this->environment,
            ],
            'spans' => [[
                'trace_id' => $this->generateTraceId(),
                'span_id' => $this->generateSpanId(),
                'name' => sprintf('synthetic.%s', $name),
                'kind' => 'SPAN_KIND_CLIENT',
                'start_time_unix_nano' => $startTime,
                'end_time_unix_nano' => $endTime,
                'attributes' => [
                    'http.request.method' => 'GET',
                    'http.response.status_code' => $statusCode,
                    'url.full' => $url,
                    'synthetic.check.name' => $name,
                    'error' => !$success,
                    'synthetic.error' => $error,
                ],
                'status' => $success ? null : [
                    'code' => 'STATUS_CODE_ERROR',
                    'message' => $error ?: sprintf('Synthetic check failed with HTTP %d', $statusCode),
                ],
            ]],
        ];

        $this->appendTraceToSpool($trace);
    }

    public function getCurrentTraceId(): ?string
    {
        return $this->context['trace_id'] ?? null;
    }

    public function getCurrentSpanId(): ?string
    {
        return $this->context['root_span_id'] ?? null;
    }

    /**
     * @param array<string, mixed> $span
     */
    private function addSpan(array $span): void
    {
        if (count($this->spans) >= $this->maxSpansPerTrace) {
            $this->droppedSpans = true;

            return;
        }

        $this->spans[] = $span;
    }

    /**
     * @param array<string, mixed> $trace
     */
    private function appendTraceToSpool(array $trace): void
    {
        $this->spoolWriter->append($trace);
    }

    private function shouldSample(bool $failed, bool $slow): bool
    {
        if ($this->context['inbound_sampled'] ?? false) {
            return true;
        }

        if ($failed) {
            return $this->randomFloat() <= $this->errorSampleRate;
        }

        if ($slow) {
            return true;
        }

        return $this->randomFloat() <= $this->defaultSampleRate;
    }

    /**
     * @return array{0: string|null, 1: string|null, 2: bool}
     */
    private function parseTraceparent(?string $traceparent): array
    {
        if (!$traceparent || !preg_match(self::TRACEPARENT_PATTERN, $traceparent, $matches)) {
            return [null, null, false];
        }

        $traceId = strtolower($matches[1]);
        $spanId = strtolower($matches[2]);
        $flags = strtolower($matches[3]);

        if (str_repeat('0', 32) === $traceId || str_repeat('0', 16) === $spanId) {
            return [null, null, false];
        }

        return [$traceId, $spanId, 1 === (hexdec($flags) & 1)];
    }

    private function isExcludedPath(string $path): bool
    {
        if ('' === $this->excludedPathRegex) {
            return false;
        }

        return 1 === @preg_match($this->excludedPathRegex, $path);
    }

    private function generateTraceId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function generateSpanId(): string
    {
        return bin2hex(random_bytes(8));
    }

    private function nowNanos(): int
    {
        return (int) round(microtime(true) * 1_000_000_000);
    }

    private function randomFloat(): float
    {
        return random_int(0, PHP_INT_MAX) / PHP_INT_MAX;
    }

    private function clampSampleRate(float $value): float
    {
        return min(1.0, max(0.0, $value));
    }
}
