<?php

declare(strict_types=1);

/** @var string|null $error */
/** @var string $username */
/** @var bool $tokenFromEnvironment */
?>
<h1>First-run setup</h1>
<p>No administrator exists yet. Create the first one. This page disappears as soon as it is done.</p>
<?php if ($error !== null): ?><p class="error" role="alert"><?= special_escape($error) ?></p><?php endif; ?>
<form method="post" action="/setup" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= special_escape(special_csrf_token()) ?>">
    <p><label>Setup token
        <input name="token" required maxlength="200" autocomplete="off" spellcheck="false">
        <span class="muted form-help">
            <?php if ($tokenFromEnvironment): ?>
                The token configured for this deployment (<code>SPECIAL_SETUP_TOKEN</code>).
            <?php else: ?>
                Printed once to the application log when this page was first opened (in AWS: CloudWatch log group, stream <code>app</code>; on a host: <code>docker compose logs app</code>). It is valid for 24 hours.
            <?php endif; ?>
        </span>
    </label></p>
    <p><label>Administrator username (a login name or email address) <input name="username" value="<?= special_escape($username) ?>" required maxlength="100" autocomplete="username"></label></p>
    <p><label>Password (12 characters or more) <input name="password" type="password" minlength="12" required autocomplete="new-password"></label></p>
    <p><label>Repeat password <input name="password_confirm" type="password" minlength="12" required autocomplete="new-password"></label></p>
    <p><button type="submit">Create administrator</button></p>
</form>
