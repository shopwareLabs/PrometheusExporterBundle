# Shopware Prometheus Exporter

The official **pull transport** for Shopware's telemetry metrics abstraction. Metrics emitted through
the core `Meter`/`Telemetry` API are stored in Redis and served in Prometheus text format on
`GET /api/_internal/prometheus`. The bundle ships no instrumentation of its own — every metric defined
in the core telemetry configuration arrives automatically.

## Requirements

- Shopware **6.7.12 or later** (first release with Telemetry v2). On 6.7 the experimental
  `TELEMETRY_METRICS` feature flag must be enabled.
- Redis, reachable through either the **phpredis** extension or **predis/predis**. Other clients
  (RedisCluster, RedisArray, Relay) are not supported for metric storage.

## Installation

```shell
composer require shopware/prometheus-exporter
```

Register the bundle in `config/bundles.php`:

```php
Shopware\PrometheusExporter\PrometheusExporterBundle::class => ['all' => true],
```

Enable telemetry and configure the bundle:

```yaml
# config/packages/prometheus_exporter.yaml
shopware:
    telemetry:
        metrics:
            enabled: true

prometheus_exporter:
    storage:
        redis_connection_name: 'telemetry'   # a name under shopware.redis.connections
    endpoint:
        auth_token: '%env(PROMETHEUS_AUTH_TOKEN)%'
        allowed_ips: []                      # disable the IP check when scraping remotely
```

```shell
# .env / environment (Shopware 6.7 only; 6.8 stabilizes the feature)
TELEMETRY_METRICS=1
```

## Configuration reference

```yaml
prometheus_exporter:
    storage:
        # Exactly one source is required; the container fails to build with neither.
        redis_connection_name: null   # name under shopware.redis.connections
        dsn: null                     # dedicated connection; takes priority over the name
        key_prefix: 'sw:metrics'      # prefix for all metric keys in Redis
    transport:
        write_mode: 'buffered'        # 'buffered' or 'direct', see below
    endpoint:
        allowed_ips: ['127.0.0.1', '::1']
        auth_token: null              # when set, scrapes need "Authorization: Bearer <token>"
    scrape_metrics_namespace: null    # null = inherit shopware.telemetry.metrics.namespace,
                                      # '' = no prefix, any other string = custom prefix
    scrape_providers:                 # host-local, computed live per scrape, all opt-in
        instance: false
        opcache: false
        php_fpm: false
        opensearch: false
```

### Storage

The Redis connection is resolved in this order:

1. `storage.dsn` — the bundle opens its own connection (same DSN dialect as
   `shopware.redis.connections`).
2. `storage.redis_connection_name` — reuses a connection managed by Shopware.

The resolved client must be a phpredis `\Redis` or a `Predis\Client`; anything else fails with a
configuration exception. Metric series are shared by the whole fleet through Redis: every application
server returns identical output, so **one scrape target is enough** (scraping all instances would
duplicate ingestion, not add information).

### Write modes

- `buffered` (default): emissions are buffered in memory and written to Redis when the framework
  flushes transports — after the response is sent, at command termination, and periodically in
  message workers. Keeps Redis I/O off the request critical path; metrics of a fatally crashed
  request are lost.
- `direct`: every emission is written immediately. One Redis round-trip per emission, for setups
  where that loss is unacceptable or for debugging.

### Endpoint protection

Every **configured** check must pass:

- `auth_token` set → the scrape must send `Authorization: Bearer <token>` (mismatch → 401).
- `allowed_ips` non-empty → the client IP must match (mismatch → 403).

Note that `allowed_ips` defaults to localhost: when you protect the endpoint with a token and scrape
from another host, set `allowed_ips: []` explicitly. `prometheus:test-metrics` warns when neither
check is configured.

The endpoint is always registered while the bundle is active. When telemetry is disabled
(`shopware.telemetry.metrics.enabled: false` or the feature flag is off), the stored-metrics section
is simply empty — scrape-time providers keep working.

### Scrape-time providers (opt-in)

