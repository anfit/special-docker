# MCP bearer tokens

MCP clients can authenticate either with the OAuth 2.1 flow in
[`OAUTH.md`](OAUTH.md) or with a long-lived bearer token issued by the user
who owns the token or by an administrator.

## Issue and revoke

Users can open **Account**, enter a label in the **MCP bearer tokens** section,
and select **Create bearer token**. Administrators can also issue tokens for
any user from that user's **MCP bearer tokens** section on the **Users** page.
The token is displayed once. Copy it into the MCP client's HTTP headers as:

```text
Authorization: Bearer spc_...
```

The user must be enabled and have completed any required password change
before a token can be issued. The Account and Users pages show each active
token's label, last four characters, and creation time. Users can revoke their
own tokens from Account; administrators can revoke any user's tokens from
Users. Disabling the account or resetting/changing its password revokes all of
its bearer and OAuth credentials.

## Permissions and storage

Each token has the permissions of its assigned user. The server
reloads that user's current role for each MCP request, so changing a role takes
effect for existing tokens. The token does not grant browser access or the
`users.manage` permission through MCP.

Token values are generated with 256 bits of randomness. The application stores
only a keyed hash, and the original value cannot be recovered. These tokens do
not expire automatically; revoke or replace them from the Account or Users page
when a client no longer needs access.
