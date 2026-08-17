<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $this->renderSection('title') ?: 'Pépite' ?></title>
    <style>
        :root {
            --bg: #fbfaf7;
            --fg: #1c1a17;
            --muted: #6b6459;
            --line: #e3ded4;
            --accent: #a8791f;
            --accent-fg: #fff;
            --surface: #fff;
            --surface-2: #f4f1ea;
            --danger: #b3341f;
            --danger-fg: #fff;
            --ok: #2f7d4f;
            --shadow: 0 1px 2px rgba(20, 16, 8, .06);
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #14171c;
                --fg: #e6e9ee;
                --muted: #8c93a1;
                --line: #2b3037;
                --accent: #5b9cf6;
                --accent-fg: #0b1220;
                --surface: #1b1f26;
                --surface-2: #21252c;
                --danger: #e0685a;
                --danger-fg: #1c1a17;
                --ok: #6bc48f;
                --shadow: 0 1px 3px rgba(0, 0, 0, .35);
            }
        }

        * { box-sizing: border-box; }

        /* Column layout so the footer sits at the bottom on short pages
           (the login screen) without floating over long ones. */
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            background: var(--bg);
            color: var(--fg);
            font: 16px/1.6 system-ui, -apple-system, "Segoe UI", sans-serif;
        }


        h1, h2, h3 { line-height: 1.25; }
        h1 { font-size: 1.6rem; margin: 0 0 1rem; }
        h2 { font-size: 1.15rem; }

        header.site {
            border-bottom: 1px solid var(--line);
            padding: .85rem 1.5rem;
            display: flex;
            align-items: baseline;
            gap: 1rem;
            background: var(--surface);
            box-shadow: var(--shadow);
            position: sticky;
            top: 0;
            z-index: 10;
        }

        header.site a.brand {
            font-weight: 700;
            font-size: 1.15rem;
            color: var(--fg);
            text-decoration: none;
        }

        header.site .version {
            color: var(--muted);
            font-size: .75rem;
        }

        header.site nav {
            margin-left: auto;
            display: flex;
            gap: 1.25rem;
            align-items: center;
        }

        header.site nav a { color: var(--muted); text-decoration: none; font-size: .95rem; }
        header.site nav a:hover { color: var(--accent); }

        main.site { flex: 1 0 auto; max-width: 62rem; margin: 0 auto; padding: 2rem 1.5rem 4rem; width: 100%; }

        a { color: var(--accent); }

        p.lead { color: var(--muted); margin-top: -.5rem; }

        .toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }

        table { border-collapse: collapse; width: 100%; font-size: .95rem; }
        th, td { text-align: left; padding: .55rem .75rem; border-bottom: 1px solid var(--line); vertical-align: middle; }
        th { color: var(--muted); font-weight: 600; font-size: .82rem; text-transform: uppercase; letter-spacing: .02em; }
        tbody tr:hover { background: var(--surface-2); }
        td.actions { text-align: right; white-space: nowrap; }
        td.actions form { display: inline-block; margin-left: .4rem; }

        code, pre { font-family: ui-monospace, "Cascadia Code", Consolas, monospace; font-size: .92em; }
        pre { background: var(--surface-2); padding: .75rem 1rem; border-radius: 6px; overflow-x: auto; }

        .card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 1.25rem;
            box-shadow: var(--shadow);
            margin-bottom: 1rem;
        }

        /* The update panel (forgelab-me/ci4-updater) pairs some cards inside
           Bootstrap .row/.col-md-6 wrappers this layout doesn't style, so
           `.card + .card` alone wouldn't reach them — a card always carries
           its own bottom margin instead, wrapper or no wrapper. */

        label { display: block; margin: .9rem 0 .3rem; font-size: .92rem; }
        label:first-child { margin-top: 0; }

        input[type="text"], input[type="email"], input[type="password"], input[type="url"],
        input[type="number"], input[type="search"], select, textarea {
            width: 100%;
            padding: .5rem .65rem;
            border: 1px solid var(--line);
            border-radius: 6px;
            background: var(--bg);
            color: var(--fg);
            font: inherit;
        }

        input:focus, select:focus, textarea:focus { outline: 2px solid var(--accent); outline-offset: 1px; }

        label.checkbox { display: flex; align-items: center; gap: .5rem; font-size: .92rem; }
        label.checkbox input { width: auto; }

        button, .button {
            display: inline-block;
            font: inherit;
            font-size: .92rem;
            padding: .45rem .9rem;
            border: 1px solid var(--line);
            border-radius: 6px;
            background: var(--surface);
            color: var(--fg);
            cursor: pointer;
            text-decoration: none;
            line-height: 1.4;
        }

        button:hover, .button:hover { border-color: var(--accent); color: var(--accent); }

        button.primary, .button.primary {
            background: var(--accent);
            border-color: var(--accent);
            color: var(--accent-fg);
        }
        button.primary:hover, .button.primary:hover { filter: brightness(1.08); color: var(--accent-fg); }

        button.danger, .button.danger { color: var(--danger); border-color: var(--danger); }
        button.danger:hover, .button.danger:hover { background: var(--danger); color: var(--danger-fg); }

        button.small, .button.small { padding: .25rem .6rem; font-size: .82rem; }

        .badge {
            display: inline-block;
            font-size: .75rem;
            font-weight: 600;
            padding: .1rem .5rem;
            border-radius: 99px;
            border: 1px solid var(--line);
            color: var(--muted);
        }
        .badge.ok { color: var(--ok); border-color: var(--ok); }
        .badge.warn { color: var(--danger); border-color: var(--danger); }
        .badge.accent { color: var(--accent); border-color: var(--accent); }

        .flash {
            background: var(--surface-2);
            border: 1px solid var(--line);
            border-left: 3px solid var(--ok);
            border-radius: 6px;
            padding: .6rem 1rem;
            margin-bottom: 1.25rem;
            font-size: .92rem;
        }

        .errors {
            border: 1px solid var(--danger);
            border-left: 3px solid var(--danger);
            border-radius: 6px;
            padding: .75rem 1rem;
            margin-bottom: 1rem;
            font-size: .92rem;
        }
        .errors p { margin: .25rem 0; }

        .muted { color: var(--muted); }
        .back-link { display: inline-block; margin-bottom: 1rem; font-size: .9rem; color: var(--muted); text-decoration: none; }
        .back-link:hover { color: var(--accent); }

        footer.site {
            border-top: 1px solid var(--line);
            padding: 1.25rem 1.5rem;
            color: var(--muted);
            font-size: .82rem;
        }
        footer.site .row {
            max-width: 62rem;
            margin: 0 auto;
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: .5rem;
        }
        footer.site a { color: var(--muted); text-decoration: none; }
        footer.site a:hover { color: var(--accent); text-decoration: underline; }
        footer.site .github-link { display: inline-flex; align-items: center; gap: .4rem; }
    </style>
    <?= $this->renderSection('head') ?>
