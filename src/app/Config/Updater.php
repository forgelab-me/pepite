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
    public const VERSION    = '1.4.1';
    public const DATE       = '2026-08-20';
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
}
