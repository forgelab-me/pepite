<?= $this->extend('layout/main') ?>
<?= $this->section('title') ?><?= esc($package['package_id']) ?> — Pépite<?= $this->endSection() ?>
<?= $this->section('content') ?>

<a class="d-inline-block mb-3 text-body-secondary text-decoration-none" href="<?= site_url('browse/' . $feed['slug']) ?>">&larr; <?= esc($feed['name']) ?></a>

<div class="d-flex align-items-start gap-3 mb-1">
    <?php if ($iconUrl !== null): ?>
        <img src="<?= esc($iconUrl) ?>" alt="" width="48" height="48" class="rounded flex-shrink-0" loading="lazy">
    <?php endif ?>
    <div>
        <h1 class="h3 mb-1">
            <?= esc($package['package_id']) ?>
            <small class="text-body-secondary fw-normal"><?= esc($version['version_normalized']) ?></small>
            <?php if (! empty($version['is_prerelease'])): ?>
                <span class="badge text-bg-warning align-middle">prerelease</span>
            <?php endif ?>
        </h1>
        <?php if ($tags !== []): ?>
            <p class="mb-1">
                <?php foreach ($tags as $tag): ?>
                    <a class="badge text-bg-secondary text-decoration-none me-1"
                       href="<?= site_url('browse/' . $feed['slug']) ?>?q=<?= urlencode($tag) ?>"><?= esc($tag) ?></a>
                <?php endforeach ?>
            </p>
        <?php endif ?>
    </div>
</div>

<?php if (! empty($version['description'])): ?><p><?= esc($version['description']) ?></p><?php endif ?>

<div class="d-flex flex-wrap gap-2 mb-3">
    <a class="btn btn-primary" href="<?= site_url('feeds/' . $feed['slug'] . '/v3/flatcontainer/' . $package['package_id_lower'] . '/' . $version['version_normalized_lower'] . '/' . $package['package_id_lower'] . '.' . $version['version_normalized_lower'] . '.nupkg') ?>">
        <i class="bi bi-download me-1"></i>Download the .nupkg
    </a>
</div>

