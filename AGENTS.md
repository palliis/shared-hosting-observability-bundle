# AGENTS.md

## Project Overview

This repository is a Symfony bundle for shared-hosting observability. It avoids request-time telemetry network I/O by writing local log/trace/metric signals and shipping them later from console commands.

Core areas:

- `src/DependencyInjection/` defines configuration and manually wires all bundle services.
- `src/Command/` contains cron-oriented telemetry shippers, synthetic checks, and telemetry garbage collection.
- `src/Telemetry/` contains trace spooling, OTLP JSON payload builders, the custom OTLP protobuf log payload builder, and header parsing.
- `src/Metrics/` contains the cache-backed metrics registry and Prometheus rendering.
- `src/EventListener/` contains request tracing and optional request completion logging.
- `tests/` contains PHPUnit coverage for trace recording, OTLP payload shape, auth header behavior, metrics rendering, and telemetry GC.

## Required Commands

Run these before handing off meaningful code changes:

```bash
composer validate --strict
composer cs:check
composer analyse
composer test
```

If `vendor/` is missing, install dependencies first:

```bash
composer install
```

CI uses `composer update` against this support matrix:

- PHP 8.3 with Symfony 7.4
- PHP 8.4 with Symfony 8
- PHP 8.5 with Symfony 8

## Coding Style

- Follow the existing PHP 8.3+ style and the Symfony CS Fixer config in `.php-cs-fixer.dist.php`.
- Keep classes `final` unless there is a concrete extension point.
- Prefer constructor injection and explicit service wiring in `SharedHostingObservabilityExtension`.
- Keep code ASCII unless an edited file already requires otherwise.
- Do not introduce request-time telemetry network calls. Request paths may write local files, update cache metrics, and append traces only.
- Observability failures in listeners, trace recording, and metric side effects should not break application requests.

## Service Wiring

Service registration is manual. When changing constructor signatures or config options, update all of these together:

- `src/DependencyInjection/Configuration.php`
- `src/DependencyInjection/SharedHostingObservabilityExtension.php`
- `README.md` configuration examples
- Tests that instantiate commands or services directly

Public aliases currently expected by consumers:

- `shared_hosting_observability.trace_recorder`
- `shared_hosting_observability.metrics_registry`

## Telemetry Behavior

- Logs are tailed from a Monolog JSONL file using an inode/offset state file.
- Log shipping supports `otlp_protobuf`, `otlp_json`, and `jsonl`; the default is `otlp_protobuf`.
- Trace recording writes JSONL spool files grouped by UTC minute.
- Trace shipping sends OTLP JSON and deletes a spool file only after all batches from that file are sent.
- Metrics are accumulated in a PSR cache item under a file lock and can be rendered as Prometheus lines or shipped as OTLP JSON.
- Bearer token config should not override an explicit `Authorization` header in OTLP header strings.

## Testing Notes

- Use `MockHttpClient` and `CommandTester` for command behavior.
- Keep filesystem tests under unique temporary directories and clean them up in `tearDown()`.
- Add regression tests for state-file, lock, and spool cleanup changes.
- Payload tests should assert semantic shape, not brittle full JSON strings, unless testing exact wire formatting.

## Release And Automation

- PR titles are checked against Conventional Commits.
- Release Please manages `CHANGELOG.md` and tags from `main`.
- Renovate is configured to automerge non-major updates after checks pass.
- `composer.lock` is ignored because this is a library package.

## Areas Needing Attention

- Keep `metrics.lock_path` writable anywhere the metrics registry is used; losing the lock falls back to best-effort cache updates.
- The custom `OtlpProtobufLogPayloadBuilder` has a lightweight decode test. Add a real backend fixture or generated protobuf decoder test before larger wire-format changes.
- Container compilation is covered by a focused extension test. Expand it when changing config defaults, aliases, command registration, or listener tags.
- `TraceSpoolWriter` serializes size checks and appends with a spool lock. Preserve that behavior when changing spool file naming or retention.
- `HttpHeaderParser` supports escaped semicolons as `\;`, but it is still intentionally a small parser for env-friendly header strings.
- README examples and tests still include some legacy `motometeo` names. That may be intentional migration context, but avoid adding new legacy names unless needed for compatibility examples.
