# Changelog

## 1.1.1

* **OAuth desktop-client interoperability:** accept native-application redirect URI schemes for all clients with exact matching, and accept client metadata that advertises additional grants while continuing to expose only the server's authorization-code and refresh-token grants. This resolves GitHub Copilot Desktop's OAuth Client ID Metadata Document flow.

## 1.1.0

* **First-run web setup** at `/setup`, guarded by a one-time token (generated and logged once, or `SPECIAL_SETUP_TOKEN`); the CLI bootstrap remains.
* **Administrator password reset** with a one-time temporary password and a forced change at next sign-in.
* **TLS is no longer handled by the stack.** Caddy and Let's Encrypt are removed; the container serves plain HTTP and a corporate ALB/proxy terminates TLS (`docs/EDGE_INTEGRATION.md`). New settings: `SPECIAL_INSECURE_HTTP` (pre-TLS mode), `SPECIAL_TRUSTED_PROXY_HOPS`. `SPECIAL_DOMAIN` no longer exists; `instance.env` now carries `SPECIAL_PUBLIC_BASE_URL`. **Upgrading a 1.0.0 host:** update `instance.env` accordingly (see `docs/AWS_SETUP.md`, section 7) before `special-deploy 1.1.0`; the CloudFormation stack drops the Elastic IP and the 80/443 world-open rules.
* **Prometheus `/metrics`** (off unless `SPECIAL_METRICS_TOKEN` is set): request rate/latency by route, MCP and login counters, build info, store health (`docs/METRICS.md`).
* **Version** shown in the footer, `/health`, `deployment-check` and metrics; images are labelled by CI.
* `deploy.sh` accepts image digests (`special-deploy sha256:...`) and a host-provided registry login.

## 1.0.0

* Docker/AWS fork of `special`: DynamoDB persistence behind a `Store` interface, public-by-default specs with private on request, projects list, `list_projects` MCP tool, CloudFormation stack, GHCR release workflow.
