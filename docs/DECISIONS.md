# Implementation decisions

Small, reversible decisions. `special-docker` inherits ADR-001–ADR-011 from the original `special` project; entries below say whether each still holds, and ADR-012 onward are new to this fork.

| ADR | Topic | Status here |
|---|---|---|
| 001 | Native PHP, PDO, no repository layer | **Superseded** by ADR-012 |
| 002 | One service contract for both transports | Kept |
| 003 | Signed keyset cursors | Kept; cursor position is `(updated_at, id)` over the merged visible streams |
| 004 | Scholion-compatible MCP/OAuth boundary | Kept |
| 005 | Exact delivery for oversized bodies | Kept |
| 006 | Shared VPS and PostgreSQL | **Superseded** by ADR-013 |
| 007 | Rotating remembered browser login | Kept; rotation is a conditional write on DynamoDB |
| 008 | Signed share permalinks | Kept (works for private and public specs) |
| 009 | Author-scoped ownership | **Superseded** by ADR-015 (reads), kept for writes |
| 010 | Markdown source with safe display rendering | Kept |
| 011 | Resource-bound OAuth grants | Kept |

## ADR-002: One service contract for both transports

The browser and MCP adapters may perform early transport-specific checks, but all domain operations call `SpecService`, which re-checks permissions and owns validation, concurrency control and soft deletion.

## ADR-003: Opaque signed keyset cursors

`list_specs` uses `(updated_at DESC, id DESC)` keyset pagination. Cursors are compact base64url JSON signed with `SPECIAL_APP_KEY` and include the filter fingerprint, so clients cannot alter the position or reuse a cursor with different filters. In this fork the position applies to the merge of the public stream and the caller's private stream, and the service may return a short page with a cursor when a sparse filter hits the per-request scan bound.

## ADR-004: Scholion-compatible MCP/OAuth boundary

Public `server/discover`; bearer-protected `initialize`, `ping`, notifications, tools and resources; path-specific protected-resource metadata; dynamic public-client registration and HTTPS Client ID Metadata Documents; S256 PKCE; ChatGPT-scoped CORS; bearer challenges with `resource_metadata`, invalid-token and insufficient-scope details; `model.read`/`model.write` plus legacy `mcp:tools`; per-tool annotations and security schemes. Internal policy names remain `spec.read` and `spec.write`.

## ADR-005: Exact delivery for oversized bodies

A body exceeding the MCP response budget is replaced by length and SHA-256 metadata plus an authenticated `special://specs/{id}/body` resource URI; `resources/read` returns the complete body.

## ADR-007: Rotating remembered browser login

Persistent login stores a random selector and the SHA-256 of a separate secret (`REMEMBER#<selector>` item with a TTL); the raw value lives only in an HTTP-only cookie. Restoration needs an enabled, unexpired user and rotates the pair with a transactional delete-if-hash-matches plus put, so a replayed cookie fails. Password changes and account disablement delete all of a user's remember tokens. Default lifetime 60 days (`SPECIAL_REMEMBER_DAYS`).

## ADR-008: Signed share permalinks

`/share/{token}` carries a versioned payload and an HMAC-SHA-256 signature under a context-specific key derived from `SPECIAL_APP_KEY`. It bypasses visibility on purpose: holding the link is the read grant. Shared pages are read-only, no-store, noindex, no-referrer. Rotating the application key revokes all links.

## ADR-010: Markdown source with safe display rendering

Bodies are Markdown source everywhere (storage, forms, MCP, resources). Only the browser display path renders them, through pinned Parsedown 1.8.0 in safe mode with an HTTP(S) link whitelist.

## ADR-011: Resource-bound MCP OAuth grants

