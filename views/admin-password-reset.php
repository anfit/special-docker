<?php

declare(strict_types=1);

/** @var array<string, mixed> $resetUser */
/** @var string $temporaryPassword */
?>
<h1>Temporary password</h1>
<p>The password for <strong><?= special_escape((string) $resetUser['username']) ?></strong> was reset and the user was signed out everywhere.</p>
<p>Give them this temporary password through a trusted channel. <strong>It is shown only once.</strong> They must choose a new password at their next sign-in.</p>
<p><input id="temporary-password" readonly value="<?= special_escape($temporaryPassword) ?>" size="28" aria-label="Temporary password"></p>
<p><a class="button" href="/admin/users">Back to users</a></p>