</head>
<body>
<header class="site">
    <a class="brand" href="<?= site_url('/') ?>">Pépite</a>
    <span class="version"><?= esc(\Config\Updater::VERSION) ?></span>
    <nav>
        <a href="<?= site_url('/') ?>">Feeds</a>
        <?php if (function_exists('auth') && auth()->loggedIn()): ?>
            <a href="<?= site_url('admin/feeds') ?>">Admin feeds</a>
            <a href="<?= site_url('admin/keys') ?>">Clés API</a>
            <a href="<?= site_url('admin/updates') ?>">Mises à jour</a>
            <a href="<?= site_url('logout') ?>">Déconnexion</a>
        <?php else: ?>
            <a href="<?= site_url('login') ?>">Connexion</a>
        <?php endif ?>
    </nav>
</header>

<main class="site">
    <?php if (session()->getFlashdata('message')): ?>
        <div class="flash"><?= esc(session()->getFlashdata('message')) ?></div>
    <?php endif ?>
    <?php // The update panel (forgelab-me/ci4-updater) reports every outcome —
          // applied, failed download, rollback, refused signature — as
          // 'error'/'success' flashdata and a redirect. It renders none of
          // it itself: skipping these two silently swallows the result. ?>
    <?php if ($flash = session()->getFlashdata('success')): ?>
        <div class="flash"><?= esc($flash) ?></div>
    <?php endif ?>
    <?php if ($flash = session()->getFlashdata('error')): ?>
        <div class="errors"><?= esc($flash) ?></div>
    <?php endif ?>
    <?php // `content` is used by the updater panel, `main` by Shield's views. ?>
    <?= $this->renderSection('content') ?>
    <?= $this->renderSection('main') ?>
</main>

<footer class="site">
    <div class="row">
        <span>&copy; <?= date('Y') ?> ForgeLab — MIT</span>
        <a href="https://github.com/forgelab-me/pepite" target="_blank" rel="noopener noreferrer" class="github-link">
            <svg viewBox="0 0 16 16" width="16" height="16" fill="currentColor" aria-hidden="true"><path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.01 8.01 0 0 0 16 8c0-4.42-3.58-8-8-8Z"/></svg>
            forgelab-me/pepite
        </a>
    </div>
</footer>

<?= $this->renderSection('scripts') ?>
</body>
</html>
