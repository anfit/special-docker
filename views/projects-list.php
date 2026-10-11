<?php

declare(strict_types=1);

/** @var list<array{project_name: string, spec_count: int}> $projects */
?>
<div class="toolbar">
    <h1>Projects</h1>
</div>
<p class="muted">Projects that have at least one public spec, or one of your private specs.</p>
<?php if ($projects === []): ?>
    <p class="muted">No projects yet.</p>
<?php else: ?>
    <table>
        <thead><tr><th>Project</th><th>Specs</th></tr></thead>
        <tbody>
        <?php foreach ($projects as $project): ?>
            <tr>
                <td><a href="/specs?project_name=<?= special_escape(rawurlencode($project['project_name'])) ?>"><?= special_escape($project['project_name']) ?></a></td>
                <td><?= (int) $project['spec_count'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
