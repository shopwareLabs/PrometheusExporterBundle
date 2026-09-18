# Prometheus Exporter — Telemetry v2 Integration Spec

Status: **ready**

## Context

The bundle becomes the official **pull transport** for Shopware's metrics abstraction
([ADR 2026-04-23](../shopware/adr/2026-04-23-telemetry-v2-metrics-evolution.md), shipped in core since
**v6.7.12.0** behind the `TELEMETRY_METRICS` feature flag, stable target v6.8): metrics pushed through
`Meter::emit()` are written to Redis (buffered per process or directly, configurable) and served in
Prometheus text format on scrape. The bundle ships **no inline instrumentation** — every core metric
(see [telemetry.yaml](../shopware/src/Core/Framework/Resources/config/packages/telemetry.yaml), 29 definitions)
arrives through `emit()` automatically.

Primary audience: Shopware PaaS; secondary: advanced on-prem installs. The PoC has no users — no BC
obligations toward its interfaces, metric names, or config keys.

## Target architecture

```
runtime (request/CLI/worker)                        scrape
Meter::emit(Metric) ──► PrometheusTransport         GET /api/_internal/prometheus
                          │ buffer (or direct)        │ auth guard (token / IP)
kernel.terminate,         │                           │ Redis registry samples
console.terminate,        ▼                           │ + provider samples (opt-in, InMemory)
WorkerRunningEvent ────► flush() ──► promphp Redis ◄──┤
(throttled 60s, core)                 registry        └► one RenderTextFormat pass
```

### Components

1. **`PrometheusTransportFactory`** — implements core `MetricTransportFactoryInterface`, service tagged
   `shopware.metric_transport_factory` (tag is **not** autoconfigured — set it explicitly). Receives
   `TransportConfig` (all `MetricConfig` definitions + namespace); passes a `name → MetricConfig` map and
   the sanitized namespace to the transport.
2. **`PrometheusTransport`** — implements `MetricTransportInterface`. Write mode is configurable
   (`transport.write_mode`):
   - `buffered` (default): `emit(Metric)` appends to an in-memory buffer; `flush()` replays the buffer
     into the promphp registry and clears it. Moves Redis I/O off the request critical path
     (`kernel.terminate` runs after the response is sent); core guarantees a final flush at process
     termination and throttled flushes (60 s) in long-running workers. Accepted loss: metrics from
     fatally-crashed requests.
   - `direct`: `emit()` writes to the registry immediately, `flush()` is a no-op. One Redis round-trip
     per emission on the critical path; for setups where crash-loss is unacceptable or when debugging.
3. **`RegistryFactory`** — builds the promphp `CollectorRegistry` from bundle config (see Storage).
4. **`MetricsController`** — auth guard, then a **single library render pass**:
   `RenderTextFormat::render()` accepts a plain `MetricFamilySamples[]`, so the controller merges
   `$redisRegistry->getMetricFamilySamples()` with the samples of a per-scrape `InMemory` registry that
   the enabled scrape-time providers write into. No hand-rolled exposition rendering anywhere.
5. **Scrape-time providers** — kept from the PoC, all **disabled by default**, computed live per scrape,
   per-scraped-host: `PHPInfoMetricProvider` (OPcache — not available via FPM status page),
   `PHPFPMMetricProvider`, `OpenSearchMetricProvider`. Interface reworked to write into the passed
   registry (`collect(CollectorRegistry $registry): void`); the PoC's `Metric`/`MetricValue` structs and
   formatter are deleted. `QueueMetricProvider` is **deleted** (exact duplicate of core
   `messenger.queue.depth`).
   `MetricProviderInterface` is `@internal` **by design, not oversight**: metrics emitted only at scrape
   time exist for this exporter alone and are invisible to every other transport (OTel), fragmenting
   telemetry — so the sanctioned path for plugins is core `Meter`/`Telemetry`, and providers stay a
   bundle-internal escape hatch for host-local process data that cannot flow through core (OPcache,
   FPM). Opening the interface later is BC-safe if a genuine need appears; closing it wouldn't be.
   The `scrape_providers` toggles are applied in a compiler pass (`ScrapeProviderPass`) — Shopware's
   `Bundle::build()` loads `services.php` into the main container, so extension-time definition
   removal would be lost on the extension-container merge. Each provider declares its toggle name in
   the `provider` attribute of its `shopware.prometheus.metrics` tag; the pass fails the build on
   unknown configured names and duplicate names, and reserves bare names for the bundle's own
   classes — a provider registered from any other namespace must use a vendor-prefixed name
   (`<vendor>.<name>`), a defensive guard given the interface is not an extension point.
