<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\PackageDependencyModel;
use App\Models\PackageModel;
use App\Models\PackageOwnerModel;
use App\Models\PackageVersionModel;
use CodeIgniter\Database\BaseConnection;

/**
 * Irreversibly removes a package, or one or more of its versions — database
 * rows and the stored .nupkg/.nuspec/icon/readme alike.
 *
 * Shared by App\Commands\PurgePackage (the operator-run CLI) and
 * App\Controllers\Admin\Packages' superadmin-only delete actions — this
 * class owns only the mutation itself. Deciding what to delete, confirming
 * it with whoever's asking, and reporting the result back are each
 * caller's own job: a CLI prompt and a typed-confirmation web form are
 * different enough UIs that forcing them through one shared flow would
 * cost more than it saves.
 */
final class PackagePurger
{
    public function __construct(
        private readonly PackageStorage $storage,
        private readonly BaseConnection $db,
    ) {
    }

    /**
     * @param array<string, mixed>       $package
     * @param list<array<string, mixed>> $toDelete
     */
    public function purge(array $package, array $toDelete, bool $deletePackageToo): void
    {
        $this->db->transStart();

        $versions = model(PackageVersionModel::class);

        foreach ($toDelete as $row) {
            model(PackageDependencyModel::class)->where('package_version_id', $row['id'])->delete();
            $versions->delete((int) $row['id']);
        }

        if ($deletePackageToo) {
            model(PackageOwnerModel::class)->where('package_id', $package['id'])->delete();
            model(PackageModel::class)->delete((int) $package['id']);
        }

        $this->db->transComplete();

        // Files are removed only once the rows they were keyed off are
        // safely gone — a route that resolves through the database can no
        // longer reach them at this point regardless, but leaving them on
        // disk defeats the one thing this class exists to guarantee.
        foreach ($toDelete as $row) {
            $this->storage->discard(dirname((string) $row['nupkg_path']));
        }

        if ($deletePackageToo) {
            @rmdir($this->storage->absolute(sprintf('packages/%d/%s', $package['feed_id'], $package['package_id_lower'])));
        }
    }
}
