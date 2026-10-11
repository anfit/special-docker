# Moving user management to Microsoft Entra ID

Status: **planned, not implemented.** This page records how the current design leaves room for it, so the switch is a contained change.

## Who may sign in (decision)

Special remains an internal system. **Entra decides who may use it**: set *Assignment required* on the enterprise application and assign users, groups or invited B2B guests to it. Anyone Entra lets in is an ordinary Special user; there is no member/guest distinction inside the app, and `public` keeps meaning "visible to every signed-in user". Externals therefore need no special model: invite them as guests in Entra and assign an app role. Access is removed by unassigning them in Entra.

## What stays the same

Authorization is expressed in terms of a `Principal` (id, username, role, scopes) and the permissions `spec.read`, `spec.write`, `users.manage`. Specs reference users by integer `created_by` / `updated_by`. MCP bearer tokens resolve to a `Principal` through the user record. None of that cares how the user proved who they are.

## What changes

| Concern | Today (local) | With Entra |
|---|---|---|
| Browser sign-in | `POST /login` with username and password, throttling, remember-me cookie | OpenID Connect authorization-code flow with PKCE against Entra; the app keeps its own session cookie after the callback |
| Identity key | `username` (unique) | Entra `oid` (stable object id) stored on the user, with `tid` checked against the allowed tenant. UPN/email stays a display attribute |
| Account creation | admin creates users; first admin by CLI | just-in-time on first sign-in, with role taken from Entra **app roles** (`Special.Viewer`, `Special.Editor`, `Special.Admin`) assigned to users or groups |
| Passwords | PHP hashes, change-password page | none: remove `/account/password`, `password_hash`, throttling and remember-me |
| Disabling a user | admin toggles `enabled` | disabled in Entra; the local record is also kept so history is preserved. Sessions end when the session expires or the id token's `exp` passes |
| MCP / OAuth | Special is its own OAuth authorization server and users approve on its consent page | unchanged at the protocol level: the consent page is reached after an Entra sign-in. Special remains the authorization server for MCP clients; Entra is only its identity provider |

## Seams that already exist

- `Security::authenticate()` is the only place that checks a password. In the Entra design it is replaced by a callback handler that validates the ID token (issuer, audience, signature, nonce, `tid`) and calls a new `Security::principalForExternalIdentity(oid, tid, claims)`.
- The user row can gain `idp` (`local` / `entra`) and `idp_subject` (`oid`) attributes without a migration (DynamoDB items are schemaless). Add a GSI `IDP#<idp>#<subject>` (or an `IDPLOCK#...` item like `USERNAME#...`) for the lookup; a GSI can be added online.
- `Principal` already separates `role` from the user record; with Entra the role is recomputed at sign-in from the token's `roles` claim and refreshed on each sign-in.
- Everything downstream keys off the integer user id, so existing specs, audit entries and OAuth grants survive when a local account is *linked* to its Entra identity rather than recreated.

## Suggested migration path

1. Register the app in Entra (single tenant, redirect URI `https://<domain>/auth/callback`, app roles `Special.Viewer|Editor|Admin`, group or user assignments).
2. Add the OIDC client (the app has no dependencies besides the AWS SDK today; use a vetted library such as `jumbojett/openid-connect-php` or `league/oauth2-client` with the Microsoft provider rather than hand-rolling token validation).
3. Store `SPECIAL_ENTRA_TENANT_ID`, `SPECIAL_ENTRA_CLIENT_ID` in SSM; the client secret (or better, a certificate / federated credential) in SSM SecureString, read by `deploy.sh` like the app key.
4. Ship with **both** login paths enabled. Each existing user signs in once through Entra; the callback links the account by matching a verified email/UPN to an existing local username and writes `idp_subject`. Unlinked Entra users are created just-in-time.
5. Switch the login page to "Sign in with Microsoft", keeping `/login` only behind a break-glass flag for the bootstrap admin.
6. After everyone is linked, remove password hashes (a one-off script that removes `password_hash` from user items), the throttling and remember-me code, and the local-password UI.

## Things to decide when it happens

- Whether role changes made in Entra must take effect immediately (then validate against Microsoft Graph, or shorten session lifetime) or at next sign-in (simplest).
- Whether MCP access tokens should additionally be revoked when a user is removed from the Entra app role. Today disabling a user revokes their tokens immediately; with Entra the equivalent is a periodic reconciliation or a Graph change-notification webhook.
- Guest users and multi-tenant access (not planned; keep it single tenant).
