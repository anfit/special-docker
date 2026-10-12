<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $users */
/** @var \Special\Principal $principal */
/** @var string|null $error */
?>
<h1>User administration</h1>
<script src="/spec-copy.js" defer></script>
<?php if ($error !== null): ?><p class="error" role="alert"><?= special_escape($error) ?></p><?php endif; ?>
<section class="card">
    <h2>Create user</h2>
    <form method="post" action="/admin/users">
        <input type="hidden" name="csrf" value="<?= special_escape(special_csrf_token()) ?>">
        <p><label>Username <input name="username" maxlength="100" required></label></p>
        <p><label>Temporary password <input name="password" type="password" minlength="12" required></label></p>
        <p><label>Role <select name="role"><option>viewer</option><option>editor</option><option>admin</option></select></label></p>
        <p><button type="submit">Create user</button></p>
    </form>
</section>
<section class="card">
    <h2>Change your password</h2>
    <form method="post" action="/account/password">
        <input type="hidden" name="csrf" value="<?= special_escape(special_csrf_token()) ?>">
        <p><label>New password <input name="password" type="password" minlength="12" required></label></p>
        <p><button type="submit">Change password</button></p>
    </form>
</section>
<h2>Users</h2>
<table>
    <thead><tr><th>Username</th><th>Role</th><th>Enabled</th><th>MCP bearer tokens</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($users as $user): ?>
        <tr>
            <td><?= special_escape((string) $user['username']) ?></td>
            <td>
                <form method="post" action="/admin/users/<?= (int) $user['id'] ?>/role">
                    <input type="hidden" name="csrf" value="<?= special_escape(special_csrf_token()) ?>">
                    <select name="role" aria-label="Role for <?= special_escape((string) $user['username']) ?>">
                        <?php foreach (['viewer', 'editor', 'admin'] as $role): ?><option value="<?= $role ?>" <?= $user['role'] === $role ? 'selected' : '' ?>><?= $role ?></option><?php endforeach; ?>
                    </select>
                    <button type="submit">Save role</button>
                </form>
            </td>
            <td><?= $user['enabled'] ? 'Yes' : 'No' ?></td>
            <td>
                <?php foreach ($user['api_tokens'] as $token): ?>
                    <div class="card">
                        <strong><?= special_escape((string) $token['name']) ?></strong>
                        <span class="muted"> · ends <?= special_escape((string) $token['last_four']) ?> · created <?= special_escape((string) $token['created_at']) ?></span>
                        <form class="inline" method="post" action="/admin/users/<?= (int) $user['id'] ?>/tokens/<?= special_escape((string) $token['token_id']) ?>/revoke" data-confirm="Revoke <?= special_escape((string) $token['name']) ?> for <?= special_escape((string) $user['username']) ?>? MCP clients using it will stop working.">
                            <input type="hidden" name="csrf" value="<?= special_escape(special_csrf_token()) ?>">
                            <button class="danger" type="submit">Revoke</button>
                        </form>
                    </div>
                <?php endforeach; ?>
                <?php if ($user['enabled'] && !$user['must_change_password']): ?>
                    <form method="post" action="/admin/users/<?= (int) $user['id'] ?>/tokens">
                        <input type="hidden" name="csrf" value="<?= special_escape(special_csrf_token()) ?>">
                        <label>New token name <input name="name" maxlength="80" required></label>
                        <button class="secondary" type="submit">Create bearer token</button>
                    </form>
                <?php else: ?>
                    <span class="muted">Enable this user and finish any required password change to create a token.</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if ((int) $user['id'] !== $principal->id): ?>
                    <form class="inline" method="post" action="/admin/users/<?= (int) $user['id'] ?>/reset-password" data-confirm="Reset the password for <?= special_escape((string) $user['username']) ?>? They will be signed out everywhere.">
                        <input type="hidden" name="csrf" value="<?= special_escape(special_csrf_token()) ?>">
                        <button class="secondary" type="submit">Reset password</button>
                    </form>
                <?php endif; ?>
                <form class="inline" method="post" action="/admin/users/<?= (int) $user['id'] ?>/enabled">
                    <input type="hidden" name="csrf" value="<?= special_escape(special_csrf_token()) ?>">
                    <input type="hidden" name="enabled" value="<?= $user['enabled'] ? '0' : '1' ?>">
                    <button class="<?= $user['enabled'] ? 'danger' : 'secondary' ?>" type="submit"><?= $user['enabled'] ? 'Disable' : 'Enable' ?></button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

