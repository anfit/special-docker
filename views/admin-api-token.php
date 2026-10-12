<?php

declare(strict_types=1);

/** @var array<string, mixed> $tokenUser */
/** @var string $tokenName */
/** @var string $bearerToken */
?>
<h1>MCP bearer token created</h1>
<p><strong><?= special_escape($tokenName) ?></strong> is assigned to <?= special_escape((string) $tokenUser['username']) ?>. It inherits this user's current permissions.</p>
<p>This secret is shown once. Copy it now and add it to the MCP client's HTTP headers as <code>Authorization: Bearer &lt;token&gt;</code>. The application stores only a keyed hash, so it cannot be shown again.</p>
<p>
    <input id="admin-api-token" readonly value="<?= special_escape($bearerToken) ?>" size="60" aria-label="MCP bearer token">
    <button type="button" data-copy-target="admin-api-token" data-copy-status="admin-api-token-copy-status">Copy token</button>
    <span id="admin-api-token-copy-status" role="status" aria-live="polite"></span>
</p>
<p>Revoke this token from its user's entry on the user administration page. Disabling the user or resetting their password revokes all of their tokens.</p>
<p><a class="button secondary" href="/admin/users">Back to users</a></p>
<script src="/spec-copy.js" defer></script>
