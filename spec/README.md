# Special (Docker/AWS): product and architecture spec

Special is a small application with one domain entity, `spec`. This repository (`special-docker`) is a fork of the original VPS-hosted `special` with four deliberate differences:

1. **Docker is the deployment wrapper.** There is no VPS-deployer, no host PHP, and no host database. The artifact is an image.
2. **Specs are public unless explicitly made private.** "Public" means every signed-in user sees the spec in the main listing and in the project list.
3. **The target is AWS.** One EC2 instance runs the containers; all persistence lives in a pre-defined DynamoDB table.
4. **Local users are a stepping stone.** User management is isolated so Microsoft Entra ID can replace it later ([`docs/ENTRA.md`](../docs/ENTRA.md)).

Everything else (the MCP/OAuth boundary, Markdown rendering, share permalinks, optimistic concurrency, audit trail) is kept as it was.

## 1. Scope

The application provides:

- a server-rendered web UI for viewing and editing specs;
- a single `spec` entity, plus a derived list of projects;
- CRUD operations exposed through an authenticated MCP endpoint;
- local user accounts with three small roles;
- audit records for administrative and spec mutations.

There is no `project` entity. `project_name` is plain text on a spec; the project list is derived from the specs a viewer can see.

## 2. Architectural shape

```text
          browser web / MCP JSON-RPC / CLI scripts
                          |
              bootstrap and request scope
            (config, session, principal, Store)
                          |
              security and authorization policy
                 /                       \
          SpecService                 Security / OAuth
       (domain use cases)        (users, ACL, tokens)
                 \                       /
                  Store (interface)
                 /                \
          DynamoStore          MemoryStore
        (AWS / DynamoDB Local)  (tests only)
```

### Boundaries

- `bootstrap.php` owns environment validation and builds the shared `Store`. The AWS SDK is loaded lazily, only for the DynamoDB store.
- `Storage\Store` is the **only** persistence boundary. Every method is a domain-level operation (create a spec with its audit entry, rotate a refresh token, page through specs). Services never see keys, indexes or expressions. The earlier "no repository layer" rule was dropped because there are now two concrete implementations and DynamoDB has no ad-hoc query language to hide behind.
- `SpecService` owns validation, visibility, ownership, soft deletion and optimistic concurrency (a read, modify, conditional-write loop).
- `Security` and `OAuth` own authentication, roles, token lifecycle and login throttling.
- `public/index.php` is a thin HTML router. `mcp.php` is the protocol adapter. Neither touches storage directly.

Web and MCP mutations call the same `SpecService` operations. Authorization is checked in the adapters for early feedback and again in the service.

## 3. Domain model

### `spec`

| Field | Type | Required | Notes |
|---|---|---:|---|
| `id` | integer | yes | Stable public identifier, allocated from an atomic counter. |
| `title` | string | yes | Trimmed, 1–200 characters. |
| `description` | string | no | At most 2,000 characters. |
| `body` | string | no | Markdown source, at most 100,000 characters **and 350,000 bytes** (DynamoDB items are capped at 400 KB). |
| `project_name` | string | yes | Trimmed plain text, 1–200 characters. |
| `status` | string | yes | `draft` (default), `active`, `archived`. |
| `visibility` | string | yes | `public` (default) or `private`. |
| `revision` | integer | yes | Starts at 1 and increments on every successful update or delete. |
| `created_by` | integer | yes | Immutable author. |
| `updated_by` | integer | no | Last editor. |
| `created_at`, `updated_at` | timestamp | yes | UTC, ISO-8601 with microseconds. |
| `deleted_at` | timestamp | no | Soft-delete marker. A deleted row is kept but leaves every index. |

### Visibility and access

| Action | Public spec | Private spec |
|---|---|---|
| List / get / project list | any signed-in user with `spec.read` | author only |
| Create | any user with `spec.write` (public unless `visibility: private`) | same |
| Update / delete | **author only** (others get `FORBIDDEN`) | author only (others get `NOT_FOUND`) |
| Change visibility | author, through a normal update | author |
| Signed `/share/{token}` link | anyone with the link | anyone with the link |

A private spec is invisible, not forbidden: other users receive `NOT_FOUND`, so its existence does not leak. Public specs are not readable anonymously; they require a signed-in user (or a share link).

