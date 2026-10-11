<?php

declare(strict_types=1);

/** @var array<string, mixed> $spec */
/** @var \Special\Principal|null $principal */
/** @var bool $isShared */
/** @var string|null $shareUrl */
$isShared = $isShared ?? false;
$shareUrl = $shareUrl ?? null;
$backUrl = $isShared ? '/login' : '/specs';
$canEdit = !$isShared
    && $principal instanceof \Special\Principal
    && $principal->can('spec.write')
    && (int) $spec['created_by'] === $principal->id;
?>
<div class="toolbar">
    <h1><?= special_escape((string) $spec['title']) ?></h1>
    <div class="actions">
        <?php if ($canEdit): ?><a class="button" href="/specs/<?= (int) $spec['id'] ?>/edit">Edit</a><?php endif; ?>
        <a class="button secondary" href="<?= special_escape($backUrl) ?>"><?= $isShared ? 'Sign in' : 'Back' ?></a>
    </div>
</div>
<?php if ($isShared): ?><p class="share-notice" role="status">Shared read-only view. Anyone with this link can view this spec.</p><?php endif; ?>
<p class="muted"><span class="badge <?= special_escape((string) $spec['visibility']) ?>"><?= special_escape((string) $spec['visibility']) ?></span> Project: <?= $isShared ? special_escape((string) $spec['project_name']) : '<a href="/specs?project_name=' . special_escape(rawurlencode((string) $spec['project_name'])) . '">' . special_escape((string) $spec['project_name']) . '</a>' ?> · Status: <?= special_escape((string) $spec['status']) ?> · Revision: <?= (int) $spec['revision'] ?> · Author: <?= special_escape((string) ($spec['author'] ?? 'unknown')) ?></p>
<?php if ($spec['description'] !== null && $spec['description'] !== ''): ?><p><?= special_escape((string) $spec['description']) ?></p><?php endif; ?>
<?php if (!$isShared && $shareUrl !== null): ?>
    <section class="share-panel" aria-labelledby="top-share-link-heading">
        <div class="spec-panel-header">
            <h2 id="top-share-link-heading">Permalink</h2>
            <button
                class="secondary copy-button"
                type="button"
                data-copy-target="spec-share-link-top"
                data-copy-status="spec-share-copy-status-top"
                aria-label="Copy share link to clipboard"
            >Copy link</button>
        </div>
        <div class="share-link-row">
            <input id="spec-share-link-top" type="url" readonly value="<?= special_escape($shareUrl) ?>" aria-label="Share link">
        </div>
        <p id="spec-share-copy-status-top" class="copy-status muted" aria-live="polite"></p>
    </section>
<?php endif; ?>
<section class="spec-panel" aria-labelledby="spec-body-heading">
    <div class="spec-panel-header">
        <h2 id="spec-body-heading">Body</h2>
        <button
            class="secondary copy-button"
            type="button"
            data-copy-target="spec-body"
            data-copy-source="spec-body-source"
            data-copy-status="spec-copy-status"
            aria-label="Copy spec body to clipboard"
        >Copy</button>
    </div>
    <div id="spec-body" class="body markdown-body"><?= special_markdown($spec['body'] ?? null) ?></div>
    <textarea id="spec-body-source" hidden aria-hidden="true" tabindex="-1" readonly><?= special_escape($spec['body'] ?? null) ?></textarea>
    <p id="spec-copy-status" class="copy-status muted" aria-live="polite"></p>
</section>
<?php if (!$isShared && $shareUrl !== null): ?>
    <section class="share-panel" aria-labelledby="share-link-heading">
        <h2 id="share-link-heading">Share this spec</h2>
        <p class="muted">Anyone with this link can view a read-only copy of this spec.</p>
        <div class="share-link-row">
            <input id="spec-share-link" type="url" readonly value="<?= special_escape($shareUrl) ?>" aria-label="Share link">
            <button
                class="secondary copy-button"
                type="button"
                data-copy-target="spec-share-link"
                data-copy-status="spec-share-copy-status"
                aria-label="Copy share link to clipboard"
            >Copy link</button>
        </div>
        <p id="spec-share-copy-status" class="copy-status muted" aria-live="polite"></p>
    </section>
<?php endif; ?>
<script src="/spec-copy.js" defer></script>
<?php if ($canEdit): ?>
    <form method="post" action="/specs/<?= (int) $spec['id'] ?>/delete" data-confirm="Delete this spec?">
        <input type="hidden" name="csrf" value="<?= special_escape(special_csrf_token()) ?>">
        <input type="hidden" name="expected_revision" value="<?= (int) $spec['revision'] ?>">
        <button class="danger" type="submit">Delete spec</button>
    </form>
<?php endif; ?>
