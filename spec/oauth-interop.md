# Special MCP/OAuth interoperability specification

This document records the current MCP boundary that Special shares with
Scholion-shaped clients. It is deliberately narrower than a general OAuth
provider: the only protected resource is the canonical `POST /mcp` endpoint,
and the only domain permissions are specification read and write.

## Discovery and authentication

`server/discover` is public and returns the `2026-07-28` discovery shape.
`initialize`, `ping`, `notifications/initialized`, tools, and resources require
a bearer access token. Initialization and resource operations require the
read scope; `tools/list` remains available to any valid bearer so a write-only
client can discover its write tools.

An unauthenticated protected request returns HTTP 401 and a
`WWW-Authenticate` challenge containing the path-specific protected-resource
metadata URL and the default `model.read model.write` scope. Invalid tokens
add `error="invalid_token"`. A role-authorized request missing a required
token scope returns HTTP 403 with `error="insufficient_scope"` and the
required scope.

## OAuth onboarding

The authorization server publishes dynamic registration, authorization-code
and refresh-token grants, S256 PKCE, the modern `model.read` and `model.write`
scopes, and `client_id_metadata_document_supported: true`. The legacy
`mcp:tools` scope is accepted for existing clients but is not advertised.

Clients may use either:

1. `POST /oauth/register` for public dynamic registration; or
2. an HTTPS Client ID Metadata Document as the `client_id`, including Claude Code's
   `https://claude.ai/oauth/claude-code-client-metadata` identifier.

Metadata documents are fetched only over public HTTPS, without redirects, with
a three-second timeout and a 64 KiB response limit. They must declare one to
ten valid redirect URIs, public token authentication, and only supported grant
and response types.

Authorization requires `response_type=code`, validates the canonical
`resource` (and defaults to it when omitted), and requires S256 PKCE. Token
exchange requires the resource explicitly. A missing scope defaults to
`model.read model.write`. Loopback
HTTP callback ports are intentionally ignored for matching, while scheme,
host, path, and query remain exact. HTTPS callbacks remain exact-string
matches. Redirect fragments and user-info are rejected.

Code exchange revalidates the client, the exact redirect URI used by the
authorization request, the one-use code, the PKCE verifier, and the canonical
resource before issuing a token pair.

## Grant storage and lifecycle

Authorization codes, access tokens, and refresh tokens are stored as hashes and
bound to the canonical MCP resource. Existing pre-binding rows may have a
null resource for backward compatibility within Special's isolated database;
all newly issued rows are bound explicitly. Access tokens are rejected when
their resource does not match.

Refresh rotation preserves a family identifier across the new pair. Replaying
any revoked refresh token revokes every access and refresh token in that family
before returning an invalid-grant response. Disabled users and credential
changes revoke stored grants as well.

## Acceptance checks

1. Public discovery succeeds, while unauthenticated `initialize` is challenged.
2. Dynamic registration accepts valid public clients and rejects unsupported
   grant, response, redirect, and token-auth metadata.
3. A metadata-document client can authorize with an ephemeral localhost port;
   path/query changes and HTTPS port changes are rejected.
4. Authorization and token exchange require the canonical resource and S256
   PKCE.
5. Read-only and write-only grants remain separated at tool discovery and call
   time, with OAuth-compatible 401/403 responses.
6. Refresh rotation works once, and replay of the old refresh token revokes its
   complete family.
