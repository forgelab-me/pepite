<?php

declare(strict_types=1);

namespace App\Libraries\Package;

use App\Libraries\Version\NuGetVersion;

/**
 * Everything a .nuspec says about a package.
 *
 * A faithful reading of the nuspec and nothing more. The schema is closed, so
 * this is the whole of what a package can declare about itself — Pepite adds
 * no convention of its own on top.
 */
final class PackageMetadata
{
    /**
     * @param list<string>          $authors
     * @param list<string>          $owners
     * @param list<string>          $tags
     * @param list<PackageType>     $packageTypes
     * @param list<DependencyGroup> $dependencyGroups
     */
    public function __construct(
        public readonly string $id,
        public readonly NuGetVersion $version,
        public readonly ?string $description = null,
        public readonly array $authors = [],
        public readonly array $owners = [],
        public readonly array $tags = [],
        public readonly ?string $title = null,
        public readonly ?string $summary = null,
        public readonly ?string $releaseNotes = null,
        public readonly ?string $copyright = null,
        public readonly ?string $language = null,
        public readonly ?string $projectUrl = null,
        public readonly ?string $iconUrl = null,
        public readonly ?string $icon = null,
        public readonly ?string $readme = null,
        public readonly ?string $licenseUrl = null,
        public readonly ?string $licenseType = null,
        public readonly ?string $licenseValue = null,
        public readonly bool $requireLicenseAcceptance = false,
        public readonly bool $developmentDependency = false,
        public readonly bool $serviceable = false,
        public readonly ?string $minClientVersion = null,
        public readonly ?string $repositoryType = null,
        public readonly ?string $repositoryUrl = null,
        public readonly ?string $repositoryBranch = null,
        public readonly ?string $repositoryCommit = null,
        public readonly array $packageTypes = [],
        public readonly array $dependencyGroups = [],
    ) {
    }

    /**
     * The identifier folded for lookups. Uniqueness in a feed is built on this:
     * without it, Foo.Bar and foo.bar become two packages owned by two people.
     */
    public function idLower(): string
    {
        return strtolower($this->id);
    }

    /**
     * A package with no declared type is a library, per the format's default.
     *
     * @return list<PackageType>
     */
    public function effectivePackageTypes(): array
    {
        return $this->packageTypes === []
            ? [new PackageType(PackageType::DEPENDENCY)]
            : $this->packageTypes;
    }

    public function hasPackageType(string $name): bool
    {
        foreach ($this->effectivePackageTypes() as $type) {
            if (strcasecmp($type->name, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<PackageDependency>
     */
    public function allDependencies(): array
    {
        $all = [];

        foreach ($this->dependencyGroups as $group) {
            foreach ($group->dependencies as $dependency) {
                $all[] = $dependency;
            }
        }

        return $all;
    }
}
