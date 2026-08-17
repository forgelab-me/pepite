<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Exceptions\PublishException;
use App\Libraries\Package\NupkgReader;
use App\Libraries\Package\PackageMetadata;
use App\Models\FeedModel;
use App\Models\PackageDependencyModel;
use App\Models\PackageModel;
use App\Models\PackageOwnerModel;
use App\Models\PackageVersionModel;
use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * Turns a .nupkg on disk into a published version.
 *
 * Order of operations matters. The cheap refusals come first, so a conflicting
 * or misdirected push never touches the filesystem. Blobs are then written,
 * and only then are the rows inserted inside a transaction — the filesystem
 * takes no part in that transaction, so a failure after the write has to undo
 * it by hand.
 *
 * Authorisation is answered by PublishAuthorizer, injected here rather than
 * folded in: "may this identity store it" needs the API key and the pushing
 * user, and a caller with neither — the import command run from an admin's
 * own shell — has no reason to carry either.
 */
final class PackagePublisher
{
    public function __construct(
        private readonly FeedModel $feeds,
        private readonly PackageModel $packages,
        private readonly PackageVersionModel $versions,
        private readonly PackageDependencyModel $dependencies,
        private readonly PackageOwnerModel $owners,
        private readonly PackageStorage $storage,
        private readonly BaseConnection $db,
        private readonly ?PublishAuthorizer $authorizer = null,
    ) {
    }

    /**
     * @param int|null $identityId the API key used, if any — required for
     *                             PublishAuthorizer to consult its rules
     */
    public function publish(
        string $nupkgPath,
        string $feedSlug = 'default',
        ?int $ownerUserId = null,
        ?int $identityId = null,
    ): PublishResult {
        $feed = $this->feeds->findBySlug($feedSlug);

        if ($feed === null) {
            throw PublishException::feedNotFound($feedSlug);
        }

        $reader = NupkgReader::open($nupkgPath);

        try {
            $metadata = $reader->metadata();

            $this->assertPackageTypeAccepted($feed, $metadata);

            $feedId  = (int) $feed['id'];
            $package = $this->packages->findInFeed($feedId, $metadata->id);

            if ($identityId !== null) {
                $this->authorizer?->authorize($identityId, $ownerUserId, $feedId, $metadata->id, $package);
            }

            if ($package === null && ! (bool) $feed['allow_new_packages']) {
                throw PublishException::newPackagesNotAllowed($feedSlug, $metadata->id);
            }

            // Checked before writing anything: a re-push of an existing version
            // is the most common refusal, and it should cost nothing.
            if ($package !== null && $this->versions->versionExists((int) $package['id'], $metadata->version)) {
                throw PublishException::versionAlreadyExists(
                    $metadata->id,
                    $metadata->version->normalized(),
                );
            }

            $paths = $this->storage->store($feedId, $reader, $metadata, $nupkgPath);

            return $this->persist($feed, $package, $metadata, $reader, $paths, $ownerUserId);
        } finally {
            $reader->close();
        }
    }

    /**
     * @param array<string, mixed>                                                                            $feed
     * @param array<string, mixed>|null                                                                       $package
     * @param array{directory: string, nupkg: string, nuspec: string, icon: string|null, readme: string|null} $paths
     */
    private function persist(
        array $feed,
        ?array $package,
        PackageMetadata $metadata,
        NupkgReader $reader,
        array $paths,
        ?int $ownerUserId,
    ): PublishResult {
        $feedId  = (int) $feed['id'];
        $claimed = $package === null;

        $this->db->transBegin();

        try {
            if ($package === null) {
                $this->packages->insert([
                    'feed_id'          => $feedId,
                    'package_id'       => $metadata->id,
                    'package_id_lower' => $metadata->idLower(),
                ]);

                $packageRowId = (int) $this->packages->getInsertID();
            } else {
                $packageRowId = (int) $package['id'];
            }

            $this->versions->insert($this->versionRow($packageRowId, $metadata, $reader, $paths));
            $versionRowId = (int) $this->versions->getInsertID();

            $this->insertDependencies($versionRowId, $metadata);

            // First push claims the identifier.
            if ($ownerUserId !== null) {
                $this->owners->claim($packageRowId, $ownerUserId);
            }

            if ($this->db->transStatus() === false) {
                throw PublishException::storageFailed('the database rejected the publication');
            }

            $this->db->transCommit();
        } catch (Throwable $e) {
            $this->db->transRollback();
            $this->discardBlobs($packageRowId ?? null, $metadata, $paths['directory']);

            throw $e;
        }

        return new PublishResult(
            $feedId,
            (string) $feed['slug'],
            $packageRowId,
            $versionRowId,
            $metadata,
            $paths['directory'],
            $claimed,
        );
    }

