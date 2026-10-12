# Admin-issued MCP bearer tokens

MCP clients can authenticate either with the OAuth 2.1 flow in
[`OAUTH.md`](OAUTH.md) or with a long-lived bearer token issued by an
administrator.

## Issue and revoke

An administrator opens **Users**, enters a label in the target user's
**MCP bearer tokens** section, and selects **Create bearer token**. The token
is displayed once. Copy it into the MCP client's HTTP headers as:

```text
Authorization: Bearer spc_...
```

The user must be enabled and have completed any required password change
before an administrator can issue a token. The Users page shows each active
token's label, last four characters, and creation time. An administrator can
revoke a token there at any time. Disabling the account or resetting/changing
its password revokes all of its bearer and OAuth credentials.

## Permissions and storage

An admin-issued token has the permissions of its assigned user. The server
reloads that user's current role for each MCP request, so changing a role takes
effect for existing tokens. The token does not grant browser access or the
`users.manage` permission through MCP.

Token values are generated with 256 bits of randomness. The application stores
only a keyed hash, and the original value cannot be recovered. These tokens do
not expire automatically; revoke or replace them from the Users page when a
client no longer needs access.
