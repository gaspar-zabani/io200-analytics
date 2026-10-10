<?php
// Included only after dashboard authentication and checkpoint state resolution.
if (!isset($authenticated) || $authenticated !== true) { http_response_code(403); exit; }
$checkpointFormFields = static function () use ($checkpointCsrf) {
    echo '<input type="hidden" name="checkpoint_csrf" value="' . h($checkpointCsrf) . '">';
};
$checkpointUrl = static function ($selection) use ($activity, $photoTab) {
    return '?' . http_build_query(['period' => 'checkpoint', 'checkpoint' => $selection, 'activity' => $activity, 'photo_tab' => $photoTab]);
};
?>
<div class="checkpoint-split" data-checkpoint-split>
    <a class="filter <?= $period === 'checkpoint' ? 'active' : '' ?>" href="<?= h($checkpointUrl($checkpointSelection)) ?>" <?= $period === 'checkpoint' ? 'aria-current="true"' : '' ?>>Checkpoint</a>
    <button class="checkpoint-toggle" type="button" aria-label="Choose or manage checkpoints" aria-expanded="false" aria-controls="checkpoint-menu" data-checkpoint-toggle>▾</button>
    <div class="checkpoint-menu" id="checkpoint-menu" aria-label="Checkpoints" role="region" data-checkpoint-menu hidden>
        <div class="checkpoint-menu-actions">
            <?php if ($checkpointReady): ?>
                <button type="button" data-checkpoint-open="create"><span aria-hidden="true">＋</span> Create</button>
                <button type="button" data-checkpoint-open="manage"><span aria-hidden="true">✎</span> Manage</button>
            <?php else: ?><a href="install.php">Enable checkpoints</a><?php endif; ?>
        </div>
        <nav class="checkpoint-menu-list" aria-label="Select checkpoint">
            <a class="checkpoint-choice" data-checkpoint-choice href="<?= h($checkpointUrl('latest')) ?>" aria-current="<?= $checkpointSelection === 'latest' ? 'true' : 'false' ?>"><span>Latest checkpoint</span></a>
            <?php foreach ($checkpoints as $checkpoint): ?>
                <a class="checkpoint-choice" data-checkpoint-choice href="<?= h($checkpointUrl((string)$checkpoint['id'])) ?>" aria-current="<?= $checkpointSelection === (string)$checkpoint['id'] ? 'true' : 'false' ?>" title="<?= h($checkpoint['name'] . ' · ' . $checkpoint['created_at']) ?>"><span><?= h($checkpoint['name']) ?></span></a>
            <?php endforeach; ?>
            <?php if (!$checkpoints): ?><p class="checkpoint-menu-empty">No checkpoints yet. Create one to mark a moment.</p><?php endif; ?>
        </nav>
    </div>
</div>
<?php if ($checkpointReady): ?>
<dialog class="checkpoint-manager" aria-labelledby="checkpoint-dialog-title" data-checkpoint-dialog>
    <header class="checkpoint-manager-header">
        <h2 id="checkpoint-dialog-title">Checkpoints</h2>
        <button class="checkpoint-close" type="button" data-checkpoint-close aria-label="Close checkpoint management">×</button>
    </header>
    <section class="checkpoint-manager-section">
        <h3>Create</h3>
        <form method="post" data-checkpoint-create>
            <?php $checkpointFormFields(); ?>
            <input type="hidden" name="checkpoint_action" value="create">
            <label class="visit-summary__accessible" for="new-checkpoint-name">Checkpoint name</label>
            <input id="new-checkpoint-name" name="name" required maxlength="200" placeholder="e.g. Gallery delivered">
            <button class="checkpoint-primary" type="submit">Create</button>
        </form>
        <p>Mark this moment with a name. The time is saved and cannot be changed.</p>
    </section>
    <section class="checkpoint-manager-section">
        <h3 tabindex="-1" data-checkpoint-manage-focus>Statistics start</h3>
        <p>Your site's long-term reporting baseline. Applies across devices and never deletes events.</p>
        <form method="post" style="margin-top:10px">
            <?php $checkpointFormFields(); ?>
            <input type="hidden" name="checkpoint_action" value="statistics_start">
            <label class="visit-summary__accessible" for="statistics-start">Statistics start</label>
            <select id="statistics-start" name="checkpoint_id">
                <option value="">All recorded history</option>
                <?php foreach ($checkpoints as $checkpoint): ?>
                    <option value="<?= (int)$checkpoint['id'] ?>" <?= (string)($statisticsStart['id'] ?? '') === (string)$checkpoint['id'] ? 'selected' : '' ?>><?= h($checkpoint['name'] . ' · ' . $checkpoint['created_at']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Save</button>
        </form>
        <p>Choose All recorded history to restore the full history.</p>
    </section>
    <section class="checkpoint-manager-section">
        <h3>Saved checkpoints</h3>
        <?php if (!$checkpoints): ?><p>No checkpoints yet.</p><?php endif; ?>
        <?php foreach ($checkpoints as $checkpoint): ?>
            <div class="checkpoint-saved">
                <time datetime="<?= h($checkpoint['created_at']) ?>" title="Saved time — cannot be changed"><?= h($checkpoint['created_at']) ?></time>
                <form method="post">
                    <?php $checkpointFormFields(); ?>
                    <input type="hidden" name="checkpoint_id" value="<?= (int)$checkpoint['id'] ?>">
                    <label class="visit-summary__accessible" for="checkpoint-name-<?= (int)$checkpoint['id'] ?>">Checkpoint name</label>
                    <input id="checkpoint-name-<?= (int)$checkpoint['id'] ?>" name="name" required maxlength="200" value="<?= h($checkpoint['name']) ?>">
                    <button name="checkpoint_action" value="rename">Rename</button>
                </form>
                <form class="checkpoint-delete" method="post" data-checkpoint-delete>
                    <?php $checkpointFormFields(); ?>
                    <input type="hidden" name="checkpoint_id" value="<?= (int)$checkpoint['id'] ?>">
                    <input type="hidden" name="checkpoint_action" value="delete">
                    <label><input type="checkbox" name="confirm_delete" value="yes" required> Confirm deletion</label>
                    <button type="submit" aria-label="Delete <?= h($checkpoint['name']) ?>">Delete</button>
                </form>
            </div>
        <?php endforeach; ?>
    </section>
</dialog>
<?php endif; ?>
<script src="assets/checkpoints.js" defer></script>
