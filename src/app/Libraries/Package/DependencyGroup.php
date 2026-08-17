<?php

declare(strict_types=1);

namespace App\Libraries\Package;

/**
 * The dependencies that apply to one target framework.
 *
 * A null framework means the group applies to every framework, which is also
 * how the legacy flat <dependencies> form is represented.
 */
final class DependencyGroup
{
    /**
     * @param list<PackageDependency> $dependencies
     */
    public function __construct(
        public readonly ?string $targetFramework,
        public readonly array $dependencies,
    ) {
    }

    public function isUniversal(): bool
    {
        return $this->targetFramework === null;
    }
}
