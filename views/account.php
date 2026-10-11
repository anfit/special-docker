<?php

declare(strict_types=1);

/** @var \Special\Principal $principal */
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
