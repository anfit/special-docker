<?php

declare(strict_types=1);

/** @var string $returnTo */
/** @var string|null $error */
?>
<h1>Log in</h1>
<?php if ($error !== null): ?><p class="error" role="alert"><?= special_escape($error) ?></p><?php endif; ?>
<form method="post" action="/login">
    <input type="hidden" name="csrf" value="<?= special_escape(special_csrf_token()) ?>">
    <input type="hidden" name="return_to" value="<?= special_escape($returnTo) ?>">
    <p><label>Username <input name="username" autocomplete="username" required maxlength="100" autofocus></label></p>
    <p><label>Password <input name="password" type="password" autocomplete="current-password" required></label></p>
    <p><label><input name="remember_me" type="checkbox" value="1" checked> Keep me signed in</label></p>
    <p><button type="submit">Log in</button></p>
</form>
