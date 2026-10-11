<?php

declare(strict_types=1);

/** @var string $content */
/** @var \Special\Principal|null $principal */
/** @var list<array{message: string, type: string}> $flash */
/** @var string $title */
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= special_escape($title ?? 'Special') ?></title>
    <style>
        :root { color-scheme: light; font-family: system-ui, sans-serif; line-height: 1.45; }
        body { margin: 0; background: #f6f7f9; color: #18202a; }
        header, main { max-width: 1040px; margin: 0 auto; padding: 1rem; }
        header { display: flex; gap: 1rem; align-items: center; justify-content: space-between; }
        nav { display: flex; gap: .8rem; align-items: center; flex-wrap: wrap; }
        a { color: #1459a6; }
        main { background: #fff; min-height: 70vh; box-shadow: 0 1px 5px #0001; }
        .toolbar, .filters, .actions { display: flex; gap: .75rem; align-items: center; flex-wrap: wrap; }
        .filters { padding: 1rem; background: #f0f3f7; margin-bottom: 1rem; }
        label { display: grid; gap: .3rem; font-weight: 600; }
        input, select, textarea, button { font: inherit; padding: .5rem; border: 1px solid #aab4c0; border-radius: .3rem; }
        textarea { width: 100%; box-sizing: border-box; min-height: 12rem; font-family: ui-monospace, monospace; }
        input:focus, select:focus, textarea:focus, button:focus { outline: 3px solid #8fc4ff; }
        button, .button { background: #1459a6; color: white; border: 0; cursor: pointer; text-decoration: none; padding: .55rem .8rem; border-radius: .3rem; }
        .button.secondary, button.secondary { background: #5b6673; }
        .button.danger, button.danger { background: #a32929; }
        .muted { color: #5b6673; }
        .flash { padding: .75rem; margin: .75rem 0; border-radius: .3rem; background: #e4f2ff; }
        .flash.error { background: #ffe6e6; color: #7b1717; }
        .error { color: #a32929; }
        .card { border: 1px solid #d6dce4; border-radius: .35rem; padding: 1rem; margin: 1rem 0; }
        .spec-panel { border: 1px solid #d6dce4; border-radius: .35rem; padding: 1rem; margin: 1rem 0; }
        .spec-panel-header { display: flex; gap: 1rem; align-items: center; justify-content: space-between; }
        .spec-panel-header h2 { margin: 0; }
        .copy-button { white-space: nowrap; }
        .copy-status { min-height: 1.45em; margin: .5rem 0 0; }
        .share-panel { border: 1px solid #d6dce4; border-radius: .35rem; padding: 1rem; margin: 1rem 0; background: #f8fafc; }
        .share-panel h2 { margin-top: 0; }
        .share-link-row { display: flex; gap: .5rem; align-items: center; }
        .share-link-row input { flex: 1; min-width: 12rem; }
        .share-notice { padding: .75rem 1rem; border-left: .3rem solid #1459a6; background: #eef6ff; }
        .spec-list { list-style: none; padding: 0; }
        .spec-list li { border-bottom: 1px solid #e1e5ea; padding: .85rem 0; }
        .spec-list-row { display: flex; gap: 1rem; align-items: flex-start; justify-content: space-between; }
        .spec-list-info { min-width: 0; }
        .spec-list-actions { display: flex; gap: .5rem; align-items: center; flex-wrap: wrap; }
        .spec-list-actions .copy-status { margin: 0; }
        .form-help { font-size: .9rem; font-weight: 400; }
        .markdown-body { overflow-wrap: anywhere; background: #f5f6f8; padding: 1.25rem; border-radius: .35rem; line-height: 1.65; }
        .markdown-body > :first-child { margin-top: 0; }
        .markdown-body > :last-child { margin-bottom: 0; }
        .markdown-body h1, .markdown-body h2, .markdown-body h3, .markdown-body h4, .markdown-body h5, .markdown-body h6 { line-height: 1.2; margin: 1.5rem 0 .7rem; }
        .markdown-body p { margin: 0 0 1rem; }
        .markdown-body blockquote { border-left: .3rem solid #aab4c0; margin: 1rem 0; padding: .1rem 1rem; color: #4b5662; }
        .markdown-body pre { overflow-x: auto; white-space: pre-wrap; overflow-wrap: anywhere; background: #e7ebf0; padding: .8rem 1rem; border-radius: .3rem; margin: 1rem 0; }
        .markdown-body code { font-family: ui-monospace, monospace; }
        .markdown-body :not(pre) > code { background: #e7ebf0; padding: .1rem .25rem; border-radius: .2rem; }
        .markdown-body pre code { background: transparent; padding: 0; }
        .markdown-body ul, .markdown-body ol { margin: .5rem 0 1rem; padding-left: 1.75rem; }
        .markdown-body li + li { margin-top: .25rem; }
        .markdown-body table { display: block; max-width: 100%; overflow-x: auto; border-collapse: collapse; margin: 1rem 0; white-space: nowrap; }
        .markdown-body th, .markdown-body td { text-align: left; border: 1px solid #d6dce4; padding: .6rem .75rem; vertical-align: top; }
        .markdown-body th { background: #e7ebf0; font-weight: 700; }
        .markdown-body a { overflow-wrap: anywhere; }
        table { width: 100%; border-collapse: collapse; margin: 1rem 0; }
        th, td { text-align: left; border-bottom: 1px solid #d6dce4; padding: .6rem .4rem; vertical-align: top; }
        .inline { display: inline; }
        .badge { display: inline-block; font-size: .8rem; font-weight: 600; padding: .05rem .5rem; border-radius: 1rem; background: #e4f2ff; color: #0d3f78; vertical-align: middle; }
        .badge.private { background: #fdeccd; color: #6b4300; }
    </style>
</head>
<body>
<header>
    <div><a href="/specs"><strong>Special</strong></a></div>
    <?php if ($principal instanceof \Special\Principal): ?>
        <nav aria-label="Primary">
            <span class="muted"><?= special_escape($principal->username) ?> (<?= special_escape($principal->role) ?>)</span>
            <a href="/specs">Specs</a>
            <a href="/projects">Projects</a>
            <a href="/account">Account</a>
            <?php if ($principal->can('users.manage')): ?><a href="/admin/users">Users</a><?php endif; ?>
            <form class="inline" method="post" action="/logout">
                <input type="hidden" name="csrf" value="<?= special_escape(special_csrf_token()) ?>">
                <button class="secondary" type="submit">Log out</button>
            </form>
        </nav>
    <?php endif; ?>
</header>
<main>
    <?php foreach (($flash ?? []) as $item): ?>
        <div class="flash <?= special_escape($item['type']) ?>" role="status"><?= special_escape($item['message']) ?></div>
    <?php endforeach; ?>
    <?= $content ?>
</main>
</body>
</html>
