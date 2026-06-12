# Shared Hosting Observability Bundle

Reusable Symfony bundle for apps running in constrained/shared environments where you can write local files and run cron, but cannot run a sidecar OpenTelemetry Collector.

It provides:

- OTLP/HTTP log shipping from a JSON-line Monolog file;
- OTLP/HTTP trace shipping from a local JSONL trace spool;
- OTLP/HTTP metric shipping from an in-process cache-backed metrics registry;
- Prometheus text rendering for the same metrics registry;
- optional generic request completion logging;
- cron-driven synthetic HTTP checks;
- Monolog trace correlation fields (`extra.trace_id`, `extra.span_id`).

Request handling never performs remote telemetry network I/O. Requests write regular logs, update cache metrics, and append sampled traces to a local spool. Console commands ship data later.

## Install

Requires PHP 8.4+ and Symfony 8.

Use it as a normal Composer package:

```bash
composer require palliis/shared-hosting-observability-bundle
```

If the package is not registered on Packagist yet, add the public VCS repository to your application first:

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "git@github.com:palliis/shared-hosting-observability-bundle.git"
    }
  ],
  "require": {
    "palliis/shared-hosting-observability-bundle": "^0.1"
  }
}
```

If Symfony Flex does not auto-register the bundle, add it manually:

```php
// config/bundles.php
return [
    Palliis\SharedHostingObservabilityBundle\SharedHostingObservabilityBundle::class => ['all' => true],
];
```

## Configuration

```yaml
# config/packages/shared_hosting_observability.yaml
shared_hosting_observability:
  service_name: my-api
  service_namespace: my-company
  environment: '%kernel.environment%'
  metric_prefix: sho

  logs:
    source_path: '%kernel.logs_dir%/%kernel.environment%.log'
    state_path: 'var/telemetry/state/logs.offset.json'
    otlp_endpoint: '%env(default:empty_string:TELEMETRY_LOGS_OTLP_ENDPOINT)%'
    otlp_headers: '%env(default:empty_string:TELEMETRY_LOGS_OTLP_HEADERS)%'
    otlp_bearer_token: '%env(default:empty_string:TELEMETRY_LOGS_OTLP_BEARER_TOKEN)%'
    format: 'otlp_protobuf' # otlp_protobuf, otlp_json, or jsonl
    timeout_seconds: 5
    max_lines: 1000
    max_bytes: 1000000
    gc_max_bytes: 52428800
    request_logging_enabled: false
    request_logger_service: '' # optional service id, e.g. monolog.logger.request
    request_excluded_path_regex: '#^/(api/v1/health|api/v1/telemetry/metrics)$#'
    request_excluded_routes: ['app_health', 'app_telemetry_metrics']
    request_ignored_status_codes: [404, 405]

  traces:
    enabled: true
    spool_dir: 'var/telemetry/traces'
    excluded_path_regex: '#^/(api/v1/health|api/v1/telemetry/metrics)$#'
    sample_rate: 0.01
    error_sample_rate: 1.0
    slow_threshold_ms: 2500
    max_spans: 250
    max_spool_bytes: 52428800
    otlp_endpoint: '%env(default:empty_string:TELEMETRY_TRACES_OTLP_ENDPOINT)%'
    otlp_headers: '%env(default:empty_string:TELEMETRY_TRACES_OTLP_HEADERS)%'
    otlp_bearer_token: '%env(default:empty_string:TELEMETRY_TRACES_OTLP_BEARER_TOKEN)%'
    otlp_timeout_seconds: 5
    ship_min_file_age_seconds: 60
    ship_batch_size: 100
    gc_max_age_seconds: 7200
    gc_max_total_bytes: 52428800

  metrics:
    cache_pool: cache.app
    otlp_endpoint: '%env(default:empty_string:TELEMETRY_METRICS_OTLP_ENDPOINT)%'
    otlp_headers: '%env(default:empty_string:TELEMETRY_METRICS_OTLP_HEADERS)%'
    otlp_bearer_token: '%env(default:empty_string:TELEMETRY_METRICS_OTLP_BEARER_TOKEN)%'
    otlp_timeout_seconds: 5

  synthetics:
    base_url: '%env(default:empty_string:TELEMETRY_SYNTHETIC_BASE_URL)%'
    api_key: '%env(default:empty_string:MONITORING_API_KEY)%'
    timeout_seconds: 8
    checks:
      health: /api/v1/health
      telemetry_status: /api/v1/telemetry/status
