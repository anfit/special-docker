# Special (Docker / AWS)

Special is a deliberately narrow application for collecting and managing specification documents. The product entity is the `spec`; a browser UI and an MCP endpoint share the same service layer and authorization policy.

`special-docker` is a fork of the original `special` project with four differences:

1. **Docker is the deployment wrapper**: an image built from this repository; no VPS-deployer, no host-installed PHP or database.
2. **Specs are public unless explicitly made private.** Every signed-in user sees public specs in the main listing and in the project list; private specs are visible only to their author.
3. **AWS is the target**: Docker runs on its own EC2 instance behind an ALB (which terminates TLS with an ACM certificate) and **all persistence is a pre-defined DynamoDB table**. The container speaks plain HTTP and never handles certificates.
4. **Entra ID is the planned identity provider**; local users are the stepping stone ([`docs/ENTRA.md`](docs/ENTRA.md)).

## Where to start

| I want to... | Read |
|---|---|
| deploy to AWS from scratch | [`docs/AWS_SETUP.md`](docs/AWS_SETUP.md) |
| run it locally | below |
| understand the product and architecture | [`spec/README.md`](spec/README.md) |
| understand the DynamoDB layout and its guarantees | [`docs/DYNAMODB.md`](docs/DYNAMODB.md) |
| configure or operate it | [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) |
| know why things are the way they are | [`docs/DECISIONS.md`](docs/DECISIONS.md) |
| plan the move to Entra | [`docs/ENTRA.md`](docs/ENTRA.md) |
| scrape metrics with Prometheus / Grafana | [`docs/METRICS.md`](docs/METRICS.md) |
| put it behind the corporate load balancer / TLS | [`docs/EDGE_INTEGRATION.md`](docs/EDGE_INTEGRATION.md) |
| connect an MCP client | [`docs/MCP_CONTRACT.md`](docs/MCP_CONTRACT.md), [`docs/OAUTH.md`](docs/OAUTH.md) |

## Behaviour in one paragraph

Spec bodies are authored and stored as Markdown source. The browser renders them with pinned Parsedown 1.8.0 in safe mode (raw HTML escaped, links limited to HTTP(S)), while MCP responses and the copy button use the unchanged source. A spec is `public` by default; the author can switch it to `private` at any time and only the author can edit or delete it. A signed `/share/{token}` permalink grants anyone holding it a read-only view of a spec, public or private. MCP tools: `list_specs`, `list_projects`, `get_spec`, `create_spec`, `update_spec`, `delete_spec`. The first administrator is created on a token-guarded web setup page; administrators can reset a user's password (the user must then choose a new one); the footer and `/health` show the deployed version.

## Run it locally

Prerequisite: Docker. No AWS account is needed; the stack includes DynamoDB Local.

```text
docker compose up --build
```

Browse <http://localhost:8080>. The first visit redirects to the first-run setup page; the setup token for this local stack is `local-development-setup-token`. Data persists in Docker volumes until `docker compose down -v`.

## Layout

```text
public/ src/ views/            the application (src/Storage = Store interface + DynamoDB + in-memory)
scripts/                       bootstrap-admin, register-oauth-client, init-table, deployment-check
tests/                         PHP behaviour suite + Python contract tests
Dockerfile docker-compose.yml  image and local stack
docker/                        Apache vhost, php.ini, entrypoint
deploy/aws/                    CloudFormation stack, production compose, deploy/push scripts
docs/ spec/                    documentation and the product/architecture spec
```

## Tests

PHP 8.2+ with `mbstring` and `openssl` (or run them in the container). Set `SPECIAL_PHP` if `php` is not on `PATH`.

```text
php tests/behavior_test.php              # services against the in-memory store (27 tests)
python -m unittest discover -s tests -p "*_test.py"
```

To exercise the real DynamoDB code path, start DynamoDB Local and run the same suite against it. This needs the SDK (`composer install`) and a PHP with the `curl` extension; with DynamoDB Local's jar (needs Java) instead of Docker, start it with `java -Djava.library.path=./DynamoDBLocal_lib -jar DynamoDBLocal.jar -inMemory -port 8000`. The Python `web_flow_test` also switches to DynamoDB when `SPECIAL_DYNAMODB_ENDPOINT` is set.

```text
docker compose up -d dynamodb
set SPECIAL_DYNAMODB_ENDPOINT=http://localhost:8000
set AWS_ACCESS_KEY_ID=local
set AWS_SECRET_ACCESS_KEY=local
set SPECIAL_AWS_REGION=us-east-1
php tests/behavior_test.php dynamodb
```

(Use `export` instead of `set` in bash.) The suite creates and drops a throw-away table, so it is safe next to your local data.

## Security posture, briefly

Sessions are HTTP-only/SameSite-Strict with CSRF tokens on every form; passwords are hashed with PHP's default algorithm; login attempts are throttled; MCP uses OAuth 2.1 with PKCE, resource-bound tokens and refresh-family revocation; the container has no static AWS credentials and its IAM role is limited to one table, one parameter prefix and one log group; there is no SSH. Report issues privately to the repository owner.