The project list (`/projects`, MCP `list_projects`) contains the distinct `project_name` values (case-insensitive) across the specs the viewer can see, with counts. A project that only has other people's private specs does not appear.

## 4. Application operations

```text
listSpecs(principal, filters, limit, cursor)    # public specs + the caller's private specs
listProjects(principal)
getSpec(principal, specId)
createSpec(principal, fields)                    # fields include visibility (default public)
updateSpec(principal, specId, patch, expectedRevision?)
deleteSpec(principal, specId, expectedRevision?)
shareUrlFor(specId) / getSpecByShareToken(token)
```

Rules:

- reads exclude soft-deleted rows;
- `listSpecs` returns compact summaries (no body) newest-first with signed, filter-bound cursors;
- filters (`query`, `project_name`, `status`) are applied by the service while scanning the visible streams, bounded at 1,000 rows per request. A sparse filter can therefore return a short page together with a `next_cursor`; keep paging until it is `null`;
- `updateSpec` treats omitted fields as unchanged; a supplied `expected_revision` that no longer matches returns `CONFLICT` with `current_revision`;
- without `expected_revision`, the service retries its read-modify-write up to four times against concurrent writers;
- create, update and delete commit their audit record **in the same DynamoDB transaction** as the mutation;
- audit details contain metadata (changed field names, revisions), never passwords or bodies.

## 5. User management and ACL

### User record

```text
id, username (unique, case-insensitive), password_hash, role, enabled, version,
created_at, updated_at, last_login_at
```

Passwords are stored only as PHP password hashes. An enabled user is reloaded from the store whenever a browser session or MCP token is resolved. `version` supports optimistic concurrency on user changes.

### Roles

| Role | Permissions |
|---|---|
| `viewer` | `spec.read` |
| `editor` | `spec.read`, `spec.write` |
| `admin` | `spec.read`, `spec.write`, `users.manage` |

### Authentication

- `GET/POST /login`; the session ID is regenerated and only the user ID is stored; the cookie is HTTP-only, Secure in production and SameSite-Strict.
- "Keep me signed in" (default on) issues a rotating selector/secret remember token (SHA-256 of the secret is stored, the raw value only in an HTTP-only cookie). Rotation is a conditional write, so a stolen cookie replayed after the legitimate browser used it fails.
- Remember tokens are revoked on logout, when the user is disabled and when the password changes.
- Login throttling keys on `sha256(username, client IP)` and uses versioned conditional writes, so parallel guesses cannot all read a stale counter. Behind the ALB (`SPECIAL_TRUST_PROXY=1`) the client IP is the right-most `X-Forwarded-For` entry (`SPECIAL_TRUSTED_PROXY_HOPS` for longer proxy chains).
- Every state-changing browser form carries a CSRF token; all rendered content is escaped.

### MCP authentication

Unchanged from the original: OAuth 2.1 authorization code flow with mandatory S256 PKCE, resource-bound codes and tokens, short-lived access tokens, rotating refresh tokens with family-wide revocation on replay, dynamic public-client registration and HTTPS Client ID Metadata Documents. The contract is in [`oauth-interop.md`](oauth-interop.md) and [`../docs/MCP_CONTRACT.md`](../docs/MCP_CONTRACT.md). Effective MCP access is the intersection of the current role and the granted scope.

### Admin operations

List users, create user, change role, enable/disable, change own password. All need `users.manage` except the last. The final enabled administrator cannot be disabled or demoted: the store keeps an `enabled_admins` counter that is adjusted inside the same transaction as the user change and refuses to drop below one. The first administrator is created on the web `/setup` page (guarded by a one-time setup token, ADR-021) or by a non-public CLI command; either succeeds only once for the lifetime of the table. An administrator can reset another user's password: a random temporary password is shown once, the user is signed out and their MCP tokens revoked, and they can reach only the account page until they choose a new password (ADR-022).

## 6. Web surface

```text
GET  /setup            POST /setup            # first-run only, needs the setup token; 404 afterwards
GET  /login            POST /login            POST /logout
GET  /specs            # list/search/filter, public + own private
GET  /specs/new        POST /specs
GET  /specs/{id}       GET  /specs/{id}/edit  POST /specs/{id}   POST /specs/{id}/delete
GET  /projects         # distinct projects visible to the viewer
GET  /share/{token}    # unauthenticated read-only capability view
GET  /account          POST /account/password
GET  /admin/users      POST /admin/users      POST /admin/users/{id}/role|enabled|reset-password
GET  /health           # 200 when the table is reachable, 503 otherwise
POST /mcp              # MCP JSON-RPC endpoint
```

