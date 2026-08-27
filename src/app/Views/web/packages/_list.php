<?php
/**
 * Shared by web/feeds/show.php (one feed) and web/search.php (every public
 * feed at once) — the only difference is $showFeedBadge, since a global
 * result needs to say which feed it came from and a per-feed one doesn't.
 *
 * @var list<array<string, mixed>> $packages
 * @var bool                       $showFeedBadge
 */
$showFeedBadge ??= false;
?>
<?php if ($packages === []): ?>
    <p class="text-body-secondary">No matching package.</p>
<?php else: ?>
    <div class="list-group">
        <?php foreach ($packages as $package): ?>
            <a class="list-group-item list-group-item-action d-flex align-items-start gap-3"
               href="<?= site_url('browse/' . $package['_feedSlug'] . '/' . $package['package_id_lower']) ?>">
                <?php if (! empty($package['iconUrl'])): ?>
                    <img src="<?= esc($package['iconUrl']) ?>" alt="" width="40" height="40" class="rounded flex-shrink-0" loading="lazy">
                <?php else: ?>
                    <div class="rounded bg-body-tertiary flex-shrink-0 d-flex align-items-center justify-content-center text-body-secondary" style="width:40px;height:40px;">
                        <i class="bi bi-box-seam"></i>
                    </div>
                <?php endif ?>
                <div class="flex-grow-1">
                    <div class="d-flex justify-content-between align-items-baseline flex-wrap gap-2">
                        <span>
                            <span class="fw-semibold"><?= esc($package['package_id']) ?></span>
                            <?php if (! empty($package['latest_version_normalized'])): ?>
                                <span class="text-body-secondary small"><?= esc($package['latest_version_normalized']) ?></span>
                            <?php endif ?>
                            <?php if (! empty($package['latest_is_prerelease'])): ?>
                                <span class="badge text-bg-warning">prerelease</span>
                            <?php endif ?>
                            <?php if ($showFeedBadge && ! empty($package['feed_name'])): ?>
                                <span class="badge text-bg-secondary"><?= esc($package['feed_name']) ?></span>
                            <?php endif ?>
                        </span>
                        <span class="text-body-secondary small"><?= (int) $package['total_downloads'] ?> downloads</span>
                    </div>
                    <?php if (! empty($package['latest_description'])): ?>
                        <p class="text-body-secondary small mb-1 text-truncate"><?= esc($package['latest_description']) ?></p>
                    <?php endif ?>
                    <?php if (! empty($package['tags'])): ?>
                        <?php foreach (array_slice($package['tags'], 0, 5) as $tag): ?>
                            <span class="badge text-bg-secondary me-1"><?= esc($tag) ?></span>
                        <?php endforeach ?>
                    <?php endif ?>
                </div>
            </a>
        <?php endforeach ?>
    </div>
<?php endif ?>
