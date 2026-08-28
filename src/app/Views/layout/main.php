<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $this->renderSection('title') ?: 'Pépite' ?></title>
    <link rel="stylesheet" href="<?= base_url('vendor/bootstrap/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
    <style>
        /* Pépite's own palette, bridged onto Bootstrap's CSS variable API
           (5.3+) rather than Bootstrap's default blue. Vendored locally —
           see public/vendor/NOTICE.md — so nothing here reaches a CDN, and
           dark mode follows the OS the same way it always has: a media
           query, not a toggle or a data-attribute that needs JS on load. */
        :root {
            --pepite-accent: #a8791f;
            --pepite-accent-rgb: 168, 121, 31;
            --pepite-ok: #2f7d4f;
            --pepite-ok-rgb: 47, 125, 79;
            --pepite-danger: #b3341f;
            --pepite-danger-rgb: 179, 52, 31;
            /* Stays this warm amber in both themes, deliberately — accent
               already carries "brand/action" and switches to blue at night,
               so warning needs its own fixed identity to still read as
               "pay attention" rather than as another action button. */
            --pepite-warning: #a8791f;
            --pepite-warning-rgb: 168, 121, 31;

            --bs-body-bg: #fbfaf7;
            --bs-body-bg-rgb: 251, 250, 247;
            --bs-body-color: #1c1a17;
            --bs-body-color-rgb: 28, 26, 23;
            --bs-emphasis-color: #1c1a17;
            --bs-emphasis-color-rgb: 28, 26, 23;
            --bs-secondary-color: #6b6459;
            --bs-secondary-color-rgb: 107, 100, 89;
            --bs-tertiary-bg: #f4f1ea;
            /* Every bg-*/text-* utility with an opacity variant (bg-body-tertiary
               included) reads the -rgb companion, not the hex variable above —
               forgetting it here left those utilities on Bootstrap's stock light
               grey even in dark mode, unreadable against dark-mode text. */
            --bs-tertiary-bg-rgb: 244, 241, 234;
            --bs-border-color: #e3ded4;
            --bs-border-color-translucent: #e3ded4;

            --bs-primary: var(--pepite-accent);
            --bs-primary-rgb: var(--pepite-accent-rgb);
            --bs-link-color: var(--pepite-accent);
            --bs-link-color-rgb: var(--pepite-accent-rgb);
            --bs-link-hover-color: var(--pepite-accent);
            --bs-link-hover-color-rgb: var(--pepite-accent-rgb);
            --bs-code-color: var(--pepite-accent);
            --bs-success: var(--pepite-ok);
            --bs-success-rgb: var(--pepite-ok-rgb);
            --bs-danger: var(--pepite-danger);
            --bs-danger-rgb: var(--pepite-danger-rgb);
            --bs-warning: var(--pepite-warning);
            --bs-warning-rgb: var(--pepite-warning-rgb);

            --bs-card-bg: #fff;
            --bs-card-border-color: var(--bs-border-color);
            --bs-card-cap-bg: var(--bs-tertiary-bg);
            --bs-table-color: var(--bs-body-color);
            --bs-table-bg: transparent;
            --bs-table-border-color: var(--bs-border-color);
            --bs-table-hover-bg: var(--bs-tertiary-bg);

            font-size: 16px;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --pepite-accent: #5b9cf6;
                --pepite-accent-rgb: 91, 156, 246;
                --pepite-ok: #6bc48f;
                --pepite-ok-rgb: 107, 196, 143;
                --pepite-danger: #e0685a;
                --pepite-danger-rgb: 224, 104, 90;

                --bs-body-bg: #14171c;
                --bs-body-bg-rgb: 20, 23, 28;
                --bs-body-color: #e6e9ee;
                --bs-body-color-rgb: 230, 233, 238;
                --bs-emphasis-color: #f4f6f8;
                --bs-emphasis-color-rgb: 244, 246, 248;
                --bs-secondary-color: #8c93a1;
                --bs-secondary-color-rgb: 140, 147, 161;
                --bs-tertiary-bg: #21252c;
                --bs-tertiary-bg-rgb: 33, 37, 44;
                --bs-border-color: #2b3037;
                --bs-border-color-translucent: #2b3037;

                --bs-card-bg: #1b1f26;
                --bs-heading-color: #e6e9ee;
            }

            .navbar, .card { color-scheme: dark; }
        }

        /* Column layout so the footer sits at the bottom on short pages
           (the login screen) without floating over long ones. */
        body { min-height: 100vh; display: flex; flex-direction: column; }
        main.site { flex: 1 0 auto; max-width: 62rem; }

        /* Bootstrap's navbar assumes a light background by default and bakes
           that into --bs-navbar-color rather than reading --bs-body-color,
           so in dark mode the nav text stayed dark-on-dark — nearly
           invisible — until this pinned it to the same tokens as the rest
           of the page. */
        .navbar {
            --bs-navbar-color: var(--bs-secondary-color);
            --bs-navbar-hover-color: var(--bs-primary);
            --bs-navbar-active-color: var(--bs-body-color);
            --bs-navbar-brand-color: var(--bs-body-color);
            --bs-navbar-brand-hover-color: var(--bs-primary);
            --bs-navbar-toggler-border-color: var(--bs-border-color);
        }
        .navbar-brand { font-weight: 700; }
        .navbar .app-version { font-size: .75rem; color: var(--bs-secondary-color); }

        /* Bootstrap bakes each interactive component's own colour into a
           local custom property rather than reading --bs-primary at the
           root — confirmed in the browser, not assumed: badges and plain
           links pick up the palette above for free, buttons/alerts/focus
           rings do not. These override the rendered property directly,
           which works regardless of which internal variable a given
           component actually reads. */
        .btn-primary { color: #fff; background-color: var(--pepite-accent); border-color: var(--pepite-accent); }
        .btn-primary:hover, .btn-primary:focus, .btn-primary:active {
            color: #fff;
            background-color: color-mix(in srgb, var(--pepite-accent) 85%, black);
            border-color: color-mix(in srgb, var(--pepite-accent) 80%, black);
        }
        .btn-outline-primary { color: var(--pepite-accent); border-color: var(--pepite-accent); }
        .btn-outline-primary:hover, .btn-outline-primary:focus, .btn-outline-primary:active {
            color: #fff; background-color: var(--pepite-accent); border-color: var(--pepite-accent);
        }
        .btn-danger { color: #fff; background-color: var(--pepite-danger); border-color: var(--pepite-danger); }
        .btn-danger:hover, .btn-danger:focus { background-color: color-mix(in srgb, var(--pepite-danger) 85%, black); border-color: color-mix(in srgb, var(--pepite-danger) 80%, black); }
        .btn-outline-danger { color: var(--pepite-danger); border-color: var(--pepite-danger); }
        .btn-outline-danger:hover, .btn-outline-danger:focus { color: #fff; background-color: var(--pepite-danger); border-color: var(--pepite-danger); }
        .btn-success { color: #fff; background-color: var(--pepite-ok); border-color: var(--pepite-ok); }
        .btn-outline-success { color: var(--pepite-ok); border-color: var(--pepite-ok); }
        .btn-outline-success:hover, .btn-outline-success:focus { color: #fff; background-color: var(--pepite-ok); border-color: var(--pepite-ok); }
        .btn-warning { color: #fff; background-color: var(--pepite-warning); border-color: var(--pepite-warning); }
        .btn-warning:hover, .btn-warning:focus { color: #fff; background-color: color-mix(in srgb, var(--pepite-warning) 85%, black); border-color: color-mix(in srgb, var(--pepite-warning) 80%, black); }
        .btn-outline-warning { color: var(--pepite-warning); border-color: var(--pepite-warning); }
        .btn-outline-warning:hover, .btn-outline-warning:focus { color: #fff; background-color: var(--pepite-warning); border-color: var(--pepite-warning); }

        .alert-primary { color: var(--pepite-accent); background-color: rgba(var(--pepite-accent-rgb), .12); border-color: rgba(var(--pepite-accent-rgb), .3); }
        .alert-success { color: var(--pepite-ok); background-color: rgba(var(--pepite-ok-rgb), .12); border-color: rgba(var(--pepite-ok-rgb), .3); }
        .alert-danger  { color: var(--pepite-danger); background-color: rgba(var(--pepite-danger-rgb), .12); border-color: rgba(var(--pepite-danger-rgb), .3); }
        .alert-warning { color: var(--pepite-warning); background-color: rgba(168, 121, 31, .12); border-color: rgba(168, 121, 31, .3); }

        .form-control:focus, .form-select:focus {
            border-color: var(--pepite-accent);
            box-shadow: 0 0 0 .25rem rgba(var(--pepite-accent-rgb), .25);
        }
        .form-check-input:checked { background-color: var(--pepite-accent); border-color: var(--pepite-accent); }
        .form-check-input:focus { border-color: var(--pepite-accent); box-shadow: 0 0 0 .25rem rgba(var(--pepite-accent-rgb), .25); }
        .card { box-shadow: 0 1px 2px rgba(20, 16, 8, .06); }
        code, pre { font-family: ui-monospace, "Cascadia Code", Consolas, monospace; }
        .back-link { font-size: .9rem; }
        footer.site a { color: var(--bs-secondary-color); text-decoration: none; }
        footer.site a:hover { color: var(--bs-primary); text-decoration: underline; }
    </style>
    <?= $this->renderSection('head') ?>
</head>
<body>
<nav class="navbar navbar-expand-md border-bottom sticky-top" style="background: var(--bs-card-bg);">
    <div class="container-md">
        <a class="navbar-brand" href="<?= site_url('/') ?>">
            Pépite <span class="app-version"><?= esc(\Config\Updater::VERSION) ?></span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav-main">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse justify-content-end" id="nav-main">
            <ul class="navbar-nav gap-md-2">
                <li class="nav-item"><a class="nav-link" href="<?= site_url('/') ?>">Feeds</a></li>
                <li class="nav-item"><a class="nav-link" href="<?= site_url('search') ?>">Search</a></li>
                <?php if (function_exists('auth') && auth()->loggedIn()): ?>
                    <li class="nav-item"><a class="nav-link" href="<?= site_url('admin/feeds') ?>">Admin feeds</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= site_url('admin/keys') ?>">API keys</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= site_url('admin/users') ?>">Admins</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= site_url('admin/updates') ?>">Updates</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= site_url('logout') ?>">Log out</a></li>
                <?php else: ?>
                    <li class="nav-item"><a class="nav-link" href="<?= site_url('login') ?>">Log in</a></li>
                <?php endif ?>
            </ul>
        </div>
    </div>
</nav>

<main class="site container-md py-4">
    <?php
        $flashMessage = session()->getFlashdata('message') ?? session()->getFlashdata('success');
        $flashWarning = session()->getFlashdata('warning');
        $flashError   = session()->getFlashdata('error');
    ?>
    <?php if ($flashMessage): ?>
        <div class="alert alert-success d-flex align-items-center gap-2" role="alert">
            <i class="bi bi-check-circle-fill flex-shrink-0"></i><div><?= esc($flashMessage) ?></div>
        </div>
    <?php endif ?>
    <?php if ($flashWarning): ?>
        <div class="alert alert-warning d-flex align-items-center gap-2" role="alert">
            <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i><div><?= esc($flashWarning) ?></div>
        </div>
    <?php endif ?>
    <?php // The update panel (forgelab-me/ci4-updater) reports every outcome —
          // applied, failed download, rollback, refused signature — as
          // 'error'/'success' flashdata and a redirect. It renders none of
          // it itself: skipping these two silently swallows the result. ?>
    <?php if ($flashError): ?>
        <div class="alert alert-danger d-flex align-items-center gap-2" role="alert">
            <i class="bi bi-exclamation-octagon-fill flex-shrink-0"></i><div><?= esc($flashError) ?></div>
        </div>
    <?php endif ?>
    <?php // `content` is used by the updater panel, `main` by Shield's views. ?>
    <?= $this->renderSection('content') ?>
    <?= $this->renderSection('main') ?>
</main>

<footer class="site border-top py-3">
    <div class="container-md d-flex flex-wrap justify-content-between gap-2">
        <span class="text-body-secondary">&copy; <?= date('Y') ?> ForgeLab — MIT</span>
        <a href="https://github.com/forgelab-me/pepite" target="_blank" rel="noopener noreferrer">
            <i class="bi bi-github me-1"></i>forgelab-me/pepite
        </a>
    </div>
</footer>

<script src="<?= base_url('vendor/bootstrap/bootstrap.bundle.min.js') ?>"></script>
<?= $this->renderSection('scripts') ?>
</body>
</html>