<div class="card mb-3">
    <div class="card-header">Install</div>
    <div class="card-body">
        <ul class="nav nav-tabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#install-cli" type="button">.NET CLI</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#install-pkgref" type="button">PackageReference</button>
            </li>
        </ul>
        <div class="tab-content pt-3">
            <div class="tab-pane fade show active" id="install-cli">
                <div class="d-flex gap-2">
                    <code class="bg-body-tertiary rounded px-2 py-1 flex-grow-1 user-select-all" id="install-cli-text">dotnet add package <?= esc($package['package_id']) ?> --version <?= esc($version['version_normalized']) ?></code>
                    <button type="button" class="btn btn-sm btn-outline-secondary flex-shrink-0" data-copy="install-cli-text"><i class="bi bi-clipboard"></i></button>
                </div>
            </div>
            <div class="tab-pane fade" id="install-pkgref">
                <div class="d-flex gap-2">
                    <code class="bg-body-tertiary rounded px-2 py-1 flex-grow-1 user-select-all" id="install-pkgref-text">&lt;PackageReference Include="<?= esc($package['package_id']) ?>" Version="<?= esc($version['version_normalized']) ?>" /&gt;</code>
                    <button type="button" class="btn btn-sm btn-outline-secondary flex-shrink-0" data-copy="install-pkgref-text"><i class="bi bi-clipboard"></i></button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header">Versions</div>
            <ul class="list-group list-group-flush" style="max-height: 20rem; overflow-y: auto;">
                <?php foreach ($versions as $row): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span>
                            <a href="<?= site_url('browse/' . $feed['slug'] . '/' . $package['package_id_lower'] . '/' . $row['version_normalized_lower']) ?>">
                                <?= esc($row['version_normalized']) ?>
                            </a>
                            <?= $row['id'] === $version['id'] ? '<span class="badge text-bg-secondary ms-2">current</span>' : '' ?>
                            <?php if (! empty($row['is_prerelease'])): ?>
                                <span class="badge text-bg-warning ms-2">prerelease</span>
                            <?php endif ?>
                            <?php if (! empty($row['published_at'])): ?>
                                <span class="text-body-secondary small ms-2"><?= esc(substr((string) $row['published_at'], 0, 10)) ?></span>
                            <?php endif ?>
                        </span>
                        <span class="text-body-secondary small"><?= (int) ($row['downloads'] ?? 0) ?> downloads</span>
                    </li>
                <?php endforeach ?>
            </ul>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header">About</div>
            <ul class="list-group list-group-flush">
                <?php if ($authors !== []): ?>
                    <li class="list-group-item"><span class="text-body-secondary">Authors</span><br><?= esc(implode(', ', $authors)) ?></li>
                <?php endif ?>
                <?php if ($owners !== []): ?>
                    <li class="list-group-item"><span class="text-body-secondary">Owners</span><br><?= esc(implode(', ', $owners)) ?></li>
                <?php endif ?>
                <?php if (! empty($version['license_url']) || ! empty($version['license_type']) || ! empty($version['license_value'])): ?>
                    <li class="list-group-item">
                        <span class="text-body-secondary">License</span><br>
                        <?php if (! empty($version['license_url'])): ?>
                            <a href="<?= esc($version['license_url'], 'attr') ?>" rel="nofollow noopener" target="_blank"><?= esc($version['license_type'] ?? $version['license_value'] ?? $version['license_url']) ?></a>
                        <?php else: ?>
                            <?= esc($version['license_value'] ?? $version['license_type']) ?>
                        <?php endif ?>
                    </li>
                <?php endif ?>
                <?php if (! empty($version['project_url'])): ?>
                    <li class="list-group-item"><span class="text-body-secondary">Project</span><br><a href="<?= esc($version['project_url'], 'attr') ?>" rel="nofollow noopener" target="_blank"><?= esc($version['project_url']) ?></a></li>
                <?php endif ?>
                <?php if (! empty($version['repository_url'])): ?>
                    <li class="list-group-item"><span class="text-body-secondary">Repository</span><br><a href="<?= esc($version['repository_url'], 'attr') ?>" rel="nofollow noopener" target="_blank"><?= esc($version['repository_url']) ?></a></li>
                <?php endif ?>
                <?php if (! empty($version['copyright'])): ?>
                    <li class="list-group-item"><span class="text-body-secondary">Copyright</span><br><?= esc($version['copyright']) ?></li>
                <?php endif ?>
                <li class="list-group-item">
                    <span class="text-body-secondary">Size</span><br>
                    <?php $size = (int) ($version['size_bytes'] ?? 0); ?>
                    <?= $size >= 1_048_576 ? number_format($size / 1_048_576, 2) . ' MB' : number_format($size / 1_024, 1) . ' KB' ?>
                </li>
                <?php if (! empty($version['sha512_base64'])): ?>
                    <li class="list-group-item"><span class="text-body-secondary">SHA-512</span><br><code class="small text-break"><?= esc($version['sha512_base64']) ?></code></li>
                <?php endif ?>
            </ul>
        </div>
    </div>
</div>

<?php if ($dependenciesByFramework !== []): ?>
    <div class="card mb-3">
        <div class="card-header">Dependencies</div>
        <div class="card-body">
            <?php foreach ($dependenciesByFramework as $framework => $deps): ?>
                <h6 class="text-body-secondary"><?= esc($framework) ?></h6>
                <ul class="list-unstyled mb-3">
                    <?php foreach ($deps as $dep): ?>
                        <li><?= esc($dep['dependency_id']) ?> <code class="text-body-secondary"><?= esc($dep['version_range'] ?? '(, )') ?></code></li>
                    <?php endforeach ?>
                </ul>
            <?php endforeach ?>
        </div>
    </div>
<?php endif ?>

<?php if ($usedBy !== []): ?>
    <div class="card mb-3">
        <div class="card-header">Used by</div>
        <ul class="list-group list-group-flush">
            <?php foreach ($usedBy as $dependent): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <a href="<?= site_url('browse/' . $feed['slug'] . '/' . $dependent['package_id_lower']) ?>"><?= esc($dependent['package_id']) ?></a>
                    <span class="text-body-secondary small"><?= (int) $dependent['total_downloads'] ?> downloads</span>
                </li>
            <?php endforeach ?>
        </ul>
    </div>
<?php endif ?>

<?php if ($readmeHtml !== null): ?>
    <div class="card">
        <div class="card-body">
            <?= $readmeHtml ?>
        </div>
    </div>
<?php endif ?>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
document.querySelectorAll('[data-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        const text = document.getElementById(btn.dataset.copy).textContent;
        navigator.clipboard.writeText(text).then(function () {
            const icon = btn.querySelector('i');
            icon.className = 'bi bi-check2';
            setTimeout(function () { icon.className = 'bi bi-clipboard'; }, 1500);
        });
    });
});
</script>
<?= $this->endSection() ?>