6. **Console commands** — `prometheus:test-metrics` (simulates a scrape through the real controller,
   guards included, and prints the endpoint response; sends the configured `auth_token` and a
   localhost client IP by default, `--ip`/`--token` simulate other callers, non-200 responses fail
   the command), `prometheus:scrape-providers` (lists registered providers with toggle name, class,
   and enabled state — the pass injects the full map into the command definition since disabled
   providers are removed from the container), `prometheus:clear-storage` (`$registry->wipeStorage()`).

### Metric mapping

| Core `Type` | promphp op | Notes |
|---|---|---|
| `counter` | `Counter::incBy(value)` | |
| `gauge` | `Gauge::set(value)` | last-write-wins per label set, fleet-wide |
| `histogram` | `Histogram::observe(value)` | buckets from `MetricConfig::parameters['buckets']`; promphp defaults when absent |
| `updown_counter` | `Gauge::incBy(value)` | additive — see analysis below |

**`updown_counter` analysis.** Core up-down emissions are deltas (additive). promphp's Redis storage
implements `Gauge::incBy()` as an atomic `hIncrByFloat` — only `Gauge::set()` is last-write-wins
(`hSet`) — so concurrent deltas from many PHP processes accumulate correctly; there is no last-wins
hazard as long as the transport never mixes `set()` and `incBy()` on the same series (it doesn't: type
is fixed per metric definition). Exposing the result as Prometheus TYPE `gauge` is the standard mapping
(OTel: non-monotonic sum → Prometheus gauge). Inherent caveat of delta-based up-down counters: the
stored value is the sum of deltas since storage init/wipe, so `clear-storage` resets the level and the
absolute value is only trustworthy if emission started at true zero — document in README. Core currently
defines no `updown_counter` metric; revisit if one with absolute-level semantics appears.

Naming: prefix = `TransportConfig::$namespace`, then the metric name; both sanitized
`[^a-zA-Z0-9_:] → _` (so `order.placed.count` with namespace `shopware` →
`shopware_order_placed_count`). `MetricConfig::description` → `# HELP`. Label values cast to string,
bools as `true`/`false`. Exported names are a **stable public surface** — dashboards depend on them.

