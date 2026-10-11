# Deployment

Special ships as a Docker image. Where you run it decides which compose file applies.

| Target | Compose file | Persistence | Guide |
|---|---|---|---|
| Your machine | `docker-compose.yml` | DynamoDB Local container (volume) | below, and [`AWS_SETUP.md`](AWS_SETUP.md) appendix B |
| AWS (production) | `deploy/aws/compose.prod.yml`, started by `special-deploy` | DynamoDB table created by `deploy/aws/special-stack.yaml` | [`AWS_SETUP.md`](AWS_SETUP.md) |

## The image

`Dockerfile` has two stages: Composer installs the AWS SDK, then `php:8.3-apache` receives `public/`, `src/`, `views/`, `scripts/` and `vendor/`. Apache serves `public/` with `FallbackResource /index.php`, forwards the `Authorization` header (needed by MCP), and sets security headers including a CSP. The image speaks **plain HTTP on port 80 and never handles certificates**: TLS is terminated by an ALB or corporate proxy in front. The image also carries `deploy/aws/{compose.prod.yml,deploy.sh}` so a host only needs to know the image name. Health check: `GET /health` (200 when DynamoDB answers).

Build for the production architecture explicitly: `docker build --platform linux/amd64 -t special .` (the provided push scripts do this).

## Configuration

All configuration is environment variables with the `SPECIAL_*` prefix. In AWS they are written to `/opt/special/stack/.env` by `deploy.sh`; locally they are set in `docker-compose.yml`.

| Variable | Default | Meaning |
|---|---|---|
| `SPECIAL_ENV` | `development` (image: `production`) | `production` enforces the rules below |
| `SPECIAL_APP_KEY` | dev key in development only | ≥ 32 bytes; signs share links, cursors, token hashes |
| `SPECIAL_PUBLIC_BASE_URL` | `http://127.0.0.1:8080` | Public origin; must be HTTPS in production; defines the OAuth resource `<url>/mcp` |
| `SPECIAL_STORE` | `dynamodb` | `dynamodb`, or `memory` (tests only; rejected in production) |
| `SPECIAL_DYNAMODB_TABLE` | `special` | Table name |
| `SPECIAL_AWS_REGION` (or `AWS_REGION`) | none | Required in production |
| `SPECIAL_DYNAMODB_ENDPOINT` | none | DynamoDB Local URL; rejected in production |
| `SPECIAL_MEMORY_STORE_FILE` | none | Persist the memory store to a file (dev/tests) |
| `SPECIAL_SESSION_PATH` | `/var/lib/special/sessions` (image) | PHP session files; keep on a volume |
| `SPECIAL_INSECURE_HTTP` | `0` | `1` allows an `http://` base URL and non-secure cookies in production. For the pre-TLS period only (SSM tunnel); `/health` then reports `warnings: ["insecure_http"]` |
| `SPECIAL_SESSION_SECURE` | `1` in production (`0` with `SPECIAL_INSECURE_HTTP=1`) | Secure cookies |
| `SPECIAL_TRUST_PROXY` | `1` (image), `0` in dev compose | Use `X-Forwarded-For` as the client IP (behind the ALB / proxy) |
| `SPECIAL_TRUSTED_PROXY_HOPS` | 1 | Which entry from the right of `X-Forwarded-For` is the client: 1 for a single ALB, more only when further trusted proxies sit in front |
| `SPECIAL_SETUP_TOKEN` | none | Token required by the first-run `/setup` page (20+ characters). Unset = generated once, hashed in the table, written to the log, valid 24 h |
| `SPECIAL_VERSION`, `SPECIAL_COMMIT` | `dev`, `unknown` | Build labels set by the image; shown in the footer, `/health` and `deployment-check` |
| `SPECIAL_REMEMBER_DAYS` | 60 | "Keep me signed in" lifetime (1–3650) |
| `SPECIAL_MCP_RESPONSE_BYTES`, `SPECIAL_MCP_REQUEST_BYTES`, `SPECIAL_MCP_MAX_PAGE_SIZE` | 64000, 2000000, 50 | MCP bounds |
| `SPECIAL_OAUTH_ACCESS_TTL`, `_REFRESH_TTL`, `_CODE_TTL` | 900, 2592000, 300 | seconds |
| `SPECIAL_LOGIN_MAX_ATTEMPTS`, `_WINDOW_SECONDS`, `_BLOCK_SECONDS` | 5, 300, 900 | login throttling |
| `SPECIAL_AUTO_INIT_TABLE` | `0` | Local convenience: create the table on container start |

Production refuses to start the application when the key is short, the region is missing, a local endpoint or the memory store is configured, or (unless `SPECIAL_INSECURE_HTTP=1`) cookies are not secure or the URL is not HTTPS (`/health` then answers 500/503).

AWS credentials are never configured here: in AWS the SDK uses the instance role; against DynamoDB Local the compose file supplies dummy values.

## Commands

All commands run inside the app container (`docker compose exec app ...` locally; `sudo docker compose -f /opt/special/stack/compose.prod.yml exec -T app ...` on the instance).

| Command | Purpose |
|---|---|
| `php scripts/bootstrap-admin.php --username <name>` with `SPECIAL_BOOTSTRAP_ADMIN_PASSWORD` (or `..._PASSWORD_HASH`) | CLI alternative to the web `/setup` page; creates the first administrator, once per table |
| `php scripts/register-oauth-client.php --client-id ... --name ... --redirect-uri ...` | Register a fixed OAuth client |
| `php scripts/deployment-check.php` | Verify configuration, table, indexes, TTL and administrator |
| `php scripts/init-table.php` | Create the table (DynamoDB Local or an empty AWS account); idempotent |

## Local run

```text
docker compose up --build
docker compose exec -e SPECIAL_BOOTSTRAP_ADMIN_PASSWORD="a-long-passphrase" app php scripts/bootstrap-admin.php --username admin
```

Browse <http://localhost:8080>. Run the tests with the commands in the README; the DynamoDB-backed behaviour suite can run against the compose DynamoDB container (`SPECIAL_DYNAMODB_ENDPOINT=http://localhost:8000`).

## Rollback and releases

Releases are git tags `vX.Y.Z`; CI publishes `ghcr.io/<owner>/special-docker:X.Y.Z` (and `:edge` for `main`). Roll back with `sudo special-deploy <previous-version>`. There is no schema migration step: new attributes are additive, and key-format changes are explicit one-off scripts (see [`DYNAMODB.md`](DYNAMODB.md#operating-the-table)).
