<?php

declare(strict_types=1);

/** @var array<string, mixed> $tokenUser */
/** @var string $tokenName */
/** @var string $bearerToken */
/** @var bool $isOwnToken */
/** @var string $returnUrl */
/** @var string $returnLabel */
?>
<h1>MCP bearer token created</h1>
<?php if ($isOwnToken): ?>
    <p><strong><?= special_escape($tokenName) ?></strong> is assigned to you and inherits your current permissions.</p>
<?php else: ?>
    <p><strong><?= special_escape($tokenName) ?></strong> is assigned to <?= special_escape((string) $tokenUser['username']) ?> and inherits this user's current permissions.</p>
<?php endif; ?>
<p>This secret is shown once. Copy it now and add it to the MCP client's HTTP headers as <code>Authorization: Bearer &lt;token&gt;</code>. The application stores only a keyed hash, so it cannot be shown again.</p>
<p>
    <input id="api-token" readonly value="<?= special_escape($bearerToken) ?>" size="60" aria-label="MCP bearer token">
    <button type="button" data-copy-target="api-token" data-copy-status="api-token-copy-status">Copy token</button>
    <span id="api-token-copy-status" role="status" aria-live="polite"></span>
</p>
<p>Revoke this token from <?= $isOwnToken ? 'your Account page' : 'its user entry on the user administration page' ?>. Disabling the user or resetting their password revokes all of their tokens.</p>
<p><a class="button secondary" href="<?= special_escape($returnUrl) ?>"><?= special_escape($returnLabel) ?></a></p>
<script src="/spec-copy.js" defer></script>
