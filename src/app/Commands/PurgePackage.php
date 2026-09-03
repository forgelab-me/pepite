<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\FeedModel;
use App\Models\PackageModel;
use App\Models\PackageVersionModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Irreversibly removes a package, or one version of it — database rows and
 * the stored .nupkg/.nuspec/icon/readme alike.
 *
 * Nothing else in Pépite does this on purpose: a published version is
 * meant to be immutable (Web\Packages, App\Controllers\Admin\Packages),
 * because anything already depending on it must keep restoring, and
 * unlisting only hides a version from discovery — the flat container
 * still serves it by design (V3\FlatContainer). Neither helps when a
 * published file itself has to stop existing, e.g. because it contains
 * something that should never have been public in the first place. That
 * case bypasses every one of those guarantees on purpose, which is why
 * both this command and its console equivalent
 * (App\Controllers\Admin\Packages::purge()/purgeVersion(), superadmin
 * only) require the exact package id typed back before doing anything.
 * The actual mutation lives in App\Libraries\PackagePurger, shared by
 * both.
 */
final class PurgePackage extends BaseCommand
{
    protected $group       = 'Pepite';
    protected $name        = 'pepite:purge';
    protected $description = 'Permanently deletes a package, or one version of it — database rows and stored files alike.';
    protected $usage       = 'pepite:purge <feed-slug> <package-id> [version] [--yes]';
    protected $arguments   = [
        'feed-slug'  => 'The feed the package belongs to.',
        'package-id' => 'The package identifier, e.g. Contoso.Utils.',
        'version'    => 'Optional: only this version. Omitted, the whole package is removed.',
    ];
    protected $options = [
        '--yes' => 'Skip the confirmation prompt (scripted use only).',
    ];

    public function run(array $params): int
    {
        $slug      = $params[0] ?? null;
        $packageId = $params[1] ?? null;
        $version   = $params[2] ?? null;

        if (! is_string($slug) || trim($slug) === '' || ! is_string($packageId) || trim($packageId) === '') {
            CLI::error('Give a feed slug and a package id.');
            CLI::write($this->usage);

            return EXIT_USER_INPUT;
        }

        $feed = model(FeedModel::class)->findBySlug(strtolower(trim($slug)));

        if ($feed === null) {
            CLI::error(sprintf('No feed named "%s".', $slug));

            return EXIT_USER_INPUT;
        }

        $package = model(PackageModel::class)->findInFeed((int) $feed['id'], trim($packageId));

        if ($package === null) {
            CLI::error(sprintf('No package named "%s" in feed "%s".', $packageId, $slug));

            return EXIT_USER_INPUT;
        }

        $allVersions = model(PackageVersionModel::class)->forPackage((int) $package['id']);

        if (is_string($version) && trim($version) !== '') {
            $target = $this->findVersion($allVersions, trim($version));

            if ($target === null) {
                CLI::error(sprintf('No version "%s" of "%s".', $version, $packageId));

                return EXIT_USER_INPUT;
            }

            $toDelete         = [$target];
            $deletePackageToo = count($allVersions) === 1;
        } else {
            $toDelete         = $allVersions;
            $deletePackageToo = true;
        }

        if ($toDelete === []) {
            CLI::write('  Nothing to delete.');

            return EXIT_SUCCESS;
        }

        $this->describe($package, $toDelete, $deletePackageToo);

        if (! $this->flag($params, 'yes') && ! $this->confirmed($package)) {
            CLI::write('  Aborted — nothing was deleted.');

            return EXIT_USER_INPUT;
        }

        service('packagePurger')->purge($package, $toDelete, $deletePackageToo);

        CLI::write(sprintf(
            '  %s %d version(s) of "%s" removed%s.',
            CLI::color('OK', 'green'),
            count($toDelete),
            $package['package_id'],
            $deletePackageToo ? ' — the package identifier is gone too' : '',
        ));

        return EXIT_SUCCESS;
    }

    /**
     * @param array<string, mixed>       $package
     * @param list<array<string, mixed>> $toDelete
     */
    private function describe(array $package, array $toDelete, bool $deletePackageToo): void
    {
        CLI::write(sprintf('  Feed:    %s', $package['feed_id']));
        CLI::write(sprintf('  Package: %s', $package['package_id']));
        CLI::write('  Versions to delete:');

        foreach ($toDelete as $row) {
            CLI::write(sprintf('    - %s (%s)', $row['version_normalized'], $row['nupkg_path']));
        }

        if ($deletePackageToo) {
            CLI::write(CLI::color('  The package identifier itself will be deleted — nothing will remain of it.', 'yellow'));
        }

        CLI::write(CLI::color('  This cannot be undone. Any dependency declared against a deleted version breaks.', 'red'));
    }

    /**
     * @param array<string, mixed> $package
     */
    private function confirmed(array $package): bool
    {
        $typed = CLI::prompt(sprintf('  Type "%s" to confirm', $package['package_id']));

        return $typed === $package['package_id'];
    }

    /**
     * @param list<array<string, mixed>> $versions
     *
     * @return array<string, mixed>|null
     */
    private function findVersion(array $versions, string $version): ?array
    {
        foreach ($versions as $row) {
            if ($row['version_normalized_lower'] === strtolower($version)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * See App\Commands\ManageFeed::flag() for why presence in $params, not
     * CLI::getOption(), is the only check that works both for a real
     * invocation and for the command() test helper.
     */
    private function flag(array $params, string $name): bool
    {
        return array_key_exists($name, $params) || CLI::getOption($name) !== null;
    }
}
