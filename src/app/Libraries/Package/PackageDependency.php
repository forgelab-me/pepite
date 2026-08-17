<?php

declare(strict_types=1);

namespace App\Libraries\Package;

use App\Libraries\Version\VersionRange;

/**
 * One dependency of a package, within a target framework group.
 */
final class PackageDependency
{
    public function __construct(
        public readonly string $id,
        public readonly ?VersionRange $versionRange = null,
        public readonly ?string $include = null,
        public readonly ?string $exclude = null,
    ) {
    }

    /**
     * The range in NuGet's normalized spelling, which is what a registration
     * document has to carry.
     *
     * A dependency with no version means "any version", which NuGet writes as
     * an unbounded range rather than as an empty string.
     */
    public function normalizedRange(): string
    {
        return $this->versionRange?->normalized() ?? '(, )';
    }
}
