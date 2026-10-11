<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $items */
/** @var string|null $nextCursor */
/** @var string|null $query */
/** @var string|null $projectName */
/** @var string|null $status */
/** @var \Special\Principal $principal */
?>
<div class="toolbar">
    <h1>Specs</h1>
    <?php if ($principal->can('spec.write')): ?><a class="button" href="/specs/new">New spec</a><?php endif; ?>
</div>
<p class="muted">Public specs are visible to everyone who is signed in. Private specs are visible only to their author.</p>
<form class="filters" method="get" action="/specs">
    <label>Search <input name="query" value="<?= special_escape($query) ?>" maxlength="200"></label>
    <label>Project <input name="project_name" value="<?= special_escape($projectName) ?>" maxlength="200"></label>
    <label>Status
        <select name="status">
            <option value="">Any</option>
            <?php foreach (['draft', 'active', 'archived'] as $option): ?>
                <option value="<?= $option ?>" <?= $status === $option ? 'selected' : '' ?>><?= ucfirst($option) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <button type="submit">Filter</button>
    <a class="button secondary" href="/specs">Clear</a>
</form>
<?php if ($items === []): ?>
    <p class="muted">No specs found.</p>
<?php else: ?>
    <ul class="spec-list">
        <?php foreach ($items as $spec): ?>
            <li>
                <div class="spec-list-row">
                    <div class="spec-list-info">
                        <a href="/specs/<?= (int) $spec['id'] ?>"><strong><?= special_escape((string) $spec['title']) ?></strong></a>
                        <span class="badge <?= special_escape((string) $spec['visibility']) ?>"><?= special_escape((string) $spec['visibility']) ?></span>
                        <span class="muted"> · <a href="/specs?project_name=<?= special_escape(rawurlencode((string) $spec['project_name'])) ?>"><?= special_escape((string) $spec['project_name']) ?></a> · <?= special_escape((string) $spec['status']) ?> · rev <?= (int) $spec['revision'] ?> · by <?= special_escape((string) ($spec['author'] ?? 'unknown')) ?></span>
                    </div>
                    <div class="spec-list-actions">
                        <button
                            class="secondary copy-button"
                            type="button"
                            data-copy-target="spec-share-link-<?= (int) $spec['id'] ?>"
                            data-copy-status="spec-share-copy-status-<?= (int) $spec['id'] ?>"
                            aria-label="Copy permalink for <?= special_escape((string) $spec['title']) ?>"
                        >Copy link</button>
                        <span id="spec-share-copy-status-<?= (int) $spec['id'] ?>" class="copy-status muted" aria-live="polite"></span>
                    </div>
                </div>
                <?php if ($spec['description'] !== null && $spec['description'] !== ''): ?><div><?= special_escape((string) $spec['description']) ?></div><?php endif; ?>
                <input id="spec-share-link-<?= (int) $spec['id'] ?>" type="url" hidden aria-hidden="true" tabindex="-1" readonly value="<?= special_escape((string) $spec['share_url']) ?>">
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
<?php if ($nextCursor !== null): ?>
    <?php $params = array_filter(['query' => $query, 'project_name' => $projectName, 'status' => $status, 'cursor' => $nextCursor], fn ($value) => $value !== null && $value !== ''); ?>
    <p><a class="button secondary" href="/specs?<?= special_escape(http_build_query($params)) ?>">Next page</a></p>
<?php endif; ?>
<script src="/spec-copy.js" defer></script>