```

`synthetics.checks` can also be a comma-separated string for env-only setups:

```dotenv
TELEMETRY_SYNTHETIC_CHECKS='health=/api/v1/health,telemetry_status=/api/v1/telemetry/status'
```

OTLP header strings are semicolon-separated. Bearer tokens are added as `Authorization: Bearer ...` unless the headers already contain `Authorization`.

```dotenv
TELEMETRY_LOGS_OTLP_HEADERS='X-Scope-OrgID=tenant-a'
TELEMETRY_TRACES_OTLP_BEARER_TOKEN='token'
TELEMETRY_METRICS_OTLP_BEARER_TOKEN='token'
```

## Grafana Cloud OTLP

Use the Grafana Cloud OTLP endpoints for logs, traces, and metrics, usually ending in `/v1/logs`, `/v1/traces`, and `/v1/metrics`. Put tenant-specific headers in `*_OTLP_HEADERS` and tokens in `*_OTLP_BEARER_TOKEN`.

The bundle emits OTLP JSON for traces and metrics. Logs default to OTLP protobuf because Grafana OTLP log ingestion commonly expects protobuf; switch to `otlp_json` only when your backend supports JSON OTLP logs.

## Cron

```cron
* * * * * cd /path/to/app && php bin/console shared-hosting-observability:ship-logs --env=prod --no-debug --quiet
* * * * * cd /path/to/app && php bin/console shared-hosting-observability:ship-traces --env=prod --no-debug --quiet
* * * * * cd /path/to/app && php bin/console shared-hosting-observability:ship-metrics --env=prod --no-debug --quiet
*/5 * * * * cd /path/to/app && php bin/console shared-hosting-observability:synthetic-check --env=prod --no-debug --quiet
*/15 * * * * cd /path/to/app && php bin/console shared-hosting-observability:gc --env=prod --no-debug --quiet
```

Dry-run checks:

```bash
php bin/console shared-hosting-observability:ship-logs --env=prod --no-debug --dry-run
php bin/console shared-hosting-observability:ship-traces --env=prod --no-debug --dry-run
php bin/console shared-hosting-observability:gc --env=prod --no-debug --dry-run
```

## Application Metrics Endpoint

Inject `Palliis\SharedHostingObservabilityBundle\Metrics\MetricsRegistry` and append its lines to your Prometheus endpoint:

```php
$lines = array_merge($lines, $sharedHostingMetrics->renderPrometheusLines());
```

Default Prometheus metrics use the `sho_` prefix:

- `sho_provider_requests_total`
- `sho_provider_request_duration_seconds`
- `sho_synthetic_up`
- `sho_synthetic_duration_seconds`
- `sho_synthetic_status_code`
- `sho_synthetic_last_checked_timestamp_seconds`
- `sho_synthetic_checks_total`
- `sho_synthetic_failures_total`

Set `metric_prefix: motometeo` if an existing app already has dashboards or alerts using the old `motometeo_*` names.

## Provider Spans And Metrics

Record upstream/provider calls from application services:

```php
use Palliis\SharedHostingObservabilityBundle\Metrics\MetricsRegistry;

$startedAt = microtime(true);
// call provider
$metrics->recordProviderRequest('weather', 'openmeteo', microtime(true) - $startedAt, $statusCode, $success);
```

This updates Prometheus/OTLP metrics and, when a sampled request trace is active, adds a provider client span.

## Log Correlation

The bundle registers a Monolog processor that adds `extra.trace_id` and `extra.span_id` while a trace is active. Keep app-specific Monolog processors for user, device, route, or client metadata.

Enable `logs.request_logging_enabled` if the app does not already emit request completion logs. This listener records method, path, route, status code, duration, and response size.

## Migration From App-Local Classes

Replace app-local dependencies like `App\Monitoring\MetricsRegistry` with `Palliis\SharedHostingObservabilityBundle\Metrics\MetricsRegistry` in provider services and controllers.

Remove duplicated app-local telemetry commands/services only after the bundle is installed and registered. The package commands are named `shared-hosting-observability:*`, not `app:telemetry:*`.

## Development

```bash
composer install
composer test
composer analyse
composer cs:check
```
