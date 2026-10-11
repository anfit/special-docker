# Special MCP contract

Special follows the MCP discovery/authentication boundary used by Scholion’s deployed implementation. Visibility rules (public specs are shared with every signed-in user, private ones are author-only) are described in [`../spec/README.md`](../spec/README.md#visibility-and-access). Internal authorization remains `spec.read` and `spec.write`; the external OAuth vocabulary is compatible with Scholion and current MCP clients.

## Discovery and transport

The endpoint is `POST /mcp`. CORS preflight is answered without touching
storage. The following method is available without a bearer token:

- `server/discover` — advertises the newer discovery draft, including `resultType: complete`, supported discovery version, tools/resources capabilities, and `io.modelcontextprotocol/serverInfo` metadata.

`initialize`, `ping`, `notifications/initialized`, `tools/list`, `tools/call`,
`resources/list`, `resources/templates/list`, and `resources/read` require a
valid bearer token. `initialize`, `ping`, and resource operations additionally
require the read scope. `tools/list` is available to any valid bearer and
filters its result by the role/scope intersection; a write-only client can
therefore discover write tools without receiving read access. Missing/invalid
credentials return HTTP 401 with:

```text
WWW-Authenticate: Bearer resource_metadata="<base-url>/.well-known/oauth-protected-resource/mcp", scope="model.read model.write"
```

An invalid or expired bearer adds `error="invalid_token"`; a valid token
without the required scope returns HTTP 403 with
`error="insufficient_scope"` and the missing scope in the challenge. The HTTP
adapter accepts `OPTIONS` and limits CORS to `https://chatgpt.com` and
`https://chat.openai.com`, exposing the MCP protocol/session and bearer
challenge headers. Request bodies and result pages are bounded.

## OAuth discovery and scopes

The server publishes both:

- `/.well-known/oauth-protected-resource` and the path-specific `/.well-known/oauth-protected-resource/mcp`;
- `/.well-known/oauth-authorization-server`, including `registration_endpoint`.

`POST /oauth/register` supports dynamic public-client registration. Authorization
uses code + mandatory S256 PKCE. Access tokens are short-lived; refresh tokens
rotate. The resource indicator is the exact `/mcp` URL; authorization requests
default to that resource when omitted, and token requests must send it
explicitly. Authorization-server metadata advertises
`client_id_metadata_document_supported: true`; clients may either register
dynamically or provide an HTTPS Client ID Metadata Document.

The metadata-document flow is bounded to a public HTTPS URL, fetches at most
64 KiB without following redirects, and accepts the document only when it
declares supported public-client grants, S256-compatible loopback/HTTPS
redirects, and a matching optional `client_id`. This supports Claude Code's
`https://claude.ai/oauth/claude-code-client-metadata` client ID without a
pre-registered client.

External scopes are:

| OAuth scope | Internal permission |
|---|---|
| `model.read` | `spec.read` |
| `model.write` | `spec.write` |
| `mcp:tools` | both `spec.read` and `spec.write` (legacy compatibility) |

The current role is reloaded for each bearer resolution. Effective access is the
intersection of the user role and the mapped token scope. The advertised
default grant is `model.read model.write`; clients can request a narrower
explicit scope. The legacy `mcp:tools` value remains accepted for compatibility
but is not advertised, and a mixed legacy/modern request is canonicalized to
the two modern scopes.

Loopback HTTP redirect registrations follow RFC 8252: the port may be selected
at runtime, while scheme, host, path, and query remain exact. HTTPS redirect
URIs remain exact-string matches. User-info and fragments are rejected.

Authorization codes and token pairs are bound to the canonical MCP resource.
Refresh tokens carry a family identifier; replaying a rotated refresh token
revokes every access and refresh token in that family.

## Tool discovery

The domain tool set is intentionally limited to:

| Tool | Permission | Behavior |
|---|---|---|
| `list_specs` | `spec.read` | Bounded summaries of public specs plus the caller's private specs, filters, opaque cursor. Summaries include `visibility`, `author` and `is_owner`. A sparse filter may return a short page with a non-null `next_cursor`: keep paging until it is `null` |
| `list_projects` | `spec.read` | Distinct project names visible to the caller with `spec_count`; takes no arguments |
| `get_spec` | `spec.read` | Complete spec (public, or the caller's own private); oversized body is externalized |
| `create_spec` | `spec.write` | Create a spec from the product fields; `visibility` is `public` (default) or `private` |
| `update_spec` | `spec.write` | Sparse patch of the caller's own spec (including `visibility`) with optional `expected_revision`. Another user's public spec yields `FORBIDDEN`; a private one `NOT_FOUND` |
| `delete_spec` | `spec.write` | Recoverable soft delete of the caller's own spec with optional revision guard |

Every published tool has `additionalProperties: false`, bounded fields, `annotations` (`readOnlyHint`, `destructiveHint`, `idempotentHint`, `openWorldHint`), and an OAuth `securitySchemes` declaration. `tools/list` only publishes tools permitted by the current role/scope; `tools/call` enforces the same permission again.

Successful tool responses use a complete result envelope with `structuredContent.data` and `structuredContent.meta`. Domain failures use JSON-RPC errors with numeric JSON-RPC codes plus stable application codes such as `UNAUTHENTICATED`, `FORBIDDEN`, `INVALID_ARGUMENT`, `NOT_FOUND`, and `CONFLICT`.

List responses are measured against the complete MCP envelope. If descriptions
would exceed the configured response budget, the page keeps its bounded item
count and returns complete compact summaries with `description` explicitly
listed in `summary_fields_omitted`. If even the compact page cannot fit, the
request fails with a bounded `INVALID_ARGUMENT` response asking the client to
reduce `limit`.

## Complete body delivery

Spec `body` values are Markdown source text. Inline tool results return that
source unchanged; the browser’s safe renderer is not part of the MCP contract.
The complete-body resource is therefore advertised as
`text/markdown; charset=utf-8`.

When a `get_spec` result would exceed `SPECIAL_MCP_RESPONSE_BYTES`, the response omits the inline `body` and includes byte length, SHA-256, `body_delivery_status: resource_required`, and a `body_resource_uri` such as:

```text
special://specs/42/body
```

Clients retrieve it with authenticated `resources/read`. The resource returns
the exact Markdown source in one `text` content item with
`mimeType: text/markdown; charset=utf-8`; it is never rendered, truncated,
summarized, or silently replaced.