    /**
     * @param array{directory: string, nupkg: string, nuspec: string, icon: string|null, readme: string|null} $paths
     *
     * @return array<string, mixed>
     */
    private function versionRow(
        int $packageRowId,
        PackageMetadata $metadata,
        NupkgReader $reader,
        array $paths,
    ): array {
        $version = $metadata->version;

        return [
            'package_id'               => $packageRowId,
            'version_original'         => $version->original,
            'version_normalized'       => $version->normalized(),
            'version_normalized_lower' => $version->normalizedLower(),
            'version_sort_key'         => $version->sortKey(),
            'is_prerelease'            => $version->isPrerelease(),
            'is_listed'                => true,
            'semver_level'             => $version->isSemVer2()
                ? PackageVersionModel::SEMVER_2
                : PackageVersionModel::SEMVER_LEGACY,

            'title'         => $metadata->title,
            'description'   => $metadata->description,
            'summary'       => $metadata->summary,
            'release_notes' => $metadata->releaseNotes,
            'authors'       => $this->encodeList($metadata->authors),
            'owners'        => $this->encodeList($metadata->owners),
            'tags'          => $this->encodeList($metadata->tags),
            'copyright'     => $metadata->copyright,
            'language'      => $metadata->language,
            'project_url'   => $metadata->projectUrl,
            'icon_url'      => $metadata->iconUrl,
            'license_url'   => $metadata->licenseUrl,
            'license_type'  => $metadata->licenseType,
            'license_value' => $metadata->licenseValue,

            'require_license_acceptance' => $metadata->requireLicenseAcceptance,
            'development_dependency'     => $metadata->developmentDependency,

            'min_client_version' => $metadata->minClientVersion,
            'repository_type'    => $metadata->repositoryType,
            'repository_url'     => $metadata->repositoryUrl,
            'repository_branch'  => $metadata->repositoryBranch,
            'repository_commit'  => $metadata->repositoryCommit,

            'package_types' => json_encode(array_map(
                static fn ($type): array => ['name' => $type->name, 'version' => $type->version],
                $metadata->effectivePackageTypes(),
            )),

            'nupkg_path'  => $paths['nupkg'],
            'nuspec_path' => $paths['nuspec'],
            'icon_path'   => $paths['icon'],
            'readme_path' => $paths['readme'],

            'size_bytes'    => $reader->sizeBytes(),
            'sha512_base64' => $reader->sha512Base64(),
            'published_at'  => date('Y-m-d H:i:s'),
        ];
    }

    private function insertDependencies(int $versionRowId, PackageMetadata $metadata): void
    {
        foreach ($metadata->dependencyGroups as $group) {
            foreach ($group->dependencies as $dependency) {
                $this->dependencies->insert([
                    'package_version_id' => $versionRowId,
                    'target_framework'   => $group->targetFramework,
                    'dependency_id'      => $dependency->id,
                    // Stored already normalized, because that is the spelling a
                    // registration document must carry.
                    'version_range'  => $dependency->normalizedRange(),
                    'include_assets' => $dependency->include,
                    'exclude_assets' => $dependency->exclude,
                ]);
            }
        }
    }

    /**
     * @param array<string, mixed> $feed
     */
    private function assertPackageTypeAccepted(array $feed, PackageMetadata $metadata): void
    {
        $allowed = FeedModel::allowedPackageTypes($feed);

        if ($allowed === []) {
            return;
        }

        foreach ($allowed as $name) {
            if ($metadata->hasPackageType($name)) {
                return;
            }
        }

        throw PublishException::packageTypeRejected(
            (string) $feed['slug'],
            array_map(static fn ($type): string => $type->name, $metadata->effectivePackageTypes()),
            $allowed,
        );
    }

    /**
     * Leaves the blobs alone if the version now exists anyway.
     *
     * Two concurrent pushes of the same version share a storage directory. The
     * unique constraint decides which one wins; the loser must not delete the
     * winner's files on its way out.
     */
    private function discardBlobs(?int $packageRowId, PackageMetadata $metadata, string $directory): void
    {
        if ($packageRowId !== null && $this->versions->versionExists($packageRowId, $metadata->version)) {
            return;
        }

        $this->storage->discard($directory);
    }

    /**
     * @param list<string> $values
     */
    private function encodeList(array $values): ?string
    {
        return $values === [] ? null : json_encode($values);
    }
}