Requires companion core PR: change the default `shopware.telemetry.metrics.namespace` from
`io.opentelemetry.contrib.php.shopware` to `shopware` (feature is experimental — no BC concern; affects
the OTel transport's instrumentation-scope name, mention in that PR).

### Storage

`promphp/prometheus_client_php` (hard require) — no own storage or key-schema code. Supported clients:
phpredis `\Redis` (adapter `Prometheus\Storage\Redis`) and `Predis\Client`
(adapter `Prometheus\Storage\Predis`), both via `::fromExistingConnection()`. Connection resolution at
registry construction, in priority order:

1. `storage.dsn` set → open a connection via core `RedisConnectionFactory::create($dsn)` (same DSN
   dialect and memoization as `shopware.redis.connections` entries).
2. `storage.redis_connection_name` set → resolve via core `RedisConnectionProvider`
   (`shopware.redis.connections.<name>`).
3. Neither set → throw at boot ("configure `storage.dsn` or `storage.redis_connection_name`"). No guessing.

Either way the resulting client is mapped by type: `\Redis` → Redis adapter, `Predis\Client` → Predis
adapter, anything else (`RedisCluster`, `RedisArray`, `Relay`) → config exception naming the supported
clients and both config options.

Redis-backed series are **fleet-aggregated**: every instance serves identical registry output, one scrape
target suffices. Scrape-time provider metrics are per-scraped-host. README documents both semantics.
Stale gauge series (e.g. a removed transport label) are handled by `prometheus:clear-storage` only — no
TTLs (would corrupt counter/histogram rates); revisit later if needed.

## Endpoint & security

- `GET /api/_internal/prometheus`, api route scope, `auth_required: false` + bundle's own guard.
- Guard: every **configured** check must pass — `auth_token` (`Authorization: Bearer <token>`,
  `hash_equals`) and/or `allowed_ips`. 401/403 on failure. Log a warning at boot when neither is
  configured outside dev.
- The endpoint is always registered while the bundle is active: scrape-time providers work independently
  of core telemetry, so no 404-when-disabled. When `TELEMETRY_METRICS` is off or
  `shopware.telemetry.metrics.enabled: false`, the registry section is simply empty (core never calls the
  transport); README explains this.

## Config surface (stable)

```yaml
prometheus_exporter:
    storage:
        redis_connection_name: null   # name under shopware.redis.connections (\Redis or Predis\Client)
        dsn: null                     # takes priority; opened via core RedisConnectionFactory
        key_prefix: 'sw:metrics'
    transport:
        write_mode: 'buffered'        # 'buffered' (flush on terminate) | 'direct' (write per emit)
    endpoint:
        allowed_ips: ['127.0.0.1', '::1']
        auth_token: null              # null = check off; string = required Bearer token
    scrape_providers:                 # per-scraped-host metrics, computed live per scrape
        opcache: false
        php_fpm: false
        opensearch: false
```

## Compatibility

- `composer.json`: `shopware/core ~6.7.12 || ~6.8.0` (per-minor opt-in — supporting 6.9 later is a
  deliberate constraint bump, not implicit), `promphp/prometheus_client_php ^2.14`, `php >=8.2`;
  `ext-redis` and `predis/predis` move to `suggest` (one of them is required at runtime — enforced by
  the boot-time client validation).
- On 6.7 users must enable the `TELEMETRY_METRICS` flag (plus `shopware.telemetry.metrics.enabled: true`);
  the bundle itself needs no flag-conditional code — `Meter` short-circuits when the flag is off.

## Decisions (all resolved)

| # | Decision | Resolution |
|---|---|---|
| D1 | Storage | promphp adapters; phpredis `\Redis` + `Predis\Client` supported, cluster/Relay rejected at boot; `dsn` > `redis_connection_name`, neither = boot error |
| D2 | Scrape-time providers | Keep OPcache, PHP-FPM, OpenSearch — all opt-in (infra exporters remain the recommended path, README says so) |
| D3 | OpenSearch | Generic stats stay in the opt-in provider; Shopware-specific stale-index periodic collector deferred to follow-up |
| D4 | Endpoint | `/api/_internal/prometheus`, own guard; always active while bundle enabled |
| D5 | Write mode | Configurable `buffered` (default) / `direct` |
| D6 | Multi-instance | One endpoint, fleet-aggregated registry, documented |
| D7 | Stale series | `clear-storage` command only, no TTL |
| D8 | `MetricProviderInterface` | `@internal` to avoid exporter-specific metrics invisible to other transports; may open later (BC-safe direction) |
| D9 | Naming | Prefix from `TransportConfig::$namespace`; companion core PR changes default namespace to `shopware` |
| D10 | Version | `~6.7.12 \|\| ~6.8.0` (first release with Telemetry v2; explicit bump per minor) |
| D11 | `QueueMetricProvider` | Deleted — duplicate of core `messenger.queue.depth` |
| D12 | Exposition rendering | Single `RenderTextFormat` pass over merged Redis-registry + per-scrape InMemory-registry samples; hand-rolled formatter and structs deleted |
| D13 | `updown_counter` | → `Gauge::incBy` (atomic `hIncrByFloat`, additive-safe); TYPE `gauge` per OTel mapping; delta-reset caveat documented |

## Out of scope (v1)

- New inline metrics in core; core changes beyond the namespace-default PR (ADR §4: pull transport owns everything).
- Periodic collectors in the bundle (none needed for v1; OpenSearch stale-index signal is the first candidate follow-up).
- Grafana dashboards / alert rules.

## Implementation plan (by commit)

1. **Docs**: this specification + .gitignore.
2. **Tooling**: composer manifest (constraints/requires) + phpunit/phpstan/php-cs-fixer configuration.
3. **Skeleton**: new `Configuration` tree, delete `QueueMetricProvider`, `@internal` markers, wire
   `scrape_providers` toggles.
4. **Storage**: `RegistryFactory` (resolution order, client-type mapping/validation, key prefix) +
   `prometheus:clear-storage`.
5. **Transport**: `PrometheusTransportFactory` + `PrometheusTransport` (write modes, type mapping,
   naming/label normalization) + DI tag registration.
6. **Endpoint**: `MetricsController` rewrite (guard, merged single-pass render), provider interface
   rework (`collect(CollectorRegistry)`), delete structs + formatter.
7. **README**: semantics, FPM/infra-exporter guidance, updown caveat, scrape config example.
8. **Core companion PR** (shopware/shopware): namespace default → `shopware` (postponed).

## Verification

- Unit: type mapping incl. `updown_counter→Gauge::incBy`, buffered vs direct write modes, name/label
  sanitization, connection resolution + client-type validation errors (incl. Predis accepted, cluster
  rejected), merged render output, auth guard (token, IP, both, neither-warning).
- Integration (bundle symlinked into local shopware, see
  `../shopware/INSTALL_SYMLINK_TELEMETRY.md`): enable flag + `metrics.enabled`, hit a storefront page, run
  worker + `scheduled-task:run`, scrape with Bearer token → core metrics appear prefixed
  (`shopware_http_server_request_duration_bucket` with correct labels); second scrape shows monotonic
  counters; `clear-storage` empties the registry; opt-in provider appears only when enabled.
- PHPStan + ECS + PHPUnit on the bundle.