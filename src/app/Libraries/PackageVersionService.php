<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\PackageVersionModel;

/**
 * Changes to a published version that are not its removal (that is
 * PackagePurger) — currently only whether it shows up in search.
 *
 * The admin console, the owner's account pages and the NuGet delete/relist
 * endpoints each used to flip is_listed on their own. They still decide who
 * may; this is the one place the change is made and recorded.
 */
final class PackageVersionService
{
    public function __construct(
        private readonly PackageVersionModel $versions,
        private readonly Audit $audit,
    ) {
    }

    /**
     * Delisting never deletes: published versions are immutable, and anything
     * already depending on this one must keep restoring. Only its visibility
     * in search and registration changes.
     *
     * @param array<string, mixed> $package
     * @param array<string, mixed> $version
     */
    public function setListed(array $package, array $version, bool $listed): void
    {
        $this->versions->update((int) $version['id'], ['is_listed' => $listed]);

        $this->audit->record(
            $listed ? 'version.relist' : 'version.unlist',
            sprintf('%s %s (feed #%d)', $package['package_id'], $version['version_normalized'], $package['feed_id']),
        );
    }
}
