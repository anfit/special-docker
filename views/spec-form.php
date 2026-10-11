<?php

declare(strict_types=1);

/** @var bool $isEdit */
/** @var array<string, mixed>|null $spec */
/** @var string $action */
/** @var string|null $error */
?>
<h1><?= $isEdit ? 'Edit spec' : 'New spec' ?></h1>
<?php if ($error !== null): ?><p class="error" role="alert"><?= special_escape($error) ?></p><?php endif; ?>
<form method="post" action="<?= special_escape($action) ?>">
    <input type="hidden" name="csrf" value="<?= special_escape(special_csrf_token()) ?>">
    <?php if ($isEdit): ?><input type="hidden" name="expected_revision" value="<?= (int) $spec['revision'] ?>"><?php endif; ?>
    <p><label>Title <input name="title" value="<?= special_escape($spec['title'] ?? '') ?>" maxlength="200" required></label></p>
    <p><label>Project name <input name="project_name" value="<?= special_escape($spec['project_name'] ?? '') ?>" maxlength="200" required></label></p>
    <p><label>Status
        <select name="status" required>
            <?php foreach (['draft', 'active', 'archived'] as $option): ?>
                <option value="<?= $option ?>" <?= ($spec['status'] ?? 'draft') === $option ? 'selected' : '' ?>><?= ucfirst($option) ?></option>
            <?php endforeach; ?>
        </select>
    </label></p>
    <p><label>Visibility
        <select name="visibility" required>
            <?php foreach (['public' => 'Public: visible to everyone who is signed in', 'private' => 'Private: visible only to me'] as $option => $label): ?>
                <option value="<?= $option ?>" <?= ($spec['visibility'] ?? 'public') === $option ? 'selected' : '' ?>><?= special_escape($label) ?></option>
            <?php endforeach; ?>
        </select>
    </label></p>
    <p><label>Description <textarea name="description" maxlength="2000" rows="4"><?= special_escape($spec['description'] ?? '') ?></textarea></label></p>
    <p><label>Body (Markdown) <textarea name="body" maxlength="100000" rows="20"><?= special_escape($spec['body'] ?? '') ?></textarea><span class="muted form-help">Markdown is stored as source text and rendered safely when the spec is displayed.</span></label></p>
    <p class="actions"><button type="submit">Save</button> <a class="button secondary" href="<?= $isEdit ? '/specs/' . (int) $spec['id'] : '/specs' ?>">Cancel</a></p>
</form>
