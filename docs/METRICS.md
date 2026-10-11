# Metrics (Prometheus)

Special exposes a Prometheus scrape endpoint at **`GET /metrics`**. It is **off by default** and, when on, requires a bearer token. OpenTelemetry (traces/OTLP) is not implemented; the metric names below are stable and can be bridged by an OpenTelemetry Collector's Prometheus receiver if you need OTLP downstream.

## Enabling

| Setting | Meaning |
|---|---|
| `SPECIAL_METRICS_TOKEN` | Bearer token (20+ characters). Unset = `/metrics` answers 404 and **no** per-request metric I/O happens. |
| `SPECIAL_METRICS_FILE` | Where samples are buffered (default: the container's temp directory). Counters restart from zero when the container is recreated; use `rate()` / `increase()`. |

On an AWS host, opt in by creating the SSM parameter `<ssm_prefix>/metrics-token` (SecureString); `special-deploy` passes it to the container:

```bash
aws ssm put-parameter --name /special/prod/metrics-token --type SecureString --value "$(openssl rand -base64 36)"
sudo special-deploy            # on the host, or redeploy the current version
```

## Do not expose it to the world

`/metrics` must be reachable only from your Prometheus. The corporate load balancer should **not** route `/metrics` (list it in the edge request; see [`EDGE_INTEGRATION.md`](EDGE_INTEGRATION.md)). Prometheus scrapes the instance **directly on port 80** from inside the VPC, so the instance's security group must admit Prometheus's security group or CIDR on TCP 80 (CloudFormation `AlbSecurityGroupId`/`IngressCidr`, Terraform `ingress_security_group_ids`/`ingress_cidrs`). The bearer token is a second lock, not a substitute for network restriction.

## Prometheus configuration

```yaml
scrape_configs:
  - job_name: special
    metrics_path: /metrics
    scrape_interval: 30s
    authorization:
      type: Bearer
      credentials_file: /etc/prometheus/secrets/special-metrics-token
    static_configs:
      - targets: ["10.42.1.23:80"]        # the instance's private IP (terraform output private_ip)
        labels: { environment: prod }
```

For EC2 service discovery use `ec2_sd_configs` with a tag filter instead of a fixed address.

## Metrics

| Metric | Type | Labels | Meaning |
|---|---|---|---|
| `special_http_requests_total` | counter | `method`, `route`, `status` | Requests handled. `route` is a low-cardinality group: `/specs`, `/specs/{id}`, `/specs/{id}/edit`, `/specs/{id}/delete`, `/projects`, `/login`, `/logout`, `/setup`, `/account`, `/account/password`, `/admin/users`, `/share/{token}`, `/oauth/token`, `/oauth/register`, `/oauth/authorize`, `/.well-known`, `mcp`, `/`, `other`. `/health` and `/metrics` are not counted. |
| `special_http_request_duration_seconds` | histogram | `route` | Request latency; buckets 5 ms to 10 s. |
| `special_mcp_requests_total` | counter | `method`, `outcome` | MCP JSON-RPC calls (`initialize`, `tools/call`, ... else `other`) by outcome (`success`, `error`, `unauthorized`). |
| `special_login_attempts_total` | counter | `result` | Browser logins: `success`, `failure`, `blocked` (throttled). |
| `special_build_info` | gauge | `version`, `commit` | Always 1; shows what is deployed. |
| `special_store_up` | gauge | none | 1 if the DynamoDB table answered a probe during the scrape. |
| `special_store_probe_seconds` | gauge | none | Duration of that probe (a cheap DynamoDB `GetItem`). |

## Useful queries and alerts

```promql
# error ratio (5xx) over 5 minutes
sum(rate(special_http_requests_total{status=~"5.."}[5m])) / sum(rate(special_http_requests_total[5m]))

# p95 latency per route
histogram_quantile(0.95, sum by (le, route) (rate(special_http_request_duration_seconds_bucket[5m])))

# login brute-force signal
sum(rate(special_login_attempts_total{result=~"failure|blocked"}[5m]))

# MCP failures
sum by (method) (rate(special_mcp_requests_total{outcome="error"}[5m]))
```

Alert on: `special_store_up == 0` for 2 minutes (storage or IAM problem); 5xx ratio above 2% for 10 minutes; `absent(special_build_info)` (host down or scrape broken); a sustained `blocked` login rate.

## Grafana

A good first dashboard: request rate and 5xx ratio by route, p50/p95 latency by route (`histogram_quantile`), login results, MCP calls by method, `special_store_up`, and a table of `special_build_info` so every panel can be read against the running version. Host-level metrics (CPU, memory, disk) come from the usual node exporter or CloudWatch agent; DynamoDB and load balancer metrics come from CloudWatch.

## How it works (and its limits)

PHP serves each request in a fresh process, so counters cannot live in memory. Each request appends a few lines to a file under an exclusive lock; `/metrics` sums them (and compacts the file when it grows past 1 MiB). That is cheap for this application's volume but serialises writers on one lock, which is fine for a single small host and would not be for a busy fleet; at that point move to an APCu- or Redis-backed registry or an OTLP exporter behind the same `Metrics` class. Metrics are per host: with several instances, scrape each one.
