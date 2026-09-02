<?php

declare(strict_types=1);

namespace Config;

use Forgelabme\Ci4Updater\Config\Updater as BaseUpdater;

/**
 * Self-update system configuration for this app.
 * Bump VERSION / DATE before each release, then run `php spark update:manifest`.
 */
class Updater extends BaseUpdater
{
    public const VERSION    = '1.10.0';
    public const DATE       = '2026-09-01';
    public const USER_AGENT = 'UpdateServerAdmin/1.0';

    // The layout the update panel extends, and the name shown beside the
    // version. The panel itself comes from the package unless you publish
    // it with `php spark updater:setup --views`.
    public string $layout      = 'layout/main';
    public ?string $appName    = 'Pépite';
    public array $allowedRoots = ['app', 'public', 'vendor'];

    // Already have a settings system (e.g. AppSettingModel)? Point this at
    // your own class implementing Forgelabme\Ci4Updater\Libraries\SettingsInterface
    // instead of the default JSON-file-in-writable/ store:
    // public string $settingsClass = \App\Libraries\MySettingsAdapter::class;

    // List a public key here to require signed releases: from then on an
    // unsigned release is refused. Generate a pair with
    // `php spark updater:keygen` — see the package's docs/signing.md.
    public array $publicKeys = [APPPATH . 'Config/Keys/release-signing.pub'];

    // composer.lock changing is exactly when a release ships vendor/ (see
    // .github/workflows/publish.yml) — without composer.json and
    // composer.lock travelling with it, the two files on disk would describe
    // a different dependency set than the vendor/ tree that landed.
    public array $allowedFiles = ['composer.json', 'composer.lock'];

    // Renders during the maintenance window ci4-updater now holds open for
    // the whole apply (panel and `updater:apply` alike) — see
    // app/Views/maintenance.php. Replaces App\Filters\Maintenance, which
    // predated the package having a hook to attach to at all.
    public ?string $maintenanceView = 'maintenance';
}
