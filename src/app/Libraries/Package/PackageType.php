<?php

declare(strict_types=1);

namespace App\Libraries\Package;

/**
 * A declared package type.
 *
 * The format reserves "Dependency" (the default), "DotnetTool" and "Template",
 * but custom names are explicitly allowed — which is how a feed can tell an
 * application apart from a library without leaving the protocol.
 */
final class PackageType
{
    public const DEPENDENCY = 'Dependency';

    public function __construct(
        public readonly string $name,
        public readonly ?string $version = null,
    ) {
    }

    public function isDependency(): bool
    {
        return strcasecmp($this->name, self::DEPENDENCY) === 0;
    }
}
