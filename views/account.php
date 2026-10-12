<?php

declare(strict_types=1);

/** @var \Special\Principal $principal */
/** @var list<array<string, mixed>> $apiTokens */
?>
<h1>Account</h1>
<?php if ($principal->mustChangePassword): ?>
    <p class="flash error" role="alert">An administrator reset your password. Choose a new one to continue.</p>
<?php endif; ?>
<p>Signed in as <strong><?= special_escape($principal->username) ?></strong>.</p>
<section class="card">
    <h2>Change password</h2>
    <p class="muted">Changing your password revokes your active MCP tokens and remembered browser logins.</p>
    <form method="post" action="/account/password">
        <input type="hidden" name="csrf" value="<?= special_escape(special_csrf_token()) ?>">
        <p><label>New password <input name="password" type="password" minlength="12" autocomplete="new-password" required></label></p>
        <p><button type="submit">Change password</button></p>
    </form>
</section>
<section class="card">
    <h2>MCP bearer tokens</h2>
    <p>Bearer tokens let MCP clients connect as you. Each token has the permissions of your current role. You can revoke a token here at any time.</p>
    <?php if ($apiTokens === []): ?><p class="muted">You have no active bearer tokens.</p><?php endif; ?>
    <?php foreach ($apiTokens as $token): ?>
        <div class="card">
            <strong><?= special_escape((string) $token['name']) ?></strong>
            <span class="muted"> · ends <?= special_escape((string) $token['last_four']) ?> · created <?= special_escape((string) $token['created_at']) ?></span>
            <form class="inline" method="post" action="/account/tokens/<?= special_escape((string) $token['token_id']) ?>/revoke" data-confirm="Revoke <?= special_escape((string) $token['name']) ?>? MCP clients using it will stop working.">
                <input type="hidden" name="csrf" value="<?= special_escape(special_csrf_token()) ?>">
                <button class="danger" type="submit">Revoke</button>
            </form>
        </div>
    <?php endforeach; ?>
    <?php if (!$principal->mustChangePassword): ?>
        <form method="post" action="/account/tokens">
            <input type="hidden" name="csrf" value="<?= special_escape(special_csrf_token()) ?>">
            <p><label>New token name <input name="name" maxlength="80" required></label></p>
            <p><button class="secondary" type="submit">Create bearer token</button></p>
        </form>
    <?php else: ?>
        <p class="muted">Change your password before creating a bearer token.</p>
    <?php endif; ?>
</section>
<script src="/spec-copy.js" defer></script>
