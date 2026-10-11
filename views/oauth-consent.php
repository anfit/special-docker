<?php

declare(strict_types=1);

/** @var array{client_id: string, redirect_uri: string, resource: string, scope: string, scopes: list<string>, code_challenge: string, code_challenge_method: string, state: ?string} $oauthRequest */
/** @var string $clientName */
?>
<h1>Authorize MCP access</h1>
<p><strong><?= special_escape($clientName) ?></strong> is requesting access to your Special specs.</p>
<ul>
    <?php foreach ($oauthRequest['scopes'] as $scope): ?><li><?= special_escape($scope) ?></li><?php endforeach; ?>
</ul>
<form method="post" action="/oauth/authorize">
    <input type="hidden" name="csrf" value="<?= special_escape(special_csrf_token()) ?>">
    <input type="hidden" name="response_type" value="code">
    <?php foreach (['client_id', 'redirect_uri', 'resource', 'scope', 'code_challenge', 'code_challenge_method', 'state'] as $field): ?>
        <?php if ($oauthRequest[$field] !== null): ?><input type="hidden" name="<?= $field ?>" value="<?= special_escape((string) $oauthRequest[$field]) ?>"><?php endif; ?>
    <?php endforeach; ?>
    <p class="actions"><button name="approve" value="1" type="submit">Allow</button> <button class="secondary" name="approve" value="0" type="submit">Deny</button></p>
</form>