Share permalinks are versioned, HMAC-signed payloads derived from `SPECIAL_APP_KEY`; the raw capability is never stored. Rotating the application key invalidates all share links (and also cursors and stored token hashes, which therefore also invalidates OAuth tokens: treat it as a "sign everyone out" switch).

Spec bodies are Markdown source, rendered with the pinned Parsedown 1.8.0 in safe mode; the copy button copies the source, never rendered HTML.

## 7. MCP contract

| Tool | Permission | Purpose |
|---|---|---|
| `list_specs` | `spec.read` | Bounded summaries of public specs and the caller's private specs; `limit`, `cursor`, `query`, `project_name`, `status`. |
| `list_projects` | `spec.read` | Distinct project names visible to the caller, with counts. |
| `get_spec` | `spec.read` | One complete spec (public, or the caller's own private); large bodies are delivered through `resources/read`. |
| `create_spec` | `spec.write` | Create a spec; `visibility` defaults to `public`. |
| `update_spec` | `spec.write` | Patch the caller's own spec (including `visibility`); optional `expected_revision`. |
| `delete_spec` | `spec.write` | Soft-delete the caller's own spec; optional `expected_revision`. |

Tool schemas use `additionalProperties: false`, bounded strings and cursors, stable error codes (`UNAUTHENTICATED`, `FORBIDDEN`, `INVALID_ARGUMENT`, `NOT_FOUND`, `CONFLICT`), and `tools/list` is filtered by the effective role/scope.

## 8. Persistence

All state is in one DynamoDB table with two global secondary indexes; see [`../docs/DYNAMODB.md`](../docs/DYNAMODB.md) for the key layout, access patterns and consistency notes. There are no migrations: the table definition is a CloudFormation resource (`deploy/aws/special-stack.yaml`) mirrored in `src/Storage/DynamoSchema.php` for DynamoDB Local. New attributes need no migration; a new access pattern needs a new GSI (an online operation) plus, for existing rows, a one-off backfill script.

### Web mutation

```text
request -> bootstrap -> session -> optional remember restore -> enabled principal
        -> CSRF + early permission gate -> SpecService
        -> validate -> read -> conditional write + audit (one transaction) -> HTML response
```

### MCP mutation

```text
JSON-RPC -> bearer principal -> schema validation -> permission gate -> SpecService
         -> validate -> read -> conditional write + audit (one transaction) -> bounded result/error
```

## 9. Project layout

```text
public/            front controller, static assets
src/               bootstrap, Security, OAuth, SpecService, mcp, Markdown
src/Storage/       Store interface, DynamoStore, MemoryStore, DynamoSchema, CredentialCache
views/             server-rendered templates
scripts/           bootstrap-admin, register-oauth-client, init-table, deployment-check
tests/             contract tests (Python harness) and the PHP behaviour suite
docker/            Apache vhost, php.ini, entrypoint
deploy/aws/        CloudFormation stack, production compose file, deploy and push scripts
docs/              AWS setup guide, DynamoDB design, Entra plan, ADRs, MCP and OAuth contracts
Dockerfile  docker-compose.yml  composer.json
```

## 10. Testing

`tests/behavior_test.php` drives `Security`, `SpecService`, `OAuth` and the MCP adapter against a `Store`. It runs against the in-memory store always, and against DynamoDB Local when `SPECIAL_DYNAMODB_ENDPOINT` is set, so both implementations are held to one contract. The Python contract tests cover configuration, Markdown safety, MCP schemas, redirect matching and a live HTTP smoke test. See the README for commands.

## 11. Explicit non-goals

- no separate project table or membership model;
- no anonymous reads except signed share links;
- no public self-registration, invitation or password-reset workflow (Entra will replace local accounts);
- no version-history table;
- no background workers or queues;
- no full-text search beyond bounded substring filters;
- no user administration through MCP;
- no horizontal scaling in v1: sessions are PHP files on a volume ([`../docs/DECISIONS.md`](../docs/DECISIONS.md), ADR-014).