Authorization codes, access tokens and refresh tokens are bound to the canonical `/mcp` resource. Refresh-token rotation assigns a family id to both token types; replay of any revoked member revokes the family. (The legacy nullable-resource case from the PostgreSQL migrations no longer exists in this fork's storage but the null-tolerant check is kept.)

## ADR-012: A `Store` interface in place of inline SQL

The original used PDO directly in every service and explicitly refused a repository layer until a second consumer appeared. Moving to DynamoDB removes SQL entirely, and there are now two implementations (DynamoDB for runtime, an in-memory reference for tests and credential-free experiments). `Special\Storage\Store` therefore exposes **domain-level operations** rather than generic get/put: for example `createSpec(spec, audit)`, `rotateRefresh(...)`, `updateUser(..., enabledAdminDelta)`. That keeps the atomicity contract (a mutation and its audit entry commit together) inside the storage layer where a transaction primitive exists, and keeps key design out of the services. It is not a general repository or ORM, and there is no dependency-injection container.

## ADR-013: Docker on a single EC2 instance, DynamoDB for persistence

- **Wrapper:** a Docker image (PHP 8.3 + Apache) deployed with Docker Compose. There is no VPS-deployer, no host-installed PHP and no deployer metadata in this repository.
- **Host:** one EC2 instance. It is the simplest AWS shape that matches "docker runs on its own EC2". ECS/Fargate, App Runner or Lambda would also fit and would remove the host to patch, at the cost of more moving parts (load balancer, task definitions) than a small internal tool needs. The `Store` boundary and the image make that move straightforward later. Because sessions are files and TLS is on the instance, **horizontal scaling is out of scope** (ADR-014).
- **Persistence:** DynamoDB, pre-defined by CloudFormation (never created implicitly in production), on-demand billing, point-in-time recovery, deletion protection. One table, two GSIs: see [`DYNAMODB.md`](DYNAMODB.md).
- **Identity to AWS:** the EC2 instance role only. No access keys in the image, compose file or environment.
- **Secrets:** the application key is an SSM `SecureString`, read at deploy time.
- **TLS:** see ADR-020. The application never terminates TLS.
- **AWS SDK:** the original project had no Composer step. The official `aws/aws-sdk-php` is used for DynamoDB because it implements request signing, instance-profile credentials, retries and error models correctly; hand-rolling SigV4 is a security-sensitive liability. It is installed during the image build and loaded lazily (tests that use the in-memory store do not need it). Parsedown remains vendored.

## ADR-014: PHP file sessions on a Docker volume

Browser sessions keep using PHP's file handler, stored on the `special-data` volume so they survive container restarts and image updates. This is correct for one instance. Running several instances would require a shared session store (DynamoDB session handler or sticky routing); that is deliberately not built.

## ADR-015: Public by default, private on request

`spec.visibility` is `public` (default) or `private`. Every signed-in user with `spec.read` sees all public specs in the main list and their projects in the project list; a private spec is visible only to its author, and to everyone else it behaves as if it does not exist (`NOT_FOUND`). Only the author may modify or delete a spec; a non-author attempting to modify a *public* spec gets `FORBIDDEN` (it is visible, so denying existence would be misleading), and for a private one `NOT_FOUND`. Anonymous access remains limited to signed share links.

Why not "anonymous public"? The requirement was that all *logged-in* users see public specs; keeping the application closed to anonymous traffic avoids having to design rate limiting and indexing policy for an open endpoint, and MCP stays authenticated.

## ADR-016: Conditional writes instead of row locks

PostgreSQL's `SELECT ... FOR UPDATE` blocks are replaced by optimistic concurrency: `revision` on specs, `version` on users and login-attempt rows, `attribute_not_exists` guards for single-use artifacts, and counters adjusted in the same transaction for the "final administrator" invariant. The mapping is spelled out in [`DYNAMODB.md`](DYNAMODB.md#concurrency-and-transactions).

## ADR-017: Integer ids from atomic counters

URLs, share tokens, MCP schemas and tests all rely on integer ids. DynamoDB has none, so `COUNTER#spec` and `COUNTER#user` items provide them. Ids are unique and increasing but may have gaps. Switching to ULIDs would be cleaner for a distributed system but would change the public contract for no current benefit.

## ADR-018: Body size ceiling follows the item limit

A DynamoDB item is limited to 400 KB. The service accepts at most 100,000 characters **and** 350,000 UTF-8 bytes. A deliberately large body is rejected with `INVALID_ARGUMENT` rather than failing in the database. If specs need to be larger, move `body` to S3 and keep a pointer in the item; the `Store` interface isolates that change.

## ADR-019: Entra-ready, not Entra-now

Local users stay for now. The seams for replacing them (single password check, role on the principal, integer user ids, schemaless user items) are listed in [`ENTRA.md`](ENTRA.md); nothing in this fork builds Entra support yet.

## ADR-020: TLS terminates at an ALB with an ACM certificate; the app speaks HTTP

The first cut bundled Caddy with Let's Encrypt on the instance. That assumes a public host with open ports and does not fit a corporate setup where certificates are issued and owned elsewhere, or where TLS is attached *after* the first deployment. It was removed. The container now serves plain HTTP on port 80 and the standard deployment puts an **Application Load Balancer with an ACM certificate** in front (ACM-issued or imported from the corporate CA). The instance accepts traffic only from the ALB's security group, and the ALB health-checks `/health`.

To keep "deploy now, attach TLS later" possible, the instance is closed by default and reachable through an SSM port-forward, and `SPECIAL_INSECURE_HTTP=1` lets production run over HTTP during that period (reported by `/health`). `SPECIAL_PUBLIC_BASE_URL` should be the final HTTPS name from the start because OAuth resources, issuer and share links derive from it. `SPECIAL_TRUSTED_PROXY_HOPS` says how many trusted proxies append to `X-Forwarded-For` (1 for the ALB). Encryption on the ALB-to-instance hop is not provided; it stays inside the VPC.

## ADR-021: Web first-run setup guarded by a one-time token

While no administrator exists, `/` and `/login` redirect to `/setup`. Because the system may be reachable from a network before anyone has configured it, setup requires a **setup token**: generated on first visit, stored only as a hash with a 24-hour expiry, and written once to the application log (CloudWatch). `SPECIAL_SETUP_TOKEN` overrides generation for automation. Attempts are throttled like logins, the page returns 404 once an administrator exists, and the same DynamoDB marker that guards the CLI bootstrap makes creation atomic. The CLI remains as a fallback.

## ADR-022: Administrator password reset with forced change

An administrator can reset another user's password. The service sets a random temporary password (shown once), marks the user `must_change_password`, signs them out everywhere and revokes their MCP tokens. A flagged user can reach only the account page until they choose a new password, and cannot obtain MCP access in the meantime. Entra will make this moot for federated users.
