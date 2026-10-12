# MCP OAuth boundary

Special keeps browser sessions and MCP credentials independent.

The advertised default grant is `model.read model.write`; the legacy
`mcp:tools` value remains accepted for compatibility but is not advertised.

## Endpoints

- `GET /.well-known/oauth-protected-resource` and `GET /.well-known/oauth-protected-resource/mcp` publish the MCP resource and authorization-server relationship.
- `GET /.well-known/oauth-authorization-server` publishes issuer, authorization, token, dynamic-registration, scope, PKCE, and Client ID Metadata Document support metadata.
- `POST /oauth/register` supports dynamic public-client registration for MCP clients.
- `GET/POST /oauth/authorize` validates a registered redirect URI, requires S256 PKCE, displays the signed-in user a consent screen, and returns a one-use authorization code.
- `POST /oauth/token` accepts `authorization_code` and `refresh_token` grants. Form-encoded requests are the interoperable default; JSON input is also accepted for controlled clients.
- `POST /mcp` accepts a bearer access token after the MCP client has completed OAuth.

The external scopes are `model.read`, `model.write`, and legacy `mcp:tools`; they map to the internal `spec.read` and `spec.write` permissions. The user role is reloaded when a bearer token is resolved, so effective access is the intersection of the current role and the token scope. `users.manage` remains a browser/CLI-only permission.

Access tokens are short-lived and refresh tokens rotate on use. Authorization
codes and tokens are bound to the exact configured MCP resource. Reuse of a
rotated refresh token revokes its complete access/refresh-token family.
Disabling a user or changing their password revokes stored access and refresh
tokens. Bearer values are stored only as keyed hashes.

Register a fixed client through the non-public CLI (inside the container: `docker compose exec app ...`):

```text
php scripts/register-oauth-client.php --client-id local-mcp --name "Local MCP" --redirect-uri http://localhost:8765/callback
```

Use confidential clients only when the client can protect a secret:

```text
SPECIAL_OAUTH_CLIENT_SECRET="<secret>" php scripts/register-oauth-client.php --client-id server-mcp --name "Server MCP" --redirect-uri https://client.example/callback --type confidential
```

External clients may use dynamic registration at `/oauth/register`, the
Client ID Metadata Document flow, or the CLI for controlled fixed clients.
For example, GitHub Copilot Desktop uses
`https://github.com/copilot/desktop/client-metadata.json`. Redirects may use
HTTPS, loopback HTTP, or a native application's custom URI scheme. Custom
scheme redirects must match the registered URI exactly; loopback HTTP ports
may vary at runtime. The authorization server requires S256 PKCE for every
client and supports only authorization-code and refresh-token grants. Extra
grant types listed as client capabilities do not enable those grants on the
server. The token request must include the exact MCP `resource` value
(authorization requests default to it when omitted). The endpoint and
resource-link convention is recorded in [`MCP_CONTRACT.md`](MCP_CONTRACT.md).
