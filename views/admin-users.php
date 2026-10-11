<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $users */
/** @var \Special\Principal $principal */
/** @var string|null $error */
?>
<h1>User administration</h1>
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
    <thead><tr><th>Username</th><th>Role</th><th>Enabled</th><th>Actions</th></tr></thead>
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
                <form method="post" action="/admin/users/<?= (int) $user['id'] ?>/enabled">
                    <input type="hidden" name="csrf" value="<?= special_escape(special_csrf_token()) ?>">
                    <input type="hidden" name="enabled" value="<?= $user['enabled'] ? '0' : '1' ?>">
                    <button class="<?= $user['enabled'] ? 'danger' : 'secondary' ?>" type="submit"><?= $user['enabled'] ? 'Disable' : 'Enable' ?></button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