Host-local values computed during the scrape, **per scraped host** (unlike the fleet-aggregated
stored metrics):

| Provider | Metrics | Note |
|---|---|---|
| `instance` | `instance_info`, `redis_usage{purpose}`, `has_opensearch`, `mysql_version_info` | Environment, Shopware/database versions, per-subsystem Redis usage (app_cache, session, cart, number_range, cache_invalidation) |
| `opcache` | `opcache_*`, `php_version_info` | OPcache state is not exposed by the FPM status page |
| `php_fpm` | `phpfpm_*` | Prefer the native FPM status page (`pm.status_path` + `?openmetrics`, PHP ≥ 8.1) |
| `opensearch` | `opensearch_*` | Prefer a dedicated infrastructure exporter next to the cluster |

Provider metrics carry the `scrape_metrics_namespace` prefix (by default the telemetry namespace,
e.g. `shopware_opcache_memory_used_bytes`). The metric schemas are this bundle's own — they are not
compatible with community exporters for the same subsystems, and the default prefix makes that
explicit and collision-free; set `scrape_metrics_namespace: ''` if you need bare subsystem names.

These providers are not an extension point: plugin metrics belong in the core telemetry abstraction,
where every transport (Prometheus, OpenTelemetry, …) receives them.

Provider names are declared in the `provider` attribute of the `shopware.prometheus.metrics` service
tag and are validated at container build time: unknown names in `scrape_providers` and duplicate
names fail the build, bare names are reserved for the bundle's built-in providers, and a provider
registered outside this bundle must use a vendor-prefixed name (`<vendor>.<name>`).
`bin/console debug:container --tag shopware.prometheus.metrics` lists every registered provider with
its name and class.

## Metric naming

Stored metric names are prefixed with the sanitized telemetry namespace
(`shopware.telemetry.metrics.namespace`) and dots become underscores:
`order.placed.count` → `shopware_order_placed_count`.

Core `updown_counter` metrics are exported as Prometheus **gauges** (the standard mapping for
non-monotonic sums); deltas accumulate atomically in Redis. The absolute value is the sum of deltas
since the storage was last wiped — after `prometheus:clear-storage` such series restart at the next
delta, not at the true level.

## Prometheus scrape configuration

```yaml
scrape_configs:
    - job_name: shopware
      metrics_path: /api/_internal/prometheus
      scheme: https
      authorization:
          type: Bearer
          credentials: <token>
      static_configs:
          - targets: ['shop.example.com']
```

## Commands

- `bin/console prometheus:test-metrics` — simulates a scrape against the metrics endpoint (guards
  included) and prints the response: a quick way to inspect what a scrape would return without HTTP
  access. By default the request carries the configured `auth_token` and a localhost client IP;
  `--ip` and `--token` simulate other callers, and a rejected request fails the command with the
  status code and the guard that refused it. Warns when the endpoint is configured without
  `auth_token` and `allowed_ips`, i.e. reachable without restriction.
- `bin/console prometheus:scrape-providers` — lists every registered scrape-time provider (disabled
  ones included) with its toggle name, class, and enabled state.
- `bin/console prometheus:clear-storage` — wipes all stored series. Use it to drop stale gauge label
  sets (for example after removing a messenger transport); counters restart from zero, which
  Prometheus `rate()` handles as a counter reset.

## Development

```shell
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse -c phpstan.neon.dist
vendor/bin/php-cs-fixer fix --dry-run
```

When developed as a sibling of a Shopware checkout via a symlinked path repository, the platform's
dev dependencies can be reused instead of a standalone install — run the tools from the platform
directory and point them at its autoloader:

```shell
vendor/bin/phpunit -c ../PrometheusExporterBundle/phpunit.xml.dist --bootstrap vendor/autoload.php
vendor/bin/phpstan analyse -c ../PrometheusExporterBundle/phpstan.neon.dist --autoload-file vendor/autoload.php
vendor/bin/php-cs-fixer fix --config ../PrometheusExporterBundle/.php-cs-fixer.dist.php --dry-run
```
